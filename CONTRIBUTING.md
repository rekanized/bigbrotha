# Contributing

## Development

Install Git and Docker with Compose. Prepare a separate development deployment:

```sh
./docker/compose.sh init
# Set APP_URL and unique stack/port values in .env.docker.
./docker/compose.sh --dev start
./docker/compose.sh --dev exec app php artisan setup:token
```

Keep development separate from production volumes, ports, and camera recording
policies. The development image includes Composer and SQLite; the host PHP
version is not the supported test runtime.

Blade, Livewire, plain CSS, and browser JavaScript render the interface directly.
Do not add npm, Node tooling, Vite, Webpack, or a frontend compilation step.
Dependencies are managed with Composer and the committed lock file.

## Checks

```sh
./docker/compose.sh --dev exec app php artisan test
./docker/compose.sh --dev exec app vendor/bin/pint --test
./docker/compose.sh --dev exec app composer audit --locked
sh tests/Docker/compose.test.sh
git diff --check
```

PHPUnit forces an in-memory SQLite database and refuses a cached or unsafe
configuration. Never point tests at a deployment database. The JavaScript
fixtures under `tests/Browser/` run manually in Chromium; they are not part of
PHPUnit. Review affected operator screens at desktop and phone sizes and verify
live camera, SMB, and OAuth behavior when those integrations change.

GitHub CI runs Docker tests, Pint, Composer audit, credential scans of current
files and full Git history, production-image validation, and a vulnerability
scan for fixable high/critical issues. It has read-only repository permissions
and does not publish images or deploy services.

## Pull requests and releases

Explain the concrete behavior change and relevant verification. Include a new
migration for persisted schema changes and update the focused documentation.
Use fictional fixtures; never commit private configuration, keys, media, camera
inventory exports, or database backups. Follow [SECURITY.md](SECURITY.md) for
vulnerability reports.

Maintainers can run `PUSH_IMAGES=false ./publish.sh` to validate a release locally.
The default release build refreshes base images and package layers. It runs the
test suite and validates the final image before any push. Running the script
without `PUSH_IMAGES=false` publishes to Docker Hub and is a separate release
operation. GitHub publication must use a repository whose full history has been
reviewed for private data.
