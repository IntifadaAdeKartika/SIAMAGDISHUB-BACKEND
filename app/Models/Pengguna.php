<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Pengguna extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'pengguna';

    protected $fillable = [
        'username',
        'nama_lengkap',
        'password',
        'role',
        'no_hp',
        'status',
        'dibuat_oleh',
        'terakhir_login',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'terakhir_login' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPesertaMagang(): bool
    {
        return $this->role === 'peserta_magang';
    }

    public function isPembimbing(): bool
    {
        return $this->role === 'pembimbing';
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'dibuat_oleh');
    }

    public function penggunaDibuat(): HasMany
    {
        return $this->hasMany(Pengguna::class, 'dibuat_oleh');
    }

    public function pembimbing(): HasOne
    {
        return $this->hasOne(Pembimbing::class, 'id_pengguna');
    }

    public function pesertaMagang(): HasOne
    {
        return $this->hasOne(PesertaMagang::class, 'id_pengguna');
    }

    public function peserta_magang(): HasOne
    {
        return $this->hasOne(PesertaMagang::class, 'id_pengguna');
    }

    public function notifikasi(): HasMany
    {
        return $this->hasMany(Notifikasi::class, 'id_pengguna');
    }

    public function logAktivitas(): HasMany
    {
        return $this->hasMany(LogAktivitas::class, 'id_pengguna');
    }
}
