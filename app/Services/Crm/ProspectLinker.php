<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\Tenant;

/**
 * Ties the sales team's work to the restaurants it brings. A place that signs up with the same mobile as a prospect
 * is linked to it (its stage becomes "signed up", the restaurant's lead goes to the prospect's caller or field rep);
 * its first paid invoice wins it and records who closed it and who assisted, for the commission (SalesPlan).
 */
final class ProspectLinker
{
    /** Links the newly signed-up $tenant to the open prospect with its owner's mobile, if any. */
    public function link(Tenant $tenant, ?string $phone): ?Prospect
    {
        $phone = $phone !== null ? normalize_phone($phone) : null;
        if (! $phone || Prospect::query()->where('tenant_id', $tenant->id)->exists()) {
            return null;
        }
        $prospect = Prospect::query()->whereNull('tenant_id')
            ->where(fn ($q) => $q->where('phone', $phone)->orWhere('phone2', $phone))
            ->orderByRaw("stage = 'lost'")->latest('id')->first();
        if (! $prospect) {
            return null;
        }
        $prospect->update(['tenant_id' => $tenant->id, 'linked_at' => now(), 'stage' => $prospect->stage === 'won' ? 'won' : 'trial', 'next_follow_up_at' => null]);
        $prospect->events()->create(['type' => 'linked', 'meta' => ['tenant' => $tenant->id, 'name' => $tenant->name]]);
        $owner = $prospect->caller_id ?? $prospect->field_id;
        $lead = Lead::for($tenant);
        if ($owner && $lead->assigned_admin_id === null) {
            $lead->update(['assigned_admin_id' => $owner]);
        }

        return $prospect;
    }

    /**
     * The linked restaurant paid its first invoice: the prospect is won. The closer is the field rep of the last
     * visit made there (the caller who booked it assists), or else whoever phoned it.
     */
    public function won(Tenant $tenant, Invoice $invoice): void
    {
        $prospect = Prospect::query()->where('tenant_id', $tenant->id)->first();
        if (! $prospect || $prospect->stage === 'won') {
            return;
        }
        $visit = $prospect->appointments()->where('status', 'done')->whereNotNull('field_id')->latest('checked_in_at')->latest('id')->first();
        if ($visit instanceof Appointment) {
            $closer = $visit->field_id;
            $assist = $visit->set_by ?? $prospect->caller_id;
        } else {
            $closer = $prospect->caller_id ?? $prospect->field_id;
            $assist = $prospect->caller_id !== null ? $prospect->field_id : null;
        }
        $prospect->update([
            'stage' => 'won', 'won_at' => now(), 'closer_id' => $closer,
            'assist_id' => $assist !== $closer ? $assist : null, 'next_follow_up_at' => null,
        ]);
        $prospect->events()->create(['type' => 'won', 'meta' => ['invoice' => $invoice->number, 'total' => $invoice->total, 'closer' => $closer]]);
    }
}
