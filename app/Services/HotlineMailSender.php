<?php
namespace App\Services;

use App\Models\Hotline;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class HotlineMailSender
{
    public function send(Hotline $hotline, array $data): void
    {
        $transport = new EsmtpTransport($hotline->smtp_host, $hotline->smtp_port, $hotline->smtp_encryption === 'ssl');
        $transport->setUsername($hotline->email);
        $transport->setPassword($hotline->password);
        $transport->getStream()->setTimeout(30);
        $message = (new Email())
            ->from(new \Symfony\Component\Mime\Address($hotline->email, $hotline->nom))
            ->to(...$data['to'])
            ->subject($data['objet'])
            ->text($data['contenu']);
        if (!empty($data['cc'])) { $message->cc(...$data['cc']); }
        if (!empty($data['bcc'])) { $message->bcc(...$data['bcc']); }
        try {
            $transport->send($message);
        } finally {
            $transport->stop();
        }
    }
}
