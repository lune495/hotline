<?php
namespace App\Services;

use App\Models\Email;
use App\Models\Hotline;

class EmailReplies
{
    // Store canonical IDs without angle brackets; Symfony adds them on transmission.
    public static function ids(string $value): array
    {
        preg_match_all('/<?([^\s<>@,;]+@[^\s<>@,;]+)>?/', $value, $matches);
        return array_values(array_unique(array_filter($matches[1], fn ($id) => strlen($id) <= 998 && !preg_match('/[\x00-\x20\x7f]/', $id))));
    }

    private function addresses(?string $value): array
    {
        $result = [];
        foreach (explode(',', $value ?? '') as $address) {
            $address = trim($address);
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) { $result[] = $address; }
        }
        return $result;
    }

    public function context(Email $email, Hotline $hotline, string $mode): array
    {
        $seen = [strtolower($hotline->email) => true];
        $unique = function (array $addresses) use (&$seen) {
            $result = [];
            foreach ($addresses as $address) {
                $key = strtolower($address);
                if (!isset($seen[$key])) { $result[] = $address; $seen[$key] = true; }
            }
            return $result;
        };
        // Replying to our own sent message targets its recipients rather than ourselves.
        $own = strtolower(trim($email->mail_emetteur)) === strtolower($hotline->email);
        $targets = $own ? $this->addresses($email->mail_destinatire) : $this->addresses($email->reply_to ?: $email->mail_emetteur);
        $to = $unique($targets);
        if ($mode === 'reply_all') { $to = array_merge($to, $unique($this->addresses($email->mail_destinatire))); }
        $cc = $mode === 'reply_all' ? $unique($this->addresses($email->cc)) : [];
        return [
            'reply_to_email_id' => $email->id, 'reply_mode' => $mode,
            'to' => $to, 'cc' => $cc, 'bcc' => [],
            'objet' => preg_match('/^\s*re\s*:/i', $email->objet) ? $email->objet : 'Re: '.$email->objet,
            'contenu' => '', 'threading_available' => (bool) $email->message_id,
        ];
    }

    public function conversation(Email $email)
    {
        $messages = Email::where('hotline_id', $email->hotline_id)
            ->where(fn ($q) => $q->where('folder', 'inbox')->orWhere(fn ($q) => $q->where('folder', 'sent')->where('delivery_status', 'sent')))
            ->get(['id', 'message_id', 'in_reply_to', 'references', 'reply_to_email_id']);
        $members = [$email->id => true];
        $tokens = [];
        do {
            $changed = false;
            foreach ($messages as $message) {
                $ids = array_filter(array_merge([$message->message_id, $message->in_reply_to], $message->references ?? []));
                if (isset($members[$message->id]) || isset($members[$message->reply_to_email_id]) || array_intersect($ids, array_keys($tokens))) {
                    if (!isset($members[$message->id])) { $members[$message->id] = true; $changed = true; }
                    if ($message->reply_to_email_id && !isset($members[$message->reply_to_email_id])) { $members[$message->reply_to_email_id] = true; $changed = true; }
                    foreach ($ids as $id) { if (!isset($tokens[$id])) { $tokens[$id] = true; $changed = true; } }
                }
            }
        } while ($changed);
        return Email::with('attachments')->where('hotline_id', $email->hotline_id)->whereIn('id', array_keys($members))
            ->where(fn ($q) => $q->where('folder', 'inbox')->orWhere(fn ($q) => $q->where('folder', 'sent')->where('delivery_status', 'sent')))
            ->orderBy('created_at')->orderBy('id')->get();
    }
}
