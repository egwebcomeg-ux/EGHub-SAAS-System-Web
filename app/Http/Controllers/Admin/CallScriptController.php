<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallScriptItem;
use App\Models\PlatformAuditLog;
use App\Services\Crm\CallScript;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The sales call script editor (admin › متابعة العملاء › السكريبت), for the platform owner only: the opening line,
 * the WhatsApp opener, the pitch, the questions with their answer options (reply + signal for the offer advice),
 * the objections and the closing. A default item is hidden rather than deleted, so rerunning the seeder never brings
 * it back; items the owner added can be deleted. Every change is in the platform audit log.
 */
class CallScriptController extends Controller
{
    public function index(Request $request): View
    {
        $this->ownerOnly($request);

        return view('admin.crm.script', [
            'items' => CallScriptItem::query()->ordered()->get()->groupBy('kind'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate($this->rules() + ['kind' => ['required', Rule::in(array_keys(CallScriptItem::KINDS))]]);
        $item = CallScriptItem::query()->create([
            'kind' => $data['kind'], 'key' => $data['kind'].'_'.Str::lower(Str::random(8)),
            'title' => trim($data['title']), 'body' => trim($data['body']),
            'options' => $data['kind'] === 'question' ? $this->options($request) : null,
            'sort_order' => (int) CallScriptItem::query()->where('kind', $data['kind'])->max('sort_order') + 10,
            'is_active' => true,
        ]);
        PlatformAuditLog::record($request->user('admin'), 'crm.script_added', $item->key, ['kind' => $item->kind, 'title' => $item->title]);

        return $this->done($item, __('Added to the script.'));
    }

    public function update(Request $request, CallScriptItem $item): RedirectResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate($this->rules() + ['is_active' => 'nullable|boolean']);
        $item->update([
            'title' => trim($data['title']), 'body' => trim($data['body']), 'is_active' => $request->boolean('is_active'),
            'options' => $item->kind === 'question' ? $this->options($request, $item) : null,
        ]);
        PlatformAuditLog::record($request->user('admin'), 'crm.script_updated', $item->key, ['active' => $item->is_active]);

        return $this->done($item, __('Saved.'));
    }

    public function move(Request $request, CallScriptItem $item): RedirectResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $siblings = CallScriptItem::query()->where('kind', $item->kind)->ordered()->get()->values();
        $index = $siblings->search(fn (CallScriptItem $i) => $i->is($item));
        $target = $data['direction'] === 'up' ? $index - 1 : $index + 1;
        if ($index !== false && $siblings->has($target)) {
            $list = $siblings->all();
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];
            foreach ($list as $i => $sibling) {
                $sibling->update(['sort_order' => ($i + 1) * 10]);
            }
        }

        return $this->done($item, __('Order saved.'));
    }

    /** A default item is hidden (a rerun of the seeder would only add it back); one the owner added is deleted. */
    public function destroy(Request $request, CallScriptItem $item): RedirectResponse
    {
        $this->ownerOnly($request);
        if (CallScript::isDefault($item->key)) {
            $item->update(['is_active' => false]);
            PlatformAuditLog::record($request->user('admin'), 'crm.script_updated', $item->key, ['active' => false]);

            return $this->done($item, __('Hidden from the call screen.'));
        }
        $item->delete();
        PlatformAuditLog::record($request->user('admin'), 'crm.script_removed', $item->key, ['title' => $item->title]);

        return redirect()->route('admin.crm.script')->with('ok', __('Removed from the script.'));
    }

    /** Adds the default items that are missing (nothing edited is touched). */
    public function defaults(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $added = CallScript::installDefaults();
        PlatformAuditLog::record($request->user('admin'), 'crm.script_defaults', '', ['added' => $added]);

        return redirect()->route('admin.crm.script')->with('ok', $added > 0
            ? __(':n default items added.', ['n' => $added]) : __('The default script is already there.'));
    }

    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user('admin')->isOwner(), 403, __('Only the platform owner can edit the call script.'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:4000',
            'options' => 'nullable|array|max:12',
            'options.*.key' => 'nullable|string|max:40|alpha_dash',
            'options.*.label' => 'nullable|string|max:80',
            'options.*.reply' => 'nullable|string|max:1000',
            'options.*.signal' => ['nullable', Rule::in(array_keys(CallScriptItem::SIGNALS))],
        ];
    }

    /**
     * The answer options of a question: rows without a label are dropped; a new row gets a key that never changes.
     *
     * @return list<array{key: string, label: string, reply: string, signal: string}>
     */
    private function options(Request $request, ?CallScriptItem $item = null): array
    {
        $known = array_column($item?->answerOptions() ?? [], 'key');
        $out = [];
        foreach ((array) $request->input('options', []) as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $out[] = [
                'key' => in_array($key, $known, true) && ! in_array($key, array_column($out, 'key'), true) ? $key : Str::lower(Str::random(6)),
                'label' => $label, 'reply' => trim((string) ($row['reply'] ?? '')), 'signal' => (string) ($row['signal'] ?? ''),
            ];
        }

        return $out;
    }

    private function done(CallScriptItem $item, string $message): RedirectResponse
    {
        return redirect()->to(route('admin.crm.script').'#item-'.$item->id)->with('ok', $message);
    }
}
