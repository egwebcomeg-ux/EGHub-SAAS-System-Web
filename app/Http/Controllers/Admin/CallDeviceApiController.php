<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesCall;
use App\Services\Crm\CallDirectory;
use App\Services\Crm\CallLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the sales phone asks for with its own token (App\Http\Middleware\AuthenticateCallDevice): no session, no cookies. */
class CallDeviceApiController extends Controller
{
    public function ringing(Request $request, CallLog $log): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'nullable|string|max:30',
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
        ]);

        return response()->json(['ok' => true, 'card' => $log->ringing($request->attributes->get('callDevice'), $data['phone'] ?? null, $data['direction'])]);
    }

    public function calls(Request $request, CallLog $log): JsonResponse
    {
        $data = $request->validate([
            'calls' => 'required|array|max:200',
            'calls.*.id' => 'required|string|max:64',
            'calls.*.phone' => 'nullable|string|max:30',
            'calls.*.direction' => ['required', Rule::in(array_keys(SalesCall::DIRECTIONS))],
            'calls.*.started_at' => 'required|integer|min:1000000000000',
            'calls.*.duration' => 'required|integer|min:0|max:86400',
        ]);
        $call = $log->store($request->attributes->get('callDevice'), array_values($data['calls']));

        return response()->json(['ok' => true, 'summary' => $call ? [
            'name' => $call->contactName(), 'duration' => $call->durationLabel(), 'url' => route('admin.crm.calls.show', $call),
        ] : null]);
    }

    public function directory(CallDirectory $directory): JsonResponse
    {
        return response()->json(['ok' => true, 'numbers' => $directory->numbers()]);
    }
}
