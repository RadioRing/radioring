<?php

namespace App\Console\Commands;

use App\Models\InviteCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invite:manage
    {--create : Neue Einladungscodes erzeugen}
    {--count=1 : Anzahl der zu erzeugenden Codes}
    {--note= : Notiz, die an den/die Codes gehängt wird}
    {--list : Alle Codes auflisten}')]
#[Description('Erzeugt und verwaltet Einladungscodes für die Registrierung')]
class InviteCodeManage extends Command
{
    public function handle(): int
    {
        if ($this->option('create')) {
            $this->createCodes((int) $this->option('count'), $this->option('note'));
        }

        if ($this->option('list') || ! $this->option('create')) {
            $this->listCodes();
        }

        return self::SUCCESS;
    }

    private function createCodes(int $count, ?string $note): void
    {
        $count = max(1, $count);

        for ($i = 0; $i < $count; $i++) {
            $invite = InviteCode::create([
                'code' => InviteCode::generateCode(),
                'note' => $note,
            ]);

            $this->line("  → {$invite->code}".($note ? "  ({$note})" : ''));
        }

        $this->info("{$count} Einladungscode(s) erzeugt.");
        $this->newLine();
    }

    private function listCodes(): void
    {
        $codes = InviteCode::with('usedBy')->latest()->get();

        if ($codes->isEmpty()) {
            $this->warn('Keine Einladungscodes vorhanden. Mit --create erzeugen.');

            return;
        }

        $this->table(
            ['Code', 'Notiz', 'Status', 'Verwendet von', 'Verwendet am'],
            $codes->map(fn (InviteCode $code) => [
                $code->code,
                $code->note ?? '–',
                $code->isUsed() ? 'verbraucht' : 'frei',
                $code->usedBy?->email ?? '–',
                $code->used_at?->toDateTimeString() ?? '–',
            ])->all(),
        );
    }
}
