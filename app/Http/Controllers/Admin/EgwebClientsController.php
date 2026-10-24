<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Services\Seo\EgwebClients;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * admin › branding › "شعارات عملاء EgWeb": the logos of the "Restaurants and cafés that trust EgWeb" strip on the
 * home page (App\Services\Seo\EgwebClients). Add, edit, show/hide, move and remove; every change is in the platform
 * audit log. The first change saves the bundled default list, so from then on the saved list is what shows.
 */
class EgwebClientsController extends Controller
{
    public function store(Request $request, EgwebClients $clients): RedirectResponse
    {
        $data = $request->validate($this->rules() + ['image' => $this->imageRule(true)]);
        $list = EgwebClients::all();
        if (count($list) >= EgwebClients::MAX_LOGOS) {
            return back()->withErrors(['image' => __('That is the most logos the strip can hold. Remove one first.')]);
        }
        $image = $clients->storeImage($request->file('image'));
        $key = (string) Str::uuid();
        $list[] = ['key' => $key, 'name' => trim($data['name']), 'url' => trim((string) ($data['url'] ?? '')),
            'active' => $request->boolean('active')] + $image;
        EgwebClients::save($list);
        PlatformAuditLog::record($request->user('admin'), 'egweb_clients.added', $data['name'], ['key' => $key, 'active' => $request->boolean('active')]);

        return $this->done(__('Logo added.'));
    }

    public function update(Request $request, string $key, EgwebClients $clients): RedirectResponse
    {
        $data = $request->validate($this->rules() + ['image' => $this->imageRule(false)]);
        $list = EgwebClients::all();
        $index = $this->indexOf($list, $key);
        $entry = $list[$index];
        if ($request->hasFile('image')) {
            $image = $clients->storeImage($request->file('image'));
            $clients->deleteImage($entry['path']);
            $entry = $image + $entry;
        }
        $entry = ['name' => trim($data['name']), 'url' => trim((string) ($data['url'] ?? '')), 'active' => $request->boolean('active')] + $entry;
        $list[$index] = $entry;
        EgwebClients::save($list);
        PlatformAuditLog::record($request->user('admin'), 'egweb_clients.updated', $entry['name'], [
            'key' => $key, 'active' => $entry['active'], 'image_replaced' => $request->hasFile('image'),
        ]);

        return $this->done(__('Logo saved.'));
    }

    public function move(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $list = EgwebClients::all();
        $index = $this->indexOf($list, $key);
        $target = $data['direction'] === 'up' ? $index - 1 : $index + 1;
        if (isset($list[$target])) {
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];
            EgwebClients::save($list);
            PlatformAuditLog::record($request->user('admin'), 'egweb_clients.moved', $list[$target]['name'], ['key' => $key, 'position' => $target + 1]);
        }

        return $this->done(__('Order saved.'));
    }

    public function destroy(Request $request, string $key, EgwebClients $clients): RedirectResponse
    {
        $list = EgwebClients::all();
        $index = $this->indexOf($list, $key);
        $entry = $list[$index];
        array_splice($list, $index, 1);
        EgwebClients::save($list);
        $clients->deleteImage($entry['path']);
        PlatformAuditLog::record($request->user('admin'), 'egweb_clients.removed', $entry['name'], ['key' => $key]);

        return $this->done(__('Logo removed.'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'url' => 'nullable|url:http,https|max:255',
            'active' => 'nullable|boolean',
        ];
    }

    /** @return list<string> */
    private function imageRule(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'max:5120', 'mimes:png,webp,jpg,jpeg,svg'];
    }

    /** @param  list<array<string, mixed>>  $list */
    private function indexOf(array $list, string $key): int
    {
        foreach ($list as $i => $entry) {
            if ($entry['key'] === $key) {
                return $i;
            }
        }
        abort(404);
    }

    private function done(string $message): RedirectResponse
    {
        return redirect()->to(route('admin.branding').'#egweb-clients')->with('ok', $message);
    }
}
