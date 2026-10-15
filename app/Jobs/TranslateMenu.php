<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\MenuTranslation;
use App\Services\Entitlements;
use App\Services\Import\Ai\AiException;
use App\Services\Menu\MenuTranslator;
use App\Services\Settings;
use App\Support\MenuLocales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fills the missing extra-language texts of the whole menu (categories, items, option groups, options) with the
 * restaurant's own AI key, in the background. Only empty fields are written, so text typed by hand is never
 * replaced and running it again only does what is still missing. Progress is kept in the setting
 * menu_translation_status for the dashboard banner. Tenant context comes back through QueueTenancyBootstrapper.
 */
class TranslateMenu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rows sent to the AI in one request. */
    public const BATCH = 40;

    /** A "running" status older than this is considered dead (worker killed) and may be started again. */
    public const STALE_MINUTES = 15;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @return array{state:string, done:int, total:int, filled:int, error:?string, at:int}|null
     */
    public static function status(): ?array
    {
        $status = json_decode(Settings::get('menu_translation_status'), true);

        return is_array($status) && isset($status['state']) ? $status : null;
    }

    public static function isRunning(): bool
    {
        $status = self::status();

        return $status !== null && in_array($status['state'], ['queued', 'running'], true)
            && ($status['at'] ?? 0) > now()->subMinutes(self::STALE_MINUTES)->timestamp;
    }

    /** Queues a run unless one is already going; false when it was already running. */
    public static function start(): bool
    {
        if (self::isRunning()) {
            return false;
        }
        self::report('queued', 0, 0, 0);
        self::dispatch();

        return true;
    }

    public function handle(MenuTranslator $translator): void
    {
        Settings::flush();
        Entitlements::flush();
        $locales = MenuLocales::enabled();
        $sources = $this->sources();
        $existing = MenuTranslation::query()->whereIn('locale', $locales)->get()
            ->keyBy(fn (MenuTranslation $t) => $t->translatable_type.':'.$t->translatable_id.':'.$t->locale);

        // what is still missing, per language
        $todo = [];
        foreach ($locales as $locale) {
            foreach ($sources as $key => $source) {
                $row = $existing[$key.':'.$locale] ?? null;
                $needsName = trim((string) $row?->name) === '';
                $needsDescription = ($source['description'] !== '' || $source['description_en'] !== '') && trim((string) $row?->description) === '';
                if ($needsName || $needsDescription) {
                    $todo[$locale][$key] = $source;
                }
            }
        }
        $total = array_sum(array_map('count', $todo));
        $done = 0;
        $filled = 0;
        self::report('running', 0, $total, 0);

        try {
            foreach ($todo as $locale => $rows) {
                foreach (array_chunk($rows, self::BATCH, true) as $batch) {
                    foreach ($translator->translate($batch, $locale) as $key => $texts) {
                        $filled += $this->fill($key, $locale, $texts, $batch[$key]);
                    }
                    $done += count($batch);
                    self::report('running', $done, $total, $filled);
                }
            }
            self::report('done', $total, $total, $filled);
        } catch (AiException|\RuntimeException $e) {
            self::report('failed', $done, $total, $filled, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('menu translation failed', ['tenant' => tenant()?->getTenantKey(), 'error' => $e->getMessage()]);
            self::report('failed', $done, $total, $filled, __('Something went wrong while translating the menu. Try again.'));
        }
    }

    /**
     * Writes the AI texts into the empty fields of one row (never over text already there).
     *
     * @param  array{name:string, description:string}  $texts
     * @param  array{name:string, name_en:string, description:string, description_en:string}  $source
     * @return int 1 when something was written
     */
    private function fill(string $key, string $locale, array $texts, array $source): int
    {
        [$type, $id] = explode(':', $key);
        $row = MenuTranslation::query()->firstOrNew(['translatable_type' => $type, 'translatable_id' => (int) $id, 'locale' => $locale]);
        $changed = false;
        if (trim((string) $row->name) === '' && $texts['name'] !== '') {
            $row->name = $texts['name'];
            $changed = true;
        }
        $hasDescription = $source['description'] !== '' || $source['description_en'] !== '';
        if ($hasDescription && trim((string) $row->description) === '' && $texts['description'] !== '') {
            $row->description = $texts['description'];
            $changed = true;
        }
        if ($changed) {
            $row->name ??= '';
            $row->save();
        }

        return $changed ? 1 : 0;
    }

    /**
     * Every translatable row of the menu with its Arabic/English texts.
     *
     * @return array<string, array{name:string, name_en:string, description:string, description_en:string}> "type:id" => texts
     */
    private function sources(): array
    {
        $out = [];
        $add = function (string $type, $rows, bool $described) use (&$out) {
            foreach ($rows as $r) {
                $out[$type.':'.$r->id] = [
                    'name' => trim((string) $r->name), 'name_en' => trim((string) $r->name_en),
                    'description' => $described ? trim((string) $r->description) : '',
                    'description_en' => $described ? trim((string) $r->description_en) : '',
                ];
            }
        };
        $add('category', Category::query()->orderBy('sort_order')->orderBy('id')->get(), true);
        $add('item', MenuItem::query()->orderBy('category_id')->orderBy('sort_order')->orderBy('id')->get(), true);
        $add('group', ItemOptionGroup::query()->orderBy('id')->get(), false);
        $add('option', ItemOption::query()->orderBy('id')->get(), false);

        return array_filter($out, fn ($r) => $r['name'] !== '' || $r['name_en'] !== '');
    }

    private static function report(string $state, int $done, int $total, int $filled, ?string $error = null): void
    {
        Settings::put(['menu_translation_status' => json_encode([
            'state' => $state, 'done' => $done, 'total' => $total, 'filled' => $filled, 'error' => $error, 'at' => now()->timestamp,
        ], JSON_UNESCAPED_UNICODE)]);
    }
}
