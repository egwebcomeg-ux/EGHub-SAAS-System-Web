<?php

namespace App\Services\Crm;

use App\Models\PlatformConversation;
use App\Models\Prospect;

/**
 * A person who tapped «send a WhatsApp message» on one of our ads (Meta adds the ad to their first message as
 * `referral`): they wrote to us first, so the team may answer them. They become a place of the sales team with the
 * source «إعلان», or the place we already have for their number gets a note. A restaurant already on EgHub is left
 * alone. The conversation says which ad brought them.
 */
final class AdLeads
{
    /** @param  array<string, mixed>  $referral */
    public function record(PlatformConversation $conversation, array $referral): ?Prospect
    {
        if (! in_array($referral['source_type'] ?? '', ['ad', 'post'], true)) {
            return null;
        }
        $headline = mb_substr(trim((string) ($referral['headline'] ?? $referral['body'] ?? '')), 0, 150);
        $conversation->messages()->create(['direction' => 'note', 'body' => __('Came from an ad: :ad', ['ad' => $headline !== '' ? $headline : __('Ad')])]);
        $mobile = normalize_phone($conversation->phone);
        if ($conversation->tenant_id !== null || $mobile === null) {
            return null;
        }
        $place = Prospect::query()->where(fn ($query) => $query->where('phone', $mobile)->orWhere('phone2', $mobile))->first();
        if ($place) {
            $place->events()->create(['type' => 'whatsapp', 'notes' => __('Wrote to us from an ad: :ad', ['ad' => $headline])]);
            $place->update(['last_contact_at' => now()]);

            return $place;
        }
        $place = Prospect::query()->create([
            'name' => mb_substr(trim((string) $conversation->name) ?: $mobile, 0, 150), 'category' => 'restaurant', 'phone' => $mobile,
            'source' => 'ad', 'governorate' => 'الغربية', 'last_contact_at' => now(),
            'notes' => __('Wrote to us on WhatsApp from an ad: :ad. The name is their WhatsApp name: fix it to the place\'s name.', ['ad' => $headline]),
        ]);
        $place->events()->create(['type' => 'import', 'meta' => ['source' => 'ad', 'ad' => $headline]]);
        app(ProspectScore::class)->rescore([$place->id]);

        return $place;
    }
}
