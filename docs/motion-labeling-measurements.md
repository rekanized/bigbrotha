# Motion labeling validation measurements

Initial CPU VM measurements, October 2026. These are engineering checks on a
shared VMware VM exposing eight i7-6700K vCPUs and approximately 15 GiB RAM.
They are not accuracy certification or a supported camera-count promise.
No NVIDIA GPU was available.

## Single-model verification, 27 October 2026

The CPU image was rebuilt with only NanoDet-Plus-m 416. Its normalized checksum
remains `ba89e0af46b91cb4538417c61fb4dd697b825adc351b3595377dfc66a3518abd`.
Detections on the reference image were identical before and after consolidation.
All 19 Python tests and 500 application tests (4,500 assertions) passed, covering
manifest rejection, inference, authentication, stale frames, CPU fallback,
model unloading, label confirmation, recording association and retention.
CPU and CUDA Compose configurations validated; GPU hardware remains untested.

Fresh eight-second isolated replays with shipped thresholds confirmed the laser
pointer cat event (8 analyzed frames, none skipped), made no inference requests
for a quiet clip (25 motion frames), and processed four synthetic cameras
(32 analyzed frames, none skipped). These verify the workflow, not field accuracy
or a supported camera-count promise. No thresholds changed during consolidation.

## Inference-only measurement

FP32, ONNX Runtime 1.23.2, five warmups and 100 measured calls, one shared session,
sequential execution, spinning disabled. The input was the public Ultralytics bus
example image. Detection correctness on one image is not an accuracy benchmark.
Timing includes preprocessing, inference and postprocessing, but excludes FFmpeg,
motion checks and HTTP. Model graph initializer declarations were normalized at
build time. Model weights are pinned in `ai/manifests/`.

| Model | Threads | Median ms | P95 ms | CPU seconds / 100 calls | Peak RSS MiB |
|---|---:|---:|---:|---:|---:|
| NanoDet-Plus-m 416 | 1 | 32.97 | 38.65 | 3.379 | 112.4 |
| NanoDet-Plus-m 416 | 2 | 24.20 | 28.95 | 3.794 | 113.8 |

