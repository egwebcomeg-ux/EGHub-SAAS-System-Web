<?php

namespace App\Services\Import\Ai;

/** A text-only request to the restaurant's AI provider whose reply is JSON for a schema (menu translation...). */
interface JsonCompleter
{
    /**
     * @param  array<string, mixed>  $schema  JSON schema of the reply (objects closed with additionalProperties=false)
     * @return array<string, mixed> decoded reply
     *
     * @throws AiException with a message safe to show to the restaurant
     */
    public function complete(string $prompt, array $schema, int $maxTokens = 8000): array;
}
