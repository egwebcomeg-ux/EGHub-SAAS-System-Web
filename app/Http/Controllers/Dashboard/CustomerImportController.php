<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\Customers\CustomerImport;
use App\Services\Import\SpreadsheetReader;
use App\Services\Import\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Guest list import: upload a CSV / .xlsx, review new / existing / skipped rows, then save. The uploaded file waits in
 * the restaurant's private folder (its path in the session) between the review and the save, and is deleted after.
 */
class CustomerImportController extends Controller
{
    private const SESSION = 'customer_import';

    public function create(): View
    {
        return view('dashboard.customers.import', ['maxRows' => CustomerImport::MAX_ROWS, 'maxMb' => CustomerImport::MAX_KILOBYTES / 1024]);
    }

    public function template(): BinaryFileResponse
    {
        $path = storage_path('app/private/customers-template-'.random_token(4).'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new XlsxWriter)->addSheet(__('Customers'), [
            CustomerImport::templateHeader(),
            ['أحمد محمود', '01012345678', '', '', '', '1990-05-31', 1, '', '01222222222', 'بيحب القهوة من غير سكر', '12 شارع التحرير، الدور 3', 'الدقي'],
            ['Sara Ali', '01198765432', '', '', '', '', 0, '', '', '', '', ''],
        ])->save($path);

        return response()->download($path, 'egmenu-customers-template.xlsx')->deleteFileAfterSend();
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.CustomerImport::MAX_KILOBYTES]], [
            'file.required' => __('Choose the file first.'),
            'file.max' => __('The file is too large (max :n MB).', ['n' => CustomerImport::MAX_KILOBYTES / 1024]),
            'file.uploaded' => __('The file is too large (max :n MB).', ['n' => CustomerImport::MAX_KILOBYTES / 1024]),
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            return back()->withErrors(['file' => __('Unsupported file. Upload an .xlsx or .csv file.')]);
        }
        try {
            $analysis = app(CustomerImport::class)->analyse(SpreadsheetReader::read($file->getRealPath(), $file->getClientOriginalName(), CustomerImport::MAX_ROWS + 2));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        if ($analysis['guests'] === []) {
            return back()->withErrors(['file' => __('No row in the file has a valid mobile number.')]);
        }

        $this->forget($request);
        $token = random_token(16);
        $path = $file->storeAs($this->folder(), "customers-$token.$ext", 'local');
        $request->session()->put(self::SESSION, ['token' => $token, 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 120)]);

        return redirect()->route('dash.customers.import.review', $token);
    }

    public function review(Request $request, string $token): View|RedirectResponse
    {
        if (! $pending = $this->pending($request, $token)) {
            return redirect()->route('dash.customers.import')->withErrors(['file' => __('This upload has expired. Upload the file again.')]);
        }
        try {
            $analysis = app(CustomerImport::class)->analyse($this->rows($pending));
        } catch (\RuntimeException $e) {
            return redirect()->route('dash.customers.import')->withErrors(['file' => $e->getMessage()]);
        }

        return view('dashboard.customers.import-review', ['token' => $token, 'file' => $pending['name'], 'analysis' => $analysis]);
    }

    public function apply(Request $request, string $token, CustomerImport $import): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(CustomerImport::MODES)]]);
        if (! $pending = $this->pending($request, $token)) {
            return redirect()->route('dash.customers.import')->withErrors(['file' => __('This upload has expired. Upload the file again.')]);
        }
        try {
            $analysis = $import->analyse($this->rows($pending));
        } catch (\RuntimeException $e) {
            return redirect()->route('dash.customers.import')->withErrors(['file' => $e->getMessage()]);
        }
        $result = $import->save($analysis['guests'], $data['mode']) + ['skipped' => $analysis['counts']['skipped']];
        Audit::record('customers.imported', $pending['name'], $result + ['mode' => $data['mode']]);
        $this->forget($request);

        return redirect()->route('dash.customers')
            ->with('ok', __(':created new customers added, :updated updated, :skipped rows skipped.', $result))
            ->with('import_summary', $result + ['file' => $pending['name'], 'mode' => $data['mode']]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->forget($request);

        return redirect()->route('dash.customers');
    }

    /** The restaurant's own private folder: a session from another restaurant can never point at it. */
    private function folder(): string
    {
        return 'tenants/'.tenant()->getTenantKey().'/imports';
    }

    /** @return array{token:string, path:string, name:string}|null */
    private function pending(Request $request, string $token): ?array
    {
        $pending = $request->session()->get(self::SESSION);
        if (! is_array($pending) || ! hash_equals((string) ($pending['token'] ?? ''), $token)
            || ! str_starts_with((string) $pending['path'], $this->folder().'/') || ! Storage::disk('local')->exists($pending['path'])) {
            return null;
        }

        return $pending;
    }

    /** @return array<int,array<int,string>> */
    private function rows(array $pending): array
    {
        return SpreadsheetReader::read(Storage::disk('local')->path($pending['path']), $pending['path'], CustomerImport::MAX_ROWS + 2);
    }

    private function forget(Request $request): void
    {
        $pending = $request->session()->pull(self::SESSION);
        if (is_array($pending) && str_starts_with((string) ($pending['path'] ?? ''), $this->folder().'/')) {
            Storage::disk('local')->delete($pending['path']);
        }
    }
}
