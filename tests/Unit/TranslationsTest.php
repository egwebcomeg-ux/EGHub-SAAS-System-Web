<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Arabic is the default language: every __('...') string in the code must have an Arabic translation. */
class TranslationsTest extends TestCase
{
    public function test_every_ui_string_has_an_arabic_translation(): void
    {
        $root = dirname(__DIR__, 2);
        $ar = json_decode((string) file_get_contents("$root/lang/ar.json"), true, flags: JSON_THROW_ON_ERROR);

        $missing = [];
        foreach (['app', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                preg_match_all("/__\('((?:[^'\\]|\\.)*)'/", (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $key) {
                    $key = stripslashes($key);
                    if (!array_key_exists($key, $ar)) {
                        $missing[$key] = str_replace($root, '', $file->getPathname());
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Missing in lang/ar.json');
    }
}
