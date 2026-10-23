<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMenuImport;
use App\Models\MenuImport;
use App\Services\Entitlements;
use App\Services\Import\Ai\AiProviders;
use App\Services\Import\BrandStyle;
use App\Services\Import\ExcelMenuParser;
use App\Services\Import\MenuDraft;
use App\Services\Import\MenuImporter;
use App\Services\Import\SpreadsheetReader;
use App\Services\Import\XlsxWriter;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Menu import: an Excel/CSV sheet, or photos/PDF read by AI with the restaurant's own key. Both end on the same
 * review screen where the owner fixes rows before anything touches the menu.
 */
class MenuImportController extends Controller
{
    public function index(): View
    {
        $rights = Entitlements::current();

        return view('dashboard.menu.import', [
            'excel' => $rights->allows('excel_import'),
            'ai' => $rights->allows('ai_import'),
            'aiReady' => AiProviders::configured(),
            'aiLeft' => $rights->limit('ai_imports_per_month') === null ? null : max(0, $rights->limit('ai_imports_per_month') - $rights->usage('ai_imports_per_month')),
            'recent' => MenuImport::query()->latest('id')->limit(8)->get(),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        $path = storage_path('app/private/menu-template-'.random_token(4).'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new XlsxWriter)->addSheet('Menu', [
            ExcelMenuParser::templateHeader(),
            ['قهوة ساخنة', 'Hot Coffee', 'لاتيه', 'Latte', 65, 'إسبريسو ولبن', 'Espresso & milk', 'صغير:0 | كبير:15', 'شوت زيادة:10', 'dairy'],
            ['', '', 'كابتشينو', 'Cappuccino', 65, '', '', 'صغير:0 | كبير:15', '', 'dairy'],
            ['حلويات', 'Desserts', 'تشيز كيك', 'Cheesecake', 90, '', '', '', '', 'gluten | dairy | eggs'],
        ])->save($path);

        return response()->download($path, 'egmenu-menu-template.xlsx')->deleteFileAfterSend();
    }

    public function excel(Request $request): RedirectResponse
    {
        abort_unless(Entitlements::current()->allows('excel_import'), 403);
        $request->validate(['file' => 'required|file|max:6144']);
        $file = $request->file('file');
        try {
            $draft = ExcelMenuParser::parse(SpreadsheetReader::read($file->getRealPath(), $file->getClientOriginalName()));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        if (MenuDraft::itemCount($draft) === 0) {
            return back()->withErrors(['file' => __('The file has no menu rows.')]);
        }
        $import = MenuImport::query()->create(['source' => 'excel', 'file' => mb_substr($file->getClientOriginalName(), 0, 200),
            'status' => 'parsed', 'result' => $draft, 'user_id' => $request->user()->id]);

        return redirect()->route('dash.import.review', $import);
    }

    public function ai(Request $request): RedirectResponse
    {
        $rights = Entitlements::current();
        abort_unless($rights->allows('ai_import'), 403);
        if (! AiProviders::configured()) {
            return back()->withErrors(['files' => __('Add your AI API key in Settings first.')]);
        }
        if (! $rights->canAdd('ai_imports_per_month')) {
            return back()->withErrors(['files' => $rights->upgradeMessage('ai_imports_per_month')]);
        }
        $request->validate([
            'files' => 'required|array|min:1|max:5',
            'files.*' => 'file|max:10240|mimetypes:image/jpeg,image/png,image/webp,application/pdf',
        ]);
        $pdfs = collect($request->file('files'))->filter(fn ($f) => $f->getMimeType() === 'application/pdf');
        if ($pdfs->count() > 1 || ($pdfs->count() === 1 && count($request->file('files')) > 1)) {
            return back()->withErrors(['files' => __('Upload up to 5 photos, or one PDF.')]);
        }

        $dir = 'tenants/'.tenant()->getTenantKey().'/imports';
        $paths = collect($request->file('files'))->map(fn ($f) => $f->storeAs($dir, random_token(8).'.'.($f->getMimeType() === 'application/pdf' ? 'pdf' : $f->extension()), 'local'))->all();
        $import = MenuImport::query()->create([
            'source' => $pdfs->isNotEmpty() ? 'pdf' : 'image', 'file' => json_encode($paths), 'status' => 'pending', 'user_id' => $request->user()->id,
        ]);
        ProcessMenuImport::dispatch($import->id);

        return redirect()->route('dash.import.review', $import);
    }

    public function review(MenuImport $import): View
    {
        return view('dashboard.menu.import-review', [
            'import' => $import,
            'brand' => $import->status === 'parsed' ? BrandStyle::normalize($import->result['brand'] ?? null) : null,
            'brandAllowed' => BrandStyle::allowed(),
            'hasLogo' => (string) Settings::get('logo') !== '',
        ]);
    }

    public function status(MenuImport $import): JsonResponse
    {
        return response()->json(['status' => $import->status, 'error' => $import->error]);
    }

    public function apply(Request $request, MenuImport $import, MenuImporter $importer): RedirectResponse
    {
        abort_unless($import->status === 'parsed', 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['add', 'update'])],
            'rows' => 'required|array|max:'.MenuDraft::MAX_ITEMS,
            'rows.*.category' => 'nullable|string|max:80', 'rows.*.category_en' => 'nullable|string|max:80',
            'rows.*.name' => 'nullable|string|max:100', 'rows.*.name_en' => 'nullable|string|max:100',
            'rows.*.price' => 'nullable', 'rows.*.description' => 'nullable|string|max:500',
            'rows.*.description_en' => 'nullable|string|max:500',
            'rows.*.sizes' => 'nullable|string|max:500', 'rows.*.extras' => 'nullable|string|max:500',
            'rows.*.allergens' => 'nullable|string|max:300',
            'rows.*.keep' => 'nullable',
        ]);

        // rebuild the draft from the edited rows (unticked rows are skipped)
        $categories = [];
        foreach ($data['rows'] as $row) {
            if (empty($row['keep']) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $cat = trim((string) ($row['category'] ?? '')) ?: __('Menu');
            $categories[$cat] ??= ['name' => $cat, 'name_en' => (string) ($row['category_en'] ?? ''), 'items' => []];
            $categories[$cat]['items'][] = [
                'name' => $row['name'], 'name_en' => $row['name_en'] ?? '', 'price' => $row['price'] ?? 0,
                'description' => $row['description'] ?? '', 'description_en' => (string) ($row['description_en'] ?? ''),
                'sizes' => MenuDraft::parseChoices((string) ($row['sizes'] ?? '')), 'extras' => MenuDraft::parseChoices((string) ($row['extras'] ?? '')),
                'allergens' => (string) ($row['allergens'] ?? ''),
            ];
        }
        $brand = BrandStyle::normalize($import->result['brand'] ?? null);
        try {
            $result = $importer->apply($import, ['categories' => array_values($categories)], $data['mode']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['apply' => $e->getMessage()]);
        }
        // the style of the file (colours, font, logo, language) goes with the import while "apply this style" is ticked
        $styled = [];
        if ($brand !== null && $request->boolean('apply_style')) {
            $styled = BrandStyle::apply($brand, $request->boolean('replace_logo'), 'import #'.$import->id);
        } else {
            BrandStyle::discardLogo($brand);
        }
        $message = __(':created items added, :updated updated.', $result);

        return redirect()->route('dash.menu')->with('ok', $styled !== [] ? $message.' '.__('Your menu now uses the style of your file.') : $message);
    }
}
