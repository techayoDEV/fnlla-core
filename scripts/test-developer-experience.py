#!/usr/bin/env python3
"""Disposable documentation/example rehearsal; never a human or publication approval."""
import argparse
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OLD_SHA = 'de40d8ca061d74503551e15ff8246d9844804ed8ba0138213b6014394b511233'


def require(value, message):
    if not value:
        raise RuntimeError(message)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifact', type=Path, required=True)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--composer-phar', type=Path)
    args = parser.parse_args()
    artifact = args.artifact.resolve(strict=True)
    require(re.fullmatch('[a-f0-9]{40}', args.commit), 'Full expected source commit required.')
    dsn = os.environ.get('FNLLA_CORE_TEST_MYSQL_DSN', '')
    require(re.search(r'(?:^|;)dbname=fnlla_core_test(?:;|$)', dsn), 'Dedicated fnlla_core_test MySQL database required.')
    php = shutil.which('php')
    require(php, 'PHP is required.')
    composer = [php, str(args.composer_phar.resolve(strict=True))] if args.composer_phar else ['composer']
    steps = []
    def run(command, cwd):
        result = subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=180)
        # Do not print environment or raw provider diagnostics with credentials.
        require(result.returncode == 0, 'Documented command failed: ' + ' '.join(str(x) for x in command[:3]))
        steps.append({'command': [str(x) for x in command], 'status': 'passed', 'output_sha256': hashlib.sha256(result.stdout.encode()).hexdigest()})
        return result.stdout
    prerelease = '-' in artifact.stem.removeprefix('fnlla-core-')
    run(['python', str(ROOT / 'scripts/verify-release.py'), str(artifact), args.commit] + (['--local-review'] if prerelease else []), ROOT)
    with tempfile.TemporaryDirectory(prefix='fnlla-dx-') as temporary:
        work = Path(temporary)
        oldzip = work / 'fnlla-core-2.8.1.zip'
        url = 'https://github.com/techayoDEV/fnlla-core/releases/download/v2.8.1/fnlla-core-2.8.1.zip'
        with urllib.request.urlopen(url, timeout=30) as response:
            oldzip.write_bytes(response.read(4 * 1024 * 1024 + 1))
        require(digest(oldzip) == OLD_SHA, 'Original 2.8.1 package checksum mismatch.')
        def package(path, version=None):
            with zipfile.ZipFile(path) as archive:
                matches = [n for n in archive.namelist() if n.count('/') == 1 and n.endswith('/composer.json')]
                require(len(matches) == 1, 'Ambiguous package root.')
                metadata = json.loads(archive.read(matches[0]))
                base = matches[0].removesuffix('composer.json')
                version = version or archive.read(base + 'VERSION').decode().strip()
            return {**{k: metadata[k] for k in ['name', 'require', 'autoload', 'type', 'license']},
                    'version': version, 'dist': {'type': 'zip', 'url': path.as_uri(), 'shasum': hashlib.sha1(path.read_bytes()).hexdigest()}}
        target = package(artifact)
        packages = [target, package(oldzip, '2.8.1')]
        repository = [{'type': 'package', 'package': packages}]
        library = work / 'library'
        library.mkdir()
        (library / 'composer.json').write_text(json.dumps({'name': 'exercise/core-consumer', 'repositories': repository,
            'require': {'techayodev/fnlla-core': target['version']}, 'minimum-stability': 'dev', 'prefer-stable': True}))
        run(composer + ['install', '--no-dev', '--no-interaction', '--no-progress'], library)
        run(composer + ['check-platform-reqs', '--no-dev'], library)
        examples = library / 'vendor/techayodev/fnlla-core/resources/examples/developer-experience'
        require((examples / 'DemoController.php').is_file(), 'Selected package lacks the documented example.')
        original = work / 'old-library'
        original.mkdir()
        (original / 'composer.json').write_text(json.dumps({'name': 'exercise/old-consumer', 'repositories': repository,
            'require': {'techayodev/fnlla-core': '2.8.1'}}))
        run(composer + ['install', '--no-dev', '--no-interaction', '--no-progress'], original)
        fields = dict(re.findall(r'(?:^|;)([a-z]+)=([^;]*)', dsn.removeprefix('mysql:')))
        for name, source, version in [('fresh', library, target['version']), ('upgrade', original, '2.8.1')]:
            app = work / name
            run([php, str(source / 'vendor/techayodev/fnlla-core/fnlla'), 'make:project', str(app), 'Developer Exercise'], source)
            metadata = json.loads((app / 'composer.json').read_text())
            metadata['repositories'] = repository
            metadata['require']['techayodev/fnlla-core'] = version
            metadata['minimum-stability'] = 'dev'
            metadata['prefer-stable'] = True
            (app / 'composer.json').write_text(json.dumps(metadata, indent=2))
            run(composer + ['install', '--no-dev', '--no-interaction', '--no-progress'], app)
            table = 'dx_' + os.urandom(6).hex() + '_users'
            settings = {'APP_ENV': 'development', 'APP_DEBUG': 'false', 'APP_URL': '', 'TRUSTED_HOSTS': '127.0.0.1',
                'DB_HOST': fields.get('host', '127.0.0.1'), 'DB_PORT': fields.get('port', '3306'),
                'DB_DATABASE': 'fnlla_core_test', 'DB_USERNAME': os.environ.get('FNLLA_CORE_TEST_MYSQL_USER', ''),
                'DB_PASSWORD': os.environ.get('FNLLA_CORE_TEST_MYSQL_PASSWORD', ''), 'CACHE_STORE': 'file',
                'AUTH_USERS_TABLE': table, 'FNLLA_DX_DISPOSABLE': '1', 'SESSION_SECURE': 'false'}
            require(all('\n' not in str(v) and '\r' not in str(v) for v in settings.values()), 'Unsafe exercise environment value.')
            (app / '.env').write_text('\n'.join(k + '=' + str(v) for k, v in settings.items()) + '\n')
            shutil.copyfile(examples / 'DemoController.php', app / 'app/Controllers/DemoController.php')
            shutil.copyfile(examples / 'setup.php', app / 'scripts/demo-setup.php')
            with (app / 'routes/web.php').open('a') as output:
                output.write('\n' + (examples / 'routes.php').read_text().split('declare(strict_types=1);', 1)[1])
            configure = app / 'scripts/demo-configure.php'
            configure.write_text('<?php $root=dirname(__DIR__); $security=require $root."/config/security.php";'
                '$security["authorization"]["roles"]["editor"]=["permissions"=>["demo.report"]];'
                '$security["authorization"]["roles"]["reader"]=["permissions"=>[]];'
                'file_put_contents($root."/config/security.php","<?php return ".var_export($security,true).";");'
                '$app=require $root."/config/app.php"; $app["live_config_fields"]=[];'
                'file_put_contents($root."/config/app.php","<?php return ".var_export($app,true).";");')
            # Config templates use helpers, so load the actual app before evaluating them.
            configure.write_text(configure.read_text().replace('<?php ', '<?php require dirname(__DIR__)."/bootstrap/common.php"; ', 1))
            run([php, str(configure)], app)
            (app / 'public/uploads').mkdir(exist_ok=True)
            (app / 'public/uploads/retained.txt').write_text('synthetic retained upload')
            run([php, 'scripts/demo-setup.php', 'seed'], app)
            try:
                run([php, 'fnlla', 'config:cache'], app)
                run([php, 'fnlla', 'runtime:doctor', '--timeout=2'], app)
                exercise_http(php, app, steps, name + '-before')
                if name == 'upgrade':
                    backup = work / 'old-release'
                    shutil.copytree(app, backup)
                    before = {p: digest(app / p) for p in ['routes/web.php', 'config/security.php', 'app/Controllers/DemoController.php', 'public/uploads/retained.txt', '.env']}
                    metadata['require']['techayodev/fnlla-core'] = target['version']
                    (app / 'composer.json').write_text(json.dumps(metadata, indent=2))
                    run(composer + ['update', 'techayodev/fnlla-core', '--with-dependencies', '--no-dev', '--no-interaction', '--no-progress'], app)
                    run([php, 'fnlla', 'config:cache'], app)
                    require(before == {p: digest(app / p) for p in before}, 'Upgrade changed application-owned files.')
                    run([php, 'scripts/demo-setup.php', 'verify'], app)
                    exercise_http(php, app, steps, 'upgrade-after')
                    run([php, 'scripts/demo-setup.php', 'revoke'], app)
                    # Simulate failure after dependency installation, before activation.
                    (app / 'partial-update.txt').write_text('not activated')
                    rollback = work / 'rollback-release'
                    shutil.copytree(backup, rollback)
                    run(composer + ['install', '--no-dev', '--no-interaction', '--no-progress'], rollback)
                    run([php, 'fnlla', 'config:cache'], rollback)
                    require(not (rollback / 'partial-update.txt').exists(), 'Partial update survived code rollback.')
                    run([php, 'scripts/demo-setup.php', 'verify-revoked'], rollback)
                    exercise_http(php, rollback, steps, 'rollback-revocation', revoked=True)
                run(composer + ['check-platform-reqs', '--no-dev'], app)
                run([php, 'scripts/test.php'], app)
                run([php, 'scripts/lint.php'], app)
            finally:
                run([php, 'scripts/demo-setup.php', 'cleanup'], app)
        print(json.dumps({'status': 'passed', 'scope': 'automated-candidate-rehearsal' if prerelease else 'automated-release-rehearsal',
            'core_source_commit': args.commit, 'artifact_sha256': digest(artifact), 'baseline_sha256': OLD_SHA,
            'independent_human_acceptance': False, 'publication_authorized': False, 'steps': steps}, indent=2))


