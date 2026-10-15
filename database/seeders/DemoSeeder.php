<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\LoyaltyProgram;
use App\Models\MenuItem;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Services\Settings;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Local development data: a super admin and a demo cafe with a small menu.
 *   admin.localhost:8000  admin@egmenu.test / password
 *   app.localhost:8000    owner@demo.test   / password
 *   localhost:8000/demo-cafe
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        PlatformAdmin::query()->firstOrCreate(['email' => 'admin@egmenu.test'], ['name' => 'EgWeb Admin', 'password' => 'password']);

        if (Tenant::query()->whereKey('demo-cafe')->exists()) {
            return;
        }
        $tenant = app(TenantProvisioner::class)->create([
            'slug' => 'demo-cafe', 'name' => 'كافيه ديمو', 'owner_name' => 'Demo Owner', 'email' => 'owner@demo.test',
            'password' => 'password', 'phone' => '01000000000',
        ]);

        tenancy()->initialize($tenant);
        try {
            $menu = [
                ['قهوة ساخنة', 'Hot Coffee', [['إسبريسو', 'Espresso', 45], ['لاتيه', 'Latte', 65], ['كابتشينو', 'Cappuccino', 65], ['موكا', 'Mocha', 75]]],
                ['مشروبات باردة', 'Cold Drinks', [['آيس لاتيه', 'Iced Latte', 75], ['فرابيه كراميل', 'Caramel Frappe', 85], ['ليمون نعناع', 'Lemon Mint', 50]]],
                ['حلويات', 'Desserts', [['تشيز كيك', 'Cheesecake', 90], ['براونيز', 'Brownies', 70]]],
            ];
            foreach ($menu as $i => [$name, $en, $items]) {
                $cat = Category::query()->create(['name' => $name, 'name_en' => $en, 'sort_order' => $i]);
                foreach ($items as $j => [$item, $itemEn, $price]) {
                    $menuItem = MenuItem::query()->create([
                        'category_id' => $cat->id, 'name' => $item, 'name_en' => $itemEn, 'price' => $price, 'sort_order' => $j,
                    ]);
                    if ($i === 0) {
                        $size = $menuItem->optionGroups()->create(['name' => 'الحجم', 'name_en' => 'Size', 'type' => 'single', 'is_required' => true]);
                        $size->options()->create(['name' => 'صغير', 'name_en' => 'Small', 'price' => 0, 'is_default' => true]);
                        $size->options()->create(['name' => 'كبير', 'name_en' => 'Large', 'price' => 15, 'sort_order' => 1]);
                    }
                }
            }
            LoyaltyProgram::query()->create([
                'type' => 'visits', 'title' => 'كارت القهوة', 'title_en' => 'Coffee card', 'stamps_required' => 8,
                'reward_label' => 'قهوة مجاناً', 'reward_label_en' => 'A free coffee',
                'milestones' => [['at' => 4, 'type' => 'discount_percent', 'label' => 'خصم 20% على طلبك']],
                'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup'],
            ]);
            Settings::put(['google_review_url' => 'https://g.page/r/demo-cafe/review']);
        } finally {
            tenancy()->end();
            Settings::flush();
        }
    }
}
