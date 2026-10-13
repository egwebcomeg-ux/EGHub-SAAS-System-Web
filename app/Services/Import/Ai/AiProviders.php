<?php

namespace App\Services\Import\Ai;

use App\Services\Settings;
use Illuminate\Support\Facades\Crypt;

/**
 * "Bring your own key": the restaurant picks a provider and pastes its own API key in Settings, so the AI cost is
 * theirs, not EgWeb's. The key is stored encrypted (APP_KEY) and never shown again. Default models are the cheap
 * vision-capable tier of each provider; the model name stays editable because providers rename models often.
 */
final class AiProviders
{
    /** key => [label, default model, where to get a key] */
    public const PROVIDERS = [
        'anthropic' => ['Claude (Anthropic)', 'claude-haiku-5-5', 'https://console.anthropic.com/settings/keys'],
        'gemini'    => ['Google Gemini', 'gemini-3.1-flash-lite', 'https://aistudio.google.com/apikey'],
        'openai'    => ['OpenAI', 'gpt-5-nano', 'https://platform.openai.com/api-keys'],
    ];

    public static function configured(): bool
    {
        return isset(self::PROVIDERS[Settings::get('ai_provider')]) && Settings::get('ai_key') !== '';
    }

    public static function make(): MenuExtractor
    {
        $provider = Settings::get('ai_provider');
        $key = self::key() ?? throw new \RuntimeException(__('Add your AI API key in Settings first.'));
        $model = Settings::get('ai_model') ?: (self::PROVIDERS[$provider][1] ?? '');

        return match ($provider) {
            'anthropic' => new AnthropicExtractor($key, $model),
            'gemini' => new GeminiExtractor($key, $model),
            'openai' => new OpenAiExtractor($key, $model),
            default => throw new \RuntimeException(__('Choose an AI provider in Settings first.')),
        };
    }

    public static function encrypt(string $key): string
    {
        return Crypt::encryptString(trim($key));
    }

    public static function key(): ?string
    {
        $stored = Settings::get('ai_key');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null; // APP_KEY changed: ask the owner to paste the key again
        }
    }

    /** "sk-…a1b2" — enough to recognise the key, useless to anyone else. */
    public static function masked(): string
    {
        $key = self::key();
        return $key ? mb_substr($key, 0, 4) . '…' . mb_substr($key, -4) : '';
    }
}
