# Agent Entry Point

Use this file as the project-level entry point for AI agents working in this repository.

## Start Here

Read these files first when you need project context:

1. `README.md`
2. `docs/video-platform-architecture.md`
3. `docs/camera-fleet-workflow.md`
4. `docs/known-issues-and-constraints.md`
5. `.github/copilot-instructions.md`
6. `.github/instructions/no-build-frontend.instructions.md`
7. `.github/instructions/video-platform.instructions.md`

## Project Summary

This is a Laravel 13 camera operations platform for ONVIF and RTSP devices. The current implementation covers discovery, manual ONVIF verification, camera fleet management, RTSP retrieval, stream diagnostics, preview capture, Google-authenticated operator access, and shared live-wall playback through a Composer-managed MediaMTX WebRTC relay with Laravel-backed auth.

## Non-Negotiable Constraints

- Composer-only project.
- No npm, Node build tooling, Vite, Tailwind, Bootstrap, Sass, Less, or frontend asset pipeline.
- Prefer Blade, Livewire, standard CSS, and plain JavaScript only when necessary.
- Preserve operator-facing workflows for discovery, fleet management, previewing, and live monitoring.

## Current High-Value Areas

- ONVIF sweep and manual probe flow.
- Camera Fleet Livewire manager.
- RTSP profile retrieval and diagnostics.
- Preview storage and serving.
- Shared MediaMTX live wall behavior.
- Google OAuth and Laravel session auth for operator routes.
- MediaMTX auth callback behavior for WebRTC reads and internal RTSP publishing.
- Reverse-proxy and WebRTC deployment behavior.
- HTTP IP restriction behavior versus discovery behavior.

## Maintenance Rule

If a change materially affects architecture, workflow, storage, routes, or operational constraints, update the docs under `docs/` as part of the same change.