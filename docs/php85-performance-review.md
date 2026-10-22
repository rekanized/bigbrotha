# PHP 8.5 performance review

Reviewed on 2026-10-22 against baseline `ece9652`, using PHP 8.5.12. Changes are
in the source workspace; the running deployment has not been rebuilt or
restarted. No production database, settings, media, or camera policies were
modified.

## Implemented improvements

| Area | Change | Preserved behavior |
| --- | --- | --- |
| Queue dashboard | Rank scheduling columns in SQL and retrieve one payload per queue, instead of loading every queued payload into PHP | Counts, queue ordering, earliest availability, ID tie breaking, empty queues and job labels |
| Motion masks | Compute bounds from normalized runs without expanding every selected pixel | Clipping, merged/disjoint runs, row boundaries, empty masks and percentage rounding |
| Motion analysis | Skip pixel loops for identical frames, reuse absolute deltas, avoid unnecessary recovery comparisons, copy only the required history for latest-only previews, and avoid constructing an unused mask index list before decoding | Recording decisions, pixel order, weighting, rejection reasons, lookahead/lookback, frame counts and offsets |
| Timeline date formatting | Build the immutable timezone lookup once per process/request lifetime | Current operator timezone still resolves on every call; saved changes take effect immediately |

No dependencies, database schema, recording formats, encoding settings,
authentication rules, deployment settings, or browser assets changed.

## Measurements

Synthetic component measurements on the same host and PHP 8.5.12 image, with CLI
OPcache and JIT disabled for both revisions. Timing varies with host load.
These measure the code under test, not complete request latency or camera
throughput. Peak additional memory is PHP allocation after warmup, not RSS.

| Workload | Before | After | Observation |
| --- | ---: | ---: | --- |
| Queue summary, PostgreSQL 18, 5,000 jobs / 4 queues / 8 KiB payloads | 93.568 ms | 4.975 ms | About 95% less time |
| Same queue summary, in-memory SQLite | 162.650 ms | 30.333 ms | About 81% less time |
| Queue summary peak additional PHP allocation | 64,048,856 bytes | 75,304 bytes | Payload allocation is bounded by queue count |
| Full-frame mask bounds, 640×640 | 50.301 ms | 0.013 ms | Work follows run count instead of selected pixel count |
| Mask bounds peak additional allocation | 8,394,992 bytes | 1,976 bytes | No expanded pixel array |
| Identical quiet frames, 160×90, 180 frames | 131.262 ms | 2.817 ms | Exact equality fast path; sensor noise reduces this benefit |
| Local movement fixture, 160×90, 180 frames | 393.195 ms | 285.702 ms | About 27% less time |
| Latest-only analysis of the same movement fixture | 3.212 ms | 2.533 ms | About 21% less time |
| Latest-only peak additional allocation | 3,250,376 bytes | 375,304 bytes | About 88% less temporary allocation |
| Operator timestamp formatting | 0.037 ms | 0.007 ms | About 81% less time per formatting call |

Before/after result hashes matched for every benchmark. PostgreSQL validation
used a separate ephemeral PostgreSQL 18 container sharing only the isolated test
container's network namespace, with synthetic data in a temporary table.

The committed benchmark scripts are documented in
[tests/Performance/README.md](../tests/Performance/README.md). The PostgreSQL
check used the same queue benchmark with its connection changed to that isolated
fixture database.

## Review coverage and decisions

The review covered application boot and service lifetimes; PHP/FPM, nginx and
Docker configuration; Composer requirements; HTTP routes and middleware;
authentication/setup; Livewire administration and camera management; Eloquent
queries and indexes; live relay and ONVIF operations; motion and continuous
recording; storage and review assets; workers, scheduled jobs and health checks;
Blade/browser polling and lifecycle behavior; and the test/benchmark setup.

