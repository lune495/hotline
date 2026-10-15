<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Services\OutgoingMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MailDeliveryController extends Controller
{
    public function send(Request $request, OutgoingMail $sender)
    {
        $hotline = $request->user()->hotline;
        abort_unless($hotline && $hotline->active, 403, 'Aucune hotline active affectée à votre compte.');
        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:50'], 'to.*' => ['required', 'email', 'max:254'],
            'cc' => ['sometimes', 'array', 'max:50'], 'cc.*' => ['required', 'email', 'max:254'],
            'bcc' => ['sometimes', 'array', 'max:50'], 'bcc.*' => ['required', 'email', 'max:254'],
            'objet' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'contenu' => ['required', 'string', 'max:1000000'],
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,bmp,tif,tiff,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,odt,ods,odp,zip'],
        ]);
        if (count($data['to']) + count($data['cc'] ?? []) + count($data['bcc'] ?? []) > 50) {
            throw ValidationException::withMessages(['to' => 'Maximum 50 destinataires au total.']);
        }
        $files = $request->file('attachments', []);
        if (array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'Maximum 20 Mo de pièces jointes au total.']);
        }
        if (! $hotline->smtp_host) {
            return response()->json(['message' => 'Configurer le serveur SMTP de cette hotline avant l’envoi.'], 422);
        }
        // Save the message and files before SMTP: a storage failure must prevent sending.
        $paths = [];
        try {
            $email = DB::transaction(function () use ($request, $hotline, $data, $files, &$paths) {
                $email = Email::create([
                    'hotline_id' => $hotline->id, 'user_id' => $request->user()->id,
                    'folder' => 'sent', 'delivery_status' => 'pending',
                    'mail_emetteur' => $hotline->email,
                    'mail_destinatire' => implode(', ', $data['to']),
                    'cc' => empty($data['cc']) ? null : implode(', ', $data['cc']),
                    'bcc' => empty($data['bcc']) ? null : implode(', ', $data['bcc']),
                    'objet' => $data['objet'], 'contenu' => $data['contenu'],
                ]);
                foreach ($files as $index => $file) {
                    $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?: 'piece-jointe', 0, 200);
                    $key = hash('sha256', $index.'|'.$name.'|'.hash_file('sha256', $file->getRealPath()));
                    $path = 'mail-attachments/'.$email->id.'/'.$key;
                    $paths[] = $path;
                    if (!Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
                        throw new \RuntimeException('Stockage impossible.');
                    }
                    $email->attachments()->create([
                        'source_key' => $key, 'name' => $name, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => $file->getSize(), 'path' => $path,
                    ]);
                }
                return $email;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            return response()->json(['message' => 'Sauvegarde impossible. Le message n’a pas été envoyé.'], 503);
        }
        try {
            $sender->send($hotline, $data, $files);
        } catch (\Throwable $e) {
            // SMTP errors can occur after remote acceptance; do not automatically retry.
            try { $email->update(['delivery_status' => 'unconfirmed']); } catch (\Throwable $ignored) {}
            return response()->json(['message' => 'Envoi non confirmé par le serveur SMTP. Vérifiez avant de réessayer pour éviter un doublon.'], 502);
        }

        try {
            $email->update(['delivery_status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Message accepté par SMTP, mais confirmation en base impossible. Ne renvoyez pas ce message.', 'status' => 'accepted', 'email_id' => $email->id, 'archive_confirmed' => false]);
        }
        return response()->json(['message' => 'Message accepté par le serveur SMTP et sauvegardé.', 'status' => 'accepted', 'archive_confirmed' => true, 'email' => $email->load('attachments')]);
    }

    public function sent(Request $request)
    {
        $hotline = $request->user()->hotline;
        abort_unless($hotline && $hotline->active, 403, 'Aucune hotline active affectée à votre compte.');
        $data = $request->validate(['id' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = Email::with('attachments')->where('hotline_id', $hotline->id)->where('folder', 'sent')->where('delivery_status', 'sent');
        if (isset($data['id'])) { $query->whereKey($data['id']); }
        return response()->json($query->orderByDesc('sent_at')->orderByDesc('id')->paginate(20));
    }

    private function authorizeEmail(Request $request, Email $email): void
    {
        $hotline = $request->user()->hotline;
        abort_unless($hotline && $hotline->active && (int) $email->hotline_id === (int) $hotline->id, 404);
    }

    public function attachments(Request $request, Email $email)
    {
        $this->authorizeEmail($request, $email);

        return response()->json(['data' => $email->attachments]);
    }

    public function download(Request $request, EmailAttachment $attachment)
    {
        $this->authorizeEmail($request, $attachment->email);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
