"""NanoDet-Plus-m 416 inference. No training framework or runtime downloads."""
import hashlib
import json
import math
from pathlib import Path

import cv2
import numpy as np
import onnxruntime as ort

CLASSES = {0: 'person', 2: 'car', 3: 'motorcycle', 5: 'bus', 7: 'truck', 15: 'cat', 16: 'dog'}


def iou(box, boxes):
    intersection = np.maximum(0, np.minimum(box[2:], boxes[:, 2:]) - np.maximum(box[:2], boxes[:, :2])).prod(axis=1)
    area = np.maximum(0, box[2:] - box[:2]).prod()
    areas = np.maximum(0, boxes[:, 2:] - boxes[:, :2]).prod(axis=1)
    return intersection / np.maximum(area + areas - intersection, 1e-9)


def suppress(boxes, scores, threshold=0.5, limit=50):
    order = np.argsort(scores)[::-1][:1000]
    keep = []
    while len(order) and len(keep) < limit:
        index = order[0]
        keep.append(index)
        order = order[1:][iou(boxes[index], boxes[order[1:]]) < threshold]
    return keep


class Detector:
    def __init__(self, manifest, model_dir=None, threads=1, cuda=False):
        self.manifest = json.loads(Path(manifest).read_text())
        self.validate_manifest()
        # Include adapter semantics in result identity, not just unchanged weights.
        self.manifest['version'] += '/adapter-2'
        self.cuda_requested = cuda
        self.size = 416
        path = Path(model_dir or Path(manifest).parent) / self.manifest['file']
        if hashlib.sha256(path.read_bytes()).hexdigest() != self.manifest['sha256']:
            raise ValueError('Model checksum does not match manifest')
        options = ort.SessionOptions()
        options.log_severity_level = 3
        options.intra_op_num_threads = max(1, min(8, threads))
        options.inter_op_num_threads = 1
        options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
        options.add_session_config_entry('session.intra_op.allow_spinning', '0')
        options.add_session_config_entry('session.inter_op.allow_spinning', '0')
        self.fallback = None
        providers = ['CPUExecutionProvider']
        if cuda:
            if 'CUDAExecutionProvider' in ort.get_available_providers():
                providers.insert(0, 'CUDAExecutionProvider')
            else:
                self.fallback = 'CUDA provider unavailable; using CPU'
        try:
            self.session = ort.InferenceSession(str(path), options, providers=providers)
        except Exception:
            if not cuda:
                raise
            self.session = ort.InferenceSession(str(path), options, providers=['CPUExecutionProvider'])
            self.fallback = 'CUDA initialization failed; using CPU'
        self.provider = self.session.get_providers()[0]
        if cuda and self.provider != 'CUDAExecutionProvider':
            self.fallback = 'CUDA initialization failed; using CPU'
        shape = self.session.get_inputs()[0].shape
        if shape != [1, 3, self.size, self.size]:
            raise ValueError(f'Model input shape {shape} differs from pinned manifest')
        cv2.setNumThreads(1)

    def validate_manifest(self):
        manifest = self.manifest
        if (manifest.get('family') != 'nanodet'
                or manifest.get('input_size') != 416
                or manifest.get('file') != 'nanodet-plus-m-416.onnx'):
            raise ValueError('Only NanoDet-Plus-m 416 is supported')
        if not isinstance(manifest.get('version'), str) or not manifest['version']:
            raise ValueError('Model version is required')
        if type(manifest.get('calibrated', False)) is not bool:
            raise ValueError('Calibrated flag must be boolean')
        for name in CLASSES.values():
            settings = manifest.get('thresholds', {}).get(name, {})
            minimum, single = settings.get('minimum'), settings.get('single')
            if type(minimum) not in (int, float) or not math.isfinite(minimum) or not 0 < minimum <= 1:
                raise ValueError('Invalid minimum threshold for '+name)
            if single is not None and (type(single) not in (int, float) or not math.isfinite(single) or not minimum <= single <= 1):
                raise ValueError('Invalid single-observation threshold for '+name)

    def preprocess(self, image):
        height, width = image.shape[:2]
        ratio = min(self.size / width, self.size / height)
        # Upstream warp_and_resize centers the image with a floating-point
        # affine transform. resize()+integer padding shifts sample locations.
        left, top = (self.size-width*ratio)/2, (self.size-height*ratio)/2
        matrix = np.array([[ratio, 0, left], [0, ratio, top], [0, 0, 1]], np.float64)
        canvas = cv2.warpPerspective(image, matrix, (self.size, self.size))
        tensor = canvas.astype(np.float32)
        tensor = (tensor - np.array([103.53, 116.28, 123.675], np.float32)) / np.array([57.375, 57.12, 58.395], np.float32)
        return np.ascontiguousarray(tensor.transpose(2, 0, 1)[None]), (ratio, ratio, left, top)

    def decode(self, output):
        prediction = np.asarray(output[0])[0]
        if not np.isfinite(prediction).all():
            raise ValueError('Model produced non-finite output')
        strides = [8, 16, 32, 64]
        grids, scales = [], []
        for stride in strides:
            n = math.ceil(self.size / stride)
            y, x = np.mgrid[:n, :n]
            grids.append(np.stack([x, y], axis=-1).reshape(-1, 2))
            scales.append(np.full((n*n, 1), stride))
        grid, scale = np.concatenate(grids), np.concatenate(scales)
        if prediction.shape != (len(grid), 112):
            raise ValueError('Unexpected model output layout; export adapter mismatch')
        logits = prediction[:, 80:].reshape(-1, 4, 8)
        weights = np.exp(logits - logits.max(axis=-1, keepdims=True))
        distances = (weights / weights.sum(axis=-1, keepdims=True) * np.arange(8)).sum(axis=-1) * scale
        center = grid * scale
        return np.concatenate([center - distances[:, :2], center + distances[:, 2:]], axis=1), prediction[:, :80]

    def predict(self, image, minimum_override=None):
        tensor, transform = self.preprocess(image)
        output = self.session.run(None, {self.session.get_inputs()[0].name: tensor})
        self.provider = self.session.get_providers()[0]
        if self.cuda_requested and self.provider != 'CUDAExecutionProvider':
            self.fallback = 'CUDA unavailable during inference; using CPU'
        boxes, scores = self.decode(output)
        if not np.isfinite(scores).all() or np.any((scores < 0) | (scores > 1)):
            raise ValueError('Model produced invalid confidence scores')
        if not np.isfinite(boxes).all():
            raise ValueError('Model produced invalid boxes')
        rx, ry, left, top = transform
        height, width = image.shape[:2]
        boxes = (boxes - np.array([left, top, left, top])) / np.array([rx*width, ry*height, rx*width, ry*height])
        boxes = np.clip(boxes, 0, 1)
        result = []
        for class_id, name in CLASSES.items():
            settings = self.manifest['thresholds'][name]
            threshold = settings['minimum'] if minimum_override is None else minimum_override
            selected = np.flatnonzero((scores[:, class_id] >= threshold) & np.isfinite(boxes).all(axis=1)
                                      & (boxes[:, 2] > boxes[:, 0]) & (boxes[:, 3] > boxes[:, 1]))
            for index in suppress(boxes[selected], scores[selected, class_id]):
                row = selected[index]
                confidence = float(scores[row, class_id])
                single = settings.get('single') if self.manifest.get('calibrated') else None
                result.append({'class': name, 'confidence': confidence, 'box': boxes[row].tolist(),
                               'high_confidence': single is not None and confidence >= single})
        return sorted(result, key=lambda row: row['confidence'], reverse=True)[:50]
