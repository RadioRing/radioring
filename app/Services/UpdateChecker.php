<?php

namespace App\Services;

use App\Support\AppVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks GitHub whether something newer than the running build exists.
 */
class UpdateChecker
{
    /**
     * Only the newest missing commits are listed, the compare link has the rest.
     */
    private const MAX_COMMITS = 30;

    public function __construct(private readonly AppVersion $version) {}

    public static function forCurrentBuild(): self
    {
        return new self(AppVersion::fromConfig());
    }

    public function isEnabled(): bool
    {
        return (bool) config('radioring.version.update_check')
            && $this->repository() !== ''
            && ! $this->version->isDevelopment()
            && ($this->version->isRelease() || $this->version->commit() !== null);
    }

    /**
     * The last stored result for this build, null when unknown or up to date.
     *
     * @return array{kind: string, label: ?string, title: string, url: string, published_at: ?string, notes: ?string, commits: array<int, array{sha: string, message: string, date: ?string}>, behind: int}|null
     */
    public function availableUpdate(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $result = Cache::get($this->cacheKey());

        return is_array($result) && ($result['update'] ?? null) ? $result['update'] : null;
    }

    /**
     * Queries GitHub and stores the answer. A failed lookup keeps the previous
     * answer, a temporary GitHub outage should not hide a known update.
     *
     * @return array{kind: string, label: ?string, title: string, url: string, published_at: ?string, notes: ?string, commits: array<int, array{sha: string, message: string, date: ?string}>, behind: int}|null
     */
    public function refresh(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $update = $this->version->isRelease() ? $this->checkRelease() : $this->checkEdge();
        } catch (Throwable $e) {
            Log::warning('Update check failed: '.$e->getMessage());

            return $this->availableUpdate();
        }

        Cache::forever($this->cacheKey(), ['update' => $update, 'checked_at' => now()->toIso8601String()]);

        return $update;
    }

    /**
     * @return array{kind: string, label: ?string, title: string, url: string, published_at: ?string, notes: ?string, commits: array<int, array{sha: string, message: string, date: ?string}>, behind: int}|null
     */
    private function checkRelease(): ?array
    {
        $release = $this->get('releases/latest');
        $latest = ltrim((string) ($release['tag_name'] ?? ''), 'v');

        if ($latest === '' || ! version_compare($latest, $this->version->name(), '>')) {
            return null;
        }

        return [
            'kind' => 'release',
            'label' => 'v'.$latest,
            'title' => (string) ($release['name'] ?: 'v'.$latest),
            'url' => (string) $release['html_url'],
            'published_at' => $release['published_at'] ?? null,
            'notes' => $release['body'] ?? null,
            'commits' => [],
            'behind' => 0,
        ];
    }

    /**
     * @return array{kind: string, label: ?string, title: string, url: string, published_at: ?string, notes: ?string, commits: array<int, array{sha: string, message: string, date: ?string}>, behind: int}|null
     */
    private function checkEdge(): ?array
    {
        $branch = (string) ($this->get('')['default_branch'] ?? 'main');
        $compare = $this->get(sprintf('compare/%s...%s', $this->version->commit(), $branch));
        $missing = (int) ($compare['ahead_by'] ?? 0);

        if ($missing === 0) {
            return null;
        }

        $commits = collect($compare['commits'] ?? [])
            ->reverse()
            ->take(self::MAX_COMMITS)
            ->map(fn (array $commit): array => [
                'sha' => Str::substr((string) $commit['sha'], 0, 7),
                'message' => Str::before((string) ($commit['commit']['message'] ?? ''), "\n"),
                'date' => $commit['commit']['committer']['date'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'kind' => 'edge',
            'label' => null,
            'title' => $branch,
            'url' => (string) ($compare['html_url'] ?? sprintf('https://github.com/%s/compare/%s...%s', $this->repository(), $this->version->commit(), $branch)),
            'published_at' => $commits[0]['date'] ?? null,
            'notes' => null,
            'commits' => $commits,
            'behind' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return Http::acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(10)
            ->get(rtrim(sprintf('https://api.github.com/repos/%s/%s', $this->repository(), $path), '/'))
            ->throw()
            ->json();
    }

    private function repository(): string
    {
        return trim((string) config('radioring.version.repository'), '/');
    }

    private function cacheKey(): string
    {
        return sprintf('radioring:update-check:%s:%s', $this->version->name(), $this->version->commit() ?? '-');
    }
}
