<?php

namespace App\Support;

/**
 * Readable names of the platform activity log's actions (App\Models\PlatformAuditLog, admin.<domain>/activity),
 * translated through lang/ar.json. An action with no name here is shown as its raw key.
 */
final class AdminActivityLabels
{
    /** action key => English text (the translation key) */
    public const LABELS = [
        'admin.password_changed' => 'Changed own password',
        'announcement.created' => 'Announcement created',
        'announcement.updated' => 'Announcement edited',
        'announcement.deleted' => 'Announcement deleted',
        'crm.discount_granted' => 'Discount given',
        'crm.discount_revoked' => 'Discount cancelled',
        'crm.script_added' => 'Call script line added',
        'crm.script_updated' => 'Call script edited',
        'crm.script_removed' => 'Call script line removed',
        'crm.script_defaults' => 'Call script reset to the defaults',
        'egweb_clients.added' => 'Client logo added',
        'egweb_clients.updated' => 'Client logo edited',
        'egweb_clients.moved' => 'Client logo moved',
        'egweb_clients.removed' => 'Client logo removed',
        'failed_job.retried' => 'Failed job retried',
        'failed_job.deleted' => 'Failed job deleted',
        'plan.archived' => 'Plan archived',
        'plan.unarchived' => 'Plan restored from the archive',
        'pricing.updated' => 'Prices updated',
        'tenant.created_for_prospect' => 'Restaurant created for a prospect',
        'tenant.deleted' => 'Restaurant deleted for good',
        'tenant.impersonation_started' => 'Support access opened',
        'tenant.impersonation_ended' => 'Support access closed',
        'tenant.impersonation_expired' => 'Support access ended on its own',
        'tenant.welcome_kit_regenerated' => 'New welcome kit issued',
    ];

    public static function label(string $action): string
    {
        return isset(self::LABELS[$action]) ? __(self::LABELS[$action]) : $action;
    }
}
