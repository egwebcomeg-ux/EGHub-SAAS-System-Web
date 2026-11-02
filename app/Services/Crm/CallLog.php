<?php

namespace App\Services\Crm;

use App\Models\CallDevice;
use App\Models\PlatformAdmin;
use App\Models\SalesCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The phone sends its call log here. A call is stored once (the phone repeats what it could not send), tied to the
 * restaurant or place of its number; a call that was talked closes the missed calls of that number. What the sales
 * person writes after it goes on that lead's or place's timeline. While a call is on, the computer of the same admin
 * shows who it is (current()).
 */
final class CallLog
{
    private const CURRENT_MINUTES = 2;

    public function __construct(private CallDirectory $directory) {}

    /**
     * @param  list<array{id: string, phone?: ?string, direction: string, started_at: int, duration: int}>  $rows
     * @return ?SalesCall the last new call that was talked, waiting for its summary
     */
    public function store(CallDevice $device, array $rows): ?SalesCall
    {
        $latest = null;
        usort($rows, fn (array $a, array $b) => $a['started_at'] <=> $b['started_at']);
        foreach ($rows as $row) {
            $phone = CallDirectory::clean($row['phone'] ?? null);
            ['lead' => $lead, 'prospect' => $prospect] = $this->directory->match($phone);
            $startedAt = Carbon::createFromTimestampMs($row['started_at']);
            $call = SalesCall::query()->firstOrCreate(
                ['admin_id' => $device->admin_id, 'external_id' => $row['id']],
                ['direction' => $row['direction'], 'phone' => $phone, 'started_at' => $startedAt, 'duration_seconds' => $row['duration'],
                    'lead_id' => $lead?->id, 'prospect_id' => $prospect?->id],
            );
            if (! $call->wasRecentlyCreated || ! $call->wasTalked()) {
                continue;
            }
            $latest = $call;
            if ($phone !== null) {
                SalesCall::query()->where('phone', $phone)->missedNotReturned()->where('started_at', '<=', $startedAt)->update(['returned_at' => $startedAt]);
            }
            $lead?->update(['last_contact_at' => $startedAt, 'first_contact_at' => $lead->first_contact_at ?? $startedAt, 'assigned_admin_id' => $lead->assigned_admin_id ?? $device->admin_id]);
            $prospect?->update(['last_contact_at' => $startedAt, 'caller_id' => $prospect->caller_id ?? $device->admin_id]);
        }
        Cache::forget(self::currentKey($device->admin_id));

        return $latest;
    }

    /**
     * The summary: on the call, and once on the timeline of its lead or place.
     *
     * @param  array{outcome: string, notes: ?string, next_follow_up_at: ?string}  $data
     */
    public function summarize(SalesCall $call, PlatformAdmin $admin, array $data): void
    {
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $followUp = ! empty($data['next_follow_up_at']) ? local_input($data['next_follow_up_at']) : null;
        $meta = ['call_id' => $call->id, 'duration' => $call->duration_seconds, 'direction' => $call->direction];
        $isFirstSummary = $call->summarized_at === null;
        $call->update(['outcome' => $data['outcome'], 'notes' => $notes, 'summarized_at' => now()]);
        if (! $isFirstSummary) {
            return;
        }
        $reached = $data['outcome'] === 'answered';
        if ($call->lead) {
            $stage = $reached && $call->lead->stage === 'new' ? 'contacted' : null;
            $call->lead->events()->create(['type' => 'call', 'admin_id' => $admin->id, 'outcome' => $data['outcome'], 'notes' => $notes, 'next_follow_up_at' => $followUp,
                'meta' => $meta + ($stage ? ['from' => 'new', 'to' => $stage] : [])]);
            $call->lead->update(array_filter(['stage' => $stage]) + ['next_follow_up_at' => $followUp, 'assigned_admin_id' => $call->lead->assigned_admin_id ?? $admin->id]);
        }
        if ($call->prospect) {
            $stage = $reached && $call->prospect->stage === 'new' ? 'contacted' : null;
            $call->prospect->events()->create(['type' => 'call', 'admin_id' => $admin->id, 'outcome' => $data['outcome'], 'notes' => $notes,
                'meta' => $meta + ($stage ? ['from' => 'new', 'to' => $stage] : [])]);
            $call->prospect->update(array_filter(['stage' => $stage]) + ['next_follow_up_at' => $followUp, 'caller_id' => $call->prospect->caller_id ?? $admin->id]);
        }
    }

    /** @return array<string, mixed> the card, kept for the same admin's computer */
    public function ringing(CallDevice $device, ?string $phone, string $direction): array
    {
        $card = $this->directory->card($phone);
        Cache::put(self::currentKey($device->admin_id), [...$card, 'phone' => CallDirectory::clean($phone), 'direction' => $direction, 'at' => now()->timestamp], now()->addMinutes(self::CURRENT_MINUTES));

        return $card;
    }

    /** @return array<string, mixed>|null */
    public function current(PlatformAdmin $admin): ?array
    {
        return Cache::get(self::currentKey($admin->id));
    }

    private static function currentKey(int $adminId): string
    {
        return 'crm:current-call:'.$adminId;
    }
}
