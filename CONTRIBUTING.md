# Contributing

## Development

Install Git and Docker with Compose. Prepare a separate development deployment:

```sh
cp .env.example .env
chmod 600 .env
# Set APP_URL, a strong DB_PASSWORD, and unique stack/port values in .env.
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build --wait
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec app php artisan setup:token
```

Keep development separate from production volumes, ports, and camera recording
policies. The development image includes Composer and SQLite; the host PHP
version is not the supported test runtime.

Blade, Livewire, plain CSS, and browser JavaScript render the interface directly.
Do not add npm, Node tooling, Vite, Webpack, or a frontend compilation step.
Dependencies are managed with Composer and the committed lock file.

## Checks

```sh
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec app php artisan test
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec app vendor/bin/pint --test
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec app composer audit --locked
sh tests/Docker/compose.test.sh
git diff --check
```

PHPUnit forces an in-memory SQLite database and refuses a cached or unsafe
configuration. Never point tests at a deployment database. The JavaScript
fixtures under `tests/Browser/` run manually in Chromium; they are not part of
PHPUnit. Review affected operator screens at desktop and phone sizes and verify
live camera, SMB, and OAuth behavior when those integrations change.

## Pull requests and releases

Explain the concrete behavior change and relevant verification. Include a new
migration for persisted schema changes and update the focused documentation.
Use fictional fixtures; never commit private configuration, keys, media, camera
inventory exports, or database backups. Follow [SECURITY.md](SECURITY.md) for
vulnerability reports.

Use documentation addresses such as `192.0.2.10` and reserved hostnames such as
`camera.example.test` for camera fixtures. Credentials in fixtures must be
obvious test values. Keep production camera exports, deployment backups, and
machine-specific paths out of source files and documentation.

Before making a repository public, scan both the tracked source and all Git
history for secrets and private deployment details. Deleting a file or adding
it to `.gitignore` does not remove older committed copies. If history contains
private data, publish a clean source snapshot or sanitize the history first;
rotate any exposed credentials before publication.

Maintainers can run `PUSH_IMAGES=false ./publish.sh` to validate a release locally.
The default release build refreshes base images and package layers. It runs the
test suite, PHP formatting, shell syntax, and Compose checks, and validates the
final image before any push. Running the script
without `PUSH_IMAGES=false` publishes to Docker Hub and is a separate release
operation. GitHub publication must use a repository whose full history has been
reviewed for private data.
