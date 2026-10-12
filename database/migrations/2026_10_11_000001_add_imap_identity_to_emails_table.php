<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->unsignedBigInteger('uid')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->string('mailbox_key', 64)->nullable();
            $table->unique(['mailbox_key', 'uid_validity', 'uid'], 'emails_imap_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique('emails_imap_identity_unique');
            $table->dropColumn(['uid', 'uid_validity', 'mailbox_key']);
        });
    }
};
