<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CorporateVoucher;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Orders\OrderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The POS side of corporate vouchers (permission vouchers.redeem): look a voucher up by its code / QR or the
 * guest's mobile, ask for an exception on an expired one (a manager approves, on this device or with their PIN) and
 * link a voucher to the guest's mobile. The payment itself goes through PosController (OrderService::pay()).
 */
class CorporateRedeemController extends Controller
{
    public function check(Request $request, CorporateVouchers $service): JsonResponse
    {
        $data = $request->validate(['query' => 'required|string|max:300', 'total' => 'nullable|numeric|min:0']);
        try {
            return response()->json($service->lookup($data['query'], PosController::branch($request), (float) ($data['total'] ?? 0), $request->user()));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function exception(Request $request, CorporateVoucher $voucher, CorporateVouchers $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:200',
            'approver_id' => 'nullable|integer',
            'pin' => 'nullable|string|max:12',
        ]);
        try {
            return response()->json($service->approveException($voucher->load('contract'), $request->user(), $data['reason'], $data['approver_id'] ?? null, $data['pin'] ?? null));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The cashier links a voucher to the guest's mobile: with the code of the paper in hand, or by confirming the link
     * the guest asked for from that same mobile (the voucher's pending phone).
     */
    public function link(Request $request, CorporateVouchers $service): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20', 'code' => 'nullable|string|max:300', 'id' => 'nullable|integer', 'total' => 'nullable|numeric|min:0',
        ]);
        try {
            if (! empty($data['code'])) {
                $voucher = $service->findByCode($data['code']);
            } else {
                $phone = normalize_phone($data['phone']);
                $voucher = $phone ? CorporateVoucher::query()->where('pending_phone', $phone)->find((int) ($data['id'] ?? 0)) : null;
                $voucher ?? throw new OrderException(__('No voucher with this code.'));
            }
            $customer = $service->linkToPhone($voucher, $data['phone'], $request->user());
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true, 'phone' => $customer->phone,
            'voucher' => $service->present($voucher->load(['contract', 'customer']), PosController::branch($request), (float) ($data['total'] ?? 0), $customer, false),
        ]);
    }
}
