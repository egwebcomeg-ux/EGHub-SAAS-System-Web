<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallScriptItem;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\TenantDiscount;
use App\Services\Crm\CallScript;
use App\Services\Crm\LeadSearch;
use App\Services\Crm\LeadSignals;
use App\Services\Crm\OfferAdvisor;
use App\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «متابعة العملاء»: every restaurant is a lead from the moment it signs up. crm.view lists them and opens the call
 * screen (who, what they did so far, the script, the offer advice, the timeline); crm.manage logs calls and WhatsApp
 * attempts, changes the stage, assigns and sets the next follow-up. Discounts are App\Http\Controllers\Admin\TenantDiscountsController.
 * Won is never set here: the restaurant's first paid invoice sets it (Billing::markPaid).
 */
class LeadsController extends Controller
{
    public function index(Request $request, LeadSearch $search): View
    {
        $admin = $request->user('admin');
        $filters = LeadSearch::filters($request);

        return view('admin.crm.index', [
            'leads' => $search->listing($filters, $admin)->paginate(25)->withQueryString(),
            'filters' => $filters,
            'filtered' => LeadSearch::isFiltered($filters),
            'queue' => $search->queue($admin),
            'counts' => $search->counts(),
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'name_en']),
            'admin' => $admin,
        ]);
    }

    public function show(Request $request, Lead $lead, LeadSignals $signals, CallScript $script, OfferAdvisor $advisor): View
    {
        $admin = $request->user('admin');
        $tenant = $lead->tenant()->with('subscription.plan')->first();
        abort_unless($tenant, 404);
        $activity = $signals->read($tenant);
        $sections = $script->sections();
        $context = $script->context($tenant, $activity['owner'], $admin);
        $phone = (string) ($activity['owner']?->phone ?? '');
        $whatsappText = CallScript::fill((string) ($sections['whatsapp']->first()?->body ?? ''), $context);

        return view('admin.crm.show', [
            'lead' => $lead->load('assignee'),
            'tenant' => $tenant,
            'activity' => $activity,
            'sections' => $sections,
            'context' => $context,
            'advice' => $advisor->advise((array) ($lead->answers ?? []), $activity, $sections['question'], $admin),
            'events' => $lead->events()->with('admin')->latest('id')->limit(60)->get(),
            'discount' => TenantDiscount::usableFor($tenant),
            'discounts' => TenantDiscount::query()->with('grantor')->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get(),
            'admins' => PlatformAdmin::query()->active()->orderBy('name')->get()->filter(fn (PlatformAdmin $a) => $a->allows('crm.manage'))->values(),
            'phone' => $phone,
            'telUrl' => $phone !== '' ? 'tel:'.$phone : null,
            'whatsappUrl' => $phone !== '' ? 'https://wa.me/'.whatsapp_number($phone).($whatsappText !== '' ? '?text='.rawurlencode($whatsappText) : '') : null,
            'admin' => $admin,
            'trialLength' => TenantProvisioner::TRIAL_DAYS,
        ]);
    }

    /** The offer advice for the answers tapped so far (the call screen asks while the caller taps, nothing is saved). */
    public function offer(Request $request, Lead $lead, LeadSignals $signals, CallScript $script, OfferAdvisor $advisor): View
    {
        $tenant = $lead->tenant;
        abort_unless($tenant, 404);
        $questions = $script->sections()['question'];
        $answers = $this->answers($request, $questions) + (array) ($lead->answers ?? []);

        return view('admin.crm.partials.offer', [
            'advice' => $advisor->advise($answers, $signals->read($tenant), $questions, $request->user('admin')),
        ]);
    }

    /** A call or a WhatsApp attempt: outcome, answers, objections raised, notes, next follow-up and an optional stage. */
    public function call(Request $request, Lead $lead, CallScript $script): RedirectResponse
    {
        abort_unless($lead->tenant, 404);
        $sections = $script->sections();
        $data = $request->validate([
            'channel' => ['required', Rule::in(['call', 'whatsapp'])],
            'outcome' => ['required', Rule::in(array_keys(Lead::OUTCOMES))],
            'answers' => 'nullable|array',
            'answers.*' => 'nullable|string|max:40',
            'objections' => 'nullable|array',
            'objections.*' => ['string', Rule::in($sections['objection']->pluck('key')->all())],
            'notes' => 'nullable|string|max:2000',
            'next_follow_up_at' => 'nullable|date',
            'stage' => ['nullable', Rule::in(Lead::MANUAL_STAGES)],
            'lost_reason' => ['nullable', 'required_if:stage,lost', Rule::in(array_keys(Lead::LOST_REASONS))],
        ]);
        if (($data['stage'] ?? null) === 'callback' && empty($data['next_follow_up_at'])) {
            return back()->withInput()->withErrors(['next_follow_up_at' => __('Pick when to call back.')]);
        }
        $answers = $this->answers($request, $sections['question']);
        $followUp = ! empty($data['next_follow_up_at']) ? local_input($data['next_follow_up_at']) : null;
        $from = $lead->stage;
        $stage = $data['stage'] ?? null;
        if ($stage === null && $from === 'new' && in_array($data['outcome'], ['answered', 'sent'], true)) {
            $stage = 'contacted';
        }
        if ($stage === null && $followUp && $from === 'new') {
            $stage = 'callback';
        }
        if ($lead->stage === 'won') {
            $stage = null; // a paying restaurant stays won; the call is still logged
        }

        $lead->events()->create([
            'type' => $data['channel'], 'admin_id' => $request->user('admin')->id, 'outcome' => $data['outcome'],
            'answers' => $answers ?: null, 'objections' => array_values($data['objections'] ?? []) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'next_follow_up_at' => $followUp,
            'meta' => $stage && $stage !== $from ? ['from' => $from, 'to' => $stage] : null,
        ]);
        $lead->update(array_filter([
            'answers' => $answers ? $answers + (array) ($lead->answers ?? []) : null,
            'first_contact_at' => $lead->first_contact_at ?? now(),
            'last_contact_at' => now(),
            'stage' => $stage,
            'lost_reason' => $stage === 'lost' ? $data['lost_reason'] : null,
        ], fn ($v) => $v !== null) + [
            'next_follow_up_at' => $stage === 'lost' ? null : $followUp,
            'assigned_admin_id' => $lead->assigned_admin_id ?? $request->user('admin')->id,
        ]);

        return redirect()->route('admin.crm.show', $lead)->with('ok', __('Call saved.'));
    }

    /** Stage, owner of the lead, next follow-up and notes, without a call. */
    public function update(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless($lead->tenant, 404);
        $data = $request->validate([
            'stage' => ['required', Rule::in($lead->stage === 'won' ? ['won'] : Lead::MANUAL_STAGES)],
            'lost_reason' => ['nullable', 'required_if:stage,lost', Rule::in(array_keys(Lead::LOST_REASONS))],
            'lost_note' => 'nullable|string|max:255',
            'assigned_admin_id' => ['nullable', Rule::exists('platform_admins', 'id')],
            'next_follow_up_at' => ['nullable', 'date', 'required_if:stage,callback'],
            'notes' => 'nullable|string|max:5000',
        ], ['next_follow_up_at.required_if' => __('Pick when to call back.')]);
        $admin = $request->user('admin');
        $assignee = isset($data['assigned_admin_id']) ? PlatformAdmin::query()->find($data['assigned_admin_id']) : null;
        if ($assignee && (! $assignee->is_active || ! $assignee->allows('crm.manage'))) {
            return back()->withInput()->withErrors(['assigned_admin_id' => __('Assign the lead to someone who can follow up customers.')]);
        }
        $followUp = ! empty($data['next_follow_up_at']) ? local_input($data['next_follow_up_at']) : null;
        $changes = [
            'stage' => $data['stage'],
            'lost_reason' => $data['stage'] === 'lost' ? $data['lost_reason'] : null,
            'lost_note' => $data['stage'] === 'lost' ? (trim((string) ($data['lost_note'] ?? '')) ?: null) : null,
            'assigned_admin_id' => $assignee?->id,
            'next_follow_up_at' => $data['stage'] === 'lost' ? null : $followUp,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
        if ($lead->stage !== $changes['stage']) {
            $lead->events()->create(['type' => 'stage', 'admin_id' => $admin->id, 'notes' => $changes['lost_note'],
                'meta' => ['from' => $lead->stage, 'to' => $changes['stage'], 'reason' => $changes['lost_reason']]]);
        }
        if ($lead->assigned_admin_id !== $changes['assigned_admin_id']) {
            $lead->events()->create(['type' => 'assign', 'admin_id' => $admin->id, 'meta' => ['to' => $assignee?->name]]);
        }
        $lead->update($changes);

        return back()->with('ok', __('Saved.'));
    }

    /**
     * The answers posted for the active questions, kept only when the option exists.
     *
     * @param  Collection<int, CallScriptItem>  $questions
     * @return array<string, string>
     */
    private function answers(Request $request, Collection $questions): array
    {
        $posted = (array) $request->input('answers', []);
        $out = [];
        foreach ($questions as $question) {
            $value = $posted[$question->key] ?? null;
            if (is_string($value) && in_array($value, array_column($question->answerOptions(), 'key'), true)) {
                $out[$question->key] = $value;
            }
        }

        return $out;
    }
}
