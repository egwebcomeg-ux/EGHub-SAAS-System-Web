<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Prospect;
use App\Models\Tenant;
use App\Services\AdminTenantSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search of the admin panel: restaurants (tenants.view: name, address, owner email or mobile, domain),
 * invoices by number (billing.manage) and sales places (crm.view; a field rep only his own). Each group shows only to
 * the admins whose permission opens its pages, and only central platform data is read, never a restaurant's own rows.
 */
class SearchController extends Controller
{
    private const LIMIT = 6;

    public function __invoke(Request $request, AdminTenantSearch $tenants): JsonResponse
    {
        $admin = $request->user('admin');
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $groups = [];

        if ($admin->allows('tenants.view')) {
            $filters = ['q' => $q, 'status' => '', 'type' => '', 'plan' => '', 'paid' => '', 'created' => '', 'from' => '', 'to' => '', 'hidden' => '', 'sort' => 'newest'];
            $items = $tenants->query($filters, withType: false)->with('subscription.plan')->latest('tenants.created_at')->limit(self::LIMIT)->get()
                ->map(fn (Tenant $t) => [
                    'title' => $t->name,
                    'sub' => collect([$t->id, $t->subscription?->plan?->displayName(), $t->subscription?->statusLabel()])->filter()->implode(' · '),
                    'url' => route('admin.tenants.show', $t),
                ]);
            $groups[] = ['label' => __('Restaurants'), 'items' => $items];
        }

        if ($admin->allows('billing.manage')) {
            $items = Invoice::query()->with('tenant')->where('number', 'like', '%'.$q.'%')->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Invoice $i) => [
                    'title' => $i->number,
                    'sub' => collect([$i->tenant?->name ?? $i->tenant_id, money($i->total).' '.__('EGP'), match ($i->status) {
                        'paid' => __('Paid'), 'void' => __('Void'), default => __('Unpaid')
                    }])->implode(' · '),
                    'url' => $admin->allows('tenants.view') ? route('admin.tenants.show', $i->tenant_id) : route('admin.invoices', ['status' => 'all']),
                ]);
            $groups[] = ['label' => __('Invoices'), 'items' => $items];
        }

        if ($admin->allows('crm.view')) {
            $like = '%'.$q.'%';
            $phone = normalize_phone($q);
            $items = Prospect::query()->visibleTo($admin)
                ->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('owner_name', 'like', $like)->orWhere('area', 'like', $like)
                    ->when($phone, fn ($m) => $m->orWhere('phone', $phone)->orWhere('phone2', $phone)))
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Prospect $p) => [
                    'title' => $p->name,
                    'sub' => collect([$p->area, $p->city, $p->stageLabel()])->filter()->implode(' · '),
                    'url' => route('admin.crm.prospects.show', $p),
                ]);
            $groups[] = ['label' => __('Places'), 'items' => $items];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn (array $g) => $g['items']->isNotEmpty()))]);
    }
}
