# Optional local motion labeling

Object labeling is opt-in and disabled on every camera by default. It supports
people, cats, dogs, cars, motorcycles, buses, and trucks. Animals and Vehicles
are grouped recording filters. Recording continues independently of AI health.
There is no plate reading, identity tracking, counting, or continuous box overlay.

## Enable on a separate development deployment first

This feature requires an application image built from this source revision;
adding the AI override to an older published application image is insufficient.
Set a random `CAMERA_AI_TOKEN` in the deployment's protected `.env` (generate it
with `openssl rand -hex 32`). Then build/start the source application and CPU AI:

```sh
docker compose -f docker-compose.yml -f docker-compose.build.yml -f docker-compose.ai.yml up -d --build --wait
```

The normal application startup runs migrations. The AI service has no published
port. App and background share the token with the private HTTP service. The CPU
image contains ONNX Runtime, NumPy and headless OpenCV, with one pinned FP32 model;
no CUDA, PyTorch, training framework, model exporter or runtime model download.
The app license remains MIT; see [third-party notices](../THIRD_PARTY_NOTICES.md).

In Camera settings, enable **Label people, pets, and vehicles during motion** on
one camera. Save the motion mask and sensitivity even for continuous recording.
The default analysis stream is the recording profile. An explicitly selected
lower-resolution profile must have matching framing: check its preview against
the mask editor before saving. A differently cropped substream invalidates mask
alignment. Empty masks disable analysis for that camera.

Camera rows show disabled, starting, available, overloaded or unavailable, plus
current activity, confirmed labels and CPU/GPU provider. GPU initialization
failure is shown as CPU fallback. Recording details show labels and counts of
successful/skipped samples in overlapping analysis events. These counts are
sampling evidence, not proof that an entire recording was analyzed. No labels
can mean no motion, no confirmed objects, disabled analysis, or an outage.
Gold timeline dots mark segments with confirmed labels; their tooltips list them.

## Runtime behavior and bounds

One background coordinator supervises one extra FFmpeg decoder per enabled
camera, reusing the relay connection. Opening/closing the mask editor does not
control this process. A single decode produces paired gray motion-grid frames
and color frames at three samples/second. Color frames preserve proportions and
never exceed a 640-pixel long edge or upscale the source. The seven-frame ring
covers two seconds plus the current sample. Lowering output FPS does not remove
the cost of decoding the source stream.

Confirmed motion submits the corresponding lookahead-buffered frame immediately.
A second sample is due after half a second, followed by one per second (rounded
to the next available sample). Activity ends after three quiet seconds, independent
of recording post-roll. Full-frame detections describe visible objects during
motion; a parked car may receive a label even if a person caused the trigger.
A missed motion trigger also means missed AI analysis.

One shared HTTP inference request runs at a time, batch size one, in camera
round-robin order. Each camera retains one initial frame and one replaceable
latest frame. Pending work older than two seconds, replaced samples, failed
requests and interrupted work count as incomplete coverage. Frames stay in
bounded memory. Requests and inference never hold recorder locks.

Two spatially overlapping observations within three seconds confirm a class.
The overlap floor is 0.05 intersection-over-union: a stricter 0.2 floor rejected
fast-moving people/cars in the public calibration clips. This confirms event
classes, not individual identities; distant nonoverlapping boxes still do not match.
A single observation can confirm only if a separately calibrated single-frame
threshold is explicitly enabled in the model manifest. The shipped manifest
leaves this disabled. Repeated observations merge idempotently into class labels
with timestamps and best confidence. Events roll over after about one minute.
Labels associate with finalized recordings by camera and actual observation time,
including adjacent segments and late finalization. Association maintenance runs
every minute when AI is enabled, or hourly when the global service is disabled. Recording deletion cascades through the association table;
unassociated/expired events are pruned using camera retention.

Camera configuration changes discard obsolete results and restart sampling.
Failed samplers retry with exponential backoff capped at 30 seconds. A coordinator
crash leaves stale events marked incomplete by maintenance. Disabling all cameras
stops their decoders and unloads the model within 30 seconds after its last lease.
Removing the AI override and setting `CAMERA_AI_ENABLED=false` also prevents the
coordinator from starting when background restarts. If the override remains in
use, it explicitly enables the coordinator regardless of `.env`'s enabled value.

## Model and CPU configuration

