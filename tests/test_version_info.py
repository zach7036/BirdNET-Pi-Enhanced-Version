"""Read-only version metadata checks; Git integration uses disposable repositories."""
import json
import os
from pathlib import Path
import shutil
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'scripts' / 'version_info.php'
HASH = '12345678' * 5


@pytest.fixture
def php():
    binary = os.environ.get('BIRDNET_TEST_PHP') or shutil.which('php')
    if not binary:
        pytest.skip('Set BIRDNET_TEST_PHP to a PHP CLI')

    def run(code, *args, data=None):
        result = subprocess.run([binary, '-r', 'require $argv[1]; ' + code, str(HELPER), *map(str, args)],
                                input=json.dumps(data) if data is not None else '', text=True,
                                capture_output=True, timeout=20)
        assert result.returncode == 0, result.stdout + result.stderr
        assert not result.stderr, result.stderr
        return json.loads(result.stdout)
    return run


def fake_info(php, **overrides):
    responses = {
        'rev-parse': {'code': 0, 'output': HASH},
        'symbolic-ref': {'code': 0, 'output': 'main'},
        'describe': {'code': 0, 'output': 'v2.8.1-0-g' + HASH[:12]},
        'diff': {'code': 0, 'output': ''},
    }
    responses.update(overrides)
    return php('''
        $responses = json_decode(stream_get_contents(STDIN), true);
        $calls = [];
        $info = system_version_info('unused', null, function ($args) use ($responses, &$calls) {
            $calls[] = $args;
            return $responses[$args[0]];
        });
        echo json_encode(['info' => $info, 'calls' => $calls, 'text' => system_version_text($info),
                          'html' => render_system_version($info)]);
    ''', data=responses)


def test_exact_release_and_full_copy_details(php):
    result = fake_info(php)
    assert result['info'] == {'version': 'v2.8.1', 'branch': 'main', 'build': HASH, 'details': []}
    assert result['text'] == f'BirdNET-Pi\nVersion: v2.8.1\nBranch: main\nBuild: {HASH}'
    assert f'/commit/{HASH}' in result['html']
    assert '>1234567</a>' in result['html']
    assert [args[0] for args in result['calls']] == ['rev-parse', 'symbolic-ref', 'describe', 'diff']
    assert result['calls'][2][-1] == HASH
    assert result['calls'][3][-2:] == [HASH, '--']


@pytest.mark.parametrize('branch,label', [('development', 'Development build'), ('main', 'Unreleased build'),
                                         ('feature/example', 'Unreleased build')])
def test_commits_after_tag_are_not_advertised_as_release(php, branch, label):
    result = fake_info(php, **{'symbolic-ref': {'code': 0, 'output': branch},
                              'describe': {'code': 0, 'output': 'v2.8.1-2-g' + HASH[:12]}})
    assert result['info']['version'] == label
    assert result['info']['details'] == ['2 commits after v2.8.1']


def test_release_commit_on_development_keeps_accurate_tag_and_branch(php):
    result = fake_info(php, **{'symbolic-ref': {'code': 0, 'output': 'development'}})
    assert result['info']['version'] == 'v2.8.1'
    assert result['info']['branch'] == 'development'


def test_prerelease_is_identified(php):
    result = fake_info(php, describe={'code': 0, 'output': 'v2.8.2-rc.1-0-g' + HASH[:12]})
    assert result['info']['version'] == 'v2.8.2-rc.1'
    assert result['info']['details'] == ['Prerelease build']


@pytest.mark.parametrize('description', [None, 'vNOT-a-release-0-g' + HASH[:12], 'v2.8.1-0-gffffffffffff'])
def test_missing_invalid_or_inconsistent_tag_does_not_invent_version(php, description):
    result = fake_info(php, describe={'code': 128 if description is None else 0, 'output': description or ''})
    assert result['info']['version'] == 'Release unavailable'
    assert result['info']['build'] == HASH


