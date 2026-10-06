# Security assessment — 6 October 2026

**Status: application fixes implemented; deployment and exposed credential rotation remain outstanding. This is not a certification of complete safety.**

Scope: the BigBrotha source tree at `a66a582`, PHP dependencies, Docker build and runtime configuration, image packages, local Git history, and an isolated four-service deployment. The running application's image and camera credential fingerprints were inspected read-only. No production passwords, recordings, database contents, or application keys are included in this report.

## Findings addressed

| Finding | Impact and change |
| --- | --- |
| Browser-controlled discovery metadata | Livewire clients could modify `draftMetadata`, `probeResponse`, and nested `rtspProfiles`, then persist them. These server-generated properties are now locked. Regression tests reject both whole-property and nested URL updates. |
| Unrestricted media input protocols | Saved or discovered stream URIs could reach media tooling without an RTSP scheme check. Live playback, recording selection, diagnostics, and discovery now reject non-RTSP inputs and malformed addresses. |
| Camera-controlled request destinations | ONVIF discovery could forward authenticated requests to another advertised host. Media/PTZ service discovery and discovered RTSP addresses are now restricted to the configured device host; service ports may differ. Redirects remain disabled. |
| Disabled TLS verification and HTTPS downgrade | Device/media probes now verify certificates. HTTPS survives camera draft creation and persistence; discovery cannot downgrade an HTTPS service to HTTP. Advertised network details cannot replace an explicitly configured device IP. |
| Remembered local-login method confusion | Restoring a local remember-me cookie without `auth_method` could fall back to a linked Google identity when local login was disabled. Restored cookies now retain their local authentication method and are rejected when local access is revoked. A real-cookie regression covers this case. |
| Unnecessary FastCGI network exposure | PHP-FPM now binds only to `127.0.0.1:9000`. An isolated sibling container verified that it cannot connect to FastCGI. |
| Broad implicit proxy trust | Removed the image's default trust of `172.16.0.0/12`. Deployments must explicitly set actual reverse-proxy addresses. Public URL generation continues to use `APP_URL`. |
| Overly readable runtime directories | Private media/configuration, sessions/cache, and logs are restricted to the service owner/group. Runtime directory and log normalization now uses `2770`/`0660`, with a restrictive startup umask. |
| Legacy image dependencies and build tools | Moved the image from Debian 12 to Debian 13, updated system packages, and removed compilers, development libraries, and kernel headers from production. Build-time extension checks prevent cleanup from accidentally removing required PHP libraries. |
| XML parser exposure | ONVIF clients reject DTD/entity declarations before invoking libxml, reject alternate encodings containing NUL bytes or invalid UTF-8, and cap XML parsing at 1 MiB. `LIBXML_NONET` remains enabled. This mitigates the reviewed internal-subset attack path; it does not patch the system library. |

## Validation

- Composer locked-dependency audit: no advisories and no abandoned packages. Versions include Laravel 13.34.0, Livewire 4.4.7, and Socialite 5.31.0.
- Source secret scan: no findings. Separate Git-history scanning is described below.
- Regression tests cover component tampering, unsafe protocols, cross-host requests, TLS verification, HTTPS persistence, XML rejection, remembered-login revocation, and replaying a camera component after logout.
- Production image verification checks nginx configuration, effective loopback FastCGI binding, required PHP extensions, bundled binaries, absence of development tools, and exclusion of deployment secrets.
- Fresh isolated PostgreSQL/app/background/relay stack: all four services healthy. Real HTTP checks passed for first-admin setup, CSRF enforcement, session cookie attributes, authenticated settings/camera/recording/live-wall pages, private caching, security headers, hostile Host rejection, hidden-file denial, and arbitrary PHP-path denial.
- Compose configuration checks passed, including production/development/build overrides and required deployment inputs.
- Final reproducible snapshot: **468 tests passed, 2,403 assertions** on PHP 8.5 / Debian 13, using in-memory SQLite and no network.
- Acceptance image: `bigbrotha:security-fixed-20261006`, image ID `sha256:4f57bf5f03de643ce185544fbb0c846719b243d7a5c9b0947fd3292fd38625c9`.

Concurrent relay/player edits were observed during the review. They were preserved. Final security acceptance uses an immutable copy of `a66a582` plus the security changes, excluding those unrelated edits. Browser DOM tests for the separate player changes are not part of this security acceptance.

## Outstanding: exposed camera credential

