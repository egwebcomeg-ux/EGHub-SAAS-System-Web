<?php

namespace App\Services\Import\Ai;

use App\Services\Import\MenuDraft;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Google Gemini (generateContent REST API) with JSON output; images and PDFs go inline. */
final class GeminiExtractor implements JsonCompleter, MenuExtractor
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(private readonly string $key, private readonly string $model) {}

    public function extract(array $files): array
    {
        $parts = array_map(fn ($f) => ['inline_data' => ['mime_type' => $f['mime'], 'data' => $f['data']]], $files);
        $parts[] = ['text' => MenuDraft::prompt()."\n\nJSON schema:\n".json_encode(MenuDraft::schema())];

        $body = $this->post(['contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => ['responseMimeType' => 'application/json']]);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! $text) {
            throw new AiException(__('The AI returned no menu. Try clearer photos.'));
        }

        return (array) json_decode($text, true);
    }

    public function complete(string $prompt, array $schema, int $maxTokens = 8000): array
    {
        $body = $this->post([
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt."\n\nJSON schema:\n".json_encode($schema)]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens],
        ]);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! $text) {
            throw new AiException(__('The AI returned an empty reply. Try again.'));
        }

        return (array) json_decode($text, true);
    }

    public function ping(): void
    {
        $this->post(['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply OK']]]], 'generationConfig' => ['maxOutputTokens' => 8]]);
    }

    private function post(array $payload): array
    {
        try {
            $res = Http::timeout(120)->withHeaders(['x-goog-api-key' => $this->key])
                ->post(sprintf(self::URL, rawurlencode($this->model)), $payload);
        } catch (ConnectionException) {
            throw new AiException(__(':provider could not be reached. Check the server internet connection.', ['provider' => 'Gemini']));
        }
        if (! $res->successful()) {
            throw AiException::fromStatus($res->status(), 'Gemini');
        }

        return (array) $res->json();
    }
}
