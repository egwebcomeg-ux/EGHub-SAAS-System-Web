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
        'item.deleted' => ['مسح صنف', 'Item deleted'],
        'settings.updated' => ['تعديل الإعدادات', 'Settings updated'],
        'branding.from_import' => ['شكل المنيو اتاخد من الملف المرفوع', 'Menu style taken from the uploaded file'],
        'modules.updated' => ['تغيير الموديولات المستخدمة', 'Modules in use changed'],
        'user.created' => ['إضافة مستخدم', 'User added'],
        'user.updated' => ['تعديل مستخدم', 'User updated'],
        'user.permissions' => ['تغيير صلاحيات مستخدم زيادة عن دوره', 'Extra user permissions changed'],
        'order.cancelled' => ['إلغاء طلب', 'Order cancelled'],
        'order.confirm.confirm' => ['العميل أكّد طلب الدفع عند الاستلام', 'Guest confirmed a cash-on-delivery order'],
        'order.confirm.edit' => ['العميل طلب تعديل الطلب', 'Guest asked to change the order'],
        'order.confirm.cancel' => ['العميل طلب إلغاء الطلب', 'Guest asked to cancel the order'],
        'order.confirm.phone' => ['تأكيد طلب بالتليفون', 'Order confirmed by phone'],
        'order.driver' => ['تحديد طيار لطلب', 'Driver assigned'],
        'delivery.failed' => ['توصيل ما تمّش', 'Delivery failed'],
        'driver.settled' => ['تسوية فلوس طيار', 'Driver cash settled'],
        'table.closed' => ['قفل ترابيزة', 'Table closed'],
        'stock.purchase' => ['استلام مخزون', 'Stock received'],
        'stock.waste' => ['هالك مخزون', 'Stock waste'],
        'stock.count' => ['جرد مخزون', 'Stock count'],
        'stock.count_posted' => ['ترحيل جرد (تسوية المخزون)', 'Stock count posted'],
        'stock.purchase_posted' => ['تسجيل فاتورة شراء في المخزن', 'Purchase posted'],
        'stock.purchase_cancelled' => ['إلغاء فاتورة شراء متسجّلة', 'Posted purchase cancelled'],
        'customers.imported' => ['استيراد عملاء', 'Customers imported'],
        'customer.account_link_rotated' => ['رابط جديد لحساب عميل (القديم اتقفل)', 'New guest account link (old one closed)'],
        'account.sign_in' => ['دخول ضيوف على «حسابي» (عدد في الساعة)', 'Guest «My account» sign-ins (hourly counts)'],
        'support.access_by_platform' => ['دخول من فريق EgHub', 'EgHub team signed in'],
        'support.exit_by_platform' => ['خروج فريق EgHub من اللوحة', 'EgHub team signed out'],
        'login.google' => ['دخول بحساب جوجل', 'Signed in with Google'],
        'login.facebook' => ['دخول بحساب فيسبوك', 'Signed in with Facebook'],
        'corporate.created' => ['إنشاء تعاقد شركة', 'Corporate contract created'],
        'corporate.updated' => ['تعديل تعاقد شركة', 'Corporate contract edited'],
        'corporate.status' => ['تغيير حالة تعاقد شركة', 'Corporate contract status changed'],
        'corporate.extended' => ['مد صلاحية فاوتشرات', 'Voucher expiry extended'],
        'corporate.voided' => ['إلغاء فاوتشر شركة', 'Corporate voucher voided'],
        'corporate.printed' => ['طباعة فاوتشرات شركة', 'Corporate vouchers printed'],
        'corporate.exception' => ['موافقة على فاوتشر منتهي', 'Expired voucher exception approved'],
        'corporate.pin_failed' => ['PIN مدير غلط', 'Wrong manager PIN'],
        'corporate.pin_set' => ['تعيين PIN المدير', 'Manager PIN set'],
        'corporate.pin_removed' => ['شيل PIN المدير', 'Manager PIN removed'],
        'corporate.linked' => ['ربط فاوتشر بموبايل عميل', 'Voucher linked to a guest mobile'],
        'corporate.refunded' => ['رجوع قيمة فاوتشر (طلب اتلغى)', 'Voucher value returned (order cancelled)'],
        'corporate.manual' => ['صرف فاوتشرات على فاتورة ورقية', 'Vouchers taken on a paper bill'],
        'corporate.manual_cancelled' => ['إلغاء صرف فاوتشر على فاتورة ورقية', 'Paper-bill voucher use cancelled'],
        'kb.created' => ['إضافة سؤال وإجابة', 'Knowledge base answer added'],
        'kb.updated' => ['تعديل سؤال وإجابة', 'Knowledge base answer edited'],
        'kb.deleted' => ['مسح سؤال وإجابة', 'Knowledge base answer deleted'],
        'kb.suggested' => ['إضافة الأسئلة المقترحة (مسودات)', 'Suggested questions added (drafts)'],
        'kb.category_created' => ['إضافة قسم في الأسئلة والأجوبة', 'Knowledge base category added'],
        'kb.category_updated' => ['تعديل قسم في الأسئلة والأجوبة', 'Knowledge base category edited'],
        'kb.category_deleted' => ['مسح قسم من الأسئلة والأجوبة', 'Knowledge base category deleted'],
        'backup.created' => ['إنشاء نسخة احتياطية', 'Backup created'],
        'backup.downloaded' => ['تحميل نسخة احتياطية', 'Backup downloaded'],
        'backup.deleted' => ['مسح نسخة احتياطية', 'Backup deleted'],
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
