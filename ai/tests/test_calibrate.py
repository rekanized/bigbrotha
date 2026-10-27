import unittest
from calibrate import confirmed, metrics


class CalibrationTest(unittest.TestCase):
    def sample(self, at, box=None, event=1):
        return {'captured_at': at, 'event_id': event, 'detections': [
            {'class': 'cat', 'confidence': .8, 'box': box or [.1, .1, .4, .4]}]}

    def test_requires_separate_spatially_consistent_samples_in_one_event(self):
        self.assertFalse(confirmed([self.sample(1)], 'cat', .5))
        self.assertFalse(confirmed([self.sample(1), self.sample(1)], 'cat', .5))
        self.assertFalse(confirmed([self.sample(1), self.sample(5)], 'cat', .5))
        self.assertFalse(confirmed([self.sample(1), self.sample(2, event=2)], 'cat', .5))
        self.assertFalse(confirmed([self.sample(1), self.sample(2, [.6, .6, .9, .9])], 'cat', .5))
        self.assertTrue(confirmed([self.sample(1), self.sample(2)], 'cat', .5))
        self.assertTrue(confirmed([self.sample(1), self.sample(2, [.35, .1, .65, .4])], 'cat', .5))
        self.assertEqual(0, metrics([([], [self.sample(1), self.sample(2)], ['cat'])], 'cat', .5)['negative_events'])

    def test_false_positives_and_missing_coverage_are_explicit(self):
        samples = [self.sample(1), self.sample(2)]
        result = metrics([(['cat'], samples), ([], samples), (['cat'], [])], 'cat', .5)
        self.assertEqual(.5, result['precision'])
        self.assertEqual(.5, result['recall'])
        self.assertEqual(2, result['positive_events'])
        self.assertIsNone(metrics([], 'cat', .5)['recall'])
