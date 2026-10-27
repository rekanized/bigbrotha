"""Internal, bounded HTTP service with a single shared inference session."""
import hmac
import json
import logging
import math
import os
import re
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

import numpy as np

from detector import Detector


class Runtime:
    def __init__(self):
        self.detector = None
        self.lease_until = 0

    def load(self):
        if self.detector is None:
            self.detector = Detector(os.environ.get('AI_MANIFEST', '/models/model.json'),
                                     os.environ.get('AI_MODEL_DIR', '/models'),
                                     int(os.environ.get('AI_THREADS', '1')), os.environ.get('AI_DEVICE') == 'cuda')
        return self.detector

    def expire(self):
        if time.monotonic() > self.lease_until:
            self.detector = None

    def status(self):
        identity = {key: self.detector.manifest.get(key) for key in ('thresholds', 'calibrated')} if self.detector else {}
        if self.detector:
            identity.update(model_version=self.detector.manifest['version'], model_sha256=self.detector.manifest['sha256'])
        return {**identity, 'loaded': self.detector is not None,
                'provider': self.detector.provider if self.detector else None,
                'fallback': self.detector.fallback if self.detector else None}


runtime = Runtime()


class Handler(BaseHTTPRequestHandler):
    server_version = 'BigBrotha-AI/1'

    def log_message(self, *_):
        pass  # Camera identifiers, frames, and auth headers never go to access logs.

    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def reply(self, code, payload):
        data = json.dumps(payload, allow_nan=False).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        try:
            self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            pass  # A canceled camera request must not disrupt the shared worker.

    def load_detector(self):
        try:
            return runtime.load()
        except Exception as error:
            raise RuntimeError('Model initialization failed') from error

    def do_GET(self):
        if self.path == '/health':
            self.reply(200, {'service': 'available', **runtime.status()})
        else:
            self.reply(404, {'error': 'Not found'})

    def do_POST(self):
        secret = os.environ.get('CAMERA_AI_TOKEN', '')
        if not secret or not hmac.compare_digest(self.headers.get('Authorization', '').encode('utf-8'), ('Bearer '+secret).encode('utf-8')):
            self.reply(401, {'error': 'Unauthorized'})
            return
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 < length <= 640*640*3+100:
                raise ValueError('Invalid body length')
            body = self.rfile.read(length)
            if len(body) != length:
                raise ValueError('Incomplete body')
            if self.path == '/v1/lease':
                enabled = json.loads(body)['enabled']
                if type(enabled) is not int or enabled < 0:
                    raise ValueError('Invalid camera count')
                if enabled:
                    runtime.lease_until = time.monotonic() + 30
                    self.load_detector()
                runtime.expire()
                self.reply(200, runtime.status())
                return
            if self.path != '/v1/analyze':
                self.reply(404, {'error': 'Not found'})
                return
            camera = int(self.headers.get('X-Camera-Id', '0'))
            timestamp = float(self.headers.get('X-Captured-At', '0'))
            generation = self.headers.get('X-Generation', '')
            if camera <= 0 or not math.isfinite(timestamp) or abs(time.time()-timestamp) > 10 or re.fullmatch('[a-f0-9]{64}', generation) is None:
                raise ValueError('Invalid frame metadata')
            # Validate PPM dimensions before allocation or decoding.
            match = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', body)
            if match is None:
                raise ValueError('Expected P6 image')
            width, height = map(int, match.groups())
            if not (0 < width <= 640 and 0 < height <= 640) or len(body) != match.end()+width*height*3:
                raise ValueError('Invalid frame size')
            image = np.frombuffer(body, np.uint8, offset=match.end()).reshape(height, width, 3)[:, :, ::-1].copy()
            runtime.lease_until = time.monotonic()+30
            detector = self.load_detector()
            started = time.perf_counter()
            try:
                detections = detector.predict(image)
            except Exception as error:
                raise RuntimeError('Model inference failed') from error
            self.reply(200, {'camera_id': camera, 'captured_at': timestamp, 'generation': generation,
                             'model_version': detector.manifest['version'], 'model_sha256': detector.manifest['sha256'],
                             'thresholds': detector.manifest['thresholds'], 'calibrated': detector.manifest.get('calibrated', False),
                             'detections': detections,
                             'inference_ms': (time.perf_counter()-started)*1000, **runtime.status()})
        except (ValueError, KeyError, TypeError):
            self.reply(422, {'error': 'Invalid frame, metadata, or model configuration'})
        except Exception:
            logging.exception('Inference service failure')
            self.reply(503, {'error': 'Inference unavailable'})


def main():
    if len(os.environ.get('CAMERA_AI_TOKEN', '')) < 32:
        raise SystemExit('CAMERA_AI_TOKEN must contain at least 32 characters')
    server = HTTPServer(('0.0.0.0', 8091), Handler)
    server.timeout = 1
    while True:
        server.handle_request()
        runtime.expire()


if __name__ == '__main__':
    main()
