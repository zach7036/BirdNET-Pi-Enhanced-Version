"""Exercise actual PHP routing/auth/errors using an isolated SQLite fixture."""
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import uuid

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
    for name in ['common.php', 'review_data.php', 'review_actions.php', 'review_cases.php', 'review_case_actions.php', 'weather_data.php', 'spine_schema.php']:
        shutil.copyfile(ROOT / 'scripts' / name, scripts / name)
    # A location-rarity fixture also checks the otherwise-cache-dependent route.
    (scripts / 'seasonal_cache.json').write_text(json.dumps({'data': {'Testus rarus': [0.001] * 48}}))
    db = sqlite3.connect(scripts / 'birds.db')
    db.execute('CREATE TABLE detections (Date TEXT, Time TEXT, Sci_Name TEXT, Com_Name TEXT, Confidence REAL, File_Name TEXT)')
    for i in range(6):
        db.execute("INSERT INTO detections VALUES (DATE('now','localtime','-30 days'),'10:00:00','Testus birdus','Test Bird',.99,?)", (f'old-{i}',))
    for name, time in [('a.wav', '12:00:00'), ('b.wav', '12:01:00')]:
        db.execute("INSERT INTO detections VALUES (DATE('now','localtime','-1 day'),?,'Testus birdus','Test Bird',.75,?)", (time, name))
    db.commit()
    for date, common, filename in db.execute('SELECT Date, Com_Name, File_Name FROM detections'):
        clip = tmp_path / 'audio' / 'By_Date' / date / common.replace("'", '').replace(' ', '_') / filename
        clip.parent.mkdir(parents=True, exist_ok=True)
        clip.write_bytes(b'RIFF synthetic audio fixture')
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


def guided_body(case, action='confirm'):
    body = {'request_id': uuid.uuid4().hex, 'action': action,
            'case': {k: case[k] for k in ['key', 'version', 'date', 'end_date', 'sci_name']}}
    if case['evidence']:
        body['files'] = [{k: case['evidence'][0][k] for k in ['file_name', 'file_revision']}]
    return body


@pytest.mark.parametrize('auth,csrf,code', [(False, True, 401), (True, False, 403), (True, True, 200)])
def test_guided_auth(api, auth, csrf, code):
    _, request = api
    case = request('/api/v1/reviews/cases')[1]['cases'][0]
    assert request('/api/v1/reviews/case-actions', method='POST', body=guided_body(case), auth=auth, csrf=csrf)[0] == code


def test_guided_scoped_confirmation_retry_and_undo(api):
    db, request = api
    original = db.execute('SELECT * FROM detections').fetchall()
    case = request('/api/v1/reviews/cases')[1]['cases'][0]
    body = guided_body(case)
    code, saved = request('/api/v1/reviews/case-actions', method='POST', body=body)
    assert code == 200 and saved['affected'] == 1
    assert request('/api/v1/reviews/case-actions', method='POST', body=body) == (200, saved)
    assert request('/api/v1/reviews/cases')[1]['counts']['recommended'] == 0
    confirmed = request('/api/v1/reviews/confirmed?date=' + case['date'])[1]['species']
    assert len(confirmed) == 1 and confirmed[0]['individually_checked'] == 1
    assert db.execute('SELECT COUNT(*) FROM detection_reviews').fetchone()[0] == 1
    undo = {'request_id': uuid.uuid4().hex, 'action': 'undo', 'undo_token': saved['undo_token']}
    assert request('/api/v1/reviews/case-actions', method='POST', body=undo)[0] == 200
    assert request('/api/v1/reviews/cases')[1]['counts']['recommended'] == 1
    assert db.execute('SELECT * FROM detections').fetchall() == original


def test_guided_history_conflict_and_invalid_requests(api):
    _, request = api
    case = request('/api/v1/reviews/cases')[1]['cases'][0]
    assert request('/api/v1/reviews/cases?view=invalid')[0] == 400
    assert request('/api/v1/reviews/cases?start=2020-01-01&end=2025-01-01')[0] == 400
    assert request('/api/v1/reviews/confirmed?date=bad')[0] == 400
    assert request('/api/v1/reviews/case-actions', method='DELETE')[0] == 405
    body = guided_body(case, 'uncertain')
    assert request('/api/v1/reviews/case-actions', method='POST', body=body)[0] == 200
    assert request('/api/v1/reviews/cases')[1]['total'] == 0
    assert request('/api/v1/reviews/cases?view=history')[1]['cases'][0]['state'] == 'unresolved'
    assert request('/api/v1/reviews/case-actions', method='POST', body=guided_body(case))[0] == 409


def test_guided_failure_rolls_back_and_retry_recovers(api):
    db, request = api
    case = request('/api/v1/reviews/cases')[1]['cases'][0]
    # First harmless case action creates the additive schema, then Undo restores it.
    saved = request('/api/v1/reviews/case-actions', method='POST', body=guided_body(case, 'later'))[1]
    request('/api/v1/reviews/case-actions', method='POST', body={'request_id': uuid.uuid4().hex, 'action': 'undo', 'undo_token': saved['undo_token']})
    db.execute("CREATE TRIGGER fail_guided_journal BEFORE INSERT ON review_case_actions BEGIN SELECT RAISE(ABORT,'injected'); END")
    db.commit()
    body = guided_body(case)
    assert request('/api/v1/reviews/case-actions', method='POST', body=body)[0] == 503
    assert db.execute('SELECT COUNT(*) FROM detection_reviews').fetchone()[0] == 0
    db.execute('DROP TRIGGER fail_guided_journal')
    db.commit()
    assert request('/api/v1/reviews/case-actions', method='POST', body=body)[0] == 200


