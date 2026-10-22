<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Import\MenuSheet;
use App\Services\Import\XlsxWriter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The whole menu as a sheet in the layout the Excel import reads (categories, items, prices, descriptions, sizes,
 * extras, allergens), so an export can be edited and imported back. Excel (.xlsx) or CSV (UTF-8 with BOM).
 */
class MenuExportController extends Controller
{
    public function download(string $format): BinaryFileResponse|StreamedResponse
    {
        $rows = MenuSheet::rows();
        $name = 'egmenu-'.Str::slug((string) tenant()->getTenantKey()).'-'.now()->format('Ymd');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                foreach ($rows as $row) {
                    fputcsv($out, csv_row($row), ',', '"', '\\');
                }
                fclose($out);
            }, "$name.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $path = storage_path('app/private/menu-export-'.random_token(4).'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new XlsxWriter)->addSheet('Menu', $rows)->save($path);

        return response()->download($path, "$name.xlsx")->deleteFileAfterSend();
    }
}
