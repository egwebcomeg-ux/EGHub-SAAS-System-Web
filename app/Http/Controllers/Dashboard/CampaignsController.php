<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Audit;
use App\Services\Customers\ClaimLinks;
use App\Services\Customers\Outreach;
use App\Services\Customers\Segments;
use App\Services\Entitlements;
use App\Services\Marketing\Automations;
use App\Services\Marketing\Campaigns;
use App\Services\Notify\GuestNotifier;
use App\Services\Sms\Sms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One campaigns page: a message to a group of the guest list, sent via the staff's own phone (free, a send list made
 * now: App\Services\Customers\Outreach), SMS (the same list sent at once, when a provider is set up), or EgHub WhatsApp
 * (credits, now or at a time, with an optional coupon: plan feature campaigns). One list of every campaign, and the
 * automations tab (feature campaigns).
 */
class CampaignsController extends Controller
{
    /** Two tabs: the campaigns themselves and the automations (welcome / winback / birthday). */
    public function index(Request $request, Campaigns $campaigns, Automations $automations, Segments $segments, Outreach $outreach): View|Response
    {
        $rights = Entitlements::current();
        $tab = $request->query('tab') === 'automations' ? 'automations' : 'campaigns';
        if ($tab === 'automations' && ! $rights->allows('campaigns')) {
            // automations are EgHub WhatsApp messages: the same locked page as every route of the feature
            return response()->view('dashboard.locked', ['feature' => 'campaigns', 'message' => $rights->upgradeMessage('campaigns'),
                'salesPlan' => Entitlements::salesOnlyPlanFor('campaigns')], 403);
        }
        $vias = self::vias($request->user());
        abort_if($vias === [], 403);
        $list = $tab === 'automations' ? $automations->all() : collect();
        $sent = $tab === 'campaigns' ? Campaign::query()->with('coupon')->latest('id')->limit(30)->get() : collect();
        $asked = (string) $request->query('segment');
        $segment = Segments::fromRequest($asked) ?: (in_array($asked, Campaign::audiences(), true) ? $asked : 'all');
        $via = in_array($request->query('channel'), $vias, true) ? (string) $request->query('channel') : (in_array('whatsapp', $vias, true) ? 'whatsapp' : $vias[0]);

        return view('dashboard.campaigns.index', [
            'tab' => $tab,
            'vias' => $vias, 'via' => $via, 'paid' => $rights->allows('campaigns'),
            'segment' => $segment,
            // the guest groups with their size, what to do and a message to start from
            'groups' => $tab === 'campaigns' ? $segments->summary() : [],
            'messages' => collect(Segments::keys())->mapWithKeys(fn (string $k) => [$k => Segments::message($k)]),
            'campaigns' => $sent,
            'stats' => $sent->where('channel', Outreach::CHANNEL)->mapWithKeys(fn (Campaign $c) => [$c->id => $outreach->stats($c)]),
            'preview' => $tab === 'campaigns' ? $campaigns->preview($segment, null, $via) : null,
            'sender' => GuestNotifier::senderName(),
            'menuUrl' => tenant()->url(),
            'automations' => $list,
            'results' => $list->map(fn ($a) => $automations->results($a)),
            'coupons' => $tab === 'automations' ? Coupon::query()->where('is_active', true)->whereNull('customer_id')->orderBy('code')->get() : collect(),
            // a campaign can send a code from the coupons page: open to anyone (no guest, no single-use batch), still good
            'pickable' => $tab === 'campaigns' && in_array('whatsapp', $vias, true) ? self::pickable()->orderBy('code')->limit(200)->get() : collect(),
            'balance' => $automations->balance(),
        ]);
    }

    /** Audience size (and, via EgHub WhatsApp, the cost) of a group or filter, before sending. */
    public function preview(Request $request, Campaigns $campaigns): JsonResponse
    {
        $data = $request->validate([
            'segment' => ['required', Rule::in(Campaign::audiences())], 'value' => 'nullable|numeric|min:0|max:1000000',
            'channel' => ['nullable', Rule::in(array_keys(Campaign::VIA))],
        ]);

        return response()->json($campaigns->preview($data['segment'], isset($data['value']) ? (float) $data['value'] : null, $data['channel'] ?? 'whatsapp'));
    }

