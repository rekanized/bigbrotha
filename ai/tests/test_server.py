import json
import os
import threading
import time
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request
from http.server import HTTPServer

import server


class ServerTest(unittest.TestCase):
    def setUp(self):
        os.environ['CAMERA_AI_TOKEN'] = 'test-'+'x'*32
        server.runtime = server.Runtime()
        self.http = HTTPServer(('127.0.0.1', 0), server.Handler)
        self.thread = threading.Thread(target=self.http.serve_forever, daemon=True)
        self.thread.start()
        self.url = 'http://127.0.0.1:'+str(self.http.server_port)

    def tearDown(self):
        self.http.shutdown()
        self.thread.join()
        self.http.server_close()

    def request(self, path, body, auth=True, **headers):
        if auth:
            headers['Authorization'] = 'Bearer '+os.environ['CAMERA_AI_TOKEN']
        req = urllib.request.Request(self.url+path, data=body, headers=headers)
        try:
            response = urllib.request.urlopen(req, timeout=5)
        except urllib.error.HTTPError as exc:
            response = exc
        return response.status, json.loads(response.read())

    def test_authentication_and_body_limits(self):
        self.assertEqual(401, self.request('/v1/lease', b'{"enabled":1}', auth=False)[0])
        self.assertEqual(422, self.request('/v1/lease', b'{"enabled":-1}')[0])
        self.assertEqual(422, self.request('/v1/analyze', b'not an image')[0])

    def test_real_request_echoes_identity_and_model(self):
        self.assertEqual(200, self.request('/v1/lease', b'{"enabled":1}')[0])
        generation = 'a'*64
        body = b'P6\n32 18\n255\n'+bytes(32*18*3)
        code, response = self.request('/v1/analyze', body, **{'X-Camera-Id': '7', 'X-Captured-At': str(time.time()), 'X-Generation': generation})
        self.assertEqual(200, code)
        self.assertEqual(generation, response['generation'])
        self.assertEqual(7, response['camera_id'])
        self.assertIsInstance(response['detections'], list)
        self.assertIn('model_version', response)

    def test_old_and_oversized_frames_are_rejected(self):
        headers = {'X-Camera-Id': '1', 'X-Captured-At': str(time.time()-30), 'X-Generation': 'b'*64}
        self.assertEqual(422, self.request('/v1/analyze', b'P6\n1 1\n255\nxxx', **headers)[0])
        headers['X-Captured-At'] = str(time.time())
        self.assertEqual(422, self.request('/v1/analyze', b'P6\n99999 99999\n255\nxxx', **headers)[0])

    def test_malformed_auth_and_lease_values_are_rejected_without_crashing(self):
        self.assertEqual(401, self.request('/v1/lease', b'{"enabled":1}', auth=False, Authorization='Bearer \u00ff')[0])
        for body in [b'{"enabled":true}', b'{"enabled":1.5}', b'{"enabled":"1"}', b'[]']:
            self.assertEqual(422, self.request('/v1/lease', body)[0])
        self.assertEqual(200, self.request('/v1/lease', b'{"enabled":0}')[0])

    def test_bad_generation_and_model_failure_are_distinct(self):
        headers = {'X-Camera-Id': '1', 'X-Captured-At': str(time.time()), 'X-Generation': 'z'*64}
        self.assertEqual(422, self.request('/v1/analyze', b'P6\n1 1\n255\nxxx', **headers)[0])
        with patch.object(server.runtime, 'load', side_effect=ValueError('bad checksum')):
            with self.assertLogs(level='ERROR'):
                self.assertEqual(503, self.request('/v1/lease', b'{"enabled":1}')[0])
        self.assertEqual(200, self.request('/v1/lease', b'{"enabled":1}')[0])


if __name__ == '__main__':
    unittest.main()
