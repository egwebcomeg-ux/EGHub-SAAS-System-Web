<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Ingredient;
use App\Models\Tenant;

// Extra demo data for the tutorials (on top of DemoSeeder + DemoContent): delivery zones for the call center.
tenancy()->initialize(Tenant::find('demo-cafe'));
$branch = Branch::first();
$nasr = DeliveryZone::create(['branch_id' => $branch->id, 'name' => 'مدينة نصر', 'name_en' => 'Nasr City', 'fee' => 20, 'min_order' => 100, 'estimated_minutes' => 35, 'sort_order' => 1]);
foreach (['عباس العقاد', 'مكرم عبيد', 'الحي السابع'] as $i => $n) {
    DeliveryZone::create(['branch_id' => $branch->id, 'parent_id' => $nasr->id, 'name' => $n, 'sort_order' => $i]);
}
$heliopolis = DeliveryZone::create(['branch_id' => $branch->id, 'name' => 'مصر الجديدة', 'name_en' => 'Heliopolis', 'fee' => 25, 'min_order' => 120, 'estimated_minutes' => 40, 'sort_order' => 2]);
foreach (['الكوربة', 'روكسي'] as $i => $n) {
    DeliveryZone::create(['branch_id' => $branch->id, 'parent_id' => $heliopolis->id, 'name' => $n, 'sort_order' => $i]);
}
// DemoSeeder's empty starter categories sit next to DemoContent's filled ones (two «حلويات»): keep the filled ones
Category::whereDoesntHave('items')->delete();
// milk is already in the stock list: the inventory tutorial adds the coffee beans and the takeaway cup on camera
Ingredient::create(['name' => 'لبن', 'kind' => 'ingredient', 'unit' => 'ml', 'cost_per_unit' => 0.04, 'min_stock' => 2000]);
echo "extra ok\n";
