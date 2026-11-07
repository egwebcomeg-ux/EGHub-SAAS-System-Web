<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallDevice;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\SalesCall;
use App\Services\Crm\CallLog;
use App\Services\Crm\ProspectScore;
use App\Services\Push\FcmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «المكالمات» (crm.manage): the calls of the linked sales phones, who called, how long, the summary, and the missed
 * calls nobody returned. A sales person sees their own; crm.team sees the whole team and its totals.
 */
class CallsController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        $filter = in_array($request->query('show'), ['missed', 'unsummarized', 'unknown'], true) ? $request->query('show') : '';
        $query = SalesCall::query()->visibleTo($admin)->with(['admin', 'lead.tenant', 'prospect']);
        match ($filter) {
            'missed' => $query->missedNotReturned(),
            'unsummarized' => $query->where('duration_seconds', '>', 0)->whereNull('summarized_at'),
            'unknown' => $query->whereNull('lead_id')->whereNull('prospect_id'),
            default => null,
        };
        $today = local_day_start(local_at()->toDateString());

        return view('admin.crm.calls.index', [
            'calls' => $query->latest('started_at')->paginate(30)->withQueryString(),
            'filter' => $filter,
            'counts' => [
                'today' => SalesCall::query()->visibleTo($admin)->where('started_at', '>=', $today)->count(),
                'seconds' => (int) SalesCall::query()->visibleTo($admin)->where('started_at', '>=', $today)->sum('duration_seconds'),
                'missed' => SalesCall::query()->visibleTo($admin)->missedNotReturned()->count(),
                'unsummarized' => SalesCall::query()->visibleTo($admin)->where('duration_seconds', '>', 0)->whereNull('summarized_at')->count(),
            ],
            'team' => $admin->allows('crm.team') ? SalesCall::query()->where('started_at', '>=', $today)->whereNotNull('admin_id')
                ->selectRaw('admin_id, count(*) as calls, sum(duration_seconds) as seconds')->groupBy('admin_id')->with('admin')->get() : collect(),
            'devices' => CallDevice::query()->where('admin_id', $admin->id)->latest('id')->get(),
            'admin' => $admin,
        ]);
    }

    public function show(Request $request, SalesCall $call): View
    {
        $this->authorizeCall($request->user('admin'), $call);

        return view('admin.crm.calls.show', ['call' => $call->load(['admin', 'lead.tenant', 'prospect'])]);
    }

    public function summarize(Request $request, SalesCall $call, CallLog $log): RedirectResponse
    {
        $admin = $request->user('admin');
        $this->authorizeCall($admin, $call);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(array_diff_key(Lead::OUTCOMES, ['sent' => true])))],
            'notes' => 'nullable|string|max:2000',
            'next_follow_up_at' => 'nullable|date',
        ]);
        $log->summarize($call, $admin, ['outcome' => $data['outcome'], 'notes' => $data['notes'] ?? null, 'next_follow_up_at' => $data['next_follow_up_at'] ?? null]);

        return redirect()->route('admin.crm.calls')->with('ok', __('Call saved.'));
    }

    /** A number nobody knew becomes a place the team works. */
    public function addPlace(Request $request, SalesCall $call, ProspectScore $score): RedirectResponse
    {
        $admin = $request->user('admin');
        $this->authorizeCall($admin, $call);
        abort_if($call->phone === null || $call->lead_id !== null || $call->prospect_id !== null, 422);
        $data = $request->validate(['name' => 'required|string|max:150', 'category' => ['required', Rule::in(array_keys(Prospect::CATEGORIES))]]);
        $prospect = Prospect::query()->create(['name' => trim($data['name']), 'category' => $data['category'], 'phone' => $call->phone,
            'source' => 'manual', 'created_by' => $admin->id, 'caller_id' => $admin->id, 'governorate' => 'الغربية', 'last_contact_at' => $call->started_at]);
        $prospect->events()->create(['type' => 'import', 'admin_id' => $admin->id, 'meta' => ['source' => 'manual']]);
        $score->rescore([$prospect->id]);
        SalesCall::query()->where('phone', $call->phone)->whereNull('lead_id')->whereNull('prospect_id')->update(['prospect_id' => $prospect->id]);

        return back()->with('ok', __('Place added.'));
    }

    /** The app stores the token it gets here once; only its hash stays. */
    public function linkDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:80']);
        $token = Str::random(64);
        CallDevice::query()->create(['admin_id' => $request->user('admin')->id, 'name' => trim((string) ($data['name'] ?? '')) ?: 'Android', 'token_hash' => hash('sha256', $token), 'last_seen_at' => now()]);

        return response()->json(['ok' => true, 'token' => $token]);
    }

    public function unlinkDevice(Request $request, CallDevice $device): RedirectResponse
    {
        abort_unless($device->admin_id === $request->user('admin')->id, 404);
        $device->delete();

        return back()->with('ok', __('Saved.'));
    }

    /**
     * «اتصل» on the computer: the admin's linked phone places the call (an FCM message the sales app acts on). The
     * computer shows who it is at once, as for a call made on the phone. Without such a phone the page dials as before.
     */
    public function dial(Request $request, CallLog $log, FcmClient $fcm): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{3,30}$/']]);
        $number = preg_replace('/[^0-9+]/', '', $data['phone']);
        $admin = $request->user('admin');
        foreach (CallDevice::query()->dialable($admin->id)->latest('last_seen_at')->get() as $device) {
            $card = $log->ringing($device, $number, 'outgoing');
            $result = $fcm->send((string) $device->push_token, '', '', ['type' => 'sales_dial', 'number' => $number, 'name' => $card['known'] ? $card['name'] : ''], dataOnly: true);
            if ($result === FcmClient::SENT) {
                return response()->json(['ok' => true, 'device' => $device->name]);
            }
            if ($result === FcmClient::INVALID) {
                $device->forceFill(['push_token' => null])->save();
            }
        }

        return response()->json(['ok' => false], 409);
    }

    /** Asked every few seconds by the open dashboard. It does not count as activity for the idle sign-out. */
    public function current(Request $request, CallLog $log): JsonResponse
    {
        return response()->json(['call' => $log->current($request->user('admin'))]);
    }

    private function authorizeCall(PlatformAdmin $admin, SalesCall $call): void
    {
        abort_unless($call->isVisibleTo($admin), 404);
    }
}
