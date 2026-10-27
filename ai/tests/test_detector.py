import json
from pathlib import Path
import tempfile
import unittest
import numpy as np

from detector import Detector, suppress
from server import Runtime


class DetectorTest(unittest.TestCase):
    def test_nms_keeps_separate_objects(self):
        boxes = np.array([[0, 0, .3, .3], [.01, 0, .3, .3], [.7, .7, .9, .9]])
        self.assertEqual([0, 2], suppress(boxes, np.array([.9, .8, .7])))

    def test_native_model_runs_with_normalized_boxes(self):
        detector = Detector('/models/model.json', threads=1)
        output = detector.predict(np.zeros((180, 320, 3), np.uint8))
        self.assertIsInstance(output, list)
        for item in output:
            self.assertTrue(all(0 <= coordinate <= 1 for coordinate in item['box']))
            self.assertFalse(item['high_confidence'])

    def test_model_checksum_is_required(self):
        manifest = json.loads(Path('/models/model.json').read_text())
        manifest['sha256'] = '0' * 64
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'model.json'
            path.write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, 'checksum'):
                Detector(path, '/models')

    def test_other_model_manifests_are_rejected_before_loading(self):
        original = json.loads(Path('/models/model.json').read_text())
        for field, value in [('family', 'other'), ('input_size', 320), ('file', 'other.onnx')]:
            with self.subTest(field=field), tempfile.TemporaryDirectory() as directory:
                manifest = dict(original, **{field: value})
                path = Path(directory) / 'model.json'
                path.write_text(json.dumps(manifest))
                with self.assertRaisesRegex(ValueError, 'Only NanoDet-Plus-m 416'):
                    Detector(path, directory)

    def test_aspect_ratio_padding_is_reversible(self):
        detector = Detector('/models/model.json', threads=1)
        tensor, (rx, ry, left, top) = detector.preprocess(np.zeros((180, 320, 3), np.uint8))
        self.assertEqual((1, 3, 416, 416), tensor.shape)
        self.assertGreater(top, 0)
        self.assertEqual(0, left)
        self.assertAlmostEqual(320*rx, 416)

    def test_lease_expiration_unloads(self):
        runtime = Runtime()
        runtime.detector = object()
        runtime.expire()
        self.assertIsNone(runtime.detector)

    def test_upstream_padding_conventions_on_odd_sized_images(self):
        detector = Detector.__new__(Detector)
        detector.size = 416
        _, (rx, ry, left, top) = detector.preprocess(np.zeros((181, 319, 3), np.uint8))
        self.assertEqual(rx, ry)
        self.assertEqual(0, left)
        self.assertAlmostEqual((416 - 181 * 416 / 319) / 2, top)
        self.assertNotEqual(round(top), top)

    def test_nvidia_request_reports_cpu_fallback_without_gpu(self):
        detector = Detector('/models/model.json', cuda=True)
        self.assertEqual('CPUExecutionProvider', detector.provider)
        self.assertIsNotNone(detector.fallback)

    def test_invalid_thresholds_fail_before_loading_the_model(self):
        original = json.loads(Path('/models/model.json').read_text())
        for minimum, single in [(float('nan'), None), (-1, None), (.5, .4), (.5, float('inf'))]:
            manifest = json.loads(json.dumps(original))
            manifest['thresholds']['cat'] = {'minimum': minimum, 'single': single}
            with tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / 'model.json'
                path.write_text(json.dumps(manifest))
                with self.assertRaisesRegex(ValueError, 'threshold'):
                    Detector(path, '/models')

    def test_non_finite_model_output_is_an_error_not_an_empty_detection(self):
        detector = Detector('/models/model.json', threads=1)
        with self.assertRaisesRegex(ValueError, 'non-finite'):
            detector.decode([np.full((1, 1, 112), np.nan)])

    def test_nanodet_decoder_has_known_box_geometry_at_each_stride(self):
        detector = Detector.__new__(Detector)
        detector.size = 416
        count = 52*52+26*26+13*13+7*7
        output = np.zeros((1, count, 112), np.float32)
        output[0, :, 15] = .8
        boxes, scores = detector.decode([output])
        # Uniform distance logits yield 3.5 cells on each side of every center.
        offset = 0
        for stride, side in [(8, 52), (16, 26), (32, 13), (64, 7)]:
            with self.subTest(stride=stride):
                np.testing.assert_allclose(boxes[offset], np.array([-3.5, -3.5, 3.5, 3.5])*stride)
                np.testing.assert_allclose(boxes[offset+1], np.array([-2.5, -3.5, 4.5, 3.5])*stride)
                self.assertAlmostEqual(.8, float(scores[offset, 15]), places=6)
            offset += side*side

    def test_wrong_output_layout_is_rejected(self):
        detector = Detector('/models/model.json', threads=1)
        with self.assertRaisesRegex(ValueError, 'output layout'):
            detector.decode([np.zeros((1, 1, 112), np.float32)])


if __name__ == '__main__':
    unittest.main()
