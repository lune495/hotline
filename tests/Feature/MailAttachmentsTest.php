<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\Hotline;
use App\Models\User;
use App\Services\OutgoingMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailAttachmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            (require $file)->up();
        }
        Storage::fake('local');
    }

    private function user(): User
    {
        $hotline = Hotline::create(['nom' => 'Support', 'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl', 'email' => 'support@example.test', 'password' => 'secret', 'folder' => 'INBOX', 'active' => true, 'smtp_host' => 'smtp.example.test']);

        return User::create(['name' => 'Agent', 'email' => 'agent@example.test', 'password' => 'secret', 'hotline_id' => $hotline->id]);
    }

    public function test_send_passes_recipients_and_uploaded_files_and_rejects_invalid_input(): void
    {
        $this->postJson('/api/emails/send')->assertUnauthorized();
        $user = $this->user();
        $this->actingAs($user, 'sanctum');
        $this->mock(OutgoingMail::class)->shouldReceive('send')->once()->withArgs(function ($hotline, $data, $files) use ($user) {
            return $hotline->id === $user->hotline_id && $data['bcc'] === ['hidden@example.test'] && count($files) === 1 && file_get_contents($files[0]->getRealPath()) === 'Bonjour';
        });
        $payload = ['to' => ['client@example.test'], 'cc' => ['copy@example.test'], 'bcc' => ['hidden@example.test'], 'objet' => 'Document', 'contenu' => 'Bonjour'];
        $this->post('/api/emails/send', $payload + ['attachments' => [UploadedFile::fake()->createWithContent('document.txt', 'Bonjour')]], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/emails/send', array_merge($payload, ['to' => ['incorrect']]))->assertUnprocessable();
        $this->post('/api/emails/send', $payload + ['attachments' => [UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/emails/send', $payload + ['attachments' => [UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')]], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_outgoing_message_contains_files_and_bcc_without_network(): void
    {
        $user = $this->user();
        $mailer = app('mail.manager')->mailer('array');
        \Illuminate\Support\Facades\Mail::shouldReceive('purge')->with('hotline')->twice();
        \Illuminate\Support\Facades\Mail::shouldReceive('mailer')->with('hotline')->once()->andReturn($mailer);
        $file = UploadedFile::fake()->createWithContent('document.txt', 'Contenu du document');
        (new OutgoingMail)->send($user->hotline, [
            'to' => ['client@example.test'], 'cc' => ['copy@example.test'], 'bcc' => ['hidden@example.test'],
            'objet' => 'Document', 'contenu' => 'Bonjour',
        ], [$file]);
        $message = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('support@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame('hidden@example.test', $message->getBcc()[0]->getAddress());
        $this->assertSame('Bonjour', $message->getTextBody());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('document.txt', $message->getAttachments()[0]->getFilename());
        $this->assertSame('Contenu du document', base64_decode($message->getAttachments()[0]->bodyToString()));
    }

    public function test_download_is_private_and_scoped_to_active_hotline(): void
    {
        $user = $this->user();
        $email = Email::create(['hotline_id' => $user->hotline_id, 'mail_destinatire' => 'support@example.test', 'mail_emetteur' => 'client@example.test', 'objet' => 'Test', 'contenu' => 'Test']);
        Storage::disk('local')->put('mail-attachments/test', 'secret document');
        $a = $email->attachments()->create(['source_key' => str_repeat('a', 64), 'name' => 'document.txt', 'mime_type' => 'text/plain', 'size' => 15, 'path' => 'mail-attachments/test']);
        $this->getJson('/api/attachments/'.$a->id.'/download')->assertUnauthorized();
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/emails/'.$email->id.'/attachments')->assertOk()->assertJsonMissingPath('data.0.path');
        $this->get('/api/attachments/'.$a->id.'/download')->assertOk()->assertDownload('document.txt');
        $user->hotline->update(['active' => false]);
        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson('/api/attachments/'.$a->id.'/download')->assertNotFound();
        $user->update(['hotline_id' => null]);
        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson('/api/emails/'.$email->id.'/attachments')->assertNotFound();
    }
}
