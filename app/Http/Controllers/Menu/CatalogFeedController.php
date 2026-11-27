<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Marketing\TrackingPixels;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The restaurant's menu as a Meta catalog data feed (Commerce Manager → data source → scheduled feed): one row per
 * dish with a photo, priced as the default branch sells it right now (a running offer is the sale price). The id is
 * the menu item id, the same the pixel sends with ViewContent / AddToCart / Purchase, so Meta can show a guest the
 * dish they looked at. Public like the menu itself, with the tracking pixels' plan rule.
 */
class CatalogFeedController extends Controller
{
    public const COLUMNS = ['id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'brand', 'product_type'];

    public function meta(): StreamedResponse
    {
        abort_unless(TrackingPixels::enabled(), 404);
        $catalog = MenuCatalog::forBranch(null)->allDay();
        $brand = Settings::get('brand_name') ?: Settings::get('brand_name_en') ?: (string) tenant('id');

        // the rows are read now: the stream runs after the request, when the restaurant is no longer the current one
        $rows = [];
        foreach ($catalog->categories() as $category) {
            foreach ($category->items as $item) {
                if ($item->image && (float) $item->price > 0) {
                    $rows[] = self::row($item, $category, $brand);
                }
            }
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'catalog.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    /** @return list<string> */
    private static function row(MenuItem $item, Category $category, string $brand): array
    {
        $title = mb_substr(trim((string) $item->name), 0, 150);
        $description = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item->description)) ?? '');
        $offer = $item->getAttribute('offer_from');
        $price = fn (float $amount) => number_format($amount, 2, '.', '').' EGP';

        return [
            (string) $item->id,
            $title,
            mb_substr($description !== '' ? $description : $title, 0, 5000),
            $item->is_sold_out ? 'out of stock' : 'in stock',
            'new',
            $price((float) ($offer ?? $item->price)),
            $offer !== null ? $price((float) $item->price) : '',
            tenant()->url('item/'.$item->id),
            tenant()->url('storage/'.ltrim((string) $item->image, '/')),
            mb_substr($brand, 0, 100),
            mb_substr(trim((string) $category->name), 0, 750),
        ];
    }
}
