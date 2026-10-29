<?php

namespace Tests\Unit;

use App\Services\Loyalty\WalletPasses;
use Tests\TestCase;

/**
 * Apple Wallet .pkpass packaging/signing and the Google Wallet "Save" JWT, checked with a throwaway self-signed
 * certificate made here (no Apple or Google account, no network).
 */
class WalletPassesTest extends TestCase
{
    private string $dir;

    /** @var array{restaurant:string, brand:string, title:string, name:string, url:string, color:string, type:?string, stamps:?int, required:?int, points:?int, rewards:list<string>, balance:?float, currency:string, tier:?string, packages:int, vouchers:int, version:string} */
    private array $state = [
        'restaurant' => 'cafe-a', 'brand' => 'كافيه أ', 'title' => 'Coffee card', 'name' => 'Sara', 'url' => 'https://cafe-a.egmenu.app/card/0123456789abcdef01234567', 'scan_url' => 'https://cafe-a.egmenu.app/c/0123456789abcdef0123',
        'color' => '#B5451B', 'type' => 'stamps', 'stamps' => 3, 'required' => 10, 'points' => null, 'rewards' => [], 'balance' => 120.5,
        'currency' => 'ج.م', 'tier' => 'Gold', 'packages' => 0, 'vouchers' => 0, 'version' => 'abc',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wallet-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{0:\OpenSSLAsymmetricKey, 1:string, 2:string} key, key PEM, self-signed certificate PEM */
    private function selfSigned(string $cn): array
    {
        // a minimal OpenSSL config of our own: PHP builds without a default openssl.cnf cannot make a CSR
        file_put_contents($this->dir.'/openssl.cnf', "[req]\ndistinguished_name = dn\n[dn]\n");
        $options = ['config' => $this->dir.'/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => $cn], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $options);

        return [$key, $keyPem, $certPem];
    }

    public function test_nothing_is_offered_without_the_keys(): void
    {
        $passes = new WalletPasses(['apple' => [], 'google' => []]);

        $this->assertFalse($passes->isConfigured());
        $this->assertNull($passes->googleSaveUrl($this->state, 'https://cafe-a.egmenu.app/icon-512.png'));
        $this->assertNull($passes->applePass($this->state, ['icon.png' => 'x']));
        // a key file that is not there is not "configured" either
        $this->assertFalse((new WalletPasses(['google' => ['issuer_id' => '338', 'service_account' => $this->dir.'/missing.json']]))->googleConfigured());
    }

    public function test_google_save_link_is_an_rs256_jwt_with_the_card_class_and_object(): void
    {
        [$key, $keyPem] = $this->selfSigned('wallet@test');
        file_put_contents($this->dir.'/sa.json', json_encode(['client_email' => 'wallet@project.iam.gserviceaccount.com', 'private_key' => $keyPem]));
        $passes = new WalletPasses(['google' => ['issuer_id' => '3388000000012345678', 'service_account' => $this->dir.'/sa.json']]);

        $this->assertTrue($passes->googleConfigured());
        $url = $passes->googleSaveUrl($this->state, 'https://cafe-a.egmenu.app/icon-512.png');
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', (string) $url);

        [$header, $claims, $signature] = explode('.', substr((string) $url, strlen(WalletPasses::GOOGLE_SAVE_URL)));
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));
        $this->assertSame(1, openssl_verify("$header.$claims", $decode($signature), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));

        $jwt = json_decode($decode($claims), true);
        $this->assertSame('wallet@project.iam.gserviceaccount.com', $jwt['iss']);
        $this->assertSame('google', $jwt['aud']);
        $this->assertSame('savetowallet', $jwt['typ']);
        $this->assertSame(['https://cafe-a.egmenu.app'], $jwt['origins']);
        $class = $jwt['payload']['loyaltyClasses'][0];
        $object = $jwt['payload']['loyaltyObjects'][0];
        $this->assertSame('3388000000012345678.egmenu-cafe-a-card', $class['id']);
        $this->assertSame(['issuerName' => 'كافيه أ', 'programName' => 'Coffee card'], ['issuerName' => $class['issuerName'], 'programName' => $class['programName']]);
        $this->assertSame('https://cafe-a.egmenu.app/icon-512.png', $class['programLogo']['sourceUri']['uri']);
        $this->assertSame('UNDER_REVIEW', $class['reviewStatus']);
        $this->assertSame($class['id'], $object['classId']);
        $this->assertSame('3388000000012345678.cafe-a-0123456789abcdef01234567', $object['id']);
        $this->assertSame('ACTIVE', $object['state']);
        // the QR carries the card's scan code, never the card link (the guest's key to their account and balance)
        $this->assertSame(['type' => 'QR_CODE', 'value' => $this->state['scan_url']], $object['barcode']);
        $this->assertSame('3 / 10', $object['loyaltyPoints']['balance']['string']);
        $this->assertSame('Gold', $object['textModulesData'][0]['body']);
        $this->assertSame($this->state['url'], $object['linksModuleData']['uris'][0]['uri']);
    }

