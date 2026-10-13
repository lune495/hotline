<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\User;
use App\Models\Hotline;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailAuthenticationTest extends TestCase
{
    public function test_admin_configuration_hides_secrets_and_assigns_users(): void
    {
        $adminRole = \App\Models\Role::create(['nom' => 'admin']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role_id' => $adminRole->id]);
        $this->actingAs($admin, 'sanctum');
        $payload = ['nom' => 'Support', 'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl', 'email' => 'support@example.test', 'password' => 'imap-secret', 'folder' => 'INBOX', 'active' => true];
        $created = $this->postJson('/api/hotlines', $payload)->assertCreated()->assertJsonMissingPath('data.password');
        $id = $created->json('data.id');
        $this->assertNotSame('imap-secret', DB::table('hotlines')->where('id', $id)->value('password'));
        $this->assertSame('imap-secret', Hotline::find($id)->password);
        $payload['password'] = '';
        $this->putJson('/api/hotlines/'.$id, $payload)->assertOk();
        $this->assertSame('imap-secret', Hotline::find($id)->password);
        $this->postJson('/api/hotlines', array_merge($payload, ['password' => 'secret']))->assertUnprocessable();
        $userRole = \App\Models\Role::where('nom', 'user')->firstOrFail();
        $person = $this->postJson('/api/users', ['name' => 'Agent', 'email' => 'agent@example.test', 'password' => 'password', 'role_id' => $userRole->id, 'hotline_id' => $id])
            ->assertCreated()->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.hotline.password');
        $this->deleteJson('/api/hotlines/'.$id)->assertStatus(409);
        $this->actingAs(User::find($person->json('data.id')), 'sanctum');
        $this->getJson('/api/hotlines')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('hotline.id', $id)->assertJsonMissingPath('hotline.password');
    }

    public function test_shared_hotline_visibility_and_reassignment(): void
    {
        $settings = ['nom' => 'A', 'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl', 'email' => 'a@example.test', 'password' => 'secret', 'folder' => 'INBOX', 'active' => true];
        $a = Hotline::create($settings);
        $b = Hotline::create(array_merge($settings, ['nom' => 'B', 'email' => 'b@example.test']));
        $importer = User::create(['name' => 'Importer', 'email' => 'importer@example.test', 'password' => 'password', 'hotline_id' => $a->id]);
        $reader = User::create(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => 'password', 'hotline_id' => $a->id]);
        $email = Email::create(['user_id' => $importer->id, 'hotline_id' => $a->id, 'mail_destinatire' => $a->email, 'mail_emetteur' => 'sender@example.test', 'objet' => 'Shared', 'contenu' => 'Body']);
        $this->actingAs($reader, 'sanctum');
        $this->postJson('/graphql', ['query' => '{ emails { id } }'])->assertOk()->assertJsonPath('data.emails.0.id', (string) $email->id);
        $reader->update(['hotline_id' => $b->id]);
        $this->actingAs($reader->fresh(), 'sanctum');
        $this->postJson('/graphql', ['query' => '{ emails { id } }'])->assertOk()->assertJsonPath('data.emails', []);
        $b->update(['active' => false]);
        $this->actingAs($reader->fresh(), 'sanctum');
        $this->postJson('/api/synchro_mails')->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach ([
            '2014_10_12_000000_create_users_table.php',
            '2019_12_14_000001_create_personal_access_tokens_table.php',
            '2026_10_10_000001_create_roles_table.php',
            '2026_10_10_000003_add_nom_prenom_role_id_to_users_table.php',
            '2026_10_10_000002_create_emails_table.php',
            '2026_10_11_000001_add_imap_identity_to_emails_table.php',
            '2026_10_11_000002_add_user_id_to_emails_table.php',
            '2026_10_12_000001_create_hotlines_and_associations.php',
            '2026_10_13_000001_add_mail_attachments_and_smtp.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_login_graphql_ownership_and_logout(): void
    {
        $hotline = Hotline::create(['nom' => 'Test', 'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl', 'email' => 'mail@example.test', 'password' => 'secret', 'folder' => 'INBOX', 'active' => true]);
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password', 'hotline_id' => $hotline->id]);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password']);
        $mine = Email::create(['user_id' => $owner->id, 'hotline_id' => $hotline->id, 'mail_destinatire' => $owner->email, 'mail_emetteur' => 'sender@example.test', 'objet' => 'Mine', 'contenu' => 'Body']);
        $theirs = Email::create(['user_id' => $other->id, 'mail_destinatire' => $other->email, 'mail_emetteur' => 'sender@example.test', 'objet' => 'Other', 'contenu' => 'Body']);

        $this->postJson('/graphql', ['query' => '{ emails { id } }'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $owner->email, 'password' => 'wrong'])->assertUnauthorized();
        $login = $this->postJson('/api/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $login->assertJsonMissingPath('user.password');
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->postJson('/graphql', ['query' => '{ emails { id objet } }'], $headers)
            ->assertOk()->assertJsonCount(1, 'data.emails')->assertJsonPath('data.emails.0.id', (string) $mine->id);
        $this->postJson('/graphql', ['query' => 'query($id: Int) { emails(id: $id) { id } }', 'variables' => ['id' => $theirs->id]], $headers)
            ->assertOk()->assertJsonPath('data.emails', []);
        $owner->update(['hotline_id' => null]);
        auth()->forgetGuards();
        $this->postJson('/api/synchro_mails', [], $headers)->assertForbidden();
        $this->postJson('/api/logout', [], $headers)->assertOk();
        $this->assertSame(0, $owner->tokens()->count());
    }
}
