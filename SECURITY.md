# Security policy

## Supported deployment

Security fixes target the latest release and its locked Composer dependencies.
Use the supported Docker Compose stack with PostgreSQL, PHP 8.5, Laravel 13,
and Livewire 4. Pin a tested image tag for production and review new releases
regularly. Older image tags do not receive package updates automatically.

## Reporting a vulnerability

Use [GitHub private vulnerability reporting](https://github.com/rekanized/bigbrotha/security/advisories/new).
If that option is unavailable, open an issue requesting a private reporting
channel, without disclosing exploit details or sensitive data.

Include the affected version or image tag, the configuration needed to reproduce
the issue, its impact, and a minimal reproduction using fictional data. Never
include camera credentials, OAuth secrets, application keys, private footage,
database dumps, or unredacted logs in public issues or pull requests.

## Operating an internet-facing instance

- Complete initial setup using the private server-side setup token.
- Use HTTPS, a strong administrator password, and narrowly scoped trusted proxies.
- Expose only the intended web and ICE ports; keep PostgreSQL and relay management internal.
- Protect and back up `.env`, `.docker-state/app.key`, the database, and private storage together.
- Do not use development mode, seed example accounts, or enable debug output on a public server.
- Treat camera/network reachability and access to private storage as trusted deployment privileges.

ONVIF HTTPS requests verify the device certificate. Install the device's issuing
CA in the image's trust store when using a private CA. Discovery accepts media,
PTZ and RTSP addresses on the configured device host only (service ports may
differ); configure devices to advertise that same hostname or IP. SOAP responses
must be UTF-8, at most 1 MiB, and must not contain a DTD or entity declarations.

Production images use Debian 13 and exclude build tools. Rebuild with current
base images and scan the resulting image as well as Composer dependencies;
a clean Composer audit does not cover operating-system or media-decoder issues.

See [deployment](docs/startup-from-scratch.md), [backup and restore](docs/backup-and-restore.md),
and [deployment constraints](docs/known-issues-and-constraints.md) for configuration requirements.
