<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Support\MailHeader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\ClientManager;

class EmailController extends Controller
{
    public function synchro_mails(ClientManager $manager): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Authentification nécessaire.'], 401);
        }
        $hotline = $user->hotline;
        if (!$hotline || !$hotline->active) {
            return response()->json(['message' => 'Aucune hotline active affectée à votre compte.'], 403);
        }
        $config = [
            'host' => $hotline->host, 'port' => $hotline->port,
            'encryption' => $hotline->encryption, 'username' => $hotline->email,
            'password' => $hotline->password, 'folder' => $hotline->folder,
        ];

        $client = $manager->make(array_merge($config, [
            'protocol' => 'imap',
            'validate_cert' => true,
            'timeout' => 30,
        ]));

        try {
            $client->connect();
            $folder = $client->getFolderByPath($config['folder']);
            if ($folder === null) {
                throw new RuntimeException('Dossier IMAP introuvable.');
            }
            $status = $folder->status();
            $validity = $status['uidvalidity'] ?? $status['UIDVALIDITY'] ?? null;
            if ($validity === null) {
                throw new RuntimeException('UIDVALIDITY absent.');
            }
            $mailboxKey = hash('sha256', implode('|', [$config['host'], $config['port'], $config['username'], $config['folder']]));
            $messages = $folder->query()->all()->setFetchOrder('desc')->limit(5)->leaveUnread()->get();
            $rows = [];
            foreach ($messages as $message) {
                $rows[] = [
                    'user_id' => $user->id,
                    'hotline_id' => $hotline->id,
                    'uid' => $message->getUid(),
                    'uid_validity' => $validity,
                    'mailbox_key' => $mailboxKey,
                    'mail_destinatire' => $this->addresses($message->getTo()) ?: $config['username'],
                    'mail_emetteur' => $this->addresses($message->getFrom()),
                    'objet' => MailHeader::decode((string) $message->getSubject()),
                    'contenu' => $message->getTextBody() ?: $message->getHTMLBody(),
                    'cc' => $this->addresses($message->getCc()) ?: null,
                ];
            }

            $emails = DB::transaction(function () use ($rows) {
                return collect($rows)->map(function ($row) {
                    $identity = array_intersect_key($row, array_flip(['hotline_id', 'uid', 'uid_validity', 'mailbox_key']));
                    // Preserve the original importer and any response entered in the application.
                    $email = Email::firstOrCreate($identity, $row);
                    unset($row['user_id']);
                    $email->fill($row)->save();
                    return $email;
                });
            });

            return response()->json([
                'message' => 'Synchronisation terminée.',
                'nombre' => $emails->count(),
                'emails' => $emails->map(fn (Email $email) => $email->makeHidden(['mailbox_key', 'uid_validity'])),
            ]);
        } catch (Throwable $exception) {
            if (app()->runningUnitTests()) {
                throw $exception;
            }
            // Never expose credentials or raw IMAP exchanges in the response.
            return response()->json(['message' => 'La synchronisation a échoué. Vérifier la connexion IMAP et les migrations.'], 502);
        } finally {
            $client->disconnect();
        }
    }

    private function addresses(Attribute $addresses): string
    {
        return implode(', ', array_map(fn ($address) => $address->mail, $addresses->toArray()));
    }
}