    public function test_apple_pkpass_is_a_zip_with_a_sha1_manifest_and_a_detached_pkcs7_signature(): void
    {
        [, $keyPem, $certPem] = $this->selfSigned('Pass Type ID: pass.app.egmenu.card');
        [, , $wwdrPem] = $this->selfSigned('Test WWDR');
        file_put_contents($this->dir.'/pass.pem', $certPem.$keyPem);
        file_put_contents($this->dir.'/wwdr.pem', $wwdrPem);
        $passes = new WalletPasses(['apple' => [
            'pass_type_id' => 'pass.app.egmenu.card', 'team_id' => 'ABCDE12345',
            'certificate' => $this->dir.'/pass.pem', 'wwdr_certificate' => $this->dir.'/wwdr.pem',
        ]]);
        $this->assertTrue($passes->appleConfigured());

        $bytes = $passes->applePass($this->state, ['icon.png' => 'icon-bytes', 'icon@2x.png' => 'icon2-bytes']);
        $this->assertNotNull($bytes);
        file_put_contents($this->dir.'/card.pkpass', $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->dir.'/card.pkpass'));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['icon.png', 'icon@2x.png', 'manifest.json', 'pass.json', 'signature'], $names);

        // manifest: the SHA-1 of every other file
        $manifest = (string) $zip->getFromName('manifest.json');
        $hashes = json_decode($manifest, true);
        $this->assertSame(['pass.json', 'icon.png', 'icon@2x.png'], array_keys($hashes));
        foreach ($hashes as $name => $sha1) {
            $this->assertSame(sha1((string) $zip->getFromName($name)), $sha1);
        }

        // signature: DER PKCS #7 over manifest.json, signed by the pass certificate, carrying the WWDR certificate
        $signature = (string) $zip->getFromName('signature');
        file_put_contents($this->dir.'/manifest.json', $manifest);
        file_put_contents($this->dir.'/signature', $signature);
        $this->assertSame("\x30", $signature[0]);   // DER, not S/MIME text
        $flags = OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY;
        $this->assertTrue(openssl_cms_verify($this->dir.'/manifest.json', $flags, $this->dir.'/signers.pem', [], null, null, null,
            $this->dir.'/signature', OPENSSL_ENCODING_DER), (string) openssl_error_string());
        $this->assertSame(openssl_x509_fingerprint($certPem), openssl_x509_fingerprint((string) file_get_contents($this->dir.'/signers.pem')));
        $wwdrDer = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $wwdrPem));
        $this->assertStringContainsString($wwdrDer, $signature);
        // a changed manifest no longer matches the signature
        file_put_contents($this->dir.'/tampered.json', $manifest.' ');
        $this->assertFalse(openssl_cms_verify($this->dir.'/tampered.json', $flags, null, [], null, null, null, $this->dir.'/signature', OPENSSL_ENCODING_DER));

        $pass = json_decode((string) $zip->getFromName('pass.json'), true);
        $zip->close();
        $this->assertSame(1, $pass['formatVersion']);
        $this->assertSame('pass.app.egmenu.card', $pass['passTypeIdentifier']);
        $this->assertSame('ABCDE12345', $pass['teamIdentifier']);
        $this->assertSame('cafe-a-0123456789abcdef01234567', $pass['serialNumber']);
        $this->assertSame('كافيه أ', $pass['organizationName']);
        $this->assertSame('rgb(181, 69, 27)', $pass['backgroundColor']);
        $this->assertSame([['format' => 'PKBarcodeFormatQR', 'message' => $this->state['scan_url'], 'messageEncoding' => 'iso-8859-1']], $pass['barcodes']);
        $this->assertSame('3 / 10', $pass['storeCard']['primaryFields'][0]['value']);
        $this->assertSame('Gold', $pass['storeCard']['secondaryFields'][0]['value']);
        $this->assertSame($this->state['url'], $pass['storeCard']['backFields'][0]['value']);
    }
}
