<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Services\Audit;
use App\Services\Marketing\Automations;
use App\Services\Marketing\Campaigns;
use App\Services\Notify\GuestNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** WhatsApp campaigns to a segment of the guest list, with an optional coupon (plan feature campaigns). */
class CampaignsController extends Controller
{
    /** Two tabs: the campaigns themselves and the automations (welcome / winback / birthday). */
    public function index(Request $request, Campaigns $campaigns, Automations $automations): View
    {
        $tab = $request->query('tab') === 'automations' ? 'automations' : 'campaigns';
        $list = $tab === 'automations' ? $automations->all() : collect();

        return view('dashboard.campaigns.index', [
            'tab' => $tab,
            'campaigns' => Campaign::query()->with('coupon')->latest('id')->limit(30)->get(),
            'preview' => $campaigns->preview('all'),
            'sender' => GuestNotifier::senderName(),
            'menuUrl' => tenant()->url(),
            'automations' => $list,
            'results' => $list->map(fn ($a) => $automations->results($a)),
            'coupons' => $tab === 'automations' ? Coupon::query()->where('is_active', true)->whereNull('customer_id')->orderBy('code')->get() : collect(),
            'balance' => $automations->balance(),
        ]);
    }

    /** Audience size and cost of a segment, before sending. */
    public function preview(Request $request, Campaigns $campaigns): JsonResponse
    {
        $data = $request->validate(['segment' => ['required', Rule::in(array_keys(Campaign::SEGMENTS))], 'value' => 'nullable|numeric|min:0|max:1000000']);

        return response()->json($campaigns->preview($data['segment'], isset($data['value']) ? (float) $data['value'] : null));
    }

    public function store(Request $request, Campaigns $campaigns): RedirectResponse
    {
        $request->merge(['coupon_code' => Coupon::normalize((string) $request->input('coupon_code', ''))]);
        $needsValue = array_keys(array_filter(Campaign::SEGMENTS, fn ($s) => $s[2] !== null));
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'segment' => ['required', Rule::in(array_keys(Campaign::SEGMENTS))],
            'segment_value' => ['nullable', Rule::requiredIf(in_array($request->input('segment'), $needsValue, true)), 'numeric', 'min:1', 'max:1000000'],
            'message' => 'required|string|max:1000',
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
        ], [], ['coupon_code' => __('Coupon code')]);

        $preview = $campaigns->preview($data['segment'], isset($data['segment_value']) ? (float) $data['segment_value'] : null);
        if ($preview['recipients'] === 0) {
            return back()->withInput()->withErrors(['segment' => __('No customers match this audience.')]);
        }
        if ($data['when'] === 'now' && $preview['balance'] < 1) {
            return back()->withInput()->withErrors(['when' => __('Your WhatsApp credits ran out').'. '.__('Top up to send this campaign.')]);
        }

        $campaign = DB::transaction(function () use ($data, $request) {
            $coupon = null;
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
                'segment_value' => Campaign::SEGMENTS[$data['segment']][2] !== null ? $data['segment_value'] : null,
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
}
