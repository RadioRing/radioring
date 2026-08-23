<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What this build actually is.
 *
 * Both values are baked into the image at build time, not read from the
 * installation. That distinction matters: the .env records which release an
 * installation is pinned to, while this records what the running container was
 * built from. When the two disagree, something went wrong with a deployment,
 * and a panel that reported the .env value would hide exactly that.
 */
class AppVersion
{
    public function __construct(
        private readonly string $version,
        private readonly ?string $commit,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) (config('radioring.version.name') ?: 'dev'),
            config('radioring.version.commit') ? (string) config('radioring.version.commit') : null,
        );
    }

    /**
     * A tagged release carries a semantic version, everything else does not.
     * "edge" and "dev" are deliberately not versions.
     */
    public function isRelease(): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+/', $this->version);
    }

    public function shortCommit(): ?string
    {
        return $this->commit ? Str::substr($this->commit, 0, 7) : null;
    }

    /**
     * "v0.1.0" for a release, "edge (a1b2c3d)" for a build off a branch,
     * "dev" when the image carries no build information at all.
     */
    public function label(): string
    {
        if ($this->isRelease()) {
            return 'v'.$this->version;
        }

        $short = $this->shortCommit();

        return $short ? sprintf('%s (%s)', $this->version, $short) : $this->version;
    }

    /**
     * Where to look this build up. A release points at its notes, a branch
     * build at the exact commit it was made from.
     */
    public function url(): ?string
    {
        $repo = trim((string) config('radioring.version.repository'), '/');

        if ($repo === '') {
            return null;
        }

        if ($this->isRelease()) {
            return sprintf('https://github.com/%s/releases/tag/v%s', $repo, $this->version);
        }

        return $this->commit
            ? sprintf('https://github.com/%s/commit/%s', $repo, $this->commit)
            : null;
    }
}
