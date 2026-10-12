<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Email extends Model
{
    use HasFactory;

    protected $fillable = [
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

    public function hotline(): BelongsTo
    {
        return $this->belongsTo(Hotline::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
