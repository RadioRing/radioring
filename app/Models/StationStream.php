<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'station_id',
    'server_id',
    'container_name',
    'status',
    'live_port',
    'live_password',
    'last_started_at',
])]
class StationStream extends Model
{
    protected function casts(): array
    {
        return [
            'live_port' => 'integer',
            'last_started_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Klartext Live-Passwort transparent ver-/entschlüsseln (gespeichert in live_password_enc).
     */
    protected function livePassword(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->live_password_enc ? Crypt::decryptString($this->live_password_enc) : null,
            set: fn (?string $value): array => ['live_password_enc' => $value ? Crypt::encryptString($value) : null],
        );
    }
}
