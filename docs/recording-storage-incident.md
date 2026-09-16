# Recording storage investigation — 11 September 2026

## Production evidence

The initial production database inspection found 589 failed rows, including 73
with the hourly missing-storage message. The other failures were historical motion
analysis errors; no failed captures had been created in the preceding four days.
Production uses SMB storage. Diagnostic PHP processes must load the persisted
application key before bootstrapping Laravel: without it, encrypted storage
credentials cannot be read and settings can appear to select local storage.

Recording 201625 demonstrates a concurrent finalization race in the audit log:

1. At 13:42:12 UTC on 6 September, motion finalization saved its MKV.
2. At 13:42:15, review generation changed the database path to MP4.
3. At 13:42:17, another finalization pass wrote the old MKV path back, using stale
   processing state and a different event end time.
4. At 14:05:38, maintenance marked that obsolete path missing.

The MP4 still exists on SMB. Thirteen of the 73 affected rows had this recoverable
layout. The other 60 had neither their referenced file nor a same-stem alternate
in the active or supported legacy SMB layout. The audit log does not establish
which operation removed every absent file. Retention deletion was not established
as the cause of the demonstrated race.

## Additional code defects

The scheduler called motion synchronization without the queue's per-camera lock.
Its scheduling lease could also expire during a lengthy pass. Review generation
wrote unfinished MP4 output into the recoverable SMB staging directory, changed
the database path before remote publication, and cleaned up staged output even
after publication failures. Availability recovery could upload and unlink another
process's staged input. These windows could produce false missing-file states or
lose the only completed replacement. Production background logs also contained
review-generation errors for disappearing staged MP4 input files.

## Resolution

Motion passes now share a process-lifetime file lock. Review encodes in an isolated
workspace, verifies publication before changing the database path, and retains
completed staged output after failed publication. Availability recovery does not
unlink staged inputs. Maintenance and review jobs share a recording lock, with a
review lease longer than the worker timeout. Queue jobs refresh state after
locking and do not reopen terminal recordings. Camera-scoped retention now
actually scopes deletion as well as recovery and reconciliation.

Maintenance recovery can reconnect stale source paths to a nonempty saved MP4.
Unavailable footage is not presented as recovered, and historical failure/audit
rows are not erased to conceal missing files. Normal retention still applies.

The 16 September test follow-up found a second stale-state deletion path:
motion finalization saves a separately loaded active recording, while the
preferred queued-row instance can still report `processing`. Cleanup now
reloads that preferred row before deciding whether to discard it, preserving
the completed recording and its file reference. The preferred-row regression
supplies explicit closed segments to verify promotion, finalization, and row
identity without racing the background fake FFmpeg process. A quiet,
unclaimed preferred row is still discarded.

Regression coverage includes overlapping motion synchronization, maintenance
during publication, failed SMB publication preserving the original path and staged
copy, and recovery of stale source paths.
