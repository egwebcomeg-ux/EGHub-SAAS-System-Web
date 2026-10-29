<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Audit;
use App\Services\Customers\Outreach;
use App\Services\Customers\Segments;
use App\Services\Sms\Sms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «حملة واتساب مجانية»: the send list of a manual campaign (App\Services\Customers\Outreach), for every restaurant with
 * the customer list, no Meta account or credits needed. The campaign is made on the campaigns page (sent via «from my
 * own phone»); here the staff send it row by row from their own WhatsApp. Results come from the guests' personal
 * coupons and links. With an SMS provider configured (config/sms.php) the same list can also go out as SMS.
 */
class OutreachController extends Controller
{
    /** The old address of the free campaign's form: the campaigns page, sent from my own phone, to the same group. */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('dash.campaigns', array_filter(['channel' => Outreach::CHANNEL, 'segment' => Segments::fromRequest($request->query('audience'))]));
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
