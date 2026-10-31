<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «سجل العمليات» (admins.manage): what each member of the admin team did on the platform side (prices, plans,
 * restaurants, invoices, support access...), newest first, filtered by person, action, restaurant and days.
 */
class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $filters = self::filters($request);
        $logs = PlatformAuditLog::query()->with('admin')
            ->when($filters['admin'] !== '', fn ($q) => $q->where('admin_id', (int) $filters['admin']))
            ->when($filters['action'] !== '', fn ($q) => $q->where('action', $filters['action']))
            ->when($filters['q'] !== '', fn ($q) => $q->where('subject', 'like', '%'.$filters['q'].'%'))
            ->when($filters['from'] !== '', fn ($q) => $q->where('created_at', '>=', local_day_start($filters['from'])))
            ->when($filters['to'] !== '', fn ($q) => $q->where('created_at', '<=', local_day_end($filters['to'])))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.activity', [
            'logs' => $logs,
            'filters' => $filters,
            'admins' => PlatformAdmin::query()->orderBy('name')->get(['id', 'name']),
            'actions' => PlatformAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /** @return array{admin: string, action: string, q: string, from: string, to: string} */
    private static function filters(Request $request): array
    {
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value = (string) $request->query($key, '')) ? $value : '';

        return [
            'admin' => ctype_digit($admin = (string) $request->query('admin', '')) ? $admin : '',
            'action' => preg_match('/^[a-z0-9_.:-]{1,80}$/', $action = (string) $request->query('action', '')) ? $action : '',
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }
}
