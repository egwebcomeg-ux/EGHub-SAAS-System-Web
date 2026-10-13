<?php

namespace App\Services\Import\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Services\Import\MenuDraft;

/** Claude through the official Anthropic PHP SDK, with structured output (the reply is valid JSON for the schema). */
final class AnthropicExtractor implements MenuExtractor
{
    public function __construct(private readonly string $key, private readonly string $model)
    {
    }

    public function extract(array $files): array
    {
        $content = [];
        foreach ($files as $f) {
            $content[] = $f['mime'] === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $f['data']]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $f['mime'], 'data' => $f['data']]];
        }
        $content[] = ['type' => 'text', 'text' => MenuDraft::prompt()];

        $message = $this->call(fn (Client $c) => $c->messages->create(
            model: $this->model,
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $content]],
            // reading a menu needs little reasoning: low effort keeps it fast and cheap on the restaurant's key
            outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => MenuDraft::schema()]],
        ));

        if ($message->stopReason === 'refusal') {
            throw new AiException(__('The AI declined to read this file. Try clearer photos.'));
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AiException(__('This menu is too long for one upload. Upload fewer pages at a time.'));
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return (array) json_decode($block->text, true);
            }
        }
        throw new AiException(__('The AI returned no menu. Try clearer photos.'));
    }

    public function ping(): void
    {
        $this->call(fn (Client $c) => $c->messages->create(model: $this->model, maxTokens: 16, messages: [['role' => 'user', 'content' => 'Reply OK']]));
    }

    private function call(\Closure $fn): mixed
    {
        try {
            return $fn(new Client(apiKey: $this->key));
        } catch (APIStatusException $e) {
            throw AiException::fromStatus((int) $e->status, 'Claude');
        } catch (APIConnectionException) {
            throw new AiException(__(':provider could not be reached. Check the server internet connection.', ['provider' => 'Claude']));
        }
    }
}
