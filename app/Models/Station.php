<?php

namespace App\Models;

use App\Services\SyndicationClient;
use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'tenant_id', 'name', 'slug', 'status', 'api_token', 's4r_partner_token', 'regenerate_rundowns_nightly', 'stereo_tool_license_key', 'stereo_tool_preset'])]
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'regenerate_rundowns_nightly' => 'boolean',
            // Geteiltes Geheimnis mit dem Station-Container: der braucht den Klartext als
            // Env-Variable, hashen scheidet daher aus. Nachgeschlagen wird nie ueber den
            // Token, sondern immer ueber den Slug, danach hash_equals - die nicht
            // deterministische Verschluesselung stoert also keine Abfrage.
            'api_token' => 'encrypted',
            's4r_partner_token' => 'encrypted',
            'stereo_tool_enabled' => 'boolean',
            'stereo_tool_license_key' => 'encrypted',
        ];
    }

    /**
     * Ist Stereo Tool für diese Station betriebsbereit? Freigeschaltet (Admin) UND
     * vom Betreiber vollständig konfiguriert (Lizenz + Preset). Ohne Lizenz liefe
     * Stereo Tool im Demo-Modus mit periodischen Aussetzern – dann lieber weglassen.
     */
    public function stereoToolActive(): bool
    {
        return $this->stereo_tool_enabled
            && filled($this->stereo_tool_license_key)
            && filled($this->stereo_tool_preset);
    }

    /** Ist diese Station mit Syndications4Radio verknüpft (Partner-Token hinterlegt)? */
    public function hasSyndicationConnection(): bool
    {
        return filled($this->s4r_partner_token);
    }

    /**
     * Client für die S4R-Partner-API mit dem Token dieser Station – oder null,
     * wenn keine Verknüpfung besteht.
     */
    public function s4rClient(): ?SyndicationClient
    {
        if (! $this->hasSyndicationConnection()) {
            return null;
        }

        return new SyndicationClient(
            $this->s4r_partner_token,
            rtrim((string) config('services.syndications4radio.base_url'), '/'),
        );
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The tenant this station belongs to. Owns the shared media library.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Alle Nutzer mit Zugriff auf diese Station (inkl. Besitzer), mit ihrer Rolle.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'station_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Die Rolle eines Nutzers an dieser Station oder null, wenn er keinen Zugriff hat.
     */
    public function roleFor(User $user): ?string
    {
        return $this->members()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot->role;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Darf der Nutzer die Station verwalten (Einstellungen, Team, Löschen)?
     * Nur der Besitzer.
     */
    public function canBeManagedBy(User $user): bool
    {
        return $this->isOwnedBy($user);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    /**
     * The media library this station draws from. It belongs to the tenant, not the
     * station, so every station of a tenant sees the same files. No linking involved.
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'tenant_id', 'tenant_id');
    }

    public function externalSources(): HasMany
    {
        return $this->hasMany(ExternalSource::class);
    }

    /**
     * Every media file of the owning tenant. All stations of a tenant share one library,
     * so this is a plain tenant scope. No per-station linking involved.
     *
     * @return Builder<MediaFile>
     */
    public function poolMediaFiles(): Builder
    {
        return MediaFile::query()->where('tenant_id', $this->tenant_id);
    }

    /**
     * May this station use the given media file? True when it belongs to the same tenant.
     */
    public function canUseMedia(MediaFile $file): bool
    {
        return $file->tenant_id === $this->tenant_id;
    }

    /**
     * Tags are tenant-wide too, so one file can be tagged consistently across every
     * station of the tenant.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'tenant_id', 'tenant_id');
    }

    public function hourGridSlots(): HasMany
    {
        return $this->hasMany(HourGridSlot::class);
    }

    public function generatedPlaylists(): HasMany
    {
        return $this->hasMany(GeneratedPlaylist::class);
    }

    public function liquidsoapState(): HasOne
    {
        return $this->hasOne(LiquidsoapState::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(StationOutput::class);
    }

    /**
     * Der aktive laut.fm-Ausgang dieser Station (liefert die Credentials für die
     * Nachrichten-/Wetter-API). Null, wenn die Station kein laut.fm-Ausgang ist.
     */
    public function lautfmOutput(): ?StationOutput
    {
        return $this->outputs()
            ->where('type', 'lautfm')
            ->where('enabled', true)
            ->first();
    }

    public function stream(): HasOne
    {
        return $this->hasOne(StationStream::class);
    }

    /**
     * Stellt sicher, dass der Stream-Datensatz inkl. Live-Harbor-Zugangsdaten existiert.
     * Jede Station bekommt einen eindeutigen TCP-Port aus dem konfigurierten Bereich
     * (auch Alt-Datensätze mit doppeltem/ungültigem Port werden umgezogen).
     */
    public function ensureStream(): StationStream
    {
        $stream = $this->stream()->firstOrCreate(
            ['station_id' => $this->id],
            ['container_name' => 'radioring-'.$this->slug, 'status' => 'stopped'],
        );

        $changed = false;

        if (! $this->streamPortIsValid($stream->live_port)) {
            $stream->live_port = $this->allocateStreamPort();
            $changed = true;
        }

        if (! $stream->live_password) {
            $stream->live_password = Str::random(24);
            $changed = true;
        }

        if ($changed) {
            $stream->save();
        }

        return $stream;
    }

    /**
     * Gültig = im Bereich UND von keiner anderen Station belegt.
     */
    private function streamPortIsValid(?int $port): bool
    {
        $min = (int) config('radioring.stream.port_min', 8001);
        $max = (int) config('radioring.stream.port_max', 8099);

        if ($port === null || $port < $min || $port > $max) {
            return false;
        }

        return ! StationStream::where('live_port', $port)
            ->where('station_id', '!=', $this->id)
            ->exists();
    }

    /**
     * Niedrigster freier Port im konfigurierten Bereich.
     */
    private function allocateStreamPort(): int
    {
        $min = (int) config('radioring.stream.port_min', 8001);
        $max = (int) config('radioring.stream.port_max', 8099);

        $used = array_flip(
            StationStream::where('station_id', '!=', $this->id)
                ->whereNotNull('live_port')
                ->pluck('live_port')
                ->all()
        );

        for ($port = $min; $port <= $max; $port++) {
            if (! isset($used[$port])) {
                return $port;
            }
        }

        throw new \RuntimeException("Keine freien Stream-Ports im Bereich {$min}-{$max}.");
    }

    /**
     * Öffentlicher Hostname für die Live-Übernahme ({slug}.{domain}) oder null,
     * wenn keine Stream-Domain konfiguriert ist.
     */
    public function streamHost(): ?string
    {
        $domain = (string) config('radioring.stream.domain');

        return $domain !== '' ? $this->slug.'.'.$domain : null;
    }

    /**
     * Verbindungsdaten für den Live-Eingang (Anzeige im Portal) oder null,
     * wenn keine Stream-Domain konfiguriert ist.
     *
     * @return array{host: string, port: ?int, mount: string, username: string, password: ?string, tls: bool}|null
     */
    public function liveStreamCredentials(): ?array
    {
        $host = $this->streamHost();

        if ($host === null) {
            return null;
        }

        $stream = $this->ensureStream();

        return [
            'host' => $host,
            'port' => $stream->live_port,
            'mount' => (string) config('radioring.stream.mount', '/live'),
            'username' => (string) config('radioring.stream.username', 'source'),
            'password' => $stream->live_password,
            // Klartext-Ingest (eigener Port pro Station, kein TLS).
            'tls' => false,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Station $station) {
            if (empty($station->slug)) {
                $station->slug = Str::slug($station->name);
            }

            // A station must always live in a tenant, so fall back to the owner's home tenant.
            if (empty($station->tenant_id) && $station->user_id) {
                $station->tenant_id = User::find($station->user_id)?->tenant_id;
            }

            if (empty($station->api_token)) {
                $station->api_token = Str::random(64);
            }
        });

        static::created(function (Station $station) {
            $station->members()->syncWithoutDetaching([
                $station->user_id => ['role' => 'owner'],
            ]);
        });
    }
}
