<?php

use App\Services\RemoteFetchException;
use App\Services\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;

test('it downloads over http', function () {
    Http::fake(['example.com/*' => Http::response('AUDIO-BYTES', 200)]);

    expect(app(RemoteFileFetcher::class)->fetch('https://example.com/show.mp3'))->toBe('AUDIO-BYTES');
});

test('it sends credentials as http basic auth instead of putting them in the address', function () {
    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);

    app(RemoteFileFetcher::class)->fetch('https://example.com/show.mp3', 'radioring', 'geheim123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/show.mp3'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('radioring:geheim123'));
    });
});

test('it reports the status code of a failed http download', function () {
    Http::fake(['example.com/*' => Http::response('', 503)]);

    expect(fn () => app(RemoteFileFetcher::class)->fetch('https://example.com/show.mp3'))
        ->toThrow(RemoteFetchException::class, '503');
});

test('it rejects an empty download', function () {
    Http::fake(['example.com/*' => Http::response('', 200)]);

    expect(fn () => app(RemoteFileFetcher::class)->fetch('https://example.com/show.mp3'))
        ->toThrow(RemoteFetchException::class);
});

test('it refuses a scheme it cannot download', function (string $url) {
    expect(fn () => app(RemoteFileFetcher::class)->fetch($url))
        ->toThrow(RemoteFetchException::class);
})->with([
    'sftp' => 'sftp://files.example.com/show.mp3',
    'file' => 'file:///etc/passwd',
    'no scheme' => 'files.example.com/show.mp3',
]);

test('the form validates against the same scheme list the fetcher implements', function () {
    expect(RemoteFileFetcher::SUPPORTED_SCHEMES)->toBe(['http', 'https', 'ftp', 'ftps']);
});

test('an ftp address does not go through the http client', function () {
    Http::fake();

    // No FTP server here, so the download itself must fail - but it has to fail in
    // cURL, not with Guzzle's "The scheme 'ftp' is not supported".
    try {
        app(RemoteFileFetcher::class)->fetch('ftp://127.0.0.1:9/show.mp3', timeoutSeconds: 2);
    } catch (RemoteFetchException $e) {
        expect($e->getMessage())->not->toContain('not supported');
    }

    Http::assertNothingSent();
});