@pytest.mark.parametrize('code,detail', [(1, 'Local tracked-file changes'), (128, 'Local-change status unavailable')])
def test_custom_changes_and_unknown_status_are_explicit(php, code, detail):
    result = fake_info(php, diff={'code': code, 'output': ''})
    assert result['info']['details'] == [detail]
    assert detail in result['text']


def test_git_failure_degrades_to_unknown_without_link(php):
    result = fake_info(php, **{'rev-parse': {'code': 128, 'output': 'error'}})
    assert result['info']['build'] == ''
    assert len(result['calls']) == 1
    assert 'href=' not in result['html']
    assert 'Build: Unavailable' in result['text']


def test_runner_exception_is_not_a_broken_settings_page(php):
    result = php('echo json_encode(system_version_info("unused", null, function ($args) { throw new Exception("failure"); }));')
    assert result['version'] == 'Release unavailable'


def test_branch_names_are_escaped_in_markup_and_no_settings_are_copied(php):
    result = fake_info(php, **{'symbolic-ref': {'code': 0, 'output': 'feature/<script>alert(1)</script>'}})
    assert '<script>' not in result['html']
    assert '&lt;script&gt;' in result['html']
    assert 'CADDY_PWD' not in result['text']
    assert 'Latitude' not in result['text']


@pytest.fixture
def repo(tmp_path):
    if not shutil.which('git'):
        pytest.skip('Git is required for version integration tests')
    directory = tmp_path / 'station with spaces'
    directory.mkdir()
    hooks = tmp_path / 'no-hooks'
    hooks.mkdir()

    def git(*args):
        result = subprocess.run(['git', '-C', str(directory), *args], capture_output=True, text=True, timeout=10)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout.strip()

    git('init', '-b', 'main')
    git('config', 'user.name', 'Version Tests')
    git('config', 'user.email', 'version-tests@example.invalid')
    git('config', 'commit.gpgsign', 'false')
    git('config', 'tag.gpgsign', 'false')
    git('config', 'core.hooksPath', str(hooks))
    (directory / 'source.txt').write_text('initial\n')
    git('add', 'source.txt')
    git('commit', '-m', 'fixture')
    return directory, git


def read_repo(php, directory):
    return php('echo json_encode(system_version_info($argv[2]));', directory)


@pytest.mark.parametrize('annotated', [False, True])
def test_real_git_tag_ahead_dirty_detached_and_untracked(php, repo, annotated):
    directory, git = repo
    if annotated:
        git('tag', '-a', 'v2.8.1', '-m', 'fixture release')
    else:
        git('tag', 'v2.8.1')
    original_index = (directory / '.git' / 'index').read_bytes()
    info = read_repo(php, directory)
    assert info['version'] == 'v2.8.1'
    assert info['branch'] == 'main'
    assert info['build'] == git('rev-parse', 'HEAD')
    assert info['details'] == []
    assert (directory / '.git' / 'index').read_bytes() == original_index
    (directory / 'untracked.txt').write_text('not an installed tracked-file change\n')
    assert read_repo(php, directory)['details'] == []
    git('switch', '-c', 'development')
    (directory / 'source.txt').write_text('modified\n')
    assert read_repo(php, directory)['details'] == ['Local tracked-file changes']
    git('add', 'source.txt')
    git('commit', '-m', 'one later commit')
    info = read_repo(php, directory)
    assert info['version'] == 'Development build'
    assert info['details'] == ['1 commit after v2.8.1']
    git('checkout', '--detach', 'v2.8.1')
    info = read_repo(php, directory)
    assert info['version'] == 'v2.8.1'
    assert info['branch'] == 'Detached HEAD'


def test_real_git_without_tags_and_non_repository(php, repo, tmp_path):
    directory, _ = repo
    info = read_repo(php, directory)
    assert info['version'] == 'Release unavailable'
    assert info['branch'] == 'main'
    assert len(info['build']) == 40
    assert read_repo(php, tmp_path)['build'] == ''
