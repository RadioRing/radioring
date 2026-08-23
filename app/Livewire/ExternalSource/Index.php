<?php

namespace App\Livewire\ExternalSource;

use App\Models\ExternalSource;
use App\Models\Station;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Externe Quellen')]
class Index extends Component
{
    #[Locked]
    public Station $station;

    public bool $showForm = false;

    public ?int $editingId = null;

    // Formularfelder
    public string $name = '';

    public string $kind = 'url';

    public string $url = '';

    public ?int $expectedDuration = null;

    public int $prefetchLead = 180;

    public int $freshness = 0;

    public bool $normalize = true;

    public bool $trimLeadingSilence = false;

    public bool $fadeIn = false;

    // Syndications4Radio-Verbindung & Import
    public string $s4rTokenInput = '';

    public bool $showImport = false;

    public int $importStep = 1;

    /** @var array<int, array<string, mixed>> */
    public array $importShows = [];

    /** @var array<string, mixed>|null */
    public ?array $importSelectedShow = null;

    public string $importVariant = '';

    public ?string $importError = null;

    public function mount(): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:200',
            'kind' => 'required|in:url,news,weather,news_weather,syndication',
            'url' => 'nullable|required_if:kind,url|url|max:2048',
            'expectedDuration' => 'nullable|integer|min:1|max:86400',
            'prefetchLead' => 'required|integer|min:0|max:3600',
            'freshness' => 'required|integer|min:0|max:86400',
            'normalize' => 'boolean',
            'trimLeadingSilence' => 'boolean',
            'fadeIn' => 'boolean',
        ];
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $source = $this->station->externalSources()->findOrFail($id);

        $this->editingId = $source->id;
        $this->name = $source->name;
        $this->kind = $source->kind;
        $this->url = $source->url ?? '';
        $this->expectedDuration = $source->expected_duration_seconds;
        $this->prefetchLead = $source->prefetch_lead_seconds;
        $this->freshness = $source->freshness_seconds;
        $this->normalize = $source->normalize;
        $this->trimLeadingSilence = $source->trim_leading_silence;
        $this->fadeIn = $source->fade_in;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $attributes = [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'url' => $data['kind'] === 'url' ? $data['url'] : null,
            'expected_duration_seconds' => $data['expectedDuration'],
            'prefetch_lead_seconds' => $data['prefetchLead'],
            'freshness_seconds' => $data['freshness'],
            'normalize' => $data['normalize'],
            'trim_leading_silence' => $data['trimLeadingSilence'],
            'fade_in' => $data['fadeIn'],
        ];

        if ($this->editingId) {
            $this->station->externalSources()->findOrFail($this->editingId)->update($attributes);
            $message = __('Quelle aktualisiert.');
        } else {
            $this->station->externalSources()->create($attributes);
            $message = __('Quelle angelegt.');
        }

        $this->resetForm();
        $this->dispatch('notify', message: $message, type: 'success');
    }

    public function delete(int $id): void
    {
        $this->station->externalSources()->findOrFail($id)->delete();
        $this->dispatch('notify', message: __('Quelle gelöscht.'), type: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'kind', 'url', 'expectedDuration', 'prefetchLead', 'freshness', 'normalize', 'trimLeadingSilence', 'fadeIn');
        $this->resetValidation();
    }

    /**
     * Partner-Token speichern und damit die Station mit Syndications4Radio verknüpfen.
     */
    public function connectS4r(): void
    {
        $this->validate(
            ['s4rTokenInput' => 'required|string|min:10|max:200'],
            ['s4rTokenInput.required' => __('Bitte einen Token eingeben.')],
        );

        $this->station->update(['s4r_partner_token' => trim($this->s4rTokenInput)]);
        $this->reset('s4rTokenInput');
        $this->dispatch('notify', message: __('Mit Syndications4Radio verbunden.'), type: 'success');
    }

    public function disconnectS4r(): void
    {
        $this->station->update(['s4r_partner_token' => null]);
        $this->reset('showImport', 'importStep', 'importShows', 'importSelectedShow', 'importVariant', 'importError');
        $this->dispatch('notify', message: __('Verbindung zu Syndications4Radio getrennt.'), type: 'success');
    }

    /**
     * Schritt 1 des Imports: genehmigte Sendungen von S4R laden.
     */
    public function startImport(): void
    {
        $client = $this->station->s4rClient();

        if (! $client) {
            return;
        }

        $this->reset('importStep', 'importSelectedShow', 'importVariant', 'importError');
        $this->importStep = 1;

        try {
            $this->importShows = $client->shows();
        } catch (RequestException $e) {
            $this->importShows = [];
            $this->importError = $e->response->status() === 401
                ? __('Token ungültig oder abgelaufen. Bitte neu verbinden.')
                : __('Sendungen konnten nicht geladen werden (HTTP :status).', ['status' => $e->response->status()]);
        } catch (\Throwable) {
            $this->importShows = [];
            $this->importError = __('Syndications4Radio ist nicht erreichbar.');
        }

        $this->showImport = true;
    }

    /**
     * Schritt 2 des Imports: eine Sendung wählen und die Variante vorbelegen.
     */
    public function selectImportShow(int $sendungId): void
    {
        $show = collect($this->importShows)->firstWhere('id', $sendungId);

        if (! $show) {
            return;
        }

        $variants = $show['available_variants'] ?? [];

        $this->importSelectedShow = $show;
        // laut.fm-Variante bevorzugen, wenn es eine laut.fm-Sendung ist und sie existiert.
        $this->importVariant = (($show['is_lautfm'] ?? false) && in_array('lfm', $variants, true))
            ? 'lfm'
            : ($variants[0] ?? '');
        $this->importStep = 2;
    }

    public function backToShowList(): void
    {
        $this->importStep = 1;
        $this->importSelectedShow = null;
        $this->importVariant = '';
    }

    /**
     * Schritt 3: die Dateien der gewählten Sendung+Variante als externe Quellen anlegen –
     * je Datei eine eigene Quelle, an den Dateinamen gepinnt.
     */
    public function importShow(): void
    {
        if (! $this->importSelectedShow) {
            return;
        }

        $show = $this->importSelectedShow;
        $variants = $show['available_variants'] ?? [];

        if (! in_array($this->importVariant, $variants, true)) {
            $this->importError = __('Bitte eine verfügbare Variante wählen.');

            return;
        }

        $client = $this->station->s4rClient();

        if (! $client) {
            return;
        }

        try {
            $files = $client->files($show['id'], $this->importVariant);
        } catch (RequestException $e) {
            $this->importError = $e->response->status() === 401
                ? __('Token ungültig oder abgelaufen. Bitte neu verbinden.')
                : __('Dateien konnten nicht geladen werden (HTTP :status).', ['status' => $e->response->status()]);

            return;
        } catch (\Throwable) {
            $this->importError = __('Syndications4Radio ist nicht erreichbar.');

            return;
        }

        if (empty($files)) {
            $this->importError = __('Für diese Variante sind keine Dateien verfügbar.');

            return;
        }

        $variantLabel = $this->importVariant === 'lfm' ? 'laut.fm' : 'Standard';
        $multiple = count($files) > 1;

        foreach (array_values($files) as $index => $file) {
            // Bei mehreren Dateien jede einzeln benennen (sprechender Titel der API,
            // sonst „Teil n"); bei genau einer Datei reicht der Sendungsname.
            $baseName = $multiple
                ? (($file['title'] ?? '') !== '' ? $file['title'] : $show['name'].' – '.__('Teil :n', ['n' => $index + 1]))
                : $show['name'];

            $this->station->externalSources()->create([
                'name' => $baseName.' ('.$variantLabel.')',
                'kind' => 'syndication',
                'syndication_sendung_id' => $show['id'],
                'syndication_variant' => $this->importVariant,
                'syndication_filename' => $file['filename'] ?? null,
                // Echte Dateilänge aus der API (per getID3 gemessen) – nicht die gebuchte
                // Sendungsdauer, die bei mehreren Dateien irreführend wäre.
                'expected_duration_seconds' => ! empty($file['duration']) ? (int) $file['duration'] : null,
                // Syndications rechtzeitig vorbereiten (signierte URL gilt 60 min).
                'prefetch_lead_seconds' => 1800,
                'freshness_seconds' => 0,
                'normalize' => true,
            ]);
        }

        $count = count($files);
        $this->reset('showImport', 'importStep', 'importShows', 'importSelectedShow', 'importVariant', 'importError');
        $this->dispatch('notify', message: trans_choice('{1}Syndication „:name" importiert.|[2,*]:count Dateien von „:name" importiert.', $count, ['name' => $show['name'], 'count' => $count]), type: 'success');
    }

    public function cancelImport(): void
    {
        $this->reset('showImport', 'importStep', 'importShows', 'importSelectedShow', 'importVariant', 'importError');
    }

    /**
     * Holt die aktuelle Dateilänge einer Syndication-Quelle frisch von S4R und
     * schreibt sie in expected_duration_seconds (für Rundown-/Playlist-Planung).
     */
    public function refreshSyndicationDuration(int $id): void
    {
        $source = $this->station->externalSources()->where('kind', 'syndication')->findOrFail($id);

        $client = $this->station->s4rClient();

        if (! $client) {
            $this->dispatch('notify', message: __('Keine Verbindung zu Syndications4Radio.'), type: 'error');

            return;
        }

        try {
            $files = $client->files($source->syndication_sendung_id, $source->syndication_variant);
        } catch (RequestException $e) {
            $this->dispatch('notify', message: $e->response->status() === 401
                ? __('Token ungültig oder abgelaufen. Bitte neu verbinden.')
                : __('Aktualisierung fehlgeschlagen (HTTP :status).', ['status' => $e->response->status()]), type: 'error');

            return;
        } catch (\Throwable) {
            $this->dispatch('notify', message: __('Syndications4Radio ist nicht erreichbar.'), type: 'error');

            return;
        }

        $file = collect($files)->firstWhere('filename', $source->syndication_filename);

        if (! $file) {
            $this->dispatch('notify', message: __('Die Datei „:name" ist bei S4R nicht mehr vorhanden.', ['name' => $source->syndication_filename]), type: 'error');

            return;
        }

        $duration = ! empty($file['duration']) ? (int) $file['duration'] : null;
        $source->update(['expected_duration_seconds' => $duration]);

        $this->dispatch('notify', message: $duration !== null
            ? __('Länge aktualisiert: :time.', ['time' => sprintf('%d:%02d', intdiv($duration, 60), $duration % 60)])
            : __('Aktualisiert – für diese Datei ist keine Länge verfügbar.'), type: 'success');
    }

    /**
     * @return Collection<int, ExternalSource>
     */
    public function sources()
    {
        return $this->station->externalSources()
            ->withCount('playlistItems')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.external-source.index', [
            'sources' => $this->sources(),
        ])->layout('layouts.app');
    }
}
