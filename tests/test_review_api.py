"""Exercise actual PHP routing/auth/errors using an isolated SQLite fixture."""
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[1]


@pytest.fixture
def api(tmp_path):
    php = os.environ.get('BIRDNET_TEST_PHP') or shutil.which('php')
    if not php:
        pytest.skip('Set BIRDNET_TEST_PHP to a PHP CLI with sqlite3 and mbstring')
    scripts = tmp_path / 'scripts'
    scripts.mkdir()
    (tmp_path / '.review-test-fixture').touch()
    for name in ['common.php', 'review_data.php', 'weather_data.php', 'spine_schema.php']:
        shutil.copyfile(ROOT / 'scripts' / name, scripts / name)
    # A location-rarity fixture also checks the otherwise-cache-dependent route.
    (scripts / 'seasonal_cache.json').write_text(json.dumps({'data': {'Testus rarus': [0.001] * 48}}))
    db = sqlite3.connect(scripts / 'birds.db')
    db.execute('CREATE TABLE detections (Date TEXT, Time TEXT, Sci_Name TEXT, Com_Name TEXT, Confidence REAL, File_Name TEXT)')
    for i in range(6):
        db.execute("INSERT INTO detections VALUES (DATE('now','localtime','-30 days'),'10:00:00','Testus birdus','Test Bird',.99,?)", (f'old-{i}',))
    for name, time in [('a.wav', '12:00:00'), ('b.wav', '12:01:00')]:
        db.execute("INSERT INTO detections VALUES (DATE('now','localtime'),?,'Testus birdus','Test Bird',.75,?)", (time, name))
    db.commit()
    extensions = Path(php).parent / 'ext'
    cmd = [php]
    if extensions.is_dir():
        cmd += ['-d', f'extension_dir={extensions}', '-d', 'extension=sqlite3', '-d', 'extension=mbstring']
    cmd += [str(ROOT / 'tests' / 'review_api_request.php'), str(tmp_path)]

    def request(uri='/api/v1/reviews/queue', **kwargs):
        result = subprocess.run(cmd + [json.dumps({'uri': uri, **kwargs})], capture_output=True, text=True, timeout=15)
        assert result.returncode == 0, result.stdout + result.stderr
        response = json.loads(result.stdout)
        assert response['body'] is not None, response
        return response['code'], response['body']

    yield db, request
    db.close()


def save(request, status='false_positive', **kwargs):
    return request('/api/v1/reviews', method='POST', body={'status': status, 'file_name': 'a.wav'}, **kwargs)


@pytest.mark.parametrize('auth,csrf,code', [(False, True, 401), (True, False, 403), (True, True, 200)])
def test_review_authentication_and_csrf(api, auth, csrf, code):
    db, request = api
    actual, body = save(request, auth=auth, csrf=csrf)
    assert actual == code
    assert body['status'] == ('ok' if code == 200 else 'error')
    assert db.execute('SELECT COUNT(*) FROM detections').fetchone()[0] == 8


def test_bad_request_and_missing_detection(api):
    _, request = api
    assert save(request, status='delete')[0] == 400
    assert request('/api/v1/reviews', method='POST', body={'status': 'confirmed', 'file_name': 'absent'})[0] == 404
    assert request('/api/v1/reviews', method='DELETE')[0] == 405


def test_visit_save_updates_queue_and_preserves_detections(api):
    db, request = api
    original = db.execute('SELECT * FROM detections').fetchall()
    code, data = request()
    assert code == 200 and data['total'] == 1
    visit = data['queue'][0]
    code, data = request('/api/v1/reviews', method='POST', body={'status': 'false_positive', 'visit': {
        'sci_name': visit['sci_name'], 'date': visit['date'], 'from_time': visit['first_time'], 'to_time': visit['last_time']}})
    assert code == 200 and data['affected'] == 2
    assert request()[1]['total'] == 0
    assert db.execute('SELECT * FROM detections').fetchall() == original


def test_failed_write_is_503_not_partial_success(api):
    db, request = api
    save(request, status='confirmed')  # Creates review schema.
    save(request, status='clear')
    db.execute("CREATE TRIGGER fail_review BEFORE INSERT ON detection_reviews WHEN NEW.file_name='b.wav' BEGIN SELECT RAISE(ABORT, 'test failure'); END")
    db.commit()
    visit = request()[1]['queue'][0]
    code, data = request('/api/v1/reviews', method='POST', body={'status': 'false_positive', 'visit': {
        'sci_name': visit['sci_name'], 'date': visit['date'], 'from_time': visit['first_time'], 'to_time': visit['last_time']}})
    assert code == 503 and data['status'] == 'error'
    assert 'affected' not in data
    assert db.execute('SELECT COUNT(*) FROM detection_reviews').fetchone()[0] == 0
    assert request()[1]['total'] == 1


def test_queue_read_failure_is_not_zero_pending(api):
    db, request = api
    db.execute('DROP TABLE detections')
    db.commit()
    code, data = request()
    assert code == 503 and data['status'] == 'error'
    assert 'total' not in data


def test_api_keeps_station_gap_and_legacy_limit_bounds(api):
    _, request = api
    code, data = request('/api/v1/reviews/queue?gap_seconds=0&limit=0')
    assert code == 200 and data['total'] == 1 and data['count'] == 1
    assert len(data['queue'][0]['member_clips']) == 2


def test_location_rarity_rule_is_preserved(api):
    db, request = api
    db.execute("UPDATE detections SET Sci_Name='Testus rarus', Confidence=.99")
    db.commit()
    code, data = request()
    assert code == 200 and data['total'] == 1
    assert data['queue'][0]['reasons'] == ['region_rare']
