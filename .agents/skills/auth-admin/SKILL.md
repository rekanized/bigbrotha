---
name: auth-admin
description: Change BigBrotha onboarding, local or Google login, admin access, database-backed settings, audit logging, or HTTP restrictions.
---

# Authentication and admin

`routes/web.php` defines public setup/login/Google callback routes, an authenticated operator group, and an `admin` subgroup. `bootstrap/app.php` registers `EnsureSetupIsComplete`, `RestrictWebsiteIp`, proxy trust, and the `admin` / `media-access` aliases. The relay callback is CSRF-exempt and has its own authorization check.

- `Setup/SetupWizard.php` controls first launch. `Auth/UnifiedLoginScreen.php` handles local login; `LocalAuthenticationService` and `Auth/Google*Controller.php` handle sign-in. `AuthenticationSettingsService` reads/writes auth flags and Google config in `app_settings`, validates the saved Google verification fingerprint, and applies Socialite config at runtime.
- `User` stores local login and Google identity plus `is_admin`. `AllowedLoginEmail` is the Google allowlist. The first eligible Google login can bootstrap admin; afterward allowlist membership applies. `EnsureAdminUser` can promote an authenticated user if no admin exists. The setup/admin flows avoid disabling the last viable admin sign-in method.
- `AppServiceProvider` applies database-backed auth and application settings per request. `ApplicationSettingStore` shares a scoped settings snapshot. `ApplicationSettingsService` owns operator timezone and SMB configuration; `CameraStorageServiceProvider` builds the active camera disk. Do not run `config:cache` because the merged config can include mutable database secrets.
- `Auditable` writes model change records for selected models, using `AuditContextResolver` to label HTTP, queue, console, or system actors. `AdminAuditLogController` reads them; `AuditLog` is pruned daily using `ApplicationSettingsService::auditRetentionDays()`. Admin settings stores `audit_retention_days` (1–365, default 30) in `app_settings`; `config/audit.php` supplies the default. Saving retention applies to the next daily cleanup. Protect sensitive values when adding audited fields.
- `RestrictWebsiteIp` reads `WEBSITE_ALLOWED_IPS` after `TrustReverseProxyHeaders`. It exempts `/up` and the relay auth callback. Keep `TRUSTED_PROXIES` scoped correctly before trusting client IP headers.

Check `tests/Feature/Auth/*`, `AdminSettingsTest.php`, `AdminAuditLogTest.php`, `AuditLoggingTest.php`, and `tests/Feature/Relay/MediaMtxAuthCallbackTest.php`. Do not use real Google credentials or modify a production allowlist during tests.
