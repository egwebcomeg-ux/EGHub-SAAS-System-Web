<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;

/**
 * The admin sidebar (layouts.admin), grouped by area of work: customers, sales, plans and prices, content and
 * marketing, the platform. An admin sees only the links their permissions open (App\Support\AdminPermissions), and a
 * group with nothing left in it is dropped.
 */
final class AdminMenu
{
    /**
     * Each item: [route, label, svg path, permission (null = every admin), active route patterns, badge key].
     *
     * @return list<array{0: ?string, 1: list<array{0: string, 1: string, 2: string, 3: ?string, 4: list<string>, 5: ?string}>}>
     */
    public static function groups(PlatformAdmin $admin): array
    {
        $groups = [
            [null, [
                ['admin.home', __('Overview'), 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z', null, ['admin.home'], null],
            ]],
            [__('Customers'), [
                ['admin.tenants', __('Restaurants'), 'M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6M9 11h.01M15 11h.01', 'tenants.view', ['admin.tenants*'], null],
                ['admin.invoices', __('Invoices & payments'), 'M6 2h9l5 5v15H6zM14 2v6h6M9 13h6M9 17h6', 'billing.manage', ['admin.invoices*'], 'invoices'],
            ]],
            [__('Sales'), [
                ['admin.crm.visits', __('My visits'), 'M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15', 'crm.visits', ['admin.crm.visits*'], 'visits'],
                ['admin.crm', __('Customer follow-up'), 'M3 18v-6a9 9 0 0 1 18 0v6M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z', 'crm.manage', ['admin.crm', 'admin.crm.show', 'admin.crm.offer', 'admin.crm.script*'], 'crm'],
                ['admin.crm.prospects', __('Places & field sales'), 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z', 'crm.view', ['admin.crm.prospects*', 'admin.crm.import*'], null],
                ['admin.crm.team', __('Sales team'), 'M3 3v18h18M7 15l4-4 3 3 5-6', 'crm.team', ['admin.crm.team*'], null],
                ['admin.crm.playbook', __('Sales playbook'), 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5zM4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5', 'crm.view', ['admin.crm.playbook'], null],
            ]],
            [__('Plans & prices'), [
                ['admin.plans', __('Plans & add-ons'), 'M21 8l-9-5-9 5 9 5 9-5zM3 13l9 5 9-5M3 18l9 5 9-5', 'plans.manage', ['admin.plans*', 'admin.addons*'], null],
                ['admin.prices', __('Prices'), 'M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01', 'plans.manage', ['admin.prices*'], null],
            ]],
            [__('Content & marketing'), [
                ['admin.services', __('Service requests'), 'M4 4h16v12H5.2L4 17.2zM8 9h8M8 12h5', 'services.manage', ['admin.services*'], 'services'],
                ['admin.blog', __('Blog'), 'M4 4h12l4 4v12H4zM8 9h6M8 13h8M8 17h5', 'content.manage', ['admin.blog*'], null],
                ['admin.testimonials', __('Testimonials'), 'M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z', 'content.manage', ['admin.testimonials*'], null],
                ['admin.assistant', __('Assistant'), 'M12 3a8 8 0 0 1 8 8c0 4.4-3.6 8-8 8H5l1.5-2.6A8 8 0 0 1 12 3zM9 11h.01M12 11h.01M15 11h.01', 'assistant.view', ['admin.assistant*'], null],
            ]],
            [__('Platform'), [
                ['admin.settings', __('Payment settings'), 'M3 6h18v12H3zM3 10h18M7 15h3', 'settings.manage', ['admin.settings*'], null],
                ['admin.branding', __('Branding & look'), 'M12 22a10 10 0 1 1 10-10c0 2.2-1.8 3-3.5 3H16a2 2 0 0 0-1.5 3.3A2 2 0 0 1 12 22zM7.5 11.5h.01M10.5 7.5h.01M15.5 8.5h.01', 'settings.manage', ['admin.branding*'], null],
                ['admin.admins', __('Admin team'), 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM21 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8', 'admins.manage', ['admin.admins*'], null],
                ['admin.activity', __('Activity log'), 'M12 8v4l3 2M3.05 11a9 9 0 1 1 .5 4M3 4v5h5', 'admins.manage', ['admin.activity*'], null],
                ['admin.system', __('System health'), 'M22 12h-4l-3 9L9 3l-3 9H2', 'settings.manage', ['admin.system*'], 'system'],
                ['admin.security', __('Security'), 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4', null, ['admin.security*'], null],
            ]],
        ];

        return array_values(array_filter(array_map(
            fn (array $group) => [$group[0], array_values(array_filter($group[1], fn (array $item) => $item[3] === null || $admin->allows($item[3])))],
            $groups), fn (array $group) => $group[1] !== []));
    }

    /**
     * What waits for the team, as small counters next to the links (only for the admins who see the link).
     *
     * @return array<string, int>
     */
    public static function badges(PlatformAdmin $admin): array
    {
        return [
            'invoices' => $admin->allows('billing.manage') ? Invoice::query()->where('status', 'open')->count() : 0,
            'services' => $admin->allows('services.manage') ? ServiceRequest::query()->where('status', 'new')->count() : 0,
            // follow-up calls due today or overdue
            'crm' => $admin->allows('crm.manage') ? Lead::query()->open()->where('crm_leads.next_follow_up_at', '<', Lead::todayStart()->addDay())->count() : 0,
            // the field rep's visits still booked for today
            'visits' => $admin->allows('crm.visits') ? Appointment::query()->where('field_id', $admin->id)->where('status', 'scheduled')
                ->where('scheduled_at', '<', local_day_start(local_at()->addDay()->toDateString()))->count() : 0,
            // failed background jobs (a sent email, a push, a backup) nobody looked at
            'system' => $admin->allows('settings.manage') ? self::failedJobs() : 0,
        ];
    }

    public static function failedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
