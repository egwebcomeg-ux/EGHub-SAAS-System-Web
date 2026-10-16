<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What visitors asked the website assistant ("اسألنا" on egmenu.app) and what it answered: EgWeb reads them to see
 * what people want to know and where the answers fall short. No names or phone numbers are asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $t) {
            $t->id();
            $t->string('conversation', 40)->index();   // random id the browser keeps for one chat
            $t->string('visitor', 64)->default('');      // hashed IP, to spot abuse without storing the address
            $t->string('lang', 2)->default('ar');
            $t->text('question');
            $t->text('answer');
            $t->boolean('handoff')->default(false);    // the assistant suggested talking to a person
            $t->unsignedInteger('tokens_in')->default(0);
            $t->unsignedInteger('tokens_out')->default(0);
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
