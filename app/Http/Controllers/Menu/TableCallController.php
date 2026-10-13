<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\TableCall;
use App\Services\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Guest at a scanned table: call the waiter or ask for the bill. One open call per table and kind. */
class TableCallController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(TableCall::KINDS))]]);
        $table = MenuController::currentTable($request);
        if (! $table || ! Entitlements::current()->allows('qr_ordering')) {
            return response()->json(['message' => __('Scan the QR code on your table first.')], 422);
        }
        TableCall::query()->firstOrCreate(
            ['dining_table_id' => $table->id, 'kind' => $data['kind'], 'handled_at' => null],
            ['branch_id' => $table->branch_id],
        );

        return response()->json(['ok' => true, 'message' => $data['kind'] === 'bill' ? __('The bill is on its way.') : __('A waiter is coming.')]);
    }
}
