<?php

namespace App\Console\Commands;

use App\Models\InviteCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invite:manage
    {--create : Create new invite codes}
    {--count=1 : How many codes to create}
    {--note= : Note attached to the code or codes}
    {--list : List every code}')]
#[Description('Creates and manages invite codes for registration')]
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

            $this->line("  {$invite->code}".($note ? "  ({$note})" : ''));
        }

        $this->info(__(':count invite code(s) created.', ['count' => $count]));
        $this->newLine();
    }

    private function listCodes(): void
    {
        $codes = InviteCode::with('usedBy')->latest()->get();

        if ($codes->isEmpty()) {
            $this->warn(__('No invite codes exist. Create one with --create.'));

            return;
        }

        $this->table(
            [__('Code'), __('Note'), __('Status'), __('Used by'), __('Used at')],
            $codes->map(fn (InviteCode $code) => [
                $code->code,
                $code->note ?? '-',
                $code->isUsed() ? __('spent') : __('free'),
                $code->usedBy?->email ?? '-',
                $code->used_at?->toDateTimeString() ?? '-',
            ])->all(),
        );
    }
}
