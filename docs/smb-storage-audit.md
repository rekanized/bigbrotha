# SMB recording storage audit

## Connection and routing

An administrator enables SMB storage at `/admin/settings` with a `//host/share/cameras` or `smb://host/share/cameras` path, username, and password. The password is encrypted in `app_settings`; the form never reloads it. A domain prefix such as `DOMAIN\operator` is supported. The path parser rejects URI credentials, traversal, control characters, and characters that would break an `smbclient` command. Settings are saved without probing the share. A new request or worker boot applies the database setting to the private `camera_private` filesystem disk. Configuration is deliberately not cached because these credentials can change at runtime.

The Docker image contains `smbclient` and the PHP SMB adapter. Only finished recording clips matching `cameras/{id}/recordings/.../{file}` use the remote disk. The disk root is the configured `cameras` directory, so a database path such as `cameras/7/recordings/2026/09/29/clip.mkv` maps to `7/recordings/2026/09/29/clip.mkv` below that root. Previews, review assets, motion buffers, ffmpeg work files, and SMB read caches remain on the private local volume. The app and background services share that volume.

## Publish and retry

FFmpeg writes each clip to local private staging. The storage service checks whether the destination directory exists and creates missing ancestors. Routine uploads need only the final directory check. The upload goes to a random `.uploading-*` name, and its remote size must equal the staged file size before promotion. If a final file already exists and blocks a rename, the service moves it to a random `.replacing-*` backup, promotes the new file, and restores the backup if promotion fails. The same temporary-file rule now applies to the PHP adapter fallback when the CLI is unavailable. The final remote file is checked again before local staging is deleted. Upload failures keep the staged clip for retry; continuous and motion segment processing revisit pending clips on later ticks. Transfer timeout grows with clip size, capped at 180 seconds.

The `smbclient allinfo` size parser accepts both labeled size fields and Samba's default data stream line (`stream: [::$DATA], N bytes`). A motion tick stops processing later buffered segments after a publish failure, preserving its staged clip for one retry on the next tick instead of retrying the same upload for every segment.

The `smbclient` subprocess gets credentials through a temporary mode-0600 authentication file, removed after each command. The password is not an argument. The service does not log credentials or public media URLs.

## Read, retention, and deletion

Authenticated playback and downloads resolve remote clips into uniquely named local cache files. The cache file is removed after serving. Availability uses SMB metadata, including a legacy read path above `cameras` for recordings written before root normalization. Failed metadata checks are treated as unreachable rather than as proof of deletion. A read cache copy that fails is not returned as a complete recording.

Retention deletes the durable clip, then its local review assets and database row. A failed remote delete does not count as a missing file. The expired-recording audit checks metadata without downloading each clip. Camera deletion stops if removal of its remote directory fails and keeps the camera row and local files for a later attempt. On success, it also removes local previews and staging. Orphan inspection scans the active remote camera tree; legacy files outside that root and untracked local staging require separate operator review before cleanup.

## Verification and limits

The SMB tests cover path validation, private authentication files, directory lookup cost, size verification, partial CLI and adapter uploads, failed replacement restoration, failed deletes, and local-file preservation. The full isolated PHPUnit suite passed with in-memory SQLite. The production-style `bigbrotha` test containers and the published `/up` endpoint passed health checks.

The test stack had SMB disabled during this audit. No NAS credentials or live share were available, so a real SMB connection, throughput, permissions, and playback round trip remain unverified. After enabling SMB, inspect the recording queue and background health, then record and play a short clip before relying on the share for capture.
