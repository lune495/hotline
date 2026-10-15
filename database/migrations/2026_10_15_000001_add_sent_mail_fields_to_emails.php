<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('folder')->default('inbox');
            $table->string('delivery_status')->nullable();
            $table->text('bcc')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->index(['hotline_id', 'folder', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['hotline_id', 'folder', 'delivery_status']);
            $table->dropColumn(['folder', 'delivery_status', 'bcc', 'sent_at']);
        });
    }
};
