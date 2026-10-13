<?php

namespace App\Services;

use App\Models\Hotline;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class OutgoingMail
{
    public function send(Hotline $hotline, array $data, array $files): void
    {
        config(['mail.mailers.hotline' => [
            'transport' => 'smtp', 'host' => $hotline->smtp_host,
            'port' => $hotline->smtp_port, 'encryption' => $hotline->smtp_encryption,
            'username' => $hotline->smtp_username ?: $hotline->email,
            'password' => $hotline->smtp_password ?: $hotline->password, 'timeout' => 30,
        ]]);
        Mail::purge('hotline');
        try {
            Mail::mailer('hotline')->raw($data['contenu'], function (Message $message) use ($hotline, $data, $files) {
                $message->from($hotline->email, $hotline->nom)->to($data['to'])->subject($data['objet']);
                if (! empty($data['cc'])) {
                    $message->cc($data['cc']);
                }
                if (! empty($data['bcc'])) {
                    $message->bcc($data['bcc']);
                }
                foreach ($files as $file) {
                    $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    $message->attach($file->getRealPath(), ['as' => $name, 'mime' => $file->getMimeType()]);
                }
            });
        } finally {
            Mail::purge('hotline');
            config(['mail.mailers.hotline' => null]);
        }
    }
}
