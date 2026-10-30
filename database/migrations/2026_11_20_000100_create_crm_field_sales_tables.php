<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The field sales team of «متابعة العملاء»: places that have no EgHub account yet (prospects, from the map import, a
 * visit, a referral or an ad), their timeline, the visits the caller books for the field rep, and each person's
 * monthly targets. Platform data only: no restaurant ever reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_prospects', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('category', 12)->default('restaurant');    // App\Models\Prospect::CATEGORIES
            $t->string('governorate', 60)->default('');
            $t->string('city', 60)->default('');
            $t->string('area', 100)->default('');
            $t->string('address', 255)->default('');
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('phone', 20)->nullable()->index();        // normalized (normalize_phone)
            $t->string('phone2', 20)->nullable()->index();
            $t->string('owner_name', 100)->default('');
            $t->unsignedSmallInteger('branches')->nullable();
            $t->string('current_system', 100)->default('');
            $t->boolean('has_delivery')->nullable();
            $t->string('source', 10)->default('manual');          // map | field | referral | ad | whatsapp | manual
            $t->string('external_ref', 60)->nullable()->unique(); // osm:node/123, csv:<hash>
            $t->string('stage', 12)->default('new');              // App\Models\Prospect::STAGES
            $t->unsignedTinyInteger('score')->default(0);         // «ترشيح السيستم», 0-100
            $t->foreignId('caller_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->foreignId('field_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->foreignId('closer_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->foreignId('assist_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamp('next_follow_up_at')->nullable();
            $t->string('lost_reason', 30)->nullable();
            $t->text('notes')->nullable();
            $t->string('tenant_id')->nullable()->unique();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->nullOnDelete();
            $t->timestamp('last_contact_at')->nullable();
            $t->timestamp('linked_at')->nullable();
            $t->timestamp('won_at')->nullable();
            $t->timestamps();
            $t->index(['stage', 'score']);
            $t->index(['city', 'category']);
        });

        Schema::create('crm_prospect_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('prospect_id')->constrained('crm_prospects')->cascadeOnDelete();
            $t->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->string('type', 12);                               // App\Models\ProspectEvent::TYPES
            $t->string('outcome', 20)->nullable();                // App\Models\Lead::OUTCOMES for calls
            $t->text('notes')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['prospect_id', 'created_at']);
            $t->index(['admin_id', 'type', 'created_at']);
        });

        Schema::create('crm_appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('prospect_id')->constrained('crm_prospects')->cascadeOnDelete();
            $t->foreignId('set_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->foreignId('field_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamp('scheduled_at');
            $t->string('address', 255)->default('');
            $t->string('status', 12)->default('scheduled');       // scheduled | done | no_show | cancelled
            $t->timestamp('checked_in_at')->nullable();
            $t->decimal('check_in_lat', 10, 7)->nullable();
            $t->decimal('check_in_lng', 10, 7)->nullable();
            $t->unsignedInteger('check_in_distance')->nullable(); // metres from the place
            $t->string('outcome', 16)->nullable();                // App\Models\Appointment::OUTCOMES
            $t->json('answers')->nullable();
            $t->json('photos')->nullable();                       // paths on the local (private) disk
            $t->text('notes')->nullable();
            $t->string('next_step', 255)->default('');
            $t->timestamp('reported_at')->nullable();
            $t->timestamps();
            $t->index(['field_id', 'scheduled_at']);
            $t->index(['set_by', 'status']);
        });

        Schema::create('crm_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $t->char('month', 7);                                 // YYYY-MM
            $t->unsignedSmallInteger('calls_per_day')->default(0);
            $t->unsignedSmallInteger('appointments')->default(0);
            $t->unsignedSmallInteger('visits')->default(0);
            $t->unsignedSmallInteger('places')->default(0);
            $t->unsignedSmallInteger('trials')->default(0);
            $t->unsignedSmallInteger('paid')->default(0);
            $t->timestamps();
            $t->unique(['admin_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_targets');
        Schema::dropIfExists('crm_appointments');
        Schema::dropIfExists('crm_prospect_events');
        Schema::dropIfExists('crm_prospects');
    }
};