def test_dashboard_and_recommended_share_the_same_case_count(api):
    _, request = api
    recommended = request('/api/v1/reviews/cases')[1]
    code, dashboard = request('/api/v1/dashboard/now')
    assert code == 200
    assert dashboard['review_worthy'] == recommended['total']
    assert dashboard['review_counts'] == recommended['counts']
    saved = request('/api/v1/reviews/case-actions', method='POST', body=guided_body(recommended['cases'][0]))[1]
    assert saved['status'] == 'ok'
    assert request('/api/v1/dashboard/now')[1]['review_worthy'] == 0


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


def test_group_totals_and_invalid_filter(api):
    _, request = api
    _, data = request()
    assert data['counts']['ready'] == data['counts']['routine'] == 1
    assert data['counts']['important'] == 0
    assert request('/api/v1/reviews/queue?group=important')[1]['total'] == 0
    assert request('/api/v1/reviews/queue?group=bogus')[0] == 400


def test_skip_resume_and_undo_are_metadata_only(api):
    db, request = api
    original = db.execute('SELECT * FROM detections').fetchall()
    visit = request()[1]['queue'][0]
    target = {'sci_name': visit['sci_name'], 'date': visit['date'], 'from_time': visit['first_time'], 'to_time': visit['last_time']}
    code, skipped = request('/api/v1/reviews', method='POST', body={'action': 'skip', 'visit': target})
    assert code == 200 and skipped['affected'] == 2
    assert request()[1]['counts']['skipped'] == 1
    assert request()[1]['total'] == 0
    assert request('/api/v1/reviews/queue?group=skipped')[1]['total'] == 1
    assert db.execute('SELECT COUNT(*) FROM detection_reviews').fetchone()[0] == 0
    code, resumed = request('/api/v1/reviews', method='POST', body={'action': 'resume', 'visit': target})
    assert code == 200 and request()[1]['total'] == 1
    assert request('/api/v1/reviews', method='POST', body={'action': 'undo', 'undo_token': resumed['undo_token']})[0] == 200
    assert request()[1]['counts']['skipped'] == 1
    assert request('/api/v1/reviews', method='POST', body={'action': 'undo', 'undo_token': skipped['undo_token']})[0] == 200
    assert request()[1]['total'] == 1
    assert db.execute('SELECT * FROM detections').fetchall() == original


def test_undo_auth_validation_and_conflicts(api):
    db, request = api
    _, saved = save(request, status='confirmed')
    body = {'action': 'undo', 'undo_token': saved['undo_token']}
    assert request('/api/v1/reviews', method='POST', body=body, auth=False)[0] == 401
    assert request('/api/v1/reviews', method='POST', body=body, csrf=False)[0] == 403
    assert request('/api/v1/reviews', method='POST', body={'action': 'undo', 'undo_token': '../bad'})[0] == 400
    assert request('/api/v1/reviews', method='POST', body={'action': 'undo', 'undo_token': 'f' * 64})[0] == 404
    save(request, status='confirmed')  # Same verdict still counts as a newer decision.
    assert request('/api/v1/reviews', method='POST', body=body)[0] == 409
    assert db.execute("SELECT status FROM detection_reviews WHERE file_name='a.wav'").fetchone()[0] == 'confirmed'


def test_missing_best_clip_falls_back_then_moves_out_of_ready(api):
    db, request = api
    db.execute("UPDATE detections SET Confidence=.80 WHERE File_Name='b.wav'")
    db.commit()
    _, first = request()
    assert first['queue'][0]['playback_file'] == 'b.wav'
    # Resolve only paths inside this test's own disposable database directory.
    db_path = Path(db.execute('PRAGMA database_list').fetchone()[2])
    audio = db_path.parent.parent / 'audio' / 'By_Date'
    for file in audio.rglob('b.wav'):
        file.unlink()
    _, fallback = request()
    assert fallback['queue'][0]['playback_file'] == 'a.wav'
    assert fallback['queue'][0]['audio_fallback'] is True
    assert fallback['queue'][0]['reassign_available'] is False
    for file in audio.rglob('a.wav'):
        file.unlink()
    _, missing = request()
    assert missing['total'] == 0 and missing['counts']['unavailable'] == 1
    _, unavailable = request('/api/v1/reviews/queue?group=unavailable')
    assert unavailable['queue'][0]['clip_path'] is None
    assert db.execute('SELECT COUNT(*) FROM detections').fetchone()[0] == 8


def test_future_visit_is_active_not_ready(api):
    db, request = api
    db.execute("UPDATE detections SET Date=DATE('now','localtime','+1 day') WHERE File_Name IN ('a.wav','b.wav')")
    db.commit()
    code, data = request()
    assert code == 200 and data['total'] == 0 and data['counts']['active'] == 1
    assert request('/api/v1/reviews/queue?group=active')[1]['queue'][0]['active'] is True
