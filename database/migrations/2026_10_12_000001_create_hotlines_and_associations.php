<?php
use App\Models\Hotline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotlines', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('host');
            $table->unsignedInteger('port')->default(993);
            $table->string('encryption')->default('ssl');
            $table->string('email');
            $table->text('password');
            $table->string('folder')->default('INBOX');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['host', 'port', 'email', 'folder'], 'hotlines_mailbox_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('hotline_id')->nullable()->constrained('hotlines')->restrictOnDelete();
        });
        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('hotline_id')->nullable()->constrained('hotlines')->restrictOnDelete();
            $table->dropUnique('emails_imap_identity_unique');
            $table->unique(['hotline_id', 'mailbox_key', 'uid_validity', 'uid'], 'emails_hotline_imap_unique');
        });
        DB::table('roles')->insertOrIgnore(['nom' => 'user', 'created_at' => now(), 'updated_at' => now()]);
        $config = config('imap');
        if (!empty($config['host']) && !empty($config['username']) && !empty($config['password'])) {
            $hotline = Hotline::create([
                'nom' => 'Hotline Finance', 'host' => $config['host'], 'port' => $config['port'],
                'encryption' => $config['encryption'], 'email' => $config['username'],
                'password' => $config['password'], 'folder' => $config['folder'], 'active' => true,
            ]);
            $key = hash('sha256', implode('|', [$config['host'], $config['port'], $config['username'], $config['folder']]));
            $importers = DB::table('emails')->where('mailbox_key', $key)->whereNotNull('user_id')->pluck('user_id');
            DB::table('users')->whereNull('hotline_id')->where(function ($query) use ($config, $importers) {
                $query->whereIn('id', $importers)->orWhereIn('email', [$config['username'], 'badaralune9@gmail.com']);
            })->update(['hotline_id' => $hotline->id]);
            DB::table('emails')->where('mailbox_key', $key)->update(['hotline_id' => $hotline->id]);
        }
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique('emails_hotline_imap_unique');
            $table->dropConstrainedForeignId('hotline_id');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('hotline_id'));
        Schema::dropIfExists('hotlines');
        // Do not restore the old global UID index: separate hotlines can contain identical UIDs.
    }
};
