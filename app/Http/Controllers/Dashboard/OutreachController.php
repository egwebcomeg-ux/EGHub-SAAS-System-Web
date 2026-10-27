<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Audit;
use App\Services\Customers\Audiences;
use App\Services\Customers\ClaimLinks;
use App\Services\Customers\Outreach;
use App\Services\Sms\Sms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «حملة واتساب مجانية»: the manual campaign (App\Services\Customers\Outreach) for every restaurant with the customer
 * list, no Meta account or credits needed: pick a group, write the message, then send it row by row from the staff's
 * own WhatsApp. Results come from the guests' personal coupons and links. With an SMS provider configured
 * (config/sms.php) the same list can also go out as SMS.
 */
class OutreachController extends Controller
{
    public function index(Request $request, Audiences $audiences, Outreach $outreach): View
    {
        $campaigns = Campaign::query()->where('channel', Outreach::CHANNEL)->latest('id')->limit(20)->get();
        $pick = Audiences::is((string) $request->query('audience')) ? (string) $request->query('audience') : 'imported';

        return view('dashboard.customers.outreach', [
            'audiences' => $audiences->summary(),
            'pick' => $pick,
            'messages' => collect(Audiences::KEYS)->mapWithKeys(fn (string $k) => [$k => Audiences::message($k)]),
            'campaigns' => $campaigns,
            'stats' => $campaigns->mapWithKeys(fn (Campaign $c) => [$c->id => $outreach->stats($c)]),
        ]);
    }

    public function store(Request $request, Outreach $outreach): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'audience' => ['required', Rule::in(Audiences::KEYS)],
            'message' => 'required|string|max:1000',
            'with_coupon' => 'nullable|boolean',
            'coupon_type' => ['nullable', 'required_if:with_coupon,1', Rule::in(['percent', 'amount'])],
            'coupon_value' => ['nullable', 'required_if:with_coupon,1', 'numeric', 'min:1', $request->input('coupon_type') === 'percent' ? 'max:100' : 'max:100000'],
            'coupon_days' => 'nullable|required_if:with_coupon,1|integer|min:1|max:90',
            'coupon_min_order' => 'nullable|numeric|min:0|max:100000',
            'with_claim' => 'nullable|boolean',
        ]);
        $options = [
            'coupon' => $request->boolean('with_coupon') ? ['type' => $data['coupon_type'], 'value' => (float) $data['coupon_value'],
                'days' => (int) $data['coupon_days'], 'min_order' => (float) ($data['coupon_min_order'] ?? 0)] : null,
            'claim' => $request->boolean('with_claim'),
            'claim_days' => ClaimLinks::DAYS,
        ];
        $count = app(Audiences::class)->query($data['audience'])->where('whatsapp_opt_out', false)->whereNotNull('phone')->where('phone', '!=', '')->count();
        if ($count === 0) {
            return back()->withInput()->withErrors(['audience' => __('No guests with a mobile in this group (or all of them asked for no messages).')]);
        }
        $campaign = $outreach->create($data['name'], $data['audience'], $data['message'], $options, $request->user());
        Audit::record('campaign.manual_created', $campaign->name, ['audience' => $data['audience'], 'recipients' => $campaign->cursor]);

        return redirect()->route('dash.outreach.show', $campaign)->with('ok', __('The send list is ready: tap «Send» on each guest.'));
    }

    public function show(Request $request, Campaign $campaign, Outreach $outreach): View
    {
        $this->manual($campaign);
        $filter = in_array($request->query('show'), ['waiting', 'sent'], true) ? $request->query('show') : 'all';
        $rows = CampaignRecipient::query()->where('campaign_id', $campaign->id)->with(['coupon', 'customer:id,whatsapp_opt_out'])
            ->when($filter === 'waiting', fn ($q) => $q->whereNull('sent_at')->whereNull('opted_out_at'))
            ->when($filter === 'sent', fn ($q) => $q->whereNotNull('sent_at'))
            ->orderBy('id')->paginate(Outreach::PER_PAGE)->withQueryString();
        $stats = $outreach->stats($campaign);
        $sample = $rows->first();

        return view('dashboard.customers.outreach-show', [
            'campaign' => $campaign, 'rows' => $rows, 'stats' => $stats, 'filter' => $filter, 'outreach' => $outreach,
            'sms' => Sms::enabled(), 'smsState' => $campaign->options['sms'] ?? null,
            'smsEstimate' => $sample ? Sms::estimate($outreach->render($campaign, $sample), max(0, $stats['recipients'] - $stats['opted_out'])) : null,
        ]);
    }

    /** «ابعت» was tapped: WhatsApp opened on the staff's phone with the message (the page marks the row at once). */
    public function sent(Request $request, Campaign $campaign, CampaignRecipient $recipient, Outreach $outreach): JsonResponse
    {
        $this->row($campaign, $recipient);
        $outreach->markSent($recipient, $request->user());

        return response()->json(['ok' => true, 'sent' => $outreach->stats($campaign)['sent']]);
    }

    /** The guest answered «وقف»: never in a campaign again. */
    public function optOut(Campaign $campaign, CampaignRecipient $recipient, Outreach $outreach): RedirectResponse
    {
        $this->row($campaign, $recipient);
        $outreach->optOut($recipient);
        Audit::record('customer.opted_out', (string) $recipient->customer_id, ['campaign' => $campaign->id]);

        return back()->with('ok', __('Done: this guest will not get campaign messages again.'));
    }

    /** phone, name, message, link: for an SMS provider's bulk upload (owner and manager only, in the audit log). */
    public function export(Campaign $campaign, Outreach $outreach): StreamedResponse
    {
        $this->manual($campaign);
        Audit::record('campaign.links_exported', $campaign->name, ['campaign' => $campaign->id]);
        $rows = CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereNull('opted_out_at')
            ->whereHas('customer', fn ($q) => $q->where('whatsapp_opt_out', false))->with('coupon')->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows, $campaign, $outreach) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['phone', 'name', 'link', 'code', 'message']);
            foreach ($rows as $row) {
                fputcsv($out, csv_row([$row->phone, $row->name, $outreach->link($row), (string) $row->coupon?->code, $outreach->render($campaign, $row)]));
            }
            fclose($out);
        }, 'campaign-'.$campaign->id.'-links.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** «ابعت SMS للشريحة»: queued, throttled, only with a provider configured. */
    public function sms(Campaign $campaign, Outreach $outreach): RedirectResponse
    {
        $this->manual($campaign);
        abort_unless(Sms::enabled(), 404);
        if (($campaign->options['sms'] ?? null) === 'sending') {
            return back()->with('ok', __('The SMS are already being sent.'));
        }
        $outreach->startSms($campaign);
        Audit::record('campaign.sms_started', $campaign->name, ['campaign' => $campaign->id]);

        return back()->with('ok', __('The SMS are being sent in the background. Refresh to follow.'));
    }

    private function manual(Campaign $campaign): void
    {
        abort_unless($campaign->channel === Outreach::CHANNEL, 404);
    }

    private function row(Campaign $campaign, CampaignRecipient $recipient): void
    {
        $this->manual($campaign);
        abort_unless($recipient->campaign_id === $campaign->id, 404);
    }
}
