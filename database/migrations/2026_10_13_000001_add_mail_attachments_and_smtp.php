<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotlines', function (Blueprint $t) {
            $t->string('smtp_username')->nullable();
            $t->text('smtp_password')->nullable();
        });
        Schema::create('email_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('email_id')->constrained()->cascadeOnDelete();
            $t->string('source_key', 64);
            $t->string('name');
            $t->string('mime_type');
            $t->unsignedBigInteger('size');
            $t->string('path');
            $t->timestamps();
            $t->unique(['email_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
        Schema::table('hotlines', fn (Blueprint $t) => $t->dropColumn(['smtp_username', 'smtp_password']));
    }
};
