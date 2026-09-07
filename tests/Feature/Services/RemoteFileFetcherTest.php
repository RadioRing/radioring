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

test('it percent-encodes spaces in an address', function () {
    expect(RemoteFileFetcher::normalizeUrl('ftp://markstafford.co.uk/All/current/Show - Stafford 1A.mp3'))
        ->toBe('ftp://markstafford.co.uk/All/current/Show%20-%20Stafford%201A.mp3');
});

test('normalizing an address twice changes nothing', function (string $url) {
    $once = RemoteFileFetcher::normalizeUrl($url);

    expect(RemoteFileFetcher::normalizeUrl($once))->toBe($once);
})->with([
    'spaces' => 'ftp://host/All/Show - Stafford 1A.mp3',
    'already encoded' => 'ftp://host/All/Show%20-%20Stafford%201A.mp3',
    'query' => 'https://host/file.mp3?token=ab%2Bcd&expires=123',
    'login in the authority' => 'https://mount:pa%3Fss@api.example.com/news/1',
    'umlaut' => 'ftp://host/Sendung/Grüße.mp3',
]);

test('it keeps query and fragment delimiters intact', function () {
    expect(RemoteFileFetcher::normalizeUrl('https://host/a b.mp3?x=1&y=2#top'))
        ->toBe('https://host/a%20b.mp3?x=1&y=2#top');
});

test('it leaves an address without a path alone', function () {
    expect(RemoteFileFetcher::normalizeUrl('  https://example.com  '))->toBe('https://example.com');
});

test('it fetches the encoded address when the path contains spaces', function () {
    Http::fake(['*' => Http::response('AUDIO', 200)]);

    app(RemoteFileFetcher::class)->fetch('https://example.com/All/Show - Stafford 1A.mp3');

    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/All/Show%20-%20Stafford%201A.mp3');
});
