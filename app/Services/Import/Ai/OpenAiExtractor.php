<?php

namespace App\Services\Import\Ai;

use App\Services\Import\MenuDraft;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI Chat Completions with a JSON-schema response format; images as data URLs, a PDF as a file part. */
final class OpenAiExtractor implements JsonCompleter, MenuExtractor
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private readonly string $key, private readonly string $model) {}

    public function extract(array $files): array
    {
        $content = [['type' => 'text', 'text' => MenuDraft::prompt()]];
        foreach ($files as $i => $f) {
            $url = 'data:'.$f['mime'].';base64,'.$f['data'];
            $content[] = $f['mime'] === 'application/pdf'
                ? ['type' => 'file', 'file' => ['filename' => "menu-$i.pdf", 'file_data' => $url]]
                : ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }
        $body = $this->post([
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'menu', 'strict' => true, 'schema' => MenuDraft::schema()]],
        ]);
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (! $text) {
            throw new AiException(__('The AI returned no menu. Try clearer photos.'));
        }

        return (array) json_decode($text, true);
    }

    public function complete(string $prompt, array $schema, int $maxTokens = 8000): array
    {
        $body = $this->post([
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_completion_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'reply', 'strict' => true, 'schema' => $schema]],
        ]);
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (! $text) {
            throw new AiException(__('The AI returned an empty reply. Try again.'));
        }

        return (array) json_decode($text, true);
    }

    public function ping(): void
    {
        $this->post(['model' => $this->model, 'messages' => [['role' => 'user', 'content' => 'Reply OK']], 'max_completion_tokens' => 16]);
    }

    private function post(array $payload): array
    {
        try {
            $res = Http::timeout(120)->withToken($this->key)->post(self::URL, $payload);
        } catch (ConnectionException) {
            throw new AiException(__(':provider could not be reached. Check the server internet connection.', ['provider' => 'OpenAI']));
        }
        if (! $res->successful()) {
            throw AiException::fromStatus($res->status(), 'OpenAI');
        }

        return (array) $res->json();
    }
}
