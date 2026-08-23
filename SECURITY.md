# Security Policy

## Reporting a vulnerability

Please report security issues privately through GitHub, **not** as a public issue:

> Repository → **Security** → **Advisories** → **Report a vulnerability**

Only the maintainers can see such a report. You will get an acknowledgement within a few
days. If a fix is needed, we will coordinate a release and credit you in the advisory
unless you prefer otherwise.

Public issues are fine for anything that is not exploitable, for example a hardening
suggestion or a question about the threat model below.

## Supported versions

RadioRing has no long-term support branches yet. Security fixes go into `main` and the
next tagged release. Run a recent image.

---

## Threat model

Read this before exposing an instance to the internet. Some of it is uncomfortable, and
you should know it rather than discover it.

### RadioRing controls Docker on its host

To start and stop the per-station Liquidsoap containers, the application talks to the
Docker Engine API. **Anyone who can reach that API can start a privileged container with
the host filesystem mounted, and is therefore root on the host.**

That is true for every driver:

| Driver | Exposure |
|---|---|
| `docker` via socket proxy (default) | Reduced attack surface, same trust boundary |
| `docker` via mounted socket | Full Docker API |
| `portainer` | The API token is equivalent to Docker admin |

The bundled `tecnativa/docker-socket-proxy` is a **narrowing of the attack surface, not a
security boundary**. With `CONTAINERS=1`, `IMAGES=1` and `POST=1` an attacker who reaches
it can still create and start an arbitrary container. What it does buy you: no `exec`, no
volume, network or secret endpoints, and no `GET /info` leaking host details. That cuts
off lateral paths and a lot of accidental damage, but not the escalation itself.

Consequence: **a remote code execution bug in RadioRing means root on the host.** Treat
the panel as a privileged service.

Two related notes:

- The proxy must never publish a port. In the shipped compose file it lives on an internal
  network only.
- `:ro` on the socket mount is cosmetic. A unix socket is bidirectional; read-only applies
  to the inode, not to what you can ask the daemon to do. We keep it because it costs
  nothing, but it hardens nothing.

**What actually holds the line** is an invariant in the code, and it must stay that way:

> The container spec is built entirely from server-side configuration. No station-owned
> field ever reaches `HostConfig`, `Binds`, `Image` or `Privileged`. `Env` and `Labels`
> carry only the slug, the station API token and configuration values.

If you extend the container drivers, do not break this.

If you need real isolation, run the station containers on a separate host and point
`DOCKER_HOST` at it over an authenticated channel.

### Station members are semi-privileged users

Anyone you invite into a station can upload audio that the server processes with `ffmpeg`,
and can make the station fetch external HTTP sources. Invite people you trust.

In `standalone` mode the media library belongs to the account, not to a single station, so
inviting an editor into one station gives them read access to the whole library, including
material of sibling stations they were never invited to. Editors cannot delete; only
owners can. This is a deliberate trade-off of the shared-library model.

### The application trusts proxy headers

`bootstrap/app.php` sets `trustProxies(at: '*')`. This is correct behind the documented
reverse proxy setup, where the proxy is the only way in. It is **not** safe if the
container port is reachable directly: a client could then spoof `X-Forwarded-For` and
defeat rate limiting and login throttling.

The shipped compose file publishes the app port on `127.0.0.1` only (`APP_BIND`). Widening
it to `0.0.0.0` without a proxy in front exposes the application directly and makes the
spoofing above possible.

### APP_KEY is critical

Several values are encrypted with `APP_KEY`:

- `stations.api_token` (shared secret with the station container)
- `stations.s4r_partner_token`, `stations.stereo_tool_license_key`
- `station_outputs.password_enc`, `station_streams.live_password_enc`

Signed delivery URLs are also derived from it.

**Losing `APP_KEY` means these values cannot be recovered.** Back it up separately from the
database, and never regenerate it on an existing installation. If it is lost or you suspect
it leaked, rotate the station tokens:

```bash
php artisan station:rotate-token <slug>
```

This recreates the container, because the token is passed to it as an environment
variable.

### Secrets and where they appear

- The station API token is sent as an `Authorization: Bearer` header, never in a URL.
  Media and prepared files are delivered through **signed URLs** with a limited lifetime
  (`DELIVERY_URL_TTL_SECONDS`, default 6 hours), so an entry in a proxy log grants at most
  one file for a limited time.
- `GET /api/liquidsoap/{slug}/script` returns the generated Liquidsoap script, which
  contains the Icecast output password and the live input password. It is Bearer
  authenticated. Treat the station token as equivalent to those credentials.
- The generated `.env` contains database and Redis passwords. The installer writes it with
  mode `600`.
- With `env_file: .env` the whole file is passed into the app container, including the
  bundled database root password. That is acceptable on a single host, but it is one more
  reason to keep `APP_DEBUG=false`: the debug screen renders every environment value.

### Uploads

Chunked uploads are validated by file extension, the chunk id is checked against a UUID
pattern and the final name is slugified with a random prefix, so path traversal is covered.

Two gaps remain. There is **no content-type verification** beyond the extension. And while
each chunk is capped at 20 MB, the *assembled* file is not limited: with up to 500 chunks a
single upload can reach several gigabytes. Storage is therefore bounded by disk space and
by whom you invite, not by the application.

### Optional proprietary components

Thimeo Stereo Tool is an optional add-on. Its shared library and presets are **not** part
of this repository and must be licensed and supplied by the operator. Enabling it without a
valid licence runs it in demo mode, which injects periodic audio artefacts.

---

## Hardening checklist

Not everything below is done for you.

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] `APP_KEY` backed up outside the database
- [ ] Panel only reachable over HTTPS
- [ ] Docker access through the socket proxy, not a mounted socket
- [ ] The socket proxy has no published port
- [ ] Only the live-input port range is open in the firewall, next to 80 and 443
- [ ] Database and Redis not published to the host
- [ ] Registration closed or invite-code only
- [ ] Backups of the database *and* `storage/`

## Known gaps

Tracked, not yet done. Listed here so you can judge the risk yourself:

- Authorisation is enforced by explicit checks in controllers and Livewire components
  rather than by policies. Correct today, but a new action added without a check would not
  be caught by anything structural.
- Assembled uploads have no total size limit; only single chunks are capped.
- There is no rate limiting on the Liquidsoap pull API beyond the token check.
