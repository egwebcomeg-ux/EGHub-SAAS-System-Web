<?php

use App\Services\Crm\LeadBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sales follow-up of new sign-ups on admin.<domain> («متابعة العملاء»), platform side only (never a TenantModel,
 * so no restaurant ever reads it and its backups leave it out):
 *  - crm_leads: one per restaurant (stage, who follows it, next follow-up, the answers given on calls);
 *  - crm_lead_events: its timeline (calls and WhatsApp attempts with their outcome, stage changes, discounts, notes);
 *  - crm_script_items: the call script the callers read (opening, pitch, questions with answer options, objections),
 *    edited by the platform owner; defaults come from Database\Seeders\CallScriptSeeder;
 *  - tenant_discounts: a % off the restaurant's next subscription invoice(s), granted on a call within the caller's
 *    limit (platform_admins.crm_discount_max), with an expiry; Billing::quote() applies it.
 * Existing restaurants are backfilled as leads (App\Services\Crm\LeadBackfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->unique();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('stage', 20)->default('new');            // App\Models\Lead::STAGES
            $t->string('source', 10)->default('signup');        // signup | manual
            $t->foreignId('assigned_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamp('next_follow_up_at')->nullable();
            $t->string('lost_reason', 30)->nullable();
            $t->string('lost_note', 255)->nullable();
            $t->text('notes')->nullable();
            $t->json('answers')->nullable();                    // question key => option key, the latest answer of each
            $t->timestamp('first_contact_at')->nullable();
            $t->timestamp('last_contact_at')->nullable();
            $t->timestamp('won_at')->nullable();
            $t->timestamps();
            $t->index(['stage', 'next_follow_up_at']);
        });

        Schema::create('crm_lead_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $t->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->string('type', 12);                             // call | whatsapp | stage | assign | discount | note | won
            $t->string('outcome', 20)->nullable();              // answered | no_answer | busy | wrong_number | sent
            $t->json('answers')->nullable();
            $t->json('objections')->nullable();
            $t->text('notes')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('next_follow_up_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['lead_id', 'created_at']);
        });

        Schema::create('crm_script_items', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 12);                             // opening | whatsapp | pitch | question | objection | closing
            $t->string('key', 40)->unique();
            $t->string('title', 120);
            $t->text('body');
            $t->json('options')->nullable();                    // questions: [{key, label, reply, signal}]
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['kind', 'sort_order']);
        });

        Schema::create('tenant_discounts', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->decimal('percent', 5, 2);
            $t->unsignedTinyInteger('invoices')->default(1);    // how many paid subscription invoices it covers
            $t->unsignedTinyInteger('invoices_used')->default(0);
            $t->timestamp('expires_at');
            $t->string('note', 255)->default('');
            $t->foreignId('granted_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        Schema::table('platform_admins', function (Blueprint $t) {
            // the most % this admin may give on a call; null = the role's default (App\Support\AdminPermissions)
            $t->unsignedTinyInteger('crm_discount_max')->nullable()->after('permissions');
        });

        app(LeadBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('platform_admins', fn (Blueprint $t) => $t->dropColumn('crm_discount_max'));
        Schema::dropIfExists('tenant_discounts');
        Schema::dropIfExists('crm_script_items');
        Schema::dropIfExists('crm_lead_events');
        Schema::dropIfExists('crm_leads');
    }
};
