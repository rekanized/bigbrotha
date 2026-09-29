---
name: verification
description: Select and run BigBrotha PHPUnit, formatting, Compose, and browser checks for application changes.
---

# Verification

Use PHP 8.5 in the development/test Docker image. The local host may have an older PHP; the production image has no Composer dev dependencies. `phpunit.xml` runs `tests/Unit/` and `tests/Feature/` with SQLite `:memory:`, sync queues, and array cache/session. Test files use PHPUnit and Laravel's base `tests/TestCase.php`.

## Fast commands

```bash
./docker/compose-dev.sh exec app php artisan test --filter=CameraFleetManagerTest
./docker/compose-dev.sh exec app php artisan test
./docker/compose-dev.sh exec app vendor/bin/pint --test
git diff --check
```

`composer.json` defines `composer test` (config clear, then Artisan test), but use the container command above when selecting tests. `publish.sh` builds and runs the Dockerfile `test` target before publishing. There is no PHPStan/Psalm configuration, `package.json`, or CI workflow.

Pick feature tests by boundary: `Auth/*` for setup/sign-in; `CameraFleetManagerTest` and ONVIF/RTSP service tests for intake; `Relay/*` and `LiveWallStreamTest` for MediaMTX/WebRTC; `CameraRecording*Test`, `RecordingBrowserTest`, and `TimelineReviewTest` for recording; `DockerRuntimeConfigurationTest` for container assumptions. Prefer the smallest relevant set before running the full suite.

`tests/Browser/*.test.js` export functions to invoke in a browser after loading the corresponding `public/js` file. They are not PHPUnit tests and no Node runner is configured. A green PHPUnit suite cannot prove camera reachability, an SMB server, Google OAuth round-trip, or public ICE networking; verify those only in an authorized matching environment and report the gap when unavailable. For Compose edits, check merged service names with `docker compose ... config --services` using placeholder environment values, without starting or modifying a live stack.

The PHPUnit XML files force `APP_ENV=testing` and an in-memory SQLite database in both environment and server variables. `tests/TestCase.php` refuses cached config or any other effective database before `RefreshDatabase` runs. The production image has no test dependencies; run tests only with the development/test image, never against a deployment database.
