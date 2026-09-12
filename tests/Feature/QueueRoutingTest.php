<?php

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Jobs\CreateBackupJob;
use App\Jobs\PrepareUpcomingHttpItemsJob;
use App\Jobs\StartStationContainer;
use Illuminate\Support\Facades\Queue;

it('keeps long running jobs off the default queue', function (object $job) {
    Queue::fake();

    dispatch($job);

    Queue::assertPushed($job::class, fn (object $pushed): bool => $pushed->connection === 'media');
})->with([
    'loudness analysis' => fn () => new AnalyzeMediaLoudnessJob(1),
    'backup' => fn () => new CreateBackupJob(1),
    'container start' => fn () => new StartStationContainer(1),
]);

it('leaves the jobs the programme depends on on the default queue', function () {
    Queue::fake();

    dispatch(new PrepareUpcomingHttpItemsJob);

    Queue::assertPushed(PrepareUpcomingHttpItemsJob::class, fn (object $pushed): bool => $pushed->connection === null);
});

it('runs the media queue on the same driver as the default connection', function () {
    expect(config('queue.connections.media.driver'))
        ->toBe(config('queue.connections.'.config('queue.default').'.driver'));
});

/**
 * `retry_after` below the runtime of a job means the queue hands the same job to the next
 * worker while the first one is still busy. CreateBackupJob is the longest of them.
 */
it('gives the media queue a retry window that outlasts its longest job', function () {
    expect(config('queue.connections.media.retry_after'))
        ->toBeGreaterThan((new CreateBackupJob(1))->timeout);
});
