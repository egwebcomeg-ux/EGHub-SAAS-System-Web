<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The last 200 recorded actions of the restaurant, filterable by user. */
class AuditController extends Controller
{
    /** action => [arabic, english] */
    public const ACTIONS = [
        'item.created' => ['إضافة صنف', 'Item added'],
        'item.updated' => ['تعديل صنف', 'Item edited'],
        'item.price_changed' => ['تغيير سعر', 'Price changed'],
        'item.deleted' => ['حذف صنف', 'Item deleted'],
        'settings.updated' => ['تعديل الإعدادات', 'Settings updated'],
        'modules.updated' => ['تغيير الموديولات المستخدمة', 'Modules in use changed'],
        'user.created' => ['إضافة مستخدم', 'User added'],
        'user.updated' => ['تعديل مستخدم', 'User updated'],
        'order.cancelled' => ['إلغاء طلب', 'Order cancelled'],
        'table.closed' => ['قفل ترابيزة', 'Table closed'],
        'stock.purchase' => ['استلام مخزون', 'Stock received'],
        'stock.waste' => ['هالك مخزون', 'Stock waste'],
        'stock.count' => ['جرد مخزون', 'Stock count'],
        'customers.imported' => ['استيراد عملاء', 'Customers imported'],
        'login.google' => ['دخول بحساب جوجل', 'Signed in with Google'],
        'login.facebook' => ['دخول بحساب فيسبوك', 'Signed in with Facebook'],
    ];

    public function index(Request $request): View
    {
        $userId = $request->integer('user') ?: null;

        return view('dashboard.audit', [
            'logs' => AuditLog::query()->with('user:id,name')->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->latest('id')->limit(200)->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'userId' => $userId,
        ]);
    }

    public static function label(string $action): string
    {
        $l = self::ACTIONS[$action] ?? [$action, $action];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
