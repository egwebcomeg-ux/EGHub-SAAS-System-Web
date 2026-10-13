<?php

namespace App\Services\Import\Ai;

/** An AI call failed for a reason the restaurant can act on (bad key, quota, unreadable photo...). */
final class AiException extends \RuntimeException
{
    /** Maps an HTTP status from any provider to a message the owner understands. */
    public static function fromStatus(int $status, string $provider): self
    {
        return new self(match (true) {
            in_array($status, [401, 403], true) => __('The :provider API key was refused. Check it in Settings.', ['provider' => $provider]),
            $status === 404 => __('The model name was not found at :provider. Check it in Settings.', ['provider' => $provider]),
            $status === 429 => __(':provider says the key reached its limit or has no credit. Try later or check your account.', ['provider' => $provider]),
            $status >= 500 => __(':provider is busy right now. Try again in a few minutes.', ['provider' => $provider]),
            default => __(':provider could not read this menu (error :status).', ['provider' => $provider, 'status' => $status]),
        });
    }
}
