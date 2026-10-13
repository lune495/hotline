<?php

namespace App\Services;

use App\Models\Email;
use Illuminate\Support\Facades\Storage;

class IncomingAttachments
{
    public function store(Email $email, iterable $attachments): void
    {
        foreach ($attachments as $index => $attachment) {
            $content = $attachment->getContent();
            $name = basename(str_replace('\\', '/', $attachment->getName() ?: 'piece-jointe'));
            $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?: 'piece-jointe', 0, 200);
            $key = hash('sha256', $index.'|'.$name.'|'.hash('sha256', $content));
            $path = 'mail-attachments/'.$email->id.'/'.$key;
            if (! Storage::disk('local')->put($path, $content)) {
                throw new \RuntimeException('Impossible de stocker la pièce jointe.');
            }
            $email->attachments()->updateOrCreate(['source_key' => $key], [
                'name' => $name, 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream',
                'size' => strlen($content), 'path' => $path,
            ]);
        }
    }
}
