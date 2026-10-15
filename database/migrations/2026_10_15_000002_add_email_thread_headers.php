<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('emails', function (Blueprint $t) {
            $t->string('message_id', 998)->nullable();
            $t->string('in_reply_to', 998)->nullable();
            $t->json('references')->nullable();
            $t->text('reply_to')->nullable();
            $t->foreignId('reply_to_email_id')->nullable()->constrained('emails')->nullOnDelete();
            $t->index(['hotline_id', 'message_id']);
        });
    }
    public function down(): void {
        Schema::table('emails', function (Blueprint $t) {
            $t->dropIndex(['hotline_id', 'message_id']);
            $t->dropConstrainedForeignId('reply_to_email_id');
            $t->dropColumn(['message_id', 'in_reply_to', 'references', 'reply_to']);
        });
    }
};
