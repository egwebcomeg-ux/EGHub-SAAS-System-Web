<?php

namespace App\Services\Menu;

use App\Models\MenuTranslation;
use App\Support\MenuLocales;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and writes the extra-language texts of menu rows (menu_translations). The public menu reads every
 * translation of one language once per request (one query), kept on the request so it never goes stale.
 */
final class MenuTranslations
{
    /** Translated $field ("name" / "description") of $model in $locale, null when there is none. */
    public static function text(Model $model, string $field, string $locale): ?string
    {
        $type = MenuTranslation::typeOf($model);
        if ($type === null || ! in_array($field, ['name', 'description'], true)) {
            return null;
        }
        $row = self::map($locale)[$type.':'.$model->getKey()] ?? null;

        return $row ? (string) ($row[$field] ?? '') : null;
    }

    /**
     * Texts of one row per locale, for the dashboard form.
     *
     * @return array<string, array{name:string, description:string}>
     */
    public static function of(Model $model): array
    {
        if (! $model->exists || ! ($type = MenuTranslation::typeOf($model))) {
            return [];
        }

        return MenuTranslation::query()->where('translatable_type', $type)->where('translatable_id', $model->getKey())->get()
            ->mapWithKeys(fn (MenuTranslation $t) => [$t->locale => ['name' => $t->name, 'description' => (string) $t->description]])
            ->all();
    }

    /**
     * Saves the texts typed in the dashboard for the enabled languages; a language left empty is removed.
     *
     * @param  array<string, array{name?:string|null, description?:string|null}>  $input
     */
    public static function save(Model $model, array $input): void
    {
        $type = MenuTranslation::typeOf($model);
        if ($type === null) {
            return;
        }
        foreach (MenuLocales::enabled() as $locale) {
            $name = trim((string) ($input[$locale]['name'] ?? ''));
            $description = trim((string) ($input[$locale]['description'] ?? ''));
            $key = ['translatable_type' => $type, 'translatable_id' => $model->getKey(), 'locale' => $locale];
            if ($name === '' && $description === '') {
                MenuTranslation::query()->where($key)->delete();
            } else {
                MenuTranslation::query()->updateOrCreate($key, ['name' => $name, 'description' => $description !== '' ? $description : null]);
            }
        }
    }

    /** Removes every translation of the given rows (after they are deleted). */
    public static function forget(string $type, array $ids): void
    {
        if ($ids !== []) {
            MenuTranslation::query()->where('translatable_type', $type)->whereIn('translatable_id', $ids)->delete();
        }
    }

    /**
     * Option groups and options are re-created when an item is saved: carries their translations over to the new
     * rows with the same Arabic name (group name, then "group name / option name"), and drops the old ones.
     *
     * @param  array<int, string>  $oldGroups  old group id => name
     * @param  array<int, string>  $oldOptions  old option id => "group name\x1Foption name"
     * @param  array<string, int>  $newGroups  name => new group id
     * @param  array<string, int>  $newOptions  "group name\x1Foption name" => new option id
     */
    public static function carryOver(array $oldGroups, array $oldOptions, array $newGroups, array $newOptions): void
    {
        foreach ([['group', $oldGroups, $newGroups], ['option', $oldOptions, $newOptions]] as [$type, $old, $new]) {
            if ($old === []) {
                continue;
            }
            // read, remove, then write back: the database may give a new row the id of a deleted one
            $rows = MenuTranslation::query()->where('translatable_type', $type)->whereIn('translatable_id', array_keys($old))->get();
            MenuTranslation::query()->whereKey($rows->modelKeys())->delete();
            $written = [];
            foreach ($rows as $t) {
                $target = $new[$old[$t->translatable_id]] ?? null;
                if ($target !== null && ! isset($written[$target.':'.$t->locale])) {
                    $written[$target.':'.$t->locale] = true;
                    MenuTranslation::query()->updateOrCreate(
                        ['translatable_type' => $type, 'translatable_id' => $target, 'locale' => $t->locale],
                        ['name' => $t->name, 'description' => $t->description],
                    );
                }
            }
        }
    }

    /** @return array<string, array{name:string, description:?string}> "type:id" => texts */
    private static function map(string $locale): array
    {
        $key = 'menu_translations.'.tenant()?->getTenantKey().'.'.$locale;
        $attributes = request()->attributes;
        if (! $attributes->has($key)) {
            $attributes->set($key, tenant() ? MenuTranslation::query()->where('locale', $locale)
                ->get(['translatable_type', 'translatable_id', 'name', 'description'])
                ->mapWithKeys(fn (MenuTranslation $t) => [$t->translatable_type.':'.$t->translatable_id => ['name' => $t->name, 'description' => $t->description]])
                ->all() : []);
        }

        return $attributes->get($key);
    }
}
