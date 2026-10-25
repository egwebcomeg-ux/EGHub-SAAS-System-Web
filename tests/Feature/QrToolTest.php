<?php

namespace Tests\Feature;

use App\Http\Controllers\Platform\QrToolController;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/** The free QR code generator: the public page and its matrix endpoint (BaconQrCode, nothing stored). */
class QrToolTest extends TenantTestCase
{
    public function test_the_page_renders_in_arabic_and_english_with_its_schema(): void
    {
        $this->get($this->menuUrl('qr-code-generator'))->assertOk()
            ->assertSee('مولّد QR كود مجاني — بشكل مميز ولوجو | EgHub', false)
            ->assertSee('data-qr-tool', false)
            ->assertSee(route('tools.qr.matrix'), false)
            ->assertSee('"@type":"WebApplication"', false)
            ->assertSee('"isAccessibleForFree":true', false)
            ->assertSee('مولّد QR مجاني بأشكال وألوان ولوجو')
            ->assertSee('QR بيفتح منيو حيّ، تعدّل فيه من غير ما تطبع تاني')
            ->assertSee(route('register'), false);

        $this->get($this->menuUrl('qr-code-generator?lang=en'))->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Free QR generator with shapes, colours and your logo');
    }

    public function test_the_page_offers_a_logo_that_stays_in_the_browser(): void
    {
        $this->get($this->menuUrl('qr-code-generator'))->assertOk()
            ->assertSee('حط اللوجو بتاعك في النص')
            ->assertSee('data-qr-logo-file', false)
            ->assertSee('accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg"', false)
            ->assertSee('اللوجو بيفضل على جهازك، مش بيترفع عندنا')
            ->assertSee('name="logo_size" value="small"', false)
            ->assertSee('name="logo_size" value="large"', false)
            ->assertSee('name="logo_white"', false)
            ->assertSee('data-qr-logo-remove', false)
            ->assertSee('جرّب الكود بموبايلك قبل ما تطبعه');
    }

    public function test_the_tool_is_in_the_main_header_with_a_free_badge(): void
    {
        $html = $this->get($this->menuUrl(''))->assertOk()->getContent();

        // the desktop navigation and the mobile drawer both link it
        $this->assertSame(2, substr_count($html, 'data-nav-qr'));
        $this->assertStringContainsString('href="'.route('tools.qr').'" data-nav-qr', $html);
        $this->assertStringContainsString('مولّد QR<span', $html);
    }

    public function test_the_tool_is_linked_from_the_footer_and_listed_in_the_sitemap(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()->assertSee(route('tools.qr'), false);
        $this->assertStringContainsString(route('tools.qr'), $this->get($this->menuUrl('sitemap.xml'))->assertOk()->getContent());
    }

    public function test_the_matrix_endpoint_validates_its_input(): void
    {
        $url = $this->menuUrl('qr-code-generator/matrix');

        $this->postJson($url, ['data' => '', 'ecc' => 'M'])->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->postJson($url, ['data' => str_repeat('a', QrToolController::MAX_LENGTH + 1), 'ecc' => 'M'])->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->postJson($url, ['data' => 'hello', 'ecc' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('ecc');
        $this->postJson($url, ['data' => 'hello', 'ecc' => 'h'])->assertUnprocessable()->assertJsonValidationErrors('ecc');
        $this->postJson($url, ['data' => 'hello', 'ecc' => ['H']])->assertUnprocessable()->assertJsonValidationErrors('ecc');
        // within the length limit but more bytes than any QR code holds at H (Arabic letters are 2 bytes each)
        $this->postJson($url, ['data' => str_repeat('م', QrToolController::MAX_LENGTH), 'ecc' => 'H'])->assertUnprocessable()->assertJsonValidationErrors('data');
    }

    public function test_the_matrix_matches_bacon_for_a_known_payload(): void
    {
        // "HELLO WORLD" at Q is the textbook version 1 code: 21 × 21 modules
        $json = $this->postJson($this->menuUrl('qr-code-generator/matrix'), ['data' => 'HELLO WORLD', 'ecc' => 'Q'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['version' => 1, 'size' => 21, 'ecc' => 'Q'])->json();
        $this->assertCount(21, $json['matrix']);
        $this->assertSame([1, 1, 1, 1, 1, 1, 1, 0], array_slice($json['matrix'][0], 0, 8)); // the top-left finder and its separator

        $payload = 'https://egmenu.app/qr-code-generator?ref=test';
        $expected = Encoder::encode($payload, ErrorCorrectionLevel::H(), Encoder::DEFAULT_BYTE_MODE_ENCODING);
        $json = $this->postJson($this->menuUrl('qr-code-generator/matrix'), ['data' => $payload, 'ecc' => 'H'])->assertOk()->json();
        $this->assertSame($expected->getVersion()->getVersionNumber(), $json['version']);
        $this->assertSame($json['version'] * 4 + 17, $json['size']);
        foreach ($json['matrix'] as $y => $row) {
            $this->assertCount($json['size'], $row);
            foreach ($row as $x => $bit) {
                $this->assertSame($expected->getMatrix()->get($x, $y), $bit, "module $x,$y");
            }
        }
    }

    public function test_without_a_level_the_matrix_uses_m_and_a_logo_code_can_ask_for_h(): void
    {
        $url = $this->menuUrl('qr-code-generator/matrix');
        $payload = 'https://eghub.app/qr-code-generator';

        $default = $this->postJson($url, ['data' => $payload])->assertOk()->assertJsonPath('ecc', 'M')->json();
        $this->assertSame($this->postJson($url, ['data' => $payload, 'ecc' => 'M'])->json('matrix'), $default['matrix']);

        // H keeps 30% for recovery (room for the logo), so the same link needs a larger code
        $high = $this->postJson($url, ['data' => $payload, 'ecc' => 'H'])->assertOk()->assertJsonPath('ecc', 'H')->json();
        $this->assertGreaterThan($default['version'], $high['version']);
        $this->assertSame(Encoder::encode($payload, ErrorCorrectionLevel::H(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getVersion()->getVersionNumber(), $high['version']);
    }

    public function test_arabic_text_is_encoded_as_utf8(): void
    {
        $this->postJson($this->menuUrl('qr-code-generator/matrix'), ['data' => 'امسح المنيو', 'ecc' => 'M'])->assertOk()->assertJsonPath('version', 2);
    }

    public function test_the_matrix_endpoint_is_throttled_per_ip(): void
    {
        $url = $this->menuUrl('qr-code-generator/matrix');
        for ($i = 0; $i < 60; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson($url, ['data' => "code $i", 'ecc' => 'L'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson($url, ['data' => 'one more', 'ecc' => 'L'])->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2'])->postJson($url, ['data' => 'another visitor', 'ecc' => 'L'])->assertOk();
    }

    public function test_the_matrix_endpoint_requires_the_csrf_token(): void
    {
        // the CSRF check is skipped while unit tests run; outside "testing" it applies
        $this->app->detectEnvironment(fn () => 'local');

        $this->postJson($this->menuUrl('qr-code-generator/matrix'), ['data' => 'hello', 'ecc' => 'M'])->assertStatus(419);
    }

    public function test_nothing_is_stored(): void
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->postJson($this->menuUrl('qr-code-generator/matrix'), ['data' => 'https://wa.me/201000000000', 'ecc' => 'H'])->assertOk();

        $this->assertSame([], $writes);
    }
}
