<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Services\OutgoingMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        try {
            $sender->send($hotline, $data, $files);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Envoi non confirmé par le serveur SMTP. Vérifiez avant de réessayer pour éviter un doublon.'], 502);
        }

        return response()->json(['message' => 'Message accepté par le serveur SMTP.']);
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
