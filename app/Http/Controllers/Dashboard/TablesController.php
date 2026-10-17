<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Services\Entitlements;
use App\Services\Onboarding;
use App\Services\Qr;
use App\Services\Settings;
use App\Support\StandStyles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Tables of each branch and their QR codes (one unguessable link per table, printable as cards). */
class TablesController extends Controller
{
    public function index(): View
    {
        $rights = Entitlements::current();

        return view('dashboard.tables.index', [
            'branches' => Branch::query()->orderBy('sort_order')->with(['tables' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->get(),
            'usage' => ['used' => $rights->usage('tables'), 'limit' => $rights->limit('tables')],
            'qrTarget' => Settings::get('qr_target') === 'links' ? 'links' : 'menu',
        ]);
    }

    /** Adds tables "from".."to" (numbers) or one named table. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'from' => 'nullable|integer|min:1|max:999',
            'to' => 'nullable|integer|min:1|max:999|gte:from',
            'name' => 'nullable|string|max:40',
        ]);
        $names = $data['name'] ?? null
            ? [$data['name']]
            : array_map('strval', range((int) ($data['from'] ?? 1), (int) ($data['to'] ?? $data['from'] ?? 1)));

        $existing = DiningTable::query()->where('branch_id', $data['branch_id'])->pluck('name')->all();
        $names = array_values(array_diff($names, $existing));
        if ($names === []) {
            return back()->withErrors(['name' => __('These tables already exist.')]);
        }
        $rights = Entitlements::current();
        if (! $rights->canAdd('tables', count($names))) {
            return back()->withErrors(['limit' => $rights->upgradeMessage('tables')]);
        }
        $order = (int) DiningTable::query()->where('branch_id', $data['branch_id'])->max('sort_order');
        foreach ($names as $name) {
            DiningTable::query()->create(['branch_id' => $data['branch_id'], 'name' => $name, 'sort_order' => ++$order]);
        }

        return back()->with('ok', __(':n tables added.', ['n' => count($names)]));
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:40', 'seats' => 'nullable|integer|min:1|max:50']);
        $table->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('Table saved.'));
    }

    /** New QR link for a table (old printed QR stops working), e.g. after a photo of it was shared. */
    public function rotate(DiningTable $table): RedirectResponse
    {
        $table->update(['qr_token' => random_token(10)]);

        return back()->with('ok', __('New QR created for table :name. Print it again.', ['name' => $table->name]));
    }

    public function destroy(DiningTable $table): RedirectResponse
    {
        $table->delete();

        return back()->with('ok', __('Table deleted.'));
    }

    /** Printable QR cards (all tables of a branch, or one table) in the saved stand design, with the design panel. */
    public function print(Request $request): View
    {
        $query = DiningTable::query()->where('is_active', true)->with('branch')->orderBy('branch_id')->orderBy('sort_order');
        if ($request->filled('table')) {
            $query->whereKey((int) $request->query('table'));
        } elseif ($request->filled('branch')) {
            $query->where('branch_id', (int) $request->query('branch'));
        }
        $settings = Settings::all();
        $qrColor = StandStyles::qrColor($settings['color_secondary']);
        // the storefront QR encodes the chosen target; table QRs keep their /t/{token} link and are redirected on scan
        $toLinks = $settings['qr_target'] === 'links';
        $canCallWaiter = StandStyles::canCallWaiter();
        Onboarding::markQrPrinted();

        return view('dashboard.tables.print', [
            'cards' => $query->get()->map(fn (DiningTable $t) => [
                'table' => $t, 'url' => $t->menuUrl(), 'qr' => Qr::svg($t->menuUrl(), 300, $qrColor, 2),
            ]),
            'menuQr' => Qr::svg($toLinks ? tenant()->url('links') : tenant()->url(), 300, $qrColor, 2),
            'toLinks' => $toLinks,
            'settings' => $settings,
            'onlyMenu' => $request->boolean('menu'),
            'design' => StandStyles::current(),
            'canCallWaiter' => $canCallWaiter,
            'tableHeadline' => $this->tableHeadline($toLinks, $canCallWaiter),
            'shortUrl' => $this->shortUrl(tenant()->url()),
        ]);
    }

    /** Saves the stand design (material, print layout, optional lines) used by the print page and the SVG download. */
    public function design(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'stand_style' => ['required', Rule::in(StandStyles::keys())],
            'stand_layout' => ['required', Rule::in(StandStyles::LAYOUTS)],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'wifi_name' => 'nullable|string|max:40',
            'wifi_password' => 'nullable|string|max:40',
        ]);
        Settings::put([
            'stand_style' => $data['stand_style'],
            'stand_layout' => $data['stand_layout'],
            'stand_options' => json_encode([
                'accent' => strtoupper($data['accent']),
                'waiter' => $request->boolean('waiter'),
                'payment' => $request->boolean('payment'),
                'wifi_name' => trim((string) ($data['wifi_name'] ?? '')),
                'wifi_password' => trim((string) ($data['wifi_password'] ?? '')),
                'url' => $request->boolean('url'),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('ok', __('Stand design saved.'));
    }

    /** One table's card as a vector SVG file (A6, QR embedded) for a print shop or a design program. */
    public function svg(DiningTable $table): Response
    {
        $settings = Settings::all();
        $design = StandStyles::current();
        $options = $design['options'];
        $canCallWaiter = StandStyles::canCallWaiter();
        $wifi = $options['wifi_name'] === '' ? null
            : 'Wi-Fi: '.$options['wifi_name'].($options['wifi_password'] !== '' ? ' · '.__('Password').': '.$options['wifi_password'] : '');

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('dashboard.tables.card-svg', [
            'design' => $design,
            'palette' => StandStyles::palette($design['style']),
            'brand' => tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name,
            'logo' => $this->logoDataUrl($settings['logo']),
            'headline' => $this->tableHeadline($settings['qr_target'] === 'links', $canCallWaiter),
            'tableLabel' => __('Table :name', ['name' => $table->name]),
            'qr' => Qr::svg($table->menuUrl(), 300, StandStyles::qrColor($settings['color_secondary']), 2),
            'lines' => array_values(array_filter([
                $canCallWaiter && $options['waiter'] ? __('Call the waiter from your phone') : null,
                $options['payment'] ? __('Pay cash or InstaPay') : null,
                $wifi,
            ])),
            'url' => $options['url'] ? $this->shortUrl(tenant()->url()) : null,
        ])->render();
        $filename = 'table-'.(Str::slug($table->name) ?: $table->id).'-qr.svg';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function tableHeadline(bool $toLinks, bool $canOrder): string
    {
        return match (true) {
            $toLinks => __('Scan for the menu, ordering and our links'),
            $canOrder => __('Scan the code and order'),
            default => __('Scan to see the menu'),
        };
    }

    /** egmenu.app/rosto: the menu address without the scheme, short enough to type. */
    private function shortUrl(string $url): string
    {
        return (string) preg_replace('#^https?://#', '', rtrim($url, '/'));
    }

    /** The logo inlined as a data: URL so the downloaded SVG works on its own. */
    private function logoDataUrl(string $path): ?string
    {
        $disk = Storage::disk('public');
        if ($path === '' || ! $disk->exists($path) || $disk->size($path) > 2_000_000) {
            return null;
        }

        return 'data:'.$disk->mimeType($path).';base64,'.base64_encode((string) $disk->get($path));
    }
}
