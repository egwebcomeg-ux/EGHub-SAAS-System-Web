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
}
