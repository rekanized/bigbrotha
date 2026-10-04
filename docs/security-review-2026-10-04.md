# Authentication and security review — 4 October 2026

This review covers the Laravel authentication and authorization flows, first-run setup, Livewire actions, sessions and cookies, Google identity linking, private media routes, relay authentication, sensitive diagnostics, and the Docker web boundary. Changes were built into a production image and verified byte-for-byte in the running application, background, and relay containers of the `bigbrotha` test stack at https://monitor-test.schollinetz.com/. The separate `actualbigbrotha` production stack was not changed.

The security build is available as `bigbrotha:security-20261004` (image ID `1b1d1eb63c24`). A concurrent UI deployment subsequently selected `bigbrotha:desktop-sidebar-20261004` (image ID `0d39efd526c5`); its security files were verified against the same source in all three application roles. All four test services are healthy.

A review and passing tests cannot prove that an application has no vulnerabilities. The confirmed issues below were fixed; the verification limits and operational assumptions are explicit.

## Confirmed issues and fixes

| Area | Finding | Result |
| --- | --- | --- |
| First-run setup | Anyone reaching an unconfigured instance could claim the first administrator. | Setup mutations require a private token derived from the deployment encryption key. Retrieve it through `php artisan setup:token`; it is never returned by an HTTP endpoint. |
| Setup replay | A signed setup component loaded before onboarding completed could still call setup actions later. | Both setup mutations recheck database state. Completion uses a shared cache lock and database transaction, preventing concurrent completions and partial writes. |
| Administrator escalation | Admin middleware promoted an authenticated operator whenever no administrator existed. | Automatic promotion was removed, including the corresponding navigation assumption. Administrators are established through authorized setup or explicit administrator actions. |
| Livewire authorization | The custom administrator middleware was absent from Livewire's persistent middleware list. | It is registered for update requests. Every admin component also checks the current stored role in `boot()`, rejecting demoted users with stale snapshots. |
| Administrator concurrency | Independent demotions could each pass the last-admin check. | Role changes, allowlist removals, and authentication-setting changes share administrator row locks and recheck the acting administrator inside a database transaction. They protect the last administrator able to use an enabled method, even when other unusable administrator accounts exist. |
| Local brute force | Password login had no attempt limit. | Five attempts per normalized account and thirty per client IP per minute. Failed attempts consume both budgets; success clears the account budget. Unknown accounts still perform password verification. |
| Password input | New passwords could exceed bcrypt's effective byte limit. | Creation/reset require at least 12 characters, at most 72 bytes, and no null characters. Local remember-me defaults to off. |
| Google identity | Callbacks accepted email without checking verified identity claims and could link by an ambiguous subject-or-email query. | Verified email and nonempty subject are required. Conflicting subjects and accounts are rejected. Initial Google-only admin creation requires the exact email and subject verified during setup and an empty user table. |
| Google account linking | A third-party email Google account could claim an unlinked local account based on an old email verification. | Automatic linking requires a Google-authoritative address: Gmail or a matching Workspace hosted domain. Existing approved subject links remain usable. |
| OAuth test authorization | A pending admin OAuth test could survive loss of administrator rights, and its recorded start time was not enforced. | Pending tests recheck their setup/admin context and expire after ten minutes. Saved/draft secrets stay on the server rather than being populated into the administrator component snapshot. |
| Session revocation | Password changes and removed authentication permissions did not invalidate existing sessions. | Laravel authenticates session password hashes; password resets rotate remember tokens. A middleware checks the active authentication method and Google approval on every web request. Google login uses ordinary sessions. |
| Sensitive storage | Sessions could hold plaintext OAuth drafts; Google secret ciphertext entered audit snapshots. | Sessions are encrypted by default. Saved Google secret values are excluded from audit snapshots. Previously unencrypted sessions require a fresh sign-in after deployment. |
| Media credentials | Tokens were not bound to the current account password/access state; missing keys had publicly derivable fallback secrets. | Tokens bind to password state and authentication method, check current access, and reject exact expiry, tampering, or wrong scopes. No private signing key means failure. Callback loopback fallback checks the transport peer rather than forwarded client IP. |
| Public origin and proxy | Forwarded scheme handling occurred in Nginx before Laravel checked proxy trust, and arbitrary hosts could influence URLs. | Public web hosts must match `APP_URL`; HTTPS URL generation uses the configured origin. Forwarded HTTPS is not treated as native HTTPS by PHP. Internal health and relay callback hosts remain supported. |
| Browser controls | Authenticated pages lacked a consistent no-store policy and application-level security headers. | CSP, HSTS for the configured HTTPS origin, frame/type/referrer restrictions, permissions policy, and private no-store responses are applied. Nginx avoids duplicating its three existing security headers. |
| Logs and diagnostics | Nginx query strings and ffmpeg URL diagnostics could include OAuth codes, relay tokens, or camera credentials. | Inner access logs omit query strings/referrers. Laravel logging redacts sensitive context fields, URL credentials, and credential query values. Persisted recording error summaries redact URLs before storage. |
| Camera responses | Discovery followed HTTP redirects and XML parsers did not explicitly forbid network access. | Discovery disables redirects and parses XML with `LIBXML_NONET`. Shell-based recorder commands were reviewed for argument escaping; private storage resolves paths inside its managed roots. |

