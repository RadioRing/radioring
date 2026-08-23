# Contributing

Thanks for considering a contribution. This file describes how to get the project running
and the few conventions that are enforced automatically.

## Getting started

```sh
git clone https://github.com/radioring/radioring.git
cd radioring
composer setup     # dependencies, .env, APP_KEY, migrations, assets
composer dev       # server, queue worker, vite
```

`composer setup` uses SQLite by default, which is fine for development. Production uses
MySQL.

## Before you open a pull request

```sh
php artisan test
vendor/bin/pint --dirty
```

Both run in CI as well. `install.sh`, `update.sh` and the compose template are additionally
checked with `shellcheck` and `docker compose config`.

## Conventions

### Language

Code, comments, docblocks, commit messages and operator documentation are **English**. The
user interface is **German** and stays that way for now.

Translation keys are the English source strings; `lang/de.json` maps them to German:

```php
__('Media library')            // key
// lang/de.json: "Media library": "Medienbibliothek"
```

There are still older strings with German keys. Convert them when you touch the file, and
add the translation to `lang/de.json`. Untouched strings keep working, so this happens
gradually rather than in one sweep.

`tests/Feature/TranslationsTest.php` checks that `lang/de.json` is valid and that
placeholders such as `:name` survive translation.

### Tests

Every change needs a test. Write a new one or extend an existing one, then run the affected
tests. Feature tests are preferred over unit tests; use factories, and check for existing
factory states before setting attributes by hand.

Do not delete tests without saying why in the pull request.

### Code style

Laravel conventions, enforced by Pint (`laravel` preset). Beyond that:

- Explicit return types and parameter type hints.
- Constructor property promotion.
- Curly braces on every control structure, even single-line bodies.
- Prefer a docblock over inline comments; comment the *why*, not the *what*.
- Use `php artisan make:` to create new classes so they land in the right shape.

## Things worth knowing before you change them

A few areas have non-obvious constraints. They are documented in the code, but here is
where to look:

- **Container drivers** (`app/Services/DockerService.php`): the container spec must be
  built only from server-side config. No station-owned field may reach `HostConfig`,
  `Binds`, `Image` or `Privileged`. See [`SECURITY.md`](SECURITY.md).
- **Operating mode** (`app/Enums/AppMode.php`): the mode may only affect visibility plus
  three named behavioural rules. It must not spread into services, jobs or models, and
  routes must never be registered conditionally on it, because route definitions are
  cached at boot.
- **Media library**: it belongs to the tenant, not the station. Access is always derived
  through a station the user is a member of, never through `users.tenant_id`, which is only
  the home tenant for new stations and uploads.
- **Liquidsoap pull model** (`app/Services/LiquidsoapStateService.php`): the pull cursor
  runs ahead of what is actually on air because of prefetching. Anything that reasons about
  "what is playing right now" has to use `now_playing`, not the cursor.

## AI-assisted contributions

This project was itself written with the aid of AI coding assistants, so pull requests
produced that way are welcome. There is one hard rule:

**No AI-generated code reaches `main` without a human having read it and understood it.**

That means the person opening the pull request, not only the maintainer merging it. Before
you submit, you are expected to be able to explain why the change works, what it touches,
and what happens when it fails. If you cannot, the patch is not ready, however green the
tests are.

Concretely:

- Read the diff line by line. Generated code tends to look plausible where it is wrong.
- Check the invariants listed above. Assistants have no way of knowing them and will
  happily break them.
- Do not submit generated tests that only restate the implementation. A test that would
  still pass if the feature were removed is worse than no test.
- Verify claims against the code, not against what the assistant said it did.

You do not have to disclose that you used an assistant, and the checklist item in the pull
request template is a statement about review, not about tooling. Contributions that are
clearly unreviewed machine output will be closed without a detailed review, because
reviewing them costs more than writing the change.

## Reporting bugs

Open an issue with the RadioRing version or image tag, what you expected, what happened,
and the relevant part of `docker compose logs app`.

**Security issues do not belong in public issues.** See [`SECURITY.md`](SECURITY.md).

## Licence

By contributing you agree that your work is licensed under the
[AGPL-3.0-or-later](LICENSE), like the rest of the project.
