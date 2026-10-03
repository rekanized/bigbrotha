# Background CPU investigation — 2026-10-03

The affected deployment was `actualbigbrotha`, not the separate `bigbrotha` development stack. Its background container had an empty database queue and no failed queue jobs, but eight enabled motion cameras were still capturing and analyzing footage.

## Findings

- Background CPU snapshots ranged from about 155% to 247%. A 45-second cgroup measurement averaged 183% (100% represents one CPU core).
- One camera had about 11,000 temporary motion segments, occupying 2.8 GB. Its analysis cursor was hours behind current footage.
- `camera-recordings:tick` analyzed every available segment before returning. It also materialized the entire backlog as timestamp objects and pruned only after processing the batch.
- The scheduler's five-minute overlap lease expired while the tick was still running. Multiple ticks could then run concurrently, although the per-camera process locks kept them from processing the same camera simultaneously.
- Review playback and scrub-sprite generation did not bound all decoder, filter, and encoder thread counts. These jobs produced additional CPU spikes.
- The scheduler executes motion analysis and review backfills directly. Queue workers can also consume newly dispatched review jobs immediately. An empty queue therefore does not establish that background work is idle.
- An hourly retention task was also running against network storage. Its CPU contribution was much smaller than the video processing; retention remains enabled.

## Code changes

Motion synchronization now processes at most 60 segments or a ten-second budget per camera per batch. Both limits are configurable in `config/recording.php`. The budget is checked between segments; an individual decode, clip finalization, or storage operation can exceed it. One lookahead segment detects deferred work without constructing timestamp objects for the entire backlog. Deferred footage and pre-roll are retained, and an active event is not finalized before unexamined segments can extend it.

A process lock guards the complete recording tick. It remains held until the process exits, even if the Laravel scheduler lease expires. The per-camera process locks remain in place.

Playback review generation uses `FFMPEG_THREADS` for decoding and encoding and one filter thread. Scrub sprites use one decoder, filter, and encoder thread. Playback quality, motion thresholds, recording policy, and recording retention were not reduced.

Restarting a recorder on the same source now preserves its compatible buffers. If an active event's entire buffer window has already been lost, newer buffered footage proves the loss and permits the recorder to mark that event failed and resume subsequent decisions. An event remains pending when no newer footage proves its window is gone, or when a completed staged clip is available for a storage retry.

## Rollout and limitations

The first restart exposed an existing startup behavior that deleted temporary motion buffer directories. The remaining unprocessed backlog was lost in that restart; saved recordings were unaffected. The final code prevents the same loss on compatible restarts, and this was verified in the running deployment. Two interrupted events whose footage was already unavailable were marked failed rather than falsely advertised as recorded.

All three application roles were rebuilt and deployed using the local image `bigbrotha:cpu-fix-20261003`. The database, persistent volumes, and encryption key were retained. The deployment Compose file pins the local image, and its installed start wrapper respects that image's pull policy. The previous Compose file and wrapper are under `actualBigbrotha/deployment-backup-20261003-cpu-fix/`, with rollback instructions. No registry publication was performed.

Post-deployment CPU measurements were substantially lower, with idle intervals around 7% and short active intervals above 100%. The first two-minute measurement after preserving restart buffers averaged about 57%; a later three-minute check while both recovered cameras caught up averaged about 90%. All eight camera cursors were advancing, both recovered cameras saved new clips, and the queue emptied again. This is not an isolated benchmark of the thread-limit change: the first restart also cleared the historical temporary backlog. Normal motion analysis and review generation still require CPU even with an empty queue.

Validation included 401 passing PHPUnit tests, formatting checks on touched PHP files, Compose checks, a real FFmpeg H.264 playback and JPEG sprite smoke test, and live application, worker, scheduler, and relay health checks. Live checks confirmed new saved clips, buffer preservation across the corrected restart, and recovery of the two blocked events. No browser playback session was opened during this investigation.
