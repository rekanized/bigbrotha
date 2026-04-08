Place the Linux-compatible statically compiled ffmpeg and ffprobe binaries that ship with this application in this directory.

Expected filenames:
- ffmpeg
- ffprobe

Composer reapplies execute permissions to both files during install and update.

This directory is meant to follow the repository so deployments and Docker images can use the same pinned binaries as the application.
