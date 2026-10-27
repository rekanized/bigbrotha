"""Inference-only benchmark; full pipeline replay lives in tests/Performance/ai-replay.php."""
import argparse
import json
import resource
import time
import cv2
import numpy as np
from detector import Detector

parser = argparse.ArgumentParser()
parser.add_argument('manifest')
parser.add_argument('--model-dir')
parser.add_argument('--image')
parser.add_argument('--threads', type=int, default=2)
parser.add_argument('--runs', type=int, default=100)
args = parser.parse_args()
model = Detector(args.manifest, args.model_dir, args.threads)
image = cv2.imread(args.image) if args.image else np.zeros((360, 640, 3), np.uint8)
if image is None:
    raise SystemExit('Image could not be decoded')
for _ in range(5):
    model.predict(image)
latencies = []
start_cpu = time.process_time()
for _ in range(args.runs):
    start = time.perf_counter()
    detections = model.predict(image)
    latencies.append((time.perf_counter()-start)*1000)
print(json.dumps({'kind': 'inference-only', 'model': model.manifest['version'], 'sha256': model.manifest['sha256'],
                  'threads': args.threads, 'runs': args.runs, 'cpu_seconds': time.process_time()-start_cpu,
                  'median_ms': float(np.median(latencies)), 'p95_ms': float(np.percentile(latencies, 95)),
                  'peak_rss_kib': resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
                  'detections_last_frame': detections, 'accuracy_measured': False}, indent=2))
