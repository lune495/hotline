<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotlines', function (Blueprint $table) {
            if (!Schema::hasColumn('hotlines', 'smtp_host')) {
                $table->string('smtp_host')->nullable();
            }
            if (!Schema::hasColumn('hotlines', 'smtp_port')) {
                $table->unsignedInteger('smtp_port')->default(587);
            }
            if (!Schema::hasColumn('hotlines', 'smtp_encryption')) {
                $table->string('smtp_encryption')->default('tls');
            }
        });
        DB::table('hotlines')->where('host', 'pro3.mail.ovh.net')->whereNull('smtp_host')->update([
            'smtp_host' => 'pro3.mail.ovh.net', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        ]);
    }
    public function down(): void
    {
        Schema::table('hotlines', fn (Blueprint $table) => $table->dropColumn(['smtp_host', 'smtp_port', 'smtp_encryption']));
    }
};
