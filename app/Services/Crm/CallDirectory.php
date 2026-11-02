<?php

namespace App\Services\Crm;

use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;

/**
 * Whose number is this: a restaurant's owner (its lead) first, then a place the team works. The phone shows the card
 * while it rings, and keeps a copy of the list for when the internet is off.
 */
final class CallDirectory
{
    public const MOST_NUMBERS = 10000;

    /** A mobile in its one form, or the digits of a landline. */
    public static function clean(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        $digits = substr(preg_replace('/\D+/', '', $phone) ?? '', -20);

        return normalize_phone($phone) ?? ($digits !== '' ? $digits : null);
    }

    /** @return array{lead: ?Lead, prospect: ?Prospect} */
    public function match(?string $phone): array
    {
        $phone = self::clean($phone);
        if ($phone === null) {
            return ['lead' => null, 'prospect' => null];
        }
        $owner = User::query()->withoutGlobalScopes()->where('phone', $phone)->whereNotNull('tenant_id')->role('owner')->orderBy('id')->first();
        $prospect = Prospect::query()->where(fn ($query) => $query->where('phone', $phone)->orWhere('phone2', $phone))
            ->orderByRaw("stage = 'lost'")->latest('id')->first();
        $tenantId = $owner?->tenant_id ?? $prospect?->tenant_id;

        return ['lead' => $tenantId ? Lead::query()->with('tenant.subscription.plan')->where('tenant_id', $tenantId)->first() : null, 'prospect' => $prospect];
    }

    /**
     * What the sales person sees while the phone rings.
     *
     * @return array{known: bool, name: string, kind: string, stage: string, plan: string, last_note: string, url: ?string}
     */
    public function card(?string $phone): array
    {
        ['lead' => $lead, 'prospect' => $prospect] = $this->match($phone);
        if ($lead?->tenant) {
            return [
                'known' => true, 'name' => $lead->tenant->name, 'kind' => __('Restaurant'), 'stage' => $lead->stageLabel(),
                'plan' => (string) $lead->tenant->subscription?->plan?->displayName(),
                'last_note' => mb_substr((string) $lead->events()->whereNotNull('notes')->latest('id')->value('notes'), 0, 160),
                'url' => route('admin.crm.show', $lead),
            ];
        }
        if ($prospect) {
            return [
                'known' => true, 'name' => $prospect->name, 'kind' => $prospect->categoryLabel(), 'stage' => $prospect->stageLabel(), 'plan' => '',
                'last_note' => mb_substr((string) $prospect->events()->whereNotNull('notes')->latest('id')->value('notes'), 0, 160),
                'url' => route('admin.crm.prospects.show', $prospect),
            ];
        }

        return ['known' => false, 'name' => (string) self::clean($phone), 'kind' => __('New number'), 'stage' => '', 'plan' => '', 'last_note' => '', 'url' => null];
    }

    /** @return array<string, string> number => name, for the phone's own copy */
    public function numbers(): array
    {
        $names = [];
        Prospect::query()->whereNotNull('phone')->latest('id')->limit(self::MOST_NUMBERS)->get(['name', 'phone', 'phone2'])
            ->each(function (Prospect $prospect) use (&$names) {
                $names[$prospect->phone] = $prospect->name;
                if ($prospect->phone2) {
                    $names[$prospect->phone2] = $prospect->name;
                }
            });
        // a restaurant's name wins over the place it came from
        User::query()->withoutGlobalScopes()->whereNotNull('users.tenant_id')->whereNotNull('users.phone')->role('owner')
            ->join('tenants', 'tenants.id', '=', 'users.tenant_id')->whereNull('tenants.deleted_at')
            ->limit(self::MOST_NUMBERS)->get(['users.phone', 'tenants.name as tenant_name'])
            ->each(function (User $owner) use (&$names) {
                $names[$owner->phone] = (string) $owner->getAttribute('tenant_name');
            });

        return $names;
    }
}
