<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'station_id',
    'type',
    'host',
    'port',
    'mount',
    'username',
    'password',
    'bitrate',
    'enabled',
])]
class StationOutput extends Model
{
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'bitrate' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Der reine Mount-Name, ohne fuehrenden Slash und ohne Query.
     *
     * Der Mount darf Icecast-Parameter tragen (laut.fm nutzt z. B. "/station?prio=3"),
     * die fuer den ausgehenden Stream wichtig sind. Als Benutzername gegenueber der
     * RadioAdmin-API waeren sie aber falsch, und ein unkodiertes "?" wuerde dort sogar
     * den Authority-Teil der URL beenden: der Request ginge an den falschen Host und
     * Passwort samt Zielhost landeten im Query-String.
     */
    public function mountName(): string
    {
        $mount = ltrim((string) $this->mount, '/');

        foreach (['?', '#'] as $delimiter) {
            if (($position = strpos($mount, $delimiter)) !== false) {
                $mount = substr($mount, 0, $position);
            }
        }

        return $mount;
    }

    /**
     * Does this output send to the station's own Icecast sidecar?
     *
     * Host, port and password are not user-maintained for those: the script generator
     * derives them from config and station_streams.
     */
    public function isInternal(): bool
    {
        return $this->type === 'internal';
    }

    /**
     * Klartext-Passwort transparent ver-/entschlüsseln (gespeichert in password_enc).
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->password_enc ? Crypt::decryptString($this->password_enc) : null,
            set: fn (?string $value): array => ['password_enc' => $value ? Crypt::encryptString($value) : null],
        );
    }
}
