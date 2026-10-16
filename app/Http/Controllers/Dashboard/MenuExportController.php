<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Import\ExcelMenuParser;
use App\Services\Import\MenuDraft;
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
        $rows = $this->rows();
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

    /** Header + one row per item, in ExcelMenuParser::templateHeader() order with the allergens column at the end. */
    private function rows(): array
    {
        $rows = [ExcelMenuParser::templateHeader()];
        $categories = Category::query()->orderBy('sort_order')->orderBy('id')
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with('optionGroups.options')])->get();
        foreach ($categories as $category) {
            foreach ($category->items as $item) {
                $rows[] = [
                    $category->name, $category->name_en, $item->name, $item->name_en, (float) $item->price,
                    (string) $item->description, (string) $item->description_en,
                    MenuDraft::choicesText($this->choices($item, 'single')), MenuDraft::choicesText($this->choices($item, 'multiple')),
                    implode(' | ', array_column($item->allergenList(), 'key')),
                ];
            }
        }

        return $rows;
    }

    /** Sizes = the options of the single-choice groups; extras = the multiple-choice ones. @return list<array{name:string, price:float}> */
    private function choices(MenuItem $item, string $type): array
    {
        $out = [];
        foreach ($item->optionGroups->where('type', $type) as $group) {
            foreach ($group->options as $option) {
                $out[] = ['name' => $option->name, 'price' => (float) $option->price];
            }
        }

        return $out;
    }
}
