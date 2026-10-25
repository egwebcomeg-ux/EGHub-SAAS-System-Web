<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Audit;
use App\Services\Printing\PrintJobs;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Printing from the EgHub cashier app (resources/js/native-print.js): the print jobs of an order (receipt, kitchen
 * tickets per station), claimed once each for the automatic printing; the queue of orders still waiting for their
 * kitchen tickets; and the owner's printing settings (POS terminals page). Outside the app nothing here is used:
 * the browser keeps printing the receipt page as before.
 */
class PrintController extends Controller
{
    /**
     * The jobs of $order for the roles asked. Automatic printing (reprint off) claims each copy: a copy printed
     * before (by any device) is skipped. A reprint (the cashier's button) is never claimed and always returned.
     */
    public function jobs(Request $request, Order $order): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        $data = $request->validate([
            'roles' => 'required|array|min:1|max:20',
            'roles.*' => ['required', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! PrintJobs::isRole($value)) {
                    $fail(__('Unknown printer role.'));
                }
            }],
            'reprint' => 'nullable|boolean',
        ]);
        $reprint = (bool) ($data['reprint'] ?? false);
        $jobs = [];
        $skipped = [];
        foreach (array_unique($data['roles']) as $role) {
            $job = $role === PrintJobs::RECEIPT ? PrintJobs::receipt($order) : PrintJobs::kitchen($order, $role);
            if ($job === null || (! $reprint && ! PrintJobs::claim($order, $role, $request->user()))) {
                $skipped[] = $role;

                continue;
            }
            $jobs[] = $job;
        }

        return response()->json(['jobs' => $jobs, 'skipped' => $skipped]);
    }

    /** The app could not print a claimed copy (no paper, printer off): it may be printed again. */
    public function release(Request $request, Order $order): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        $role = (string) $request->validate(['role' => 'required|string|max:60'])['role'];
        if (PrintJobs::isRole($role)) {
            PrintJobs::release($order, $role);
        }

        return response()->json(['ok' => true]);
    }

    /** Orders of the cashier's branch waiting for their kitchen tickets (polled by the app with the setting on). */
    public function queue(Request $request): JsonResponse
    {
        return response()->json(['orders' => PrintJobs::pendingKitchen(PosController::branch($request))]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_print_receipt_auto' => 'nullable|boolean',
            'pos_print_kitchen_auto' => 'nullable|boolean',
            'pos_print_open_drawer' => 'nullable|boolean',
            'pos_print_logo' => 'nullable|boolean',
            'pos_print_copies' => 'required|integer|min:1|max:'.PrintJobs::MAX_COPIES,
            'pos_print_kitchen_copies' => 'required|integer|min:1|max:'.PrintJobs::MAX_COPIES,
        ]);
        $kitchen = $request->boolean('pos_print_kitchen_auto');
        $values = [
            'pos_print_receipt_auto' => $request->boolean('pos_print_receipt_auto') ? '1' : '0',
            'pos_print_kitchen_auto' => $kitchen ? '1' : '0',
            'pos_print_open_drawer' => $request->boolean('pos_print_open_drawer') ? '1' : '0',
            'pos_print_logo' => $request->boolean('pos_print_logo') ? '1' : '0',
            'pos_print_copies' => (string) $data['pos_print_copies'],
            'pos_print_kitchen_copies' => (string) $data['pos_print_kitchen_copies'],
        ];
        // switched on now: only orders from now on print (the orders already in the kitchen never come out again)
        if ($kitchen && Settings::get('pos_print_kitchen_auto') !== '1') {
            $values['pos_print_kitchen_since'] = now()->toIso8601String();
        }
        Settings::put($values);
        Audit::record('pos.printing', '', $values);

        return back()->with('ok', __('Saved.'));
    }
}
