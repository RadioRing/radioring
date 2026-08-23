<?php

namespace App\Livewire\Admin;

use App\Models\InviteCode;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Einladungscodes')]
class InviteCodes extends Component
{
    #[Validate('nullable|string|max:120')]
    public string $note = '';

    #[Validate('required|integer|min:1|max:50')]
    public int $count = 1;

    public function generate(): void
    {
        $this->validate();

        $created = $this->count;

        for ($i = 0; $i < $created; $i++) {
            InviteCode::create([
                'code' => InviteCode::generateCode(),
                'note' => $this->note !== '' ? $this->note : null,
                'created_by' => auth()->id(),
            ]);
        }

        $this->reset('note');
        $this->count = 1;

        $this->dispatch('notify', message: __(':n Code(s) erzeugt.', ['n' => $created]), type: 'success');
    }

    public function deleteCode(int $codeId): void
    {
        $code = InviteCode::findOrFail($codeId);

        // Verbrauchte Codes bleiben als Nachweis erhalten.
        if ($code->isUsed()) {
            return;
        }

        $code->delete();

        $this->dispatch('notify', message: __('Code gelöscht.'), type: 'success');
    }

    public function render()
    {
        return view('livewire.admin.invite-codes', [
            'codes' => InviteCode::with(['creator', 'usedBy'])->latest()->get(),
        ])->layout('layouts.app');
    }
}