- **PHP runtime:** Composer already requires PHP 8.5, the runtime uses PHP 8.5.12,
  production autoloading is authoritative, and FPM OPcache and realpath caching
  are already configured. PHP 8.5 bundles OPcache but still honors its enable
  flags. The Dockerfile correctly avoids separately loading `opcache.so`.
  [PHP migration guide](https://www.php.net/manual/en/migration85.incompatible.php)
- **Compatibility:** Scanned first-party code for changed/deprecated usage and
  exercised the suite under PHP 8.5.12. The database configuration already selects
  the new PDO MySQL constant when applicable. No tested PHP deprecations or
  warnings were reported.
  [PHP 8.5 deprecations](https://www.php.net/manual/en/migration85.deprecated.php)
- **CLI OPcache/JIT:** An additional OPcache-enabled benchmark produced mixed
  results: quiet-frame analysis improved, but the movement fixture did not.
  Kept the existing runtime settings. Enabling JIT, adding preloading, or raising
  FPM concurrency needs a representative deployment workload and memory budget;
  none is necessary for the measured application changes.
  [OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php)
- **Laravel caches:** Route, event and view caches are warmed by the entrypoint.
  Its deliberate avoidance of `config:cache` preserves database-backed settings.
  Retained scoped settings/relay caches rather than extending their lifetime and
  risking stale credentials, authorization or live-path state.
- **Queries:** Recording lists use pagination, camera relationships are eagerly
  loaded where needed, timeline queries select explicit columns and have camera/
  status/time indexes, and retention/reconciliation generally uses chunks.
  Wide timeline date ranges still load the matching recording set for summaries;
  reducing that further would require changing the page's query/data contract.
  Audit filter option queries and one-off storage/import scans remain candidates
  for future production profiling, not established bottlenecks in this review.
- **Media/storage:** Existing stream-copy paths, shared relay sources, local
  review assets, bounded motion work, locking and remote-availability checks were
  retained. FFmpeg and network/storage costs need their own measurements; PHP
  syntax changes do not remove those costs. No tuning that risks dropped motion
  events or incomplete uploads was introduced.
- **PHP 8.5 language features:** Replacing URL parsing with the new URI API,
  introducing persistent cURL sharing, or rewriting working code with the pipe
  operator would add behavioral surface without a demonstrated gain here. The
  implemented gains come from less work and allocation on the existing runtime.
  [PHP 8.5 release notes](https://www.php.net/releases/8.5/en.php)

## Verification

- Baseline: **489 tests, 2,797 assertions passed** on PHP 8.5.12.
- Performance-change suite: **495 tests, 4,491 assertions passed**, including real
  FFmpeg cases, storage/error-path fixtures, authentication, setup, recording,
  retention, relay, Livewire and timeline coverage.
- Concurrent test cleanup appeared in the shared workspace during final review.
  Those edits were preserved. The current combined suite was then rerun:
  **485 tests, 4,414 assertions passed**. All changed/new PHP files were
  checksum-compared with the isolated test container.
- An additional run with CLI OPcache enabled passed **131 focused tests and
  2,243 assertions** covering motion, masks, administration and timelines.
- New tests exhaustively compare bounds for every contiguous run on several
  small grids, cover disjoint/empty/clipped runs, compare full and latest-only
  motion across varying rejection windows, cover partial frames and identical
  frames, verify queue ties/empty queues, and verify timezone changes in one
  service instance.
- **2,500 additional deterministic differential comparisons** against the
  original detector/mask implementation passed, varying masks, grayscale frames,
  thresholds, cluster radii, weights, persistence windows and both analysis modes.
- Browser fixtures: motion editor (37), operator UI (12), PTZ (28), timeline (38)
  passed at both 1280×800 and 390×844. Live-player/MediaMTX reader fixtures passed
  **56 checks**.
- Eight synthetic live streams passed cold, source-warm and live-warm startup,
  ten-second continued audio/video reception per scenario, and session teardown
  with zero remaining sessions. This is a smoke test, not an endurance test or an
  audible-output/physical-camera guarantee.
- Full Pint formatting check, Composer validation, installed platform
  requirements, locked dependency audit, Compose checks and `git diff --check`
  passed. Composer reported no known dependency advisories at review time.

The complete PHPUnit suite uses in-memory SQLite; the changed queue query was
also measured and output-compared on PostgreSQL 18. Actual camera firmware, SMB
servers, external OAuth sign-in, long-duration recording, and real-media timeline
scrolling were not revalidated against production. Tests and synthetic workloads
provide evidence of preserved behavior, not a guarantee that every possible
runtime condition is bug-free.