    /** A new campaign, sent via the channel picked (EgHub WhatsApp when none is sent: the old form). */
    public function store(Request $request, Campaigns $campaigns, Outreach $outreach): RedirectResponse
    {
        $via = (string) $request->input('channel', 'whatsapp');
        abort_if($via === 'whatsapp' && ! Entitlements::current()->allows('campaigns'), 403);
        $request->validate(['channel' => ['nullable', Rule::in(self::vias($request->user()))]], [], ['channel' => __('Send via')]);

        return $via === 'whatsapp' ? $this->storeWhatsapp($request, $campaigns) : $this->storeFromPhone($request, $outreach, $via);
    }

    /** @return Builder<Coupon> codes a campaign may send: no guest attached, not a printed single-use batch, still usable */
    public static function pickable(): Builder
    {
        return Coupon::query()->whereNull('customer_id')->whereNotIn('source', ['batch', 'welcome'])->usable();
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        abort_unless(in_array($campaign->status, ['scheduled', 'stopped'], true), 404);
        $campaign->update(['status' => 'cancelled']);

        return back()->with('ok', __('Campaign cancelled.'));
    }

    /** After a top-up: continue a campaign that stopped when the credits ran out. */
    public function resume(Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        abort_unless($campaign->status === 'stopped', 404);
        $campaigns->start($campaign);

        return back()->with('ok', __('The campaign is being sent.'));
    }

    /**
     * How this user may send a campaign: from their own phone and by SMS with the customer list (feature customers and
     * the right to see the guests), from EgHub WhatsApp with feature campaigns.
     *
     * @return list<string>
     */
    public static function vias(User $user): array
    {
        $rights = Entitlements::current();
        $phone = $rights->allows('customers') && $user->can('customers.view');

        return array_keys(array_filter(['manual' => $phone, 'sms' => $phone && Sms::enabled(), 'whatsapp' => $rights->allows('campaigns')]));
    }

    /** The send list made now: the staff send it row by row from their own WhatsApp, or it goes out as SMS at once. */
    private function storeFromPhone(Request $request, Outreach $outreach, string $via): RedirectResponse
    {
        $data = $request->validate($this->audienceRules($request) + [
            'personal_coupon' => 'nullable|boolean',
            'personal_type' => ['nullable', 'required_if:personal_coupon,1', Rule::in(['percent', 'amount'])],
            'personal_value' => ['nullable', 'required_if:personal_coupon,1', 'numeric', 'min:1', $request->input('personal_type') === 'percent' ? 'max:100' : 'max:100000'],
            'personal_days' => 'nullable|required_if:personal_coupon,1|integer|min:1|max:90',
            'personal_min_order' => 'nullable|numeric|min:0|max:100000',
            'with_claim' => 'nullable|boolean',
        ]);
        $value = Campaign::valueUnit($data['segment']) !== null ? (float) $data['segment_value'] : null;
        if ($outreach->reachable($data['segment'], $value)->doesntExist()) {
            return back()->withInput()->withErrors(['segment' => __('No guests with a mobile in this group (or all of them asked for no messages).')]);
        }
        $options = [
            'coupon' => $request->boolean('personal_coupon') ? ['type' => $data['personal_type'], 'value' => (float) $data['personal_value'],
                'days' => (int) $data['personal_days'], 'min_order' => (float) ($data['personal_min_order'] ?? 0)] : null,
            'claim' => $request->boolean('with_claim'),
            'claim_days' => ClaimLinks::DAYS,
            'via' => $via,
        ];
        $campaign = $outreach->create($data['name'], $data['segment'], $value, $data['message'], $options, $request->user());
        Audit::record('campaign.manual_created', $campaign->name, ['audience' => $data['segment'], 'recipients' => $campaign->cursor, 'via' => $via]);
        if ($via === 'sms') {
            $outreach->startSms($campaign);
            Audit::record('campaign.sms_started', $campaign->name, ['campaign' => $campaign->id]);

            return redirect()->route('dash.outreach.show', $campaign)->with('ok', __('The SMS are being sent in the background. Refresh to follow.'));
        }

        return redirect()->route('dash.outreach.show', $campaign)->with('ok', __('The send list is ready: tap «Send» on each guest.'));
    }

