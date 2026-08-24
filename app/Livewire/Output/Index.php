<?php

namespace App\Livewire\Output;

use App\Models\Station;
use App\Services\IcecastStatusService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Stream-Ausgänge')]
class Index extends Component
{
    #[Locked]
    public Station $station;

    public bool $showForm = false;

    /** Null = neuer Ausgang, sonst die ID des bearbeiteten Ausgangs. */
    public ?int $editingId = null;

    public string $type = 'lautfm';

    public string $host = '';

    public int $port = 8000;

    public string $mount = '';

    public string $username = 'source';

    public string $password = '';

    public int $bitrate = 128;

    public bool $enabled = true;

    public function mount(): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');
    }

    /**
     * For the internal server the user maintains neither host nor credentials: the script
     * generator derives them. What is left is the mount, which becomes the last part of
     * the public URL and therefore must not carry special characters.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        if ($this->type === 'internal') {
            return [
                'type' => 'required|in:icecast,lautfm,internal',
                'mount' => ['required', 'string', 'max:64', 'regex:/^\/?[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/'],
                'bitrate' => 'required|integer|in:64,96,128,192,256,320',
                'enabled' => 'boolean',
            ];
        }

        return [
            'type' => 'required|in:icecast,lautfm,internal',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'mount' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            // Beim Bearbeiten darf das Passwort leer bleiben (= unverändert)
            'password' => $this->editingId ? 'nullable|string|max:255' : 'required|string|max:255',
            'bitrate' => 'required|integer|in:64,96,128,192,256,320',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Does this installation offer the internal Icecast at all?
     */
    public function internalAvailable(): bool
    {
        return Station::internalStreamSupported();
    }

    /**
     * Prefill when switching to the internal server, so the user is not left with a
     * required field whose meaning does not matter to them here.
     */
    public function updatedType(string $value): void
    {
        if ($value === 'internal' && trim($this->mount) === '') {
            $this->mount = 'stream';
        }

        $this->resetValidation();
    }

    public function createNew(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $outputId): void
    {
        $output = $this->station->outputs()->findOrFail($outputId);

        $this->editingId = $output->id;
        $this->type = $output->type;
        $this->host = $output->host;
        $this->port = $output->port;
        $this->mount = $output->mount;
        $this->username = $output->username;
        $this->password = ''; // aus Sicherheitsgründen nie vorbefüllen
        $this->bitrate = $output->bitrate;
        $this->enabled = $output->enabled;
        $this->showForm = true;
    }

    public function save(): void
    {
        $validated = $this->validate();

        if ($validated['type'] === 'internal') {
            abort_unless(Station::internalStreamSupported(), 403, 'Kein interner Server verfügbar.');

            // Host, port and credentials are display values only: the script generator
            // fills in the container name it actually sends to.
            $data = [
                'type' => 'internal',
                'host' => $this->station->icecastContainerName(),
                'port' => 8000,
                'mount' => '/'.ltrim($validated['mount'], '/'),
                'username' => 'source',
                'bitrate' => $validated['bitrate'],
                'enabled' => $validated['enabled'],
            ];

            if ($this->editingId) {
                $this->station->outputs()->findOrFail($this->editingId)->update($data);
                $message = __('Output updated.');
            } else {
                $this->station->outputs()->create($data);
                $message = __('Output added.');
            }

            $this->resetForm();
            $this->dispatch('notify', message: $message.' '.__('Container neu starten, damit die Änderung greift.'), type: 'success');

            return;
        }

        $data = [
            'type' => $validated['type'],
            'host' => $validated['host'],
            'port' => $validated['port'],
            'mount' => $validated['mount'],
            'username' => $validated['username'],
            'bitrate' => $validated['bitrate'],
            'enabled' => $validated['enabled'],
        ];

        // Passwort nur überschreiben wenn ein neues angegeben wurde
        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        if ($this->editingId) {
            $this->station->outputs()->findOrFail($this->editingId)->update($data);
            $message = __('Ausgang aktualisiert.');
        } else {
            $this->station->outputs()->create($data);
            $message = __('Ausgang hinzugefügt.');
        }

        $this->resetForm();
        $this->dispatch('notify', message: $message.' '.__('Container neu starten, damit die Änderung greift.'), type: 'success');
    }

    public function toggle(int $outputId): void
    {
        $output = $this->station->outputs()->findOrFail($outputId);
        $output->update(['enabled' => ! $output->enabled]);

        $this->dispatch('notify', message: __('Status geändert. Container neu starten, damit die Änderung greift.'), type: 'success');
    }

    public function delete(int $outputId): void
    {
        $this->station->outputs()->findOrFail($outputId)->delete();

        $this->dispatch('notify', message: __('Ausgang gelöscht.'), type: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'host', 'mount', 'password', 'showForm');
        $this->type = 'lautfm';
        $this->port = 8000;
        $this->username = 'source';
        $this->bitrate = 128;
        $this->enabled = true;
        $this->resetValidation();
    }

    public function render(IcecastStatusService $icecast)
    {
        $outputs = $this->station->outputs()->orderByDesc('enabled')->latest()->get();

        return view('livewire.output.index', [
            'outputs' => $outputs,
            'internalAvailable' => $this->internalAvailable(),
            // Query once: all internal outputs share the same sidecar.
            'listeners' => $outputs->contains(fn ($output) => $output->isInternal() && $output->enabled)
                ? $icecast->listeners($this->station)
                : null,
        ])->layout('layouts.app');
    }
}
