---
name: data-model
description: Change BigBrotha database schema, Eloquent models, seeders, relationships, or stored time handling.
---

# Data model

The deployed image defaults to PostgreSQL 18. `database/migrations/` is the authoritative schema; `phpunit.xml` switches tests to in-memory SQLite. Add a new migration for deployed schema changes and make it work on both databases. `app/Models/` holds casts, relationships, and domain helpers. Laravel 13 attribute-based `#[Fillable]` and `#[Hidden]` are used in some models, so inspect the model rather than assuming classic properties.

## Relationships

- `cameras` is the core inventory and policy table. `Camera` has many `CameraRecording` rows and `LiveWallTile` rows. Its JSON `metadata` stores ONVIF details and `rtsp_profiles`; `password` has an encrypted cast.
- `camera_recordings` references `cameras` with cascade delete; it stores capture status, mode, times, relative path, size, and motion score. `camera_motion_states` has one row per camera and an optional active recording reference. See `CameraRecording::pendingStatuses()` and `Camera` helpers for policy.
- `live_walls` has many `live_wall_tiles`. A tile references one wall and one camera; the wall/camera pair is unique. Deleting a camera or wall cascades tile deletion.
- `users` stores local and Google identities plus admin status. `allowed_login_emails` holds the Google allowlist and an optional adding-user reference. `app_settings` stores auth, timezone, and network storage settings; `AuditLog` has a polymorphic `auditable` subject and nullable actor user.
- Laravel tables in the initial migrations provide `jobs`, `failed_jobs`, `job_batches`, `sessions`, `cache`, and `cache_locks`. Production queue, sessions, and cache default to the database through the image's environment.

Event timestamps are handled in UTC; `ApplicationSettingsService` parses stored timestamps and converts to the operator timezone for display and date filters. Reuse its conversion helpers in timeline or report code. `Auditable` records selected model changes and excludes passwords/hidden fields; update exclusions when adding sensitive fields.

`DatabaseSeeder` calls `AuthenticationSettingsSeeder`, `NetworkStorageSettingsSeeder`, and `CameraFleetSeeder`. Local seeding creates example admin/cameras; production seed behavior depends on explicit `SEED_*` inputs. It is not part of routine production startup. Inspect existing data before seeding. `routes/console.php` has a legacy `db:import-sqlite` command that clears/imports active PostgreSQL tables; treat it as a migration operation, not a verification command.

Check new migrations and model behavior with focused `tests/Feature/` tests in the development image. Inspect status with `./docker/compose-dev.sh exec app php artisan migrate:status`. Never validate by resetting a deployed database.
