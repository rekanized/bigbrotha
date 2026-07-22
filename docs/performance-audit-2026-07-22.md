# Performance Audit - 2026-07-22

## Result

The application, database, media relay, recording workers, HTTP runtime, asset delivery, and Docker deployment were profiled against the running `actualbigbrotha` stack and then re-measured after the changes in this audit.

| Measurement | Before | After | Result |
| --- | ---: | ---: | ---: |
| `/up` average, 50 sequential requests | 39.2 ms | 24.5 ms | 37.5% lower |
| `/up` p95 | 48.2 ms | 34.1 ms | 29.3% lower |
| `/up` maximum | 78.1 ms | 37.0 ms | 52.6% lower |
| Default audit-log row lookup | 1,276.6 ms | 0.193 ms | more than 6,600x faster |
| Main theme transfer | 67,184 bytes | 9,159 bytes | 86.4% smaller |
| Timeline JavaScript transfer | 150,945 bytes | 25,594 bytes | 83.0% smaller |

The release test image completed 304 tests with 1,607 assertions. Composer validation and its locked production dependency security audit passed with no advisories.

## Changes Made

- Added the missing descending audit-log listing index. PostgreSQL creates it concurrently so existing audit writes remain available during deployment.
- Stopped writing a generic recording audit row for every rolling-motion heartbeat. Recording state transitions and later operator-significant updates remain audited.
- Replaced four recording-summary count queries with one conditional aggregate query.
- Added one request-scoped `app_settings` snapshot shared by application and authentication settings, removing duplicate settings reads and schema probes during bootstrap.
- Removed repeated shared-directory permission and filesystem normalization work from HTTP requests. Container startup and console processes retain that responsibility.
- Warmed route, event, and compiled Blade caches at app and background startup. Configuration remains deliberately uncached so decrypted database-backed credentials are never serialized into a cache file.
- Enabled gzip, Nginx open-file metadata caching, safe browser caching/revalidation, immutable-image OPcache, a larger realpath cache, and a bounded dynamic PHP-FPM pool.
- Added runtime configuration regression coverage and documented the immutable-image cache contract.

## Steady-State Production Verification

- All four `actualbigbrotha` services are healthy with zero restarts.
- Eight motion recorder processes, two queue workers, and the scheduler supervisor are running.
- MediaMTX reports 42 configured paths, eight ready source paths, and eight active recorder readers.
- PostgreSQL reports a 99.766% block-cache hit rate and zero deadlocks.
- The audit table contained 612,328 rows and the recording table contained 42,492 rows during final verification.
- Compiled views, route cache, and event cache are present. `bootstrap/cache/config.php` is absent by design.
- CSS conditional revalidation returns `304`; versioned JavaScript receives a one-hour browser freshness window.
- Production OPcache timestamp validation is disabled and the realpath cache is 4 MiB with a 600-second TTL.

## Capacity And Follow-Up Watch Items

These were measured and are not current incidents, but they define the next scaling thresholds:

- Numbered audit pagination still requires an exact total count. At roughly 612,000 rows that count measured about 132.6 ms; the row lookup itself is now sub-millisecond. If audit history grows well beyond the current 30-day retention window, consider a short-lived count cache or a product decision to use cursor pagination.
- The default 48-hour timeline window currently covers about 3,985 recording rows and its database query measured about 17.7 ms. Existing rail virtualization limits browser DOM cost. An aggregate-first timeline bootstrap becomes worthwhile if camera count or retention density grows substantially.
- The no-build CSS contract uses nested standard-CSS imports. Gzip and conditional revalidation greatly reduce transfer cost, but the import waterfall remains the principal frontend request-count constraint unless the project's explicit no-build policy changes.
- The host had about 10 GiB of memory available. Swap contained roughly 1.8 GiB of historical pages, but the running containers showed no active memory exhaustion, OOM condition, or restart loop.

## Deployed Artifact

- Image: `rekanized/bigbrotha-app:20260722165300-performance-audit`
- Docker Hub digest: `sha256:c99dd0c8a9c3c4c46e5480fd72b8840558b82dbfe5da935cf11a060109193264`
- The same digest is published as `rekanized/bigbrotha-app:latest`.