Gitleaks scanned 109 commits and flagged eight encrypted camera passwords in the deleted `database/seeders/data/CurrentCameraWallSeed.php`, commit `9a06856960`. Further inspection found a plaintext RTSP password embedded in `probe_message` at historical line 1088. The plaintext value is not reproduced here.

A follow-up exact-value scan of all 1,911 reachable Git blobs found the same plaintext credential in **10 distinct historical file versions across three files**. In addition to the seed, it appears in `tests/Feature/CameraFleetManagerTest.php` and `tests/Feature/RtspStreamDiagnosticsServiceTest.php`, confirmed in commit `f4b4836` and still present in `12e1986`. Commit `a66a582` removes those test occurrences; the seed was removed in `1046542`. Those removals do not erase earlier history. The initial report identified the seed occurrence only; this expanded scan corrects that scope.

A read-only hash comparison against the running application's eight configured cameras found **seven matches**. This establishes continued configuration reuse; the audit did not attempt authentication to the devices. Rotate the affected device credentials and update BigBrotha and any other camera consumers together. Removing a file from the current tree does not revoke a password or remove it from old commits, clones, backups, or published artifacts. Git history was not rewritten or force-pushed.

No `.env`, `.env.docker`, `auth.json`, `.docker-state`, or SQLite database history was found at the checked paths. The identified seed did not contain an application encryption key. Neither observation proves that a key or credential was never exposed elsewhere.

## Outstanding: system-library advisories

Trivy 0.75.0 with its downloaded vulnerability database reported the following **package/advisory occurrences**, not individually confirmed exploitable application vulnerabilities:

| Image | Critical | High | Medium | Low | Unknown | Total OS occurrences |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Original Debian 12 image | 14 | 486 | 2464 | 1127 | 44 | 4135 |
| Rebuilt Debian 13 image | 4 | 239 | 324 | 227 | 25 | 819 |

No reported OS occurrences had an available fixed version in the scanner's distribution data. This is **not** a clean scan. Repeated FFmpeg-library entries account for many occurrences. Keep device/network input trusted and continue rebuilding and scanning for distribution fixes.

The four remaining critical-labeled occurrences concern three LMDB advisories and libxml2 CVE-2026-6653. Debian describes the reviewed LMDB condition as requiring an attacker-supplied/corrupt LMDB database; no application feature accepting such databases was identified. Debian also lists the libxml2 internal-subset issue as unfixed in trixie. The ONVIF pre-parse DTD guard reduces the relevant application exposure. These are applicability observations, not universal claims of non-exploitability. See [Debian LMDB tracking](https://security-tracker.debian.org/tracker/CVE-2019-16224) and [Debian libxml2 tracking](https://security-tracker.debian.org/tracker/CVE-2026-6653).

The original image also contained Mbed TLS with ended support in bookworm; Debian lists the trixie version as fixed for the reviewed advisory. See [CVE-2025-47917](https://security-tracker.debian.org/tracker/CVE-2025-47917). Some scanner labels require interpretation: Debian states that the original zlib binary package does not build the vulnerable MiniZip code for [CVE-2023-45853](https://security-tracker.debian.org/tracker/CVE-2023-45853).

MediaMTX's Go module metadata has one unknown-severity occurrence, [GO-2026-5932](https://pkg.go.dev/vuln/GO-2026-5932), covering the unmaintained OpenPGP packages within `golang.org/x/crypto`. A module match alone does not establish that those packages are linked or reachable; binary-level reachability was not established. Composer packages inside both images had no findings.

## Deployment and compatibility

The production application has not been restarted, repointed to the new image, or given new credentials. The security changes must be built and deployed before they protect it. The local image `bigbrotha:security-fixed-20261006` is an acceptance artifact; it has not been published to a registry.

Before rollout, set narrowly scoped `TRUSTED_PROXIES` if forwarded client addresses are required, provision trusted CA certificates for HTTPS cameras, and ensure discovered ONVIF/RTSP addresses use the configured device hostname or IP. Non-RTSP stream inputs and cross-host discovery are intentionally rejected. The Debian upgrade also changes FFmpeg and other system-library versions; the isolated tests do not replace a check against actual camera hardware.

The host OS, camera firmware, external reverse proxy, network firewall, other services on this Docker host, and third-party account security were not comprehensively audited. Existing active media sessions may continue until disconnected; token validation governs new access. A test pass or an advisory scan cannot guarantee the absence of unknown vulnerabilities.

Raw scanner evidence is retained locally in the ignored, private directory `tmp/security-check-2026-10-06/`. Reports contain redacted history findings; do not publish raw operational evidence without reviewing it.
