"""Build-only normalization: make initializers constant for ONNX Runtime optimization."""
import hashlib
import json
from pathlib import Path
import sys
import onnx

manifest_path = Path(sys.argv[1])
manifest = json.loads(manifest_path.read_text())
path = manifest_path.parent / manifest['file']
model = onnx.load(path)
initializers = {item.name for item in model.graph.initializer}
for item in list(model.graph.input):
    if item.name in initializers:
        model.graph.input.remove(item)
onnx.checker.check_model(model)
onnx.save(model, path)
manifest['source_sha256'] = manifest['sha256']
manifest['sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()
manifest['normalization'] = 'remove-initializers-from-input-v1'
manifest_path.write_text(json.dumps(manifest, indent=2)+'\n')