**NanoDet-Plus-m 416 is the only supported model**, shared by the CPU and CUDA
images. It fits motion-triggered people, pet and vehicle labeling with a small
CPU footprint, Apache-2.0 licensing and a tested full-pipeline integration.
[Upstream](https://github.com/RangiLyu/nanodet#benchmarks) reports 30.4 COCO mAP
for this model; local inference measured about 33 ms median on one CPU thread.
These support the engineering choice, not an accuracy guarantee for camera footage.
Confidence 0.35 remains provisional and is not calibrated. The public smoke
evaluation exposes cat/dog confusion and a missed car; representative night,
small-object and vehicle footage is still needed before trusting its labels.
The build checks the upstream model SHA256, removes initializers from ONNX graph
inputs to enable constant optimizations, and records both source and normalized
checksums in `/models/model.json`. Runtime checks the normalized checksum again.

The image build pins the model directly; there is no model-selection setting.
`CAMERA_AI_THREADS` defaults to 1: the initial VM measurements found lower total
CPU cost with one thread. Two reduced latency but used more CPU. Sequential ONNX
execution and disabled thread spinning apply to either setting. See the
[measurements](motion-labeling-measurements.md) before changing it.

For NVIDIA, append `-f docker-compose.ai.cuda.yml` to the Compose command. This
builds a separate CUDA 12/cuDNN image and requests a GPU through NVIDIA Container
Toolkit. CPU installs never pull this target. CUDA initialization failure falls
back to CPU once the container is running; missing host drivers/toolkit can
prevent Docker from starting the GPU container at all. Use the CPU override in
that case. GPU operation is **not hardware-verified** on the current CPU-only VM.
INT8 and GPU FP16 remain later experiments.

## Reproduce performance and accuracy evaluation

Use an isolated network/service and test application image. Never run replay
against the deployed database or use production cameras as fixtures. The replay
script refuses cached configuration and forces SQLite in memory with temporary
storage. Pass `AI_URL` and `AI_TOKEN` for an isolated service. Mount an empty `.env`
in the test container to exclude deployment configuration.

```sh
# Inside the supported test container, with this repository mounted at /app:
php tests/Performance/ai-replay.php /fixtures/clip.mp4 1 20 baseline
php tests/Performance/ai-replay.php /fixtures/clip.mp4 1 20 motion
php tests/Performance/ai-replay.php /fixtures/clip.mp4 1 20 full
# Repeat with 4 and 8 cameras, saving each JSON report.
```

Replay runs concurrent stream-copy readers and the real sampler, motion detector,
scheduler, HTTP service and confirmation/database code. It simulates independent
cameras by reading the same compressed clip. It does not measure real RTSP jitter,
MediaMTX, storage writes, or the full recorder lifecycle. `max_child_peak_rss_kib`
is the largest child peak, not aggregate decoder memory. Measure the AI container's
cgroup CPU and memory separately; inference service CPU is not in PHP's rusage.
Do not overlap performance runs with builds or test suites.

For inference-only measurement, mount `ai/` into the AI container at `/opt/ai` and
run `python benchmark.py /models/model.json --threads 1 --image /fixtures/frame.jpg`.
Repeat with two threads. This excludes stream decoding and motion gating, and
does not establish end-to-end capacity or detection accuracy.

Accuracy evaluation requires separate event clips in calibration and evaluation
splits. Use distinct source scenes, not adjacent pieces of one video. Include
negative events, darkness/IR, small pets, partial occlusion, brief passersby,
multiple objects, long motion with later arrivals, weather, shadows, and exposure
changes. Preserve scene compression and document clip provenance and annotations.

For accuracy replays, copy `/models/model.json` out of the AI image, set
all class `minimum` thresholds to `0.01` and all `single` thresholds to null in that
benchmark copy, and mount it read-only into an isolated service with `AI_MANIFEST`
pointing to it and `AI_MODEL_DIR=/models`. Do not use this permissive manifest in a
normal deployment. The replay records the checksum and thresholds for validation.
Prepare a dataset JSON array such as:

```json
[
  {"split":"calibration","truth":["cat"],"report":"cat-calibration.json"},
  {"split":"evaluation","truth":[],"report":"negative-evaluation.json"}
]
```

Run `python calibrate.py /fixtures/dataset.json --precision 0.95` with `ai/` mounted.
The tool requires single-camera full-pipeline results, rejects duplicate reports,
checks model identity and score floor, and calibrates each class independently.
It reports insufficient coverage unless each split has at least 20 positive and
20 negative events for that class. This minimum is a guard, not statistical proof.
Evaluation precision must meet the target too. Single-frame thresholds remain
disabled; this tool does not calibrate them. Report event recall at the target
precision for each class, including weak or unrepresented categories and
uncertainty. Keep the coverage guard and use a fresh holdout set after tuning.

Upstream references: [NanoDet](https://github.com/RangiLyu/nanodet),
[ONNX threading](https://onnxruntime.ai/docs/performance/tune-performance/threading.html),
[ONNX CUDA requirements](https://onnxruntime.ai/docs/execution-providers/CUDA-ExecutionProvider.html).
