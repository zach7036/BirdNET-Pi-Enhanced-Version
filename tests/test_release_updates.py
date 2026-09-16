"""Release checks use mocked GitHub responses and disposable caches/checkouts only."""
import json
import os
from pathlib import Path
import shutil
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[1]
A, B, C = 'a' * 40, 'b' * 40, 'c' * 40


@pytest.fixture
def php():
    binary = os.environ.get('BIRDNET_TEST_PHP') or shutil.which('php')
    if not binary:
        pytest.skip('Set BIRDNET_TEST_PHP to a PHP CLI')

    def run(code, *args, data=None):
        result = subprocess.run([binary, '-r', code, *map(str, args)], input=json.dumps(data),
                                text=True, capture_output=True, timeout=25)
        assert result.returncode == 0, result.stdout + result.stderr
        assert not result.stderr, result.stderr
        return json.loads(result.stdout)
    return run


@pytest.fixture
def checks(php, tmp_path):
    def run(steps, **opts):
        return php('''
            require $argv[1];
            $input = json_decode(stream_get_contents(STDIN), true);
            $out = [];
            foreach ($input['steps'] as $step) {
                $requests = []; $commands = [];
                $head = $step['head'] ?? str_repeat('a', 40);
                $sha = $step['sha'] ?? str_repeat('b', 40);
                $tag = $step['tag'] ?? 'v2.8.3';
                $git = function ($args) use ($step, $head, &$commands) {
                    $commands[] = $args;
                    if ($args[0] === 'rev-parse') return ['code' => $step['git_error'] ?? 0, 'output' => $head];
                    return ['code' => $args[2] === $head ? ($step['behind'] ?? 128) : ($step['contains'] ?? 128), 'output' => ''];
                };
                $http = function ($path) use ($step, $head, $sha, $tag, &$requests) {
                    $requests[] = $path;
                    if (!empty($step['offline'])) throw new RuntimeException('offline or rate limited');
                    if ($path === '/releases/latest') return ['tag_name' => $tag,
                        'draft' => $step['draft'] ?? false, 'prerelease' => $step['prerelease'] ?? false,
                        'published_at' => '2026-09-16T00:00:00Z', 'target_commitish' => 'main', 'html_url' => 'javascript:bad'];
                    if (strpos($path, '/git/ref/') === 0) return ['object' => ['sha' => empty($step['annotated']) ? $sha : str_repeat('c', 40),
                        'type' => empty($step['annotated']) ? 'commit' : 'tag']];
                    if (strpos($path, '/git/tags/') === 0) return ['object' => ['sha' => $sha, 'type' => 'commit']];
                    return ['base_commit' => ['sha' => $step['compare_base'] ?? $head], 'status' => $step['compare'] ?? 'ahead'];
                };
                $result = release_update_status('synthetic', null, $argv[2], $step['force'] ?? false, $step['now'] ?? 100000, $git, $http);
                $out[] = ['result' => $result, 'requests' => $requests, 'commands' => $commands];
            }
            echo json_encode($out);
        ''', ROOT / 'scripts/release_updates.php', tmp_path / 'cache', data={'steps': steps, **opts})
    return run


@pytest.mark.parametrize('annotated', [False, True])
def test_published_stable_release_resolves_actual_tag(checks, annotated):
    result = checks([{'annotated': annotated}])[0]
    assert result['result']['status'] == 'available'
    assert result['result']['release'] == {'version': 'v2.8.3', 'sha': B,
                                         'url': 'https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/releases/tag/v2.8.3'}
    assert len(result['requests']) == (4 if annotated else 3)
    assert all(cmd[0] in ['rev-parse', 'merge-base'] for cmd in result['commands'])


@pytest.mark.parametrize('step', [{'sha': A}, {'contains': 0}, {'compare': 'identical'}, {'compare': 'behind'}])
def test_existing_or_ahead_build_is_not_an_update(checks, step):
    assert checks([step])[0]['result']['status'] == 'current'


def test_local_ancestor_does_not_need_github_comparison(checks):
    result = checks([{'behind': 0}])[0]
    assert result['result']['status'] == 'available'
    assert len(result['requests']) == 2


@pytest.mark.parametrize('step', [{'draft': True}, {'prerelease': True}, {'tag': 'v3.0.0-rc1'},
                               {'tag': '<script>bad</script>'}, {'sha': 'bad'}, {'compare_base': C},
                               {'compare': 'nonsense'}, {'offline': True}])
def test_failure_is_not_reported_as_current_or_an_update(checks, step):
    result = checks([step])[0]['result']
    assert result['status'] == 'unknown'
    assert result['error']
    assert result['checked_at'] is None


def test_diverged_build_is_not_suggested_as_a_safe_update(checks):
    assert checks([{'compare': 'diverged'}])[0]['result']['status'] == 'diverged'


def test_missing_git_never_starts_network_check(checks):
    result = checks([{'git_error': 128}])[0]
    assert result['result']['status'] == 'unknown'
    assert result['requests'] == []


def test_station_wide_day_cache_and_manual_cooldown(checks):
    results = checks([{}, {'now': 100020, 'force': True}, {'now': 100060, 'force': True},
                      {'now': 101260}, {'now': 186459}, {'now': 186460}])
    assert [bool(row['requests']) for row in results] == [True, False, True, False, False, True]


def test_cache_is_shared_by_separate_php_processes(checks):
    assert checks([{}])[0]['requests']
    assert checks([{'now': 101000}])[0]['requests'] == []


