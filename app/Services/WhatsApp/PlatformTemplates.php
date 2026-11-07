<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

/** What Meta says about the templates of the EgHub number, kept so a change can be told once. */
final class PlatformTemplates
{
    private const SETTING = 'whatsapp_template_status';

    /** @return array<string, string> template name => status, as last read */
    public static function known(): array
    {
        return (array) json_decode((string) PlatformSetting::get(self::SETTING), true);
    }

    public static function isApproved(string $name): bool
    {
        return (self::known()[$name] ?? null) === 'APPROVED';
    }

    /** Meta knows this template and has not approved it, so sending it can only fail. */
    public static function isNotApprovedYet(string $name): bool
    {
        return isset(self::known()[$name]) && ! self::isApproved($name);
    }

    /**
     * Reads Meta and keeps the answer; null when it could not be read.
     *
     * @return array<string, array{0: ?string, 1: string}>|null name => [old status, new status] of what changed
     */
    public function refresh(): ?array
    {
        $account = config('whatsapp.business_account_id');
        $token = config('whatsapp.meta.token');
        if (! $account || ! $token) {
            return null;
        }
        $response = Http::withToken($token)->acceptJson()->timeout(20)
            ->get('https://graph.facebook.com/'.config('whatsapp.meta.version', 'v21.0')."/$account/message_templates", ['fields' => 'name,status', 'limit' => 100]);
        if (! $response->successful()) {
            return null;
        }
        $old = self::known();
        $now = collect($response->json('data', []))->pluck('status', 'name')->all();
        PlatformSetting::put(self::SETTING, json_encode($now));
        $changed = [];
        foreach ($now as $name => $status) {
            if (($old[$name] ?? null) !== $status) {
                $changed[$name] = [$old[$name] ?? null, $status];
            }
        }

        return $changed;
    }

    /**
     * Submits to Meta the templates of ours it does not know yet (read by refresh() first); its answer shows on the
     * next refresh. Returns how many were submitted.
     */
    public function submitMissing(): int
    {
        $account = config('whatsapp.business_account_id');
        $token = config('whatsapp.meta.token');
        if (! $account || ! $token) {
            return 0;
        }
        $submitted = 0;
        foreach (self::definitions() as $definition) {
            if (isset(self::known()[$definition['name']])) {
                continue;
            }
            $response = Http::withToken($token)->acceptJson()->timeout(20)
                ->post('https://graph.facebook.com/'.config('whatsapp.meta.version', 'v21.0')."/$account/message_templates", $definition);
            if ($response->successful()) {
                PlatformSetting::put(self::SETTING, json_encode([...self::known(), $definition['name'] => (string) $response->json('status', 'PENDING')]));
                $submitted++;
            }
        }

        return $submitted;
    }

    /**
     * The templates the EgHub number submits itself.
     *
     * @return list<array{name: string, language: string, category: string, components: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return [[
            'name' => (string) config('whatsapp.templates.restaurant_welcome'), 'language' => (string) config('whatsapp.languages.ar', 'ar'), 'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => PlatformInbox::RESTAURANT_WELCOME_TEMPLATE, 'example' => ['body_text' => [['سارة', 'كافيه النخيل']]]],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'لوحة التحكم', 'url' => platform_url('app', '/')]]],
            ],
        ], [
            'name' => (string) config('whatsapp.templates.follow_up'), 'language' => (string) config('whatsapp.languages.ar', 'ar'), 'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => PlatformInbox::FOLLOW_UP_TEMPLATE, 'example' => ['body_text' => [['سارة', 'المنيو بتاعك جاهز على اللينك، ناقص بس صورة اللوجو عشان نحطها فوق.']]]],
            ],
        ]];
    }
}
