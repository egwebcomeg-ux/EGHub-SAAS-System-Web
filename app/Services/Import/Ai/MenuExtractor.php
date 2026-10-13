<?php

namespace App\Services\Import\Ai;

/** Turns menu photos / a PDF into a MenuDraft array using one AI provider. */
interface MenuExtractor
{
    /**
     * @param list<array{mime:string, data:string}> $files base64 data, images (jpeg/png/webp) or one PDF
     * @return array decoded JSON (normalised later by MenuDraft::normalize)
     * @throws AiException with a message safe to show to the restaurant
     */
    public function extract(array $files): array;

    /** Cheapest possible call to check the key and model work. */
    public function ping(): void;
}
