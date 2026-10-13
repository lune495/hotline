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
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->default(587);
            $table->string('smtp_encryption')->default('tls');
        });
        DB::table('hotlines')->where('host', 'pro3.mail.ovh.net')->update([
            'smtp_host' => 'pro3.mail.ovh.net', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        ]);
    }
    public function down(): void
    {
        Schema::table('hotlines', fn (Blueprint $table) => $table->dropColumn(['smtp_host', 'smtp_port', 'smtp_encryption']));
    }
};
