<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every Blade view must compile to valid PHP. Catches directive mistakes (e.g. an inline @php(...) whose nested
 * parentheses stop Blade from compiling the rest of the file) in pages no other test opens.
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_every_view_compiles_to_valid_php(): void
    {
        $broken = [];
        $tmp = tempnam(sys_get_temp_dir(), 'blade');
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            file_put_contents($tmp, Blade::compileString($file->getContents()));
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $broken[] = $file->getRelativePathname() . ': ' . trim(implode(' ', array_slice($out, 0, 1)));
            }
            $out = [];
        }
        @unlink($tmp);

        $this->assertSame([], $broken);
    }
}
