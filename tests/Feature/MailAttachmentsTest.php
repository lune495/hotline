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
    public function test_conversation_links_more_than_three_messages_without_using_subject(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');
        $base = ['hotline_id' => $user->hotline_id, 'mail_emetteur' => 'client@example.test', 'mail_destinatire' => 'support@example.test', 'objet' => 'Même objet', 'contenu' => 'Texte'];
        $root = Email::create($base + ['message_id' => 'root@example.test']);
        for ($i = 1; $i <= 4; $i++) {
            Email::create($base + ['message_id' => 'child'.$i.'@example.test', 'in_reply_to' => $i === 1 ? 'root@example.test' : 'child'.($i - 1).'@example.test', 'references' => ['root@example.test']]);
        }
        Email::create($base + ['message_id' => 'unrelated@example.test']);
        $this->getJson('/api/emails/'.$root->id.'/conversation')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_reply_context_thread_headers_and_cross_hotline_isolation(): void
    {
        $user = $this->user();
        $parent = Email::create(['hotline_id' => $user->hotline_id, 'mail_emetteur' => 'sender@example.test', 'mail_destinatire' => 'support@example.test, colleague@example.test', 'reply_to' => 'reply@example.test', 'cc' => 'colleague@example.test, copy@example.test, SUPPORT@example.test', 'bcc' => 'private@example.test', 'objet' => 'Sujet', 'contenu' => 'Original', 'message_id' => 'parent@example.test', 'references' => ['root@example.test']]);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/emails/'.$parent->id.'/reply-context?mode=reply')
            ->assertOk()->assertJsonPath('data.to', ['reply@example.test'])->assertJsonPath('data.cc', [])->assertJsonPath('data.bcc', []);
        $this->getJson('/api/emails/'.$parent->id.'/reply-context?mode=reply_all')
            ->assertOk()->assertJsonPath('data.to', ['reply@example.test', 'colleague@example.test'])->assertJsonPath('data.cc', ['copy@example.test']);
        $this->mock(OutgoingMail::class)->shouldReceive('send')->once()->withArgs(fn ($hotline, $data, $files) => $data['_headers']['in_reply_to'] === 'parent@example.test' && $data['_headers']['references'] === ['root@example.test', 'parent@example.test'] && $data['to'] === ['reply@example.test']);
        $response = $this->postJson('/api/emails/send', ['reply_to_email_id' => $parent->id, 'reply_mode' => 'reply', 'objet' => 'Re: Sujet', 'contenu' => 'Réponse'])->assertOk();
        $reply = Email::findOrFail($response->json('email.id'));
        $this->assertNotEmpty($reply->message_id);
        $this->assertSame($parent->id, $reply->reply_to_email_id);
        $this->assertSame('parent@example.test', $reply->in_reply_to);
        $this->getJson('/api/emails/'.$parent->id.'/conversation')->assertOk()->assertJsonCount(2, 'data');
        $foreign = Email::create(['mail_emetteur' => 'other@example.test', 'mail_destinatire' => 'other@example.test', 'objet' => 'Other', 'contenu' => 'Private', 'message_id' => 'parent@example.test']);
        $this->getJson('/api/emails/'.$foreign->id.'/reply-context')->assertNotFound();
        $this->getJson('/api/emails/'.$foreign->id.'/conversation')->assertNotFound();
        $this->postJson('/api/emails/send', ['reply_to_email_id' => $foreign->id, 'objet' => 'Re: Other', 'contenu' => 'Test'])->assertNotFound();
    }

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
        $saved = Email::where('folder', 'sent')->firstOrFail();
        $this->assertSame('sent', $saved->delivery_status);
        $this->assertSame($user->hotline_id, $saved->hotline_id);
        $this->assertSame('hidden@example.test', $saved->bcc);
        $this->assertNotNull($saved->sent_at);
        $attachment = $saved->attachments()->firstOrFail();
        $this->assertSame('Bonjour', Storage::disk('local')->get($attachment->path));
        $this->getJson('/api/emails/sent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $saved->id);
        $this->postJson('/graphql', ['query' => '{ emails { id } }'])->assertJsonPath('data.emails', []);
        $this->postJson('/graphql', ['query' => '{ emails(folder: "sent") { id delivery_status attachments { id } } }'])
            ->assertOk()->assertJsonPath('data.emails.0.id', (string) $saved->id)->assertJsonPath('data.emails.0.delivery_status', 'sent');
        $this->postJson('/api/emails/send', array_merge($payload, ['to' => ['incorrect']]))->assertUnprocessable();
        $this->post('/api/emails/send', $payload + ['attachments' => [UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/emails/send', $payload + ['attachments' => [UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')]], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_unconfirmed_smtp_is_preserved_but_not_listed_as_sent(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');
        $this->mock(OutgoingMail::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/emails/send', ['to' => ['client@example.test'], 'objet' => 'Test', 'contenu' => 'Body'])->assertStatus(502);
        $this->assertSame('unconfirmed', Email::firstOrFail()->delivery_status);
        $this->getJson('/api/emails/sent')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sent_messages_are_shared_only_with_the_same_hotline(): void
    {
        $user = $this->user();
        $saved = Email::create(['hotline_id' => $user->hotline_id, 'user_id' => $user->id, 'folder' => 'sent', 'delivery_status' => 'sent', 'sent_at' => now(), 'mail_destinatire' => 'client@example.test', 'mail_emetteur' => 'support@example.test', 'objet' => 'Test', 'contenu' => 'Body']);
        $colleague = User::create(['name' => 'Colleague', 'email' => 'colleague@example.test', 'password' => 'secret', 'hotline_id' => $user->hotline_id]);
        $this->actingAs($colleague, 'sanctum');
        $this->getJson('/api/emails/sent?id='.$saved->id)->assertOk()->assertJsonCount(1, 'data');
        $otherHotline = Hotline::create(['nom' => 'Other', 'host' => 'imap.example.test', 'email' => 'other@example.test', 'password' => 'secret', 'active' => true]);
        $colleague->update(['hotline_id' => $otherHotline->id]);
        $this->actingAs($colleague->fresh(), 'sanctum');
        $this->getJson('/api/emails/sent?id='.$saved->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/graphql', ['query' => '{ emails(folder: "sent") { id } }'])->assertJsonPath('data.emails', []);
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
            '_headers' => ['message_id' => 'new@example.test', 'in_reply_to' => 'parent@example.test', 'references' => ['root@example.test', 'parent@example.test']],
        ], [$file]);
        $message = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('support@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame('hidden@example.test', $message->getBcc()[0]->getAddress());
        $this->assertSame('Bonjour', $message->getTextBody());
        $this->assertSame('new@example.test', $message->getHeaders()->get('Message-ID')->getId());
        $this->assertSame('parent@example.test', $message->getHeaders()->get('In-Reply-To')->getId());
        $this->assertSame(['root@example.test', 'parent@example.test'], $message->getHeaders()->get('References')->getIds());
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
