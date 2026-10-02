<?php

namespace App\Console\Commands;

use App\Services\UpdateChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:check-updates')]
#[Description('Asks GitHub whether a newer release (or, on edge, newer commits) exists.')]
class CheckForUpdates extends Command
{
    public function handle(): int
    {
        $checker = UpdateChecker::forCurrentBuild();

        if (! $checker->isEnabled()) {
            $this->line(__('Update check is disabled for this build.'));

            return self::SUCCESS;
        }

        $update = $checker->refresh();

        if ($update === null) {
            $this->info(__('RadioRing is up to date.'));
        } elseif ($update['kind'] === 'release') {
            $this->info(__('Update available: :version', ['version' => $update['label']]));
        } else {
            $this->info(trans_choice('Update available: :count new commit|Update available: :count new commits', $update['behind']));
        }

        return self::SUCCESS;
    }
}
