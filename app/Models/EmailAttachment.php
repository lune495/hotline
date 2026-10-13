<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAttachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'source_key'];

    public function email()
    {
        return $this->belongsTo(Email::class);
    }
}
