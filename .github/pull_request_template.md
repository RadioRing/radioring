<!--
Thanks for contributing. Nothing below is bureaucracy for its own sake: the checks exist
because they have each caught a real bug in this project.
-->

## What this changes

<!-- One or two sentences. What was wrong or missing, and what does this do about it. -->

## Why

<!-- The reasoning, if it is not obvious from the change itself. Link an issue if there is one. -->

## How it was verified

<!--
More than "tests pass". What did you actually check?
For playout changes: did you run it against a real station container?
-->

## Checklist

- [ ] `php artisan test` passes
- [ ] `vendor/bin/pint --dirty` is clean
- [ ] The change is covered by a test
- [ ] New or changed UI strings use English keys and have a `lang/de.json` entry
- [ ] No em dash (U+2014) and no ellipsis character (U+2026) in any text I added
- [ ] I have read and understood every line of this diff myself, including any part
      written by an AI assistant, and can explain why it works

## Anything worth a second look

<!--
Trade-offs you made, things you were unsure about, or areas you would like reviewed
closely. Saying "I am not sure about X" is useful, not a weakness.
-->

<!--
If you touched one of these, please say how you kept the invariant:

- Container drivers: the container spec must be built only from server-side config.
  No station-owned field may reach HostConfig, Binds, Image or Privileged.
- Operating mode: may only affect visibility plus the three named behavioural rules,
  and routes must never be registered conditionally on it.
- Media access: derived through a station the user is a member of, never through
  users.tenant_id.
- Playout: the prefetch cursor runs ahead of what is on air. Anything reasoning about
  "now" must use now_playing.
-->