One inference thread is the provisional default because two threads consumed
12% more CPU in these runs, despite reducing latency. Recheck on the target
machine. Timing alone does not establish detection accuracy. NanoDet-Plus-m 416
is the sole supported model; see the [selection rationale](motion-labeling.md#model-and-cpu-configuration).

## Complete local replay path

Each run used the same moving synthetic 640×360 H.264, 15 fps source for 12 seconds.
Each simulated camera had a concurrent stream-copy FFmpeg reader. Motion mode
added the actual shared gray/color decoder and motion checks. Full mode added
NanoDet 416, one thread, the coordinator, private HTTP and database label path.
Runs were sequential; other host services were still running. Baseline includes
application boot cost. The source is easy to decode and does not represent a
high-resolution camera fleet.

| Mode | Cameras | PHP CPU s | FFmpeg CPU s | AI service CPU s | Analyzed / skipped |
|---|---:|---:|---:|---:|---:|
| Stream copy | 1 | 0.322 | 0.109 | — | — |
| Stream copy | 4 | 0.278 | 0.465 | — | — |
| Stream copy | 8 | 0.286 | 1.173 | — | — |
| Motion monitoring | 1 | 0.632 | 0.371 | — | — |
| Motion monitoring | 4 | 1.308 | 1.694 | — | — |
| Motion monitoring | 8 | 2.125 | 3.531 | — | — |
| Motion + inference | 1 | 0.736 | 0.359 | 0.739 | 12 / 0 |
| Motion + inference | 4 | 1.466 | 1.774 | 2.078 | 48 / 0 |
| Motion + inference | 8 | 2.486 | 3.611 | 4.223 | 94 / 2 |

Full-mode wall times were 12.02, 12.03 and 12.08 seconds. Total measured CPU use
(PHP + children + AI service) was approximately 0.15, 0.44 and 0.85 CPU cores on
average, respectively. The eight-camera skipped count includes interrupted work
at the run boundary; it is not automatically an overload diagnosis. Motion-only
replays intentionally do not consume inference requests; their skipped counts
are therefore excluded from this comparison.

PHP peak RSS in full mode was 72.0, 87.3 and 108.7 MiB. The largest individual
FFmpeg child peak was about 62.5–62.7 MiB; these are not sums across decoders.
Inference-container cgroup memory after full runs was about 86.0–87.7 MiB;
this includes more than process RSS and is not a measured peak. Aggregate decoder
memory and whole-host peak memory remain to be measured on a real deployment.

Median sampled-frame-to-result delays were 476, 509 and 767 ms, including motion
lookahead and scheduling. These are not time-to-confirmed-label measurements.
A quiet eight-second single-camera replay produced 25 motion frames and zero
inference requests. The model remained warm. All-camera disable/model-unload,
service authentication, stale frames, checksum validation, CPU fallback,
confirmation and retention also have automated checks.

An earlier upload-polling bottleneck limited eight cameras to 36 completed
requests in 12 seconds. Draining ready upload sockets within a bounded five-ms
budget removed the artificial per-chunk sleep. Reported results above include
that correction and the fix preventing one-second sampling deadlines from
drifting to 1.33 seconds at a three-fps input cadence.

## Limits and rollout gate

These runs do not include real RTSP jitter, relay overhead, disk/storage writes,
network storage, or deployed recorder latency. They do not establish 1/4/8-camera
capacity for other resolutions, codecs, bitrates or hardware. Compare production
recorder health, latency and resource use before expanding beyond one camera.

Required field scenarios still include IR/night footage, small distant pets,
rain/trees/shadows, stationary objects, reconnect storms, prolonged overload,
and real GPU operation. Keep AI disabled until an operator opts a camera in.
Detection thresholds remain provisional until representative evidence meets the
precision/recall and coverage criteria in [the workflow](motion-labeling.md).

## Public-clip smoke evaluation

At the user's request, ten public clips were downloaded and annotated for an
initial check. Five calibration excerpts and five evaluation excerpts were replayed
through NanoDet-Plus-m 416. Most excerpts were
8 seconds; the combined pedestrian/car example was 20 seconds. Local derivatives
were converted to H.264, 15 fps, CRF 23, capped at a 1280-pixel long edge without
upscaling. Source hashes, attribution, intervals, labels and split assignments are
in [the fixture manifest](../tests/Fixtures/ai-public-clips.json). Videos and images
are excluded from Git and both runtime images.

This set has only one positive event per represented class per split. It contains
no positive motorcycle, bus or truck events. The normal minimum-coverage guard
therefore rejects production calibration for **every class**.
An exploratory run with `--minimum-events 1` is included only to expose weak
categories; it is not a threshold recommendation or evidence of 95% precision.

The table shows confirmed event labels at the provisional 0.35 threshold, with
single-frame confirmation disabled and a 0.05 overlap floor.

| Clip / expected labels | NanoDet 416 |
|---|---|
| Playing tuxedo cat / cat | cat, **dog** |
| Cat with laser pointer / cat | cat |
| Labradoodle rolling / dog | dog |
| German shepherd eating / dog | dog |
| Indoor passerby / person | person |
| Car passing close to camera / car | missed |
| Outdoor pedestrian then car / person, car | person, car |
| Moving bolt / no targets | none |
| Bottles being moved / person unscored | person |
| Waterfall / no targets | none |

The bottle clip contains a hand and forearm. Its person class is explicitly
unscored because fragment-only labeling is ambiguous; other target classes are
negative. The rolling cat is a real cat/dog confusion, and the close car is a real
miss at the provisional threshold. Do not hide either by reporting grouped labels
alone. Lowering the confirmation overlap floor from 0.2 to 0.05 improved moving
person confirmation on the calibration excerpts; it still requires spatial
overlap and does not introduce identity tracking. These runs informed implementation
changes, so even the nominal evaluation split is exploratory, not an untouched
final holdout set.

Exploratory per-class threshold fitting failed the evaluation precision target
for cars. The passing categories have just one positive evaluation example each.
Selecting one supported model does not resolve these accuracy and coverage gaps;
its shipped thresholds remain provisional.

For confirmed expected classes, reconstructed confirmation times were about
1.1–3.1 seconds after the first analyzed capture for pets and people. The car
appearing later in the combined clip was confirmed about 16 seconds after that
first capture; this is **not** latency from the car's entrance. These timings are
reconstructed using the saved low-threshold detections and response timestamps,
not a separately measured run with deployed calibrated thresholds. Per-clip
results, timing definitions, model checksums, thresholds, precision/recall counts
and insufficient-coverage flags are preserved in
[machine-readable results](motion-labeling-public-results.json).

To fetch and prepare the same local test media:

```sh
python3 tests/Performance/public-ai-clips.py tmp/ai-public
```

Then follow the isolated replay/calibration procedure in
[motion labeling](motion-labeling.md). `ignore_classes` in a dataset entry excludes
an explicitly ambiguous class from scoring; the reason belongs in its annotation.
Expand the dataset before accepting any thresholds, and keep a fresh final holdout
set after tuning the sampling/confirmation policy.

Public sources and attribution:

- Intel IoT DevKit, [sample videos](https://github.com/intel-iot-devkit/sample-videos),
  CC BY 4.0: people, car, combined pedestrian/car, bolt and bottle examples.
- Riverarvi, [Cat Plays](https://commons.wikimedia.org/wiki/File:Cat_Plays.webm),
  CC BY-SA 4.0.
- Clément Bucco-Lechat,
  [Cat playing with a laser pointer](https://commons.wikimedia.org/wiki/File:Cat_playing_with_a_laser_pointer.webm),
  CC BY-SA 3.0.
- L. Beck / mother, as credited on the source page,
  [Labradoodle Berlin](https://commons.wikimedia.org/wiki/File:2015-11-11_Labradoodle_Berlin.webm),
  CC BY-SA 4.0.
- Shadster,
  [German shepherd eating apple](https://commons.wikimedia.org/wiki/File:German_shepherd_eating_apple_(1).webm),
  CC BY-SA 4.0.
- YouTube user Editor,
  [Small waterfall](https://commons.wikimedia.org/wiki/File:Small_waterfall.webm),
  CC BY 3.0.

The locally generated excerpts change duration, compression, frame rate and, where
necessary, resolution. Their media licenses remain separate from the application's
MIT license. License terms: [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/),
[CC BY 3.0](https://creativecommons.org/licenses/by/3.0/),
[CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/),
[CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/).
