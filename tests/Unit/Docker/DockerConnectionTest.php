<?php

use App\Services\Docker\DockerConnection;

test('a tcp host becomes an http base url', function () {
    $c = DockerConnection::fromDockerHost('tcp://dockerproxy:2375', 'v1.43');

    expect($c->baseUrl)->toBe('http://dockerproxy:2375/v1.43')
        ->and($c->curlOptions)->toBe([])
        ->and($c->usesSocket())->toBeFalse();
});

test('an http host passes through', function () {
    $c = DockerConnection::fromDockerHost('https://docker.example.com:2376', 'v1.43');

    expect($c->baseUrl)->toBe('https://docker.example.com:2376/v1.43')
        ->and($c->usesSocket())->toBeFalse();
});

test('a bare host gets an http scheme', function () {
    $c = DockerConnection::fromDockerHost('dockerproxy:2375', 'v1.43');

    expect($c->baseUrl)->toBe('http://dockerproxy:2375/v1.43');
});

test('a unix scheme becomes a socket connection', function () {
    $c = DockerConnection::fromDockerHost('unix:///var/run/docker.sock', 'v1.43');

    expect($c->baseUrl)->toBe('http://localhost/v1.43')
        ->and($c->socketPath)->toBe('/var/run/docker.sock')
        ->and($c->usesSocket())->toBeTrue()
        ->and($c->curlOptions)->toBe([CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock']);
});

test('a bare filesystem path is treated as a socket', function () {
    $c = DockerConnection::fromDockerHost('/var/run/docker.sock', 'v1.43');

    expect($c->usesSocket())->toBeTrue()
        ->and($c->socketPath)->toBe('/var/run/docker.sock')
        ->and($c->baseUrl)->toBe('http://localhost/v1.43');
});

test('an empty api version means no prefix', function () {
    expect(DockerConnection::fromDockerHost('tcp://proxy:2375', '')->baseUrl)->toBe('http://proxy:2375')
        ->and(DockerConnection::fromDockerHost('unix:///run/d.sock', '')->baseUrl)->toBe('http://localhost');
});

test('a trailing slash does not produce a double slash', function () {
    expect(DockerConnection::fromDockerHost('tcp://proxy:2375/', 'v1.43')->baseUrl)
        ->toBe('http://proxy:2375/v1.43');
});

test('an empty host yields an unusable connection', function () {
    $c = DockerConnection::fromDockerHost('', 'v1.43');

    expect($c->baseUrl)->toBe('')
        ->and($c->usesSocket())->toBeFalse();
});
