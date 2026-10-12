<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotline extends Model
{
    protected $fillable = ['nom', 'host', 'port', 'encryption', 'email', 'password', 'folder', 'active'];
    protected $hidden = ['password'];
    protected $casts = ['password' => 'encrypted', 'port' => 'integer', 'active' => 'boolean'];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function emails(): HasMany { return $this->hasMany(Email::class); }
}