Google distinguishes authoritative Gmail/Workspace addresses from third-party email claims that may have been verified in the past. The stricter linking behavior follows that distinction. [Google backend authentication guidance](https://developers.google.com/identity/sign-in/web/backend-auth).

Livewire custom authorization needs to persist across update requests, and component actions must authorize their mutations. The implementation was checked against the installed Livewire code and its [persistent middleware implementation](https://github.com/livewire/livewire/blob/4.x/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php).

## Verification

- The full PHPUnit suite passed 440 tests (2,383 assertions), including 37 authentication/security regression tests and two redaction unit tests. It runs in PHP 8.5 Docker with in-memory SQLite, isolated storage, and no deployment database access. Security regressions include real signed HTTP Livewire snapshot replay, production bcrypt verification, OAuth subject conflicts/state failure, session revocation, token scope/expiry, CSRF, proxy spoofing, log output, and secret snapshot exclusion.
- Composer audits the locked production and development dependencies. No known advisory was returned at review time.
- Pint checks all touched PHP files. `git diff --check`, Docker Compose configuration checks, and Nginx configuration validation are included.
- Public HTTP checks cover protected operator/admin/media endpoints, completed-setup redirects, sensitive file paths, logout CSRF, and forged relay authentication. Direct backend requests test host rejection and forwarded-header handling.
- Chromium checks the live desktop/mobile login page, Livewire initialization, a rejected login using a nonexistent account, password clearing, and browser console errors. It does not create or modify a deployment user. The snap Chromium browser required its certificate override for automation; the separate public HTTP probes validated the TLS certificate normally.
- Container IDs and start times are compared before/after for the separate production application roles.

## Operational assumptions and limits

The app intentionally gives authenticated operators access to the shared camera fleet, walls, and recordings; only administration is role restricted. This is not a per-camera or per-tenant authorization model. Approved operators can configure camera network endpoints, so camera-network access remains a trusted operator capability.

Configure `TRUSTED_PROXIES` to the actual proxy addresses before depending on client-IP allowlists or individual client-IP rate limits. The Docker image's existing default trusts the Docker private range; the non-Docker default now trusts no proxies. This deployment's external proxy falls outside the Docker default, so configured HTTPS URLs work independently of trusting it, but Laravel sees that proxy as the client until proxy trust is configured. The account-specific login limit remains independent of this setting.

The CSP retains `unsafe-inline` and `unsafe-eval` for the current Livewire/Alpine implementation. It restricts script origins, objects, forms, frames, and base URLs but is not a substitute for correct escaping. Views and browser JavaScript were reviewed for unescaped dynamic output. No unsafe dynamic HTML sink was found in the reviewed application assets.

Token and session revocation apply to subsequent HTTP requests and new relay authorizations. An already established WebRTC or streamed media connection is not forcibly closed by an account change. Short-lived tokens do not themselves terminate an existing peer connection.

A real successful Google login, an external Google callback configuration change, authenticated live-camera playback, and external SMB access were not exercised through the deployed site because no operator credentials were supplied. OAuth identity behavior is covered with mocked providers; state rejection uses the real Socialite provider. SQLite tests do not independently prove PostgreSQL concurrent transaction behavior under every isolation/race scenario.

Camera discovery retains existing support for self-signed camera HTTPS certificates. Isolate camera networks and use trusted routes. The review does not certify camera firmware, the outer reverse proxy, TLS termination infrastructure, the host OS, or every operating-system package in the image. Existing historical logs, audit snapshots, and diagnostic rows were not purged or rewritten; these changes protect newly written records. The outer proxy must have its own policy for query-string and authorization-header logging.

New Google users with third-party email addresses that Google is not authoritative for cannot automatically claim an unlinked account. Use local authentication or an explicitly established subject link. Older Google-only installations awaiting their first login without a recorded setup subject need server-side recovery/reconfiguration; arbitrary first-user administrator bootstrap is deliberately unavailable.

No MFA or recent-password challenge for administrator changes was added. An attacker possessing a valid current administrator session has administrator capabilities. Session duration, workstation protection, restricted network access, and Google account MFA remain relevant operational controls.
