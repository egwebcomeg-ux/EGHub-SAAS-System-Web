<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Services\Crm\PlaceImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * «استيراد أماكن» (crm.import): restaurants, cafes, bakeries and sweets shops inside an area of the map (OpenStreetMap)
 * or from a CSV file, straight into the sales team's places with their «ترشيح السيستم».
 */
class ProspectImportController extends Controller
{
    public function show(): View
    {
        return view('admin.crm.import', [
            'presets' => PlaceImporter::PRESETS,
            'total' => Prospect::query()->count(),
            'bySource' => Prospect::query()->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source'),
        ]);
    }

    public function map(Request $request, PlaceImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'preset' => ['nullable', Rule::in(array_keys(PlaceImporter::PRESETS))],
            'south' => 'required_without:preset|nullable|numeric|between:-90,90',
            'west' => 'required_without:preset|nullable|numeric|between:-180,180',
            'north' => 'required_without:preset|nullable|numeric|between:-90,90',
            'east' => 'required_without:preset|nullable|numeric|between:-180,180',
            'categories' => 'required|array|min:1',
            'categories.*' => [Rule::in(array_keys(Prospect::CATEGORIES))],
            'city' => 'nullable|string|max:60',
            'governorate' => 'nullable|string|max:60',
        ]);
        if (! empty($data['preset'])) {
            [$city, $governorate, $s, $w, $n, $e] = PlaceImporter::PRESETS[$data['preset']];
        } else {
            [$s, $w, $n, $e] = [(float) $data['south'], (float) $data['west'], (float) $data['north'], (float) $data['east']];
            $city = trim((string) ($data['city'] ?? ''));
            $governorate = trim((string) ($data['governorate'] ?? '')) ?: 'الغربية';
        }
        try {
            $result = $importer->fromMap([$s, $w, $n, $e], $data['categories'], $city, $governorate, $request->user('admin'));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['map' => $e->getMessage()]);
        }

        return redirect()->route('admin.crm.prospects', ['sort' => 'score', 'source' => 'map'])
            ->with('ok', __('Map import: :found found, :added added, :skipped already in the system.', $result));
    }

    public function csv(Request $request, PlaceImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:4096',
            'city' => 'nullable|string|max:60',
            'governorate' => 'nullable|string|max:60',
        ]);
        try {
            $result = $importer->fromCsv($request->file('file')->getRealPath(), trim((string) ($data['city'] ?? '')),
                trim((string) ($data['governorate'] ?? '')) ?: 'الغربية', $request->user('admin'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('admin.crm.prospects', ['sort' => 'score'])
            ->with('ok', __('File import: :found rows, :added added, :skipped already in the system.', $result));
    }
}
