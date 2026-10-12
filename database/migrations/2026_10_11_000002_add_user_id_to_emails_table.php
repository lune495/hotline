<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Assign imported messages only to the account owning the configured mailbox.
        $config = config('imap');
        $owner = DB::table('users')->where('email', $config['username'])->value('id');
        if ($owner) {
            $key = hash('sha256', implode('|', [$config['host'], $config['port'], $config['username'], $config['folder']]));
            DB::table('emails')->where('mailbox_key', $key)->whereNull('user_id')->update(['user_id' => $owner]);
        }
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
