<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Email extends Model
{
    use HasFactory;

    protected $fillable = [
        'folder',
        'delivery_status',
        'bcc',
        'sent_at',
        'user_id',
        'hotline_id',
        'uid',
        'uid_validity',
        'mailbox_key',
        'mail_destinatire',
        'mail_emetteur',
        'objet',
        'contenu',
        'reponse',
        'cc',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function attachments() { return $this->hasMany(EmailAttachment::class); }

    public function hotline(): BelongsTo
    {
        return $this->belongsTo(Hotline::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
