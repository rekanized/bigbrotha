"""Build-time only: fetch an explicit model and verify its pinned digest."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('manifest')
parser.add_argument('directory')
args = parser.parse_args()
manifest = json.loads(Path(args.manifest).read_text())
destination = Path(args.directory)
destination.mkdir(parents=True, exist_ok=True)
if not manifest['url'].startswith('https://') or len(manifest['sha256']) != 64:
    raise SystemExit('HTTPS URL and pinned SHA256 are required')
with urllib.request.urlopen(manifest['url'], timeout=60) as response:
    data = response.read(100 * 1024 * 1024)
if hashlib.sha256(data).hexdigest() != manifest['sha256']:
    raise SystemExit('Model checksum mismatch')
(destination / manifest['file']).write_bytes(data)
shutil.copyfile(args.manifest, destination / 'model.json')
