<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailController;
use App\Models\Email;
use App\Models\User;
use App\Models\Role;
use App\Models\Hotline;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

class EmailSynchronizationTest extends TestCase
{
    public function test_sync_is_limited_and_preserves_responses_without_duplicates(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2014_10_12_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_10_10_000001_create_roles_table.php'))->up();
        (require database_path('migrations/2026_10_10_000003_add_nom_prenom_role_id_to_users_table.php'))->up();
        (require database_path('migrations/2026_10_10_000002_create_emails_table.php'))->up();
        (require database_path('migrations/2026_10_11_000001_add_imap_identity_to_emails_table.php'))->up();
        config(['imap.host' => 'imap.example.test', 'imap.username' => 'user@example.test', 'imap.password' => 'test']);
        (require database_path('migrations/2026_10_11_000002_add_user_id_to_emails_table.php'))->up();
        (require database_path('migrations/2026_10_12_000001_create_hotlines_and_associations.php'))->up();
        (require database_path('migrations/2026_10_13_000001_add_mail_attachments_and_smtp.php'))->up();
        \Illuminate\Support\Facades\Storage::fake('local');
        $hotline = Hotline::first();
        (require database_path('migrations/2026_10_15_000002_add_email_thread_headers.php'))->up();
        $role = Role::create(['nom' => 'admin']);
        $this->actingAs(User::create(['name' => 'Test', 'email' => 'admin@example.test', 'password' => 'password', 'role_id' => $role->id, 'hotline_id' => $hotline->id]));

        $message = Mockery::mock(Message::class);
        $attachment = \Mockery::mock(\Webklex\PHPIMAP\Attachment::class);
        $attachment->shouldReceive('getName')->andReturn('document.txt');
        $attachment->shouldReceive('getContent')->andReturn('Document reçu');
        $message->shouldReceive('getAttachments')->andReturn(new \Webklex\PHPIMAP\Support\AttachmentCollection([$attachment]));
        $message->shouldReceive('getUid')->andReturn(42);
        $message->shouldReceive('getTo', 'getFrom', 'getCc')->andReturn(new Attribute('address', []));
        $message->shouldReceive('getMessageId')->andReturn(new Attribute('message_id', 'incoming@example.test'));
        $message->shouldReceive('getInReplyTo', 'getReferences')->andReturn(new Attribute('headers', []));
        $message->shouldReceive('getReplyTo')->andReturn(new Attribute('reply_to', []));
        $message->shouldReceive('getSubject')->andReturn(new Attribute('subject', 'Objet'));
        $message->shouldReceive('getTextBody')->andReturn('Contenu');

        $query = Mockery::mock(WhereQuery::class);
        $query->shouldReceive('all')->twice()->andReturnSelf();
        $query->shouldReceive('setFetchOrder')->with('desc')->twice()->andReturnSelf();
        $query->shouldReceive('limit')->with(5)->twice()->andReturnSelf();
        $query->shouldReceive('leaveUnread')->twice()->andReturnSelf();
        $query->shouldReceive('get')->twice()->andReturn(new MessageCollection([$message]));
        $folder = Mockery::mock(Folder::class);
        $folder->shouldReceive('status')->andReturn(['uidvalidity' => 123]);
        $folder->shouldReceive('query')->andReturn($query);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect', 'disconnect')->twice()->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->with('INBOX')->andReturn($folder);
        $manager = Mockery::mock(ClientManager::class);
        $manager->shouldReceive('make')->andReturn($client);

        $controller = new EmailController();
        $this->assertSame(200, $controller->synchro_mails($manager)->getStatusCode());
        $importerId = auth()->id();
        Email::first()->update(['reponse' => 'Réponse existante']);
        $this->actingAs(User::create(['name' => 'Agent', 'email' => 'agent@example.test', 'password' => 'password', 'hotline_id' => $hotline->id]));
        $response = $controller->synchro_mails($manager);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, Email::count());
        $this->assertSame(1, \App\Models\EmailAttachment::count());
        $saved = \App\Models\EmailAttachment::first();
        $this->assertSame('Document reçu', \Illuminate\Support\Facades\Storage::disk('local')->get($saved->path));
        $this->assertArrayNotHasKey('path', $response->getData(true)['emails'][0]['attachments'][0]);
        $this->assertSame($importerId, Email::first()->user_id);
        $this->assertSame('Réponse existante', Email::first()->reponse);
        $this->assertSame(42, $response->getData(true)['emails'][0]['uid']);
        $this->assertSame($hotline->id, Email::first()->hotline_id);
    }
}
