<?php

namespace App\Services\Docker;

/**
 * Resolves a DOCKER_HOST value into what the HTTP client needs.
 *
 * The ecosystem-standard DOCKER_HOST syntax is reused deliberately: it is the notation
 * operators already know from the Docker CLI, and it is process-local in PHP, so it can
 * never leak onto the host shell.
 *
 * This is the one piece Http::fake() can never exercise, because the fake replaces the
 * handler stack and never sees cURL options. It therefore lives in its own unit-tested
 * value object rather than inside DockerService.
 */
final readonly class DockerConnection
{
    /**
     * @param  string  $baseUrl  e.g. http://dockerproxy:2375/v1.43
     * @param  array<int, mixed>  $curlOptions  empty, or the unix socket option
     * @param  string|null  $socketPath  set only when talking to a unix socket
     */
    public function __construct(
        public string $baseUrl,
        public array $curlOptions,
        public ?string $socketPath,
    ) {}

    /**
     * | DOCKER_HOST                  | baseUrl                        | cURL              |
     * |------------------------------|--------------------------------|-------------------|
     * | tcp://dockerproxy:2375       | http://dockerproxy:2375/v1.43  | -                 |
     * | http(s)://host:port          | passthrough + /v1.43           | -                 |
     * | unix:///var/run/docker.sock  | http://localhost/v1.43         | UNIX_SOCKET_PATH  |
     * | /var/run/docker.sock         | http://localhost/v1.43         | UNIX_SOCKET_PATH  |
     *
     * An empty $apiVersion means "no prefix", i.e. let the daemon pick its default. That
     * is the escape hatch for very old engines.
     */
    public static function fromDockerHost(string $host, string $apiVersion = ''): self
    {
        $host = trim($host);
        $suffix = $apiVersion !== '' ? '/'.trim($apiVersion, '/') : '';

        if ($host === '') {
            return new self('', [], null);
        }

        if (str_starts_with($host, 'unix://')) {
            return self::forSocket(substr($host, strlen('unix://')), $suffix);
        }

        // A bare filesystem path is treated as a socket, which is what people type.
        if (str_starts_with($host, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $host) === 1) {
            return self::forSocket($host, $suffix);
        }

        if (str_starts_with($host, 'tcp://')) {
            $host = 'http://'.substr($host, strlen('tcp://'));
        }

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = 'http://'.$host;
        }

        return new self(rtrim($host, '/').$suffix, [], null);
    }

    private static function forSocket(string $path, string $suffix): self
    {
        // With a unix socket the HTTP Host header is irrelevant to cURL; "localhost" is
        // what the Docker CLI sends and keeps the URLs readable in logs.
        return new self(
            'http://localhost'.$suffix,
            [CURLOPT_UNIX_SOCKET_PATH => $path],
            $path,
        );
    }

    public function usesSocket(): bool
    {
        return $this->socketPath !== null;
    }
}