def test_twenty_minutes_after_install_is_quiet_but_explicit_check_works(checks):
    results = checks([{}, {'now': 100100, 'head': B}, {'now': 101300, 'head': B, 'sha': C},
                      {'now': 101301, 'head': B, 'sha': C, 'force': True}])
    assert [bool(row['requests']) for row in results] == [True, False, False, True]
    assert [row['result']['status'] for row in results] == ['available', 'current', 'current', 'available']


def test_failure_keeps_previous_result_and_uses_daily_backoff(checks):
    results = checks([{}, {'now': 186400, 'offline': True}, {'now': 186500, 'offline': True}])
    assert results[1]['result']['status'] == 'available'
    assert results[1]['result']['error']
    assert results[1]['result']['checked_at'] == 100000
    assert results[2]['requests'] == []


def test_clock_rollback_does_not_freeze_checks(checks):
    results = checks([{}, {'now': 90000}])
    assert results[1]['requests']
    assert results[1]['result']['checked_at'] == 90000


@pytest.mark.parametrize('corrupt', ['{broken', '{"schema":1,"head":"bad","status":"current"}',
                                   json.dumps({'schema': 1, 'head': A, 'release': 'bad'}),
                                   json.dumps({'schema': 1, 'head': A, 'last_attempt': 'bad'})])
def test_corrupt_cache_recovers_without_guessing(php, tmp_path, corrupt):
    cache = tmp_path / 'cache'
    cache.mkdir()
    (cache / 'status.json').write_text(corrupt)
    result = php('''require $argv[1]; echo json_encode(release_update_status('fake', null, $argv[2], false, 100000,
        function ($args) { return ['code' => 0, 'output' => str_repeat('a',40)]; },
        function ($path) { throw new RuntimeException('offline'); }));''', ROOT / 'scripts/release_updates.php', cache)
    assert result['status'] == 'unknown'
    assert result['error']
    assert json.loads((cache / 'status.json').read_text())['schema'] == 1


def test_concurrent_check_does_not_duplicate_network_work(php, tmp_path):
    result = php('''require $argv[1]; mkdir($argv[2]); $lock = fopen($argv[2] . '/check.lock', 'c');
        flock($lock, LOCK_EX); echo json_encode(release_update_status('fake', null, $argv[2], true, 100000,
        function ($args) { throw new RuntimeException('must not run git'); },
        function ($path) { throw new RuntimeException('must not run HTTP'); }));''', ROOT / 'scripts/release_updates.php', tmp_path / 'cache')
    assert result['status'] == 'checking'


def test_real_local_ancestry_preserves_checkout_and_index(php, tmp_path):
    repo = tmp_path / 'repo'
    repo.mkdir()

    def git(*args):
        return subprocess.check_output(['git', '-C', str(repo), *args], text=True).strip()

    git('init', '-b', 'development')
    git('config', 'user.name', 'Test')
    git('config', 'user.email', 'test@example.invalid')
    (repo / 'tracked').write_text('before')
    git('add', 'tracked')
    git('commit', '-m', 'before release')
    before = git('rev-parse', 'HEAD')
    git('commit', '--allow-empty', '-m', 'release')
    released = git('rev-parse', 'HEAD')
    git('commit', '--allow-empty', '-m', 'development work')
    head = git('rev-parse', 'HEAD')
    (repo / 'tracked').write_text('user edit')
    index = (repo / '.git/index').read_bytes()
    result = php('''require $argv[1]; $git = system_version_git_runner($argv[2]);
        echo json_encode([release_update_relation($argv[3], ['sha' => $argv[4]], $git),
            release_update_relation($argv[5], ['sha' => $argv[4]], $git)]);''',
                 ROOT / 'scripts/release_updates.php', repo, head, released, before)
    assert result == ['current', 'available']
    assert (repo / '.git/index').read_bytes() == index
    assert (repo / 'tracked').read_text() == 'user edit'
    assert git('rev-parse', 'HEAD') == head


@pytest.mark.parametrize('method,auth,header,expected', [('GET', False, True, 200), ('GET', False, False, 403),
                                                     ('POST', False, True, 401), ('POST', True, False, 403),
                                                     ('POST', True, True, 200), ('DELETE', True, True, 405)])
def test_endpoint_auth_and_no_database_access(php, tmp_path, method, auth, header, expected):
    scripts = tmp_path / 'scripts'
    scripts.mkdir()
    (scripts / 'common.php').write_text('''<?php
        function get_config() { return []; } function set_timezone() {}
        function get_home() { return __DIR__ . '/nonexistent'; } function get_user() { return null; }
        function is_authenticated() { return !empty($_SERVER['test_auth']); }
    ''')
    result = php('''define('__ROOT__', $argv[2]); $input = json_decode(stream_get_contents(STDIN), true);
        session_save_path($argv[2]); session_start();
        $_SERVER['REQUEST_URI'] = '/api/v1/system/updates'; $_SERVER['REQUEST_METHOD'] = $input['method'];
        $_SERVER['test_auth'] = $input['auth'];
        if ($input['header']) $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        ob_start(); register_shutdown_function(function () { $body = ob_get_clean();
            echo json_encode(['code' => http_response_code(), 'body' => json_decode($body, true), 'raw' => $body,
                'session_status' => session_status()]); });
        require $argv[1];''', ROOT / 'scripts/api.php', tmp_path, data={'method': method, 'auth': auth, 'header': header})
    assert result['code'] == expected
    assert not (scripts / 'birds.db').exists()
    if expected == 200:
        assert result['body']['status'] == 'unknown'
        assert result['session_status'] == 1, 'background check releases the PHP session lock'
