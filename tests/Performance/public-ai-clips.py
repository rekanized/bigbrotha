"""Fetch pinned public smoke-test clips and convert annotated excerpts to camera-like H.264.
Run manually: python3 tests/Performance/public-ai-clips.py tmp/ai-public
Requires FFmpeg. Media and derivatives remain local, outside the application image.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import urllib.request
import urllib.error
import time

parser = argparse.ArgumentParser()
parser.add_argument('directory')
args = parser.parse_args()
root = Path(args.directory)
root.mkdir(parents=True, exist_ok=True)
rows = json.loads((Path(__file__).parent.parent / 'Fixtures/ai-public-clips.json').read_text())
for index, row in enumerate(rows):
    source = root / row['file']
    if not source.exists():
        request = urllib.request.Request(row['url'], headers={'User-Agent': 'BigBrothaPublicEvaluation/1.0'})
        for attempt in range(3):
            try:
                with urllib.request.urlopen(request, timeout=60) as response:
                    source.write_bytes(response.read(100*1024*1024))
                break
            except urllib.error.HTTPError as error:
                if error.code != 429 or attempt == 2:
                    raise
                retry_after = error.headers.get('Retry-After', '30')
                if not retry_after.isdigit() or int(retry_after) > 120:
                    raise SystemExit('Source requested a later retry; run the downloader again later')
                time.sleep(max(1, int(retry_after)))
    if hashlib.sha256(source.read_bytes()).hexdigest() != row['sha256']:
        raise SystemExit('Source checksum mismatch: '+row['file'])
    output = root / f'clip-{index}.mp4'
    subprocess.run(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-threads', '1',
                    '-ss', str(row['start_seconds']), '-i', str(source), '-t', str(row['duration_seconds']),
                    '-vf', "scale=w='min(1280,iw)':h='min(1280,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2,fps=15",
                    '-an', '-c:v', 'libx264', '-threads', '1', '-crf', '23', '-pix_fmt', 'yuv420p', '-y', str(output)], check=True)
    row['clip'] = output.name
    row['clip_sha256'] = hashlib.sha256(output.read_bytes()).hexdigest()
(root / 'clips.json').write_text(json.dumps(rows, indent=2)+'\n')
