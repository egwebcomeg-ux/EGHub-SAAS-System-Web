<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Arabic is the default language: every __('...') string in the code must have an Arabic translation. */
class TranslationsTest extends TestCase
{
    /**
     * Words that read as obscene in Egyptian Arabic must never reach a guest or an owner. "منيوك" ("your menu")
     * is one: write "المنيو بتاعك" instead.
     */
    public function test_no_arabic_string_reads_as_an_egyptian_obscenity(): void
    {
        $ar = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/ar.json'), true, flags: JSON_THROW_ON_ERROR);
        $banned = '/(?<![\x{0600}-\x{06FF}])(?:منيوك\w*|كسم|كسك|زبي|طيز\w*|متناك\w*|شرموط\w*|معرص\w*)(?![\x{0600}-\x{06FF}])/u';

        $hits = array_filter($ar, fn (string $text) => preg_match($banned, $text) === 1);

        $this->assertSame([], $hits, 'Rewrite these Arabic strings');
    }

    public function test_every_ui_string_has_an_arabic_translation(): void
    {
        $root = dirname(__DIR__, 2);
        $ar = json_decode((string) file_get_contents("$root/lang/ar.json"), true, flags: JSON_THROW_ON_ERROR);

        $missing = [];
        foreach (['app', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                // matches __('text') and __('it\'s') — the key is group 1
                $found = preg_match_all(<<<'RE'
                    /__\('((?:[^'\\]|\\.)*)'/
                    RE, (string) file_get_contents($file->getPathname()), $m);
                $this->assertNotFalse($found, 'Translation key regex failed');
                foreach ($m[1] as $key) {
                    $key = stripslashes($key);
                    if (! array_key_exists($key, $ar)) {
                        $missing[$key] = str_replace($root, '', $file->getPathname());
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Missing in lang/ar.json');
    }
}