def exercise_http(php, app, steps, label, revoked=False):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (app / 'http.log').open('ab') as log:
        process = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=app, stdout=log, stderr=log)
        try:
            base = f'http://127.0.0.1:{port}'
            for attempt in range(100):
                try:
                    with urllib.request.urlopen(base + '/api/health', timeout=2) as response:
                        require(response.status == 200, 'Liveness failed.')
                    break
                except urllib.error.URLError:
                    require(process.poll() is None, 'Exercise HTTP process exited.')
                    time.sleep(.1)
            else:
                raise RuntimeError('Exercise HTTP server did not start.')
            with urllib.request.urlopen(base + '/', timeout=5) as response:
                require(response.status == 200 and b'Developer Exercise' in response.read(), 'Minimal homepage failed.')
            for email, expected in [('reader@example.test', 403), ('editor@example.test', 401 if revoked else 200)]:
                client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                def request(path, data=None, token=None):
                    headers = {'Accept': 'application/json'}
                    if data is not None: headers['Content-Type'] = 'application/json'
                    if token and data is not None: data = {**data, '_token': token}
                    query = urllib.request.Request(base + path, data=None if data is None else json.dumps(data).encode(), headers=headers)
                    try:
                        with client.open(query, timeout=5) as response: return response.status, response.read()
                    except urllib.error.HTTPError as error: return error.code, error.read()
                require(request('/demo/report')[0] == 401, 'Anonymous endpoint was not denied.')
                status, payload = request('/demo/token')
                require(status == 200, 'Token endpoint failed.')
                token = json.loads(payload)['token']
                credentials = {'email': email, 'password': 'local-demo-only'}
                require(request('/demo/login', credentials)[0] == 419, 'Missing CSRF was not denied.')
                invalid_status = request('/demo/login', {**credentials, 'password': 'incorrect'}, token)[0]
                require(invalid_status == 401, 'Invalid credential must return 401, received ' + str(invalid_status))
                status, _ = request('/demo/login', credentials, token)
                require(status == (401 if revoked and email.startswith('editor') else 200), 'Login result differs.')
                status, payload = request('/demo/report?role=editor')
                require(status == expected, 'Permission boundary failed: ' + label)
                if expected == 200: require(json.loads(payload) == {'report': 'ready', 'cache_ready': True}, 'Configured cache/report failed.')
                if expected == 200:
                    subprocess.run([php, 'scripts/demo-setup.php', 'revoke'], cwd=app, check=True, timeout=20, stdout=subprocess.DEVNULL)
                    try:
                        require(request('/demo/report')[0] == 401, 'Existing session survived identity revocation.')
                        require(request('/demo/login', credentials, token)[0] == 401, 'Revoked credential still authenticates.')
                    finally:
                        subprocess.run([php, 'scripts/demo-setup.php', 'restore-editor'], cwd=app, check=True, timeout=20, stdout=subprocess.DEVNULL)
            steps.append({'http': label, 'status': 'passed', 'assertions': ['home-200', 'anonymous-401', 'missing-csrf-419', 'invalid-credential-401', 'reader-and-forged-role-403', 'revoked-identity-and-existing-session-401' if not revoked else 'rollback-preserves-revocation-401', 'editor-401' if revoked else 'editor-and-cache-200']})
        finally:
            process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)


if __name__ == '__main__':
    main()
