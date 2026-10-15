<?php

namespace App\Services\Menu;

use App\Services\Import\Ai\AiException;
use App\Services\Import\Ai\AiProviders;
use App\Services\Import\Ai\JsonCompleter;
use App\Support\MenuLocales;

/**
 * Translates menu texts into an extra language with the restaurant's own AI key (AiProviders, "bring your own key").
 * One request per batch of rows; the reply is matched back to the rows by key.
 */
class MenuTranslator
{
    /**
     * @param  array<string, array{name:string, name_en:string, description:string, description_en:string}>  $rows  key => source texts
     * @return array<string, array{name:string, description:string}> key => translated texts (missing keys = not translated)
     *
     * @throws AiException
     */
    public function translate(array $rows, string $locale): array
    {
        $ai = AiProviders::make();
        if (! $ai instanceof JsonCompleter) {
            throw new AiException(__('Choose an AI provider in Settings first.'));
        }
        $language = MenuLocales::ALL[$locale][1] ?? $locale;
        $source = array_map(fn ($key, $r) => array_filter([
            'key' => (string) $key, 'name_ar' => $r['name'], 'name_en' => $r['name_en'],
            'description_ar' => $r['description'], 'description_en' => $r['description_en'],
        ], fn ($v) => $v !== ''), array_keys($rows), $rows);

        $reply = $ai->complete(
            "You translate a restaurant menu in Egypt into $language. For every entry, translate the dish/section name and its "
            .'description (when there is one) from the Arabic and/or English text given. Keep brand names and well-known dish '
            .'names recognisable (transliterate when there is no real translation), keep it short and appetising, no quotes or '
            ."notes. Leave description empty when the entry has none. Return every key.\n\nEntries (JSON):\n"
            .json_encode($source, JSON_UNESCAPED_UNICODE),
            self::schema(),
        );

        $out = [];
        foreach ((array) ($reply['entries'] ?? []) as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if (isset($rows[$key])) {
                $out[$key] = [
                    'name' => mb_substr(trim((string) ($entry['name'] ?? '')), 0, 100),
                    'description' => mb_substr(trim((string) ($entry['description'] ?? '')), 0, 500),
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['entries'],
            'properties' => [
                'entries' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'name', 'description'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