    /** From EgHub's WhatsApp number, now or at a time, one credit a message (feature campaigns). */
    private function storeWhatsapp(Request $request, Campaigns $campaigns): RedirectResponse
    {
        $request->merge(['coupon_code' => Coupon::normalize((string) $request->input('coupon_code', ''))]);
        $data = $request->validate($this->audienceRules($request) + [
            'when' => ['required', Rule::in(['now', 'later'])],
            'scheduled_at' => 'nullable|required_if:when,later|date|after:now',
            'coupon_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9_-]{3,30}$/',
                Rule::unique('coupons', 'code')->where('tenant_id', tenant()->getTenantKey())],
            'coupon_type' => ['nullable', 'required_with:coupon_code', Rule::in(['percent', 'amount'])],
            'coupon_value' => ['nullable', 'required_with:coupon_code', 'numeric', 'min:1', $request->input('coupon_type') === 'percent' ? 'max:100' : 'max:100000'],
            'coupon_max_discount' => 'nullable|numeric|min:1|max:100000',
            'coupon_min_order' => 'nullable|numeric|min:0|max:100000',
            'coupon_expires_at' => 'nullable|date|after:today',
            'coupon_max_uses' => 'nullable|integer|min:1|max:1000000',
            'coupon_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::pickable()->whereKey((int) $value)->exists()) {
                    $fail(__('Pick a coupon from the list.'));
                }
            }],
        ], [], ['coupon_code' => __('Coupon code')]);

        $preview = $campaigns->preview($data['segment'], isset($data['segment_value']) ? (float) $data['segment_value'] : null);
        if ($preview['recipients'] === 0) {
            return back()->withInput()->withErrors(['segment' => __('No customers match this audience.')]);
        }
        if ($data['when'] === 'now' && $preview['cost'] > 0 && $preview['balance'] < 1) {
            return back()->withInput()->withErrors(['when' => __('Your WhatsApp credits ran out').'. '.__('Top up to send this campaign.')]);
        }

        $campaign = DB::transaction(function () use ($data, $request) {
            // a new code typed here wins over one picked from the coupons list
            $coupon = ($data['coupon_code'] ?? '') === '' && ! empty($data['coupon_id']) ? Coupon::query()->find((int) $data['coupon_id']) : null;
            if (($data['coupon_code'] ?? '') !== '') {
                $coupon = Coupon::query()->create([
                    'code' => $data['coupon_code'], 'type' => $data['coupon_type'], 'value' => $data['coupon_value'],
                    'max_discount' => $data['coupon_type'] === 'percent' ? ($data['coupon_max_discount'] ?? null) : null,
                    'min_order' => $data['coupon_min_order'] ?? 0, 'max_uses' => $data['coupon_max_uses'] ?? null,
                    'expires_at' => isset($data['coupon_expires_at']) ? Carbon::parse($data['coupon_expires_at'])->endOfDay() : null,
                ]);
            }

            return Campaign::query()->create([
                'name' => $data['name'], 'channel' => 'whatsapp', 'segment' => $data['segment'],
                'segment_value' => Campaign::valueUnit($data['segment']) !== null ? $data['segment_value'] : null,
                'message' => $data['message'], 'coupon_id' => $coupon?->id, 'status' => 'scheduled',
                'scheduled_at' => $data['when'] === 'later' ? Carbon::parse($data['scheduled_at'], config('app.timezone')) : now(),
                'created_by' => $request->user()->id,
            ]);
        });
        Audit::record('campaign.created', $campaign->name, ['segment' => $campaign->segment, 'recipients' => $preview['recipients']]);

        if ($data['when'] === 'now') {
            $campaigns->start($campaign);

            return redirect()->route('dash.campaigns')->with('ok', __('The campaign is being sent.'));
        }

        return redirect()->route('dash.campaigns')->with('ok', __('The campaign is scheduled for :time.', ['time' => local_time($campaign->scheduled_at, 'Y/m/d h:i A')]));
    }

    /** @return array<string, mixed> the name, who gets it (a guest group or a filter with its number) and the message */
    private function audienceRules(Request $request): array
    {
        return [
            'name' => 'required|string|max:100',
            'segment' => ['required', Rule::in(Campaign::audiences())],
            'segment_value' => ['nullable', Rule::requiredIf(Campaign::valueUnit((string) $request->input('segment')) !== null), 'numeric', 'min:1', 'max:1000000'],
            'message' => 'required|string|max:1000',
        ];
    }
}
