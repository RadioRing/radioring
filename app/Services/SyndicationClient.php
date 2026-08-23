<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client für die Partner-API von Syndications4Radio. Stationsgebunden über den
 * jeweiligen Partner-Token; die Basis-URL ist für alle Stationen gleich (eine Instanz).
 */
class SyndicationClient
{
    public function __construct(
        private string $token,
        private string $baseUrl,
    ) {}

    /**
     * Genehmigte, abrufbare Sendungen der Station.
     *
     * @return array<int, array{id: int, name: string, description: ?string, genre: ?string, language: ?string, duration: ?int, frequency: ?string, is_lautfm: bool, available_variants: array<int, string>}>
     *
     * @throws RequestException
     */
    public function shows(): array
    {
        return $this->request('/shows')->json('data', []);
    }

    /**
     * Signierte Download-URLs einer Sendung. `variant` = lfm|normal; ohne Angabe wählt
     * die API anhand des laut.fm-Flags der Sendung.
     *
     * @return array<int, array{title: string, filename: string, url: string, expires_at: string}>
     *
     * @throws RequestException
     */
    public function files(int $sendungId, ?string $variant = null): array
    {
        $query = $variant !== null && $variant !== '' ? ['variant' => $variant] : [];

        return $this->request("/shows/{$sendungId}/files", $query)->json('files', []);
    }

    /**
     * Frische signierte URL einer bestimmten Datei (per Dateiname) einer Sendung – für
     * die Auflösung kurz vor Ausspielung. Ohne (oder bei nicht mehr vorhandenem)
     * Dateinamen wird auf die erste Datei zurückgefallen.
     *
     * @throws RequestException
     */
    public function signedUrlForFile(int $sendungId, ?string $variant, ?string $filename): ?string
    {
        $files = $this->files($sendungId, $variant);

        if ($filename !== null && $filename !== '') {
            foreach ($files as $file) {
                if (($file['filename'] ?? null) === $filename) {
                    return $file['url'] ?? null;
                }
            }
        }

        return $files[0]['url'] ?? null;
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws RequestException
     */
    private function request(string $path, array $query = []): Response
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->get($this->baseUrl.$path, $query)
            ->throw();
    }
}
