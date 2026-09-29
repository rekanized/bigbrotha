---
name: camera-fleet
description: Work on BigBrotha camera intake, ONVIF or RTSP profiles, stream diagnostics, and preview lifecycle.
---

# Camera Fleet

Use this skill for `/camera-fleet` behavior. Start with [the workflow](../../../docs/camera-fleet-workflow.md) when operator steps matter.

## Flow and ownership

- `routes/web.php` maps the main page to `CameraFleetController`, which renders `app/Livewire/CameraFleet/Manager.php` and `resources/views/livewire/camera-fleet/manager.blade.php`. The editor behavior also uses `public/js/camera-editor-modal.js` and `public/js/camera-motion-editor.js`.
- New ONVIF cameras follow a probe-first draft: `OnvifCameraDraftService` uses `OnvifDeviceProbeService` for SOAP device/network details, then `OnvifRtspStreamService` for Media profiles and `GetStreamUri`. The draft is editable before insertion. RTSP-only cameras can be entered manually without ONVIF.
- `Camera` holds network fields, encrypted password, enable/capability flags, recording policy, and JSON `metadata`. `metadata['rtsp_profiles']` carries stream URIs, probe status, codec/resolution, and preview paths. Use model helpers `onvifEndpoint()`, `rtspEndpoint()`, `rtspProfiles()`, and `rtspPreviewRefreshTarget()` rather than reconstructing those values.
- `RtspStreamDiagnosticsService::testAndPreview` runs ffprobe and ffmpeg, tries configured transport, and can reuse an active canonical MediaMTX path if a camera rejects another reader. `CameraFleetStreamPreviewController` serves validated images or a placeholder from private storage. `RefreshCameraPreviewJob` and `camera-fleet:refresh-previews` maintain thumbnails every 30 minutes.
- Camera edits that alter relay paths call `Manager::syncRelayConfig()`. When changing profile matching, preserve saved diagnostic/preview metadata as `Manager::mergeDiscoveredProfilesWithSavedState()` does.

## Pitfalls and checks

- Direct probe requires routed camera reachability from the app container. `WEBSITE_ALLOWED_IPS` only restricts incoming HTTP. Do not diagnose ONVIF probe failures as multicast discovery failures; current onboarding uses a direct service URL.
- Some cameras support device information but omit media profiles. Keep the manual RTSP path viable. A blank password on edit preserves the stored password.
- Preview files belong in `storage/app/private/cameras/{id}/previews` and are delivered through authenticated routes, not `public/storage`.
- Focused tests: `tests/Feature/CameraFleetManagerTest.php`, `OnvifDeviceProbeServiceTest.php`, `OnvifRtspStreamServiceTest.php`, `RtspStreamDiagnosticsServiceTest.php`, and `RefreshCameraPreviewsCommandTest.php`. Use the development container test command in `AGENTS.md`.
