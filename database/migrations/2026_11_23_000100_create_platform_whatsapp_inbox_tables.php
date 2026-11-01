<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «محادثات واتساب»: what people write to the EgHub number, and the team's answers, in the admin. Platform data (no
 * tenant_id): a restaurant's guests and its own number never come here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_conversations', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 20)->unique();                 // digits, international form, as WhatsApp sends it
            $t->string('name', 120)->nullable();               // the name on the sender's WhatsApp profile
            $t->string('status', 10)->default('open');         // open | closed
            $t->foreignId('assigned_to')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->unsignedInteger('unread')->default(0);
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable();      // the 24-hour window for a free-form reply starts here
            $t->timestamps();
            $t->index(['status', 'last_message_at']);
        });

        Schema::create('platform_conversation_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_conversation_id')->constrained()->cascadeOnDelete();
            $t->string('direction', 10);                       // in | out | note (seen by the team only)
            $t->string('type', 20)->default('text');           // WhatsApp's message type (image, audio...)
            $t->text('body')->nullable();
            $t->string('provider_id', 120)->nullable()->unique(); // Meta's wamid: the same message is kept once
            $t->string('status', 20)->nullable();              // out: sent | delivered | read | failed
            $t->string('error', 255)->nullable();
            $t->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamps();
            $t->index(['platform_conversation_id', 'id']);
        });

        Schema::create('platform_canned_replies', function (Blueprint $t) {
            $t->id();
            $t->string('title', 80);
            $t->string('body', 1000);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_canned_replies');
        Schema::dropIfExists('platform_conversation_messages');
        Schema::dropIfExists('platform_conversations');
    }
};
