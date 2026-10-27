"""Compare clip/event labels at matched precision using full-pipeline replay results.
Dataset: [{split: calibration|evaluation, truth: [person,cat,...], report: replay.json}].
Use one camera per accuracy replay; clips must represent separate events.
"""
import argparse
import json
from pathlib import Path
import numpy as np
from detector import CLASSES, iou


def confirmed(samples, name, minimum):
    previous = []
    event_id = None
    for sample in samples:
        if sample.get('event_id') != event_id:
            previous = []
            event_id = sample.get('event_id')
        at = sample['captured_at']
        previous = [p for p in previous if 0 < at-p[0] <= 3]
        current = [d for d in sample['detections'] if d['class'] == name and d['confidence'] >= minimum]
        for detection in current:
            if any(iou(np.array(detection['box']), np.array([box]))[0] >= .05 for _, box in previous):
                return True
        previous.extend((at, d['box']) for d in current)
    return False


def metrics(episodes, name, threshold):
    tp = fp = fn = positives = eligible = 0
    for episode in episodes:
        truth, samples = episode[:2]
        if len(episode) > 2 and name in episode[2]:
            continue
        eligible += 1
        expected = name in truth
        predicted = confirmed(samples, name, threshold)
        positives += expected
        tp += expected and predicted
        fp += not expected and predicted
        fn += expected and not predicted
    return {'precision': tp/(tp+fp) if tp+fp else None, 'recall': tp/(tp+fn) if tp+fn else None,
            'true_positives': tp, 'false_positives': fp, 'false_negatives': fn, 'positive_events': positives,
            'negative_events': eligible-positives}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('dataset')
    parser.add_argument('--precision', type=float, default=.95)
    parser.add_argument('--minimum-events', type=int, default=20, help='Minimum positives and negatives per class in each split')
    args = parser.parse_args()
    if not 0 < args.precision <= 1 or args.minimum_events < 1:
        raise SystemExit('Precision must be in (0,1]; minimum events must be positive')
    path = Path(args.dataset)
    model_sha = None
    splits = {'calibration': [], 'evaluation': []}
    reports = set()
    for row in json.loads(path.read_text()):
        report_path = (path.parent / row['report']).resolve()
        if report_path in reports:
            raise SystemExit('A replay cannot occur in both splits or be counted twice')
        reports.add(report_path)
        report = json.loads(report_path.read_text())
        if report['cameras'] != 1 or report['mode'] != 'full':
            raise SystemExit('Accuracy evaluation requires full-pipeline, single-camera replays')
        model = report.get('model', {})
        if not model.get('model_sha256') or any(model.get('thresholds', {}).get(name, {}).get('minimum', 1) > .01 for name in CLASSES.values()):
            raise SystemExit('Replay must identify the model and capture detections down to 0.01 for every class')
        if model_sha is not None and model_sha != model['model_sha256']:
            raise SystemExit('Use separate datasets for different model checksums')
        model_sha = model['model_sha256']
        if row['split'] not in splits or not set(row['truth']).issubset(CLASSES.values()):
            raise SystemExit('Unknown split or class')
        splits[row['split']].append((row['truth'], report['results'], row.get('ignore_classes', [])))
    results = {}
    for name in CLASSES.values():
        coverage = {split: metrics(episodes, name, 1) for split, episodes in splits.items()}
        if any(score['positive_events'] < args.minimum_events or score['negative_events'] < args.minimum_events for score in coverage.values()):
            results[name] = {'status': 'insufficient coverage', 'coverage': coverage}
            continue
        candidates = []
        for threshold in np.arange(.01, 1, .01):
            score = metrics(splits['calibration'], name, threshold)
            if score['precision'] is not None and score['precision'] >= args.precision and score['recall'] is not None:
                candidates.append((score['recall'], float(threshold)))
        if not candidates:
            results[name] = {'status': 'insufficient coverage or precision target unmet'}
            continue
        _, threshold = max(candidates, key=lambda row: (row[0], -row[1]))
        evaluation = metrics(splits['evaluation'], name, threshold)
        results[name] = {'threshold': threshold, 'single': None, 'evaluation': evaluation,
                         'status': 'measured' if (evaluation['precision'] or 0) >= args.precision else 'evaluation precision target unmet'}
    print(json.dumps({'model_sha256': model_sha, 'minimum_events_per_split_and_sign': args.minimum_events, 'precision_target': args.precision, 'classes': results,
                      'selection_rule': 'Choose lower CPU cost only within 0.05 recall per category at matched measured precision.'}, indent=2))


if __name__ == '__main__':
    main()
