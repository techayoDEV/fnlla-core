import contextlib
import importlib.util
import io
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import types
import unittest
import zipfile
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('release_ci', pathlib.Path(__file__).parents[1] / 'scripts/check-release-ci.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
SHA = 'a' * 40

artifact_spec = importlib.util.spec_from_file_location('verify_release', pathlib.Path(__file__).parents[1] / 'scripts/verify-release.py')
artifact = importlib.util.module_from_spec(artifact_spec)
artifact_spec.loader.exec_module(artifact)


def optimized(module):
    result = types.ModuleType(module.__name__ + '_optimized')
    result.__file__ = module.__file__
    exec(compile(pathlib.Path(module.__file__).read_text(), module.__file__, 'exec', optimize=2), result.__dict__)
    return result


class ReleaseVersionTest(unittest.TestCase):
    def test_malformed_stable_identity_is_rejected_before_consumer_installation(self):
        for version in ['03.0.0', '3.01.0', '3.0.01', '3.0.0-rc.1', '3.0.0+build.1', 'v3.0.0', '3.0']:
            with self.subTest(version=version), tempfile.TemporaryDirectory(prefix='fnlla-version-test-') as temporary:
                archive = pathlib.Path(temporary) / 'candidate.zip'
                with zipfile.ZipFile(archive, 'w') as package:
                    package.writestr('package/VERSION', version + '\n')
                    for name in ['FNLLA-PROVENANCE.json', 'FNLLA-PACKAGE.json']:
                        package.writestr('package/' + name, json.dumps({}))
                archive.with_suffix('.zip.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '\n')
                with patch.object(sys, 'argv', ['verify-release.py', str(archive), SHA]), patch.object(artifact, 'run') as install:
                    with self.assertRaisesRegex(ValueError, 'Stable version required'):
                        artifact.main()
                    install.assert_not_called()

    def test_optimized_verifier_still_rejects_an_invalid_source_commit(self):
        verifier = optimized(artifact)
        with patch.object(sys, 'argv', ['verify-release.py', 'unused.zip', 'not-a-commit']):
            with self.assertRaisesRegex(ValueError, 'Expected source commit'):
                verifier.main()


class ReleaseCITest(unittest.TestCase):
    def verify(self, missing=None, conclusion='success', run_status='completed', newer=None, duplicate=False, module=gate):
        jobs = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'html_url': 'fixture'} for name in sorted(gate.REQUIRED) if name != missing]
        if conclusion != 'success': jobs[0]['conclusion'] = conclusion
        if duplicate: jobs.append(dict(jobs[0]))
        run = {'id': 1, 'head_sha': SHA, 'head_branch': 'main', 'event': 'push', 'status': run_status, 'conclusion': 'success', 'run_attempt': 1, 'html_url': 'fixture'}
        def api(path):
            if path == 'git/ref/heads/main': return {'object': {'sha': SHA}}
            if '/runs?' in path: return {'workflow_runs': [run] + ([dict(run, id=2, conclusion=newer)] if newer else [])}
            return {'jobs': jobs}
        with patch.object(module, 'api', side_effect=api), patch.object(sys, 'argv', ['check-release-ci.py', SHA]), contextlib.redirect_stdout(io.StringIO()):
            module.main()

    def test_exact_success(self):
        self.verify()

    def test_stable_protected_checks_and_every_underlying_job_are_required(self):
        for name in gate.REQUIRED:
            with self.subTest(name=name), self.assertRaises(ValueError): self.verify(missing=name)
        with self.assertRaises(ValueError): self.verify(duplicate=True)

    def test_skipped_cancelled_failed_and_active_evidence_is_rejected(self):
        for value in ['skipped', 'cancelled', 'failure']:
            with self.subTest(value=value), self.assertRaises(ValueError): self.verify(conclusion=value)
        with self.assertRaises(ValueError): self.verify(run_status='in_progress')
        with self.assertRaises(ValueError): self.verify(newer='failure')

    def test_optimized_python_cannot_bypass_failed_or_missing_ci(self):
        module = optimized(gate)
        self.verify(module=module)
        for options in [{'missing': 'PHP 8.5'}, {'conclusion': 'failure'}, {'newer': 'failure'}, {'duplicate': True}]:
            with self.subTest(options=options), self.assertRaises(ValueError):
                self.verify(module=module, **options)


class LocalReleaseCITest(unittest.TestCase):
    def fixture(self):
        digest = 'c' * 64
        app_id = 987654
        checks = [{'name': name, 'status': 'success', 'omissions': []} for name in sorted(gate.REQUIRED)]
        names = ['Local CI / ' + row['name'] for row in checks]
        verified = {
            'schema': 'fnlla.local_ci.verified.v1', 'repository': gate.REPO,
            'source_commit': SHA, 'source_ref': 'refs/heads/main', 'purpose': 'release',
            'receipt_sha256': digest, 'signature_sha256': 'd' * 64,
            'policy_sha256': 'e' * 64, 'controller_policy_sha256': 'f' * 64,
            'latest_sequence': 1, 'latest_status': 'success', 'checks': checks,
            'publication_checks': {'app_id': app_id, 'installation_id': 321, 'slug': 'fnlla-local-ci',
                                   'names': names, 'external_id': digest},
        }
        remote = [
            {'name': name, 'head_sha': SHA, 'status': 'completed', 'conclusion': 'success',
             'app': {'id': app_id}, 'external_id': digest,
             'html_url': 'https://github.com/techayoDEV/fnlla-core/runs/fixture-' + str(index)}
            for index, name in enumerate(names)
        ]
        return verified, remote

    def verify(self, edit=None, module=gate, moved=False, signature_error=None):
        verified, remote = self.fixture()
        if edit:
            edit(verified, remote)
        main_reads = 0

        def api(path):
            nonlocal main_reads
            if path == 'git/ref/heads/main':
                main_reads += 1
                return {'object': {'sha': 'b' * 40 if moved and main_reads > 1 else SHA}}
            self.assertEqual('commits/' + SHA + '/check-runs?filter=latest&per_page=100', path)
            return {'total_count': len(remote), 'check_runs': remote}

        arguments = ['check-release-ci.py', SHA, '--venue', 'local-native', '--receipt', 'receipt.json',
                     '--signature', 'receipt.sig', '--policy', 'operator-policy.json', '--evidence-root', 'evidence']
        output = io.StringIO()
        with patch.object(module, 'api', side_effect=api), \
                patch.object(module, 'verify_signed_local_receipt', return_value=verified, side_effect=signature_error), \
                patch.object(sys, 'argv', arguments), contextlib.redirect_stdout(output):
            module.main()
        return json.loads(output.getvalue())

    def test_signed_local_release_remains_separate_from_github_actions(self):
        result = self.verify()
        self.assertEqual('signed-local-native', result['venue'])
        self.assertFalse(result['public_github_actions_receipt'])
        self.assertEqual(15, len(result['jobs']))
        self.assertEqual({'c' * 64}, {row['external_id'] for row in result['jobs']})

    def test_source_policy_signature_and_latest_sequence_are_mandatory(self):
        mutations = [
            lambda value, remote: value.update(source_commit='b' * 40),
            lambda value, remote: value.update(repository='other/repository'),
            lambda value, remote: value.update(purpose='pull-request'),
            lambda value, remote: value.update(source_ref='refs/heads/release/core-3.0.0'),
            lambda value, remote: value.update(policy_sha256=None),
            lambda value, remote: value.update(controller_policy_sha256='not-a-digest'),
            lambda value, remote: value.update(signature_sha256=''),
            lambda value, remote: value.update(latest_sequence=True),
            lambda value, remote: value.update(latest_sequence=0),
            lambda value, remote: value.update(latest_status='running'),
            lambda value, remote: value.update(latest_status='failure'),
        ]
        for index, mutate in enumerate(mutations):
            with self.subTest(case=index), self.assertRaises(ValueError):
                self.verify(edit=mutate)
        with self.assertRaisesRegex(ValueError, 'invalid signature'):
            self.verify(signature_error=ValueError('invalid signature'))

    def test_no_missing_duplicate_failed_or_unreported_local_check_is_accepted(self):
        mutations = [
            lambda value, remote: value['checks'].pop(),
            lambda value, remote: value['checks'].append(dict(value['checks'][0])),
            lambda value, remote: value['checks'][0].update(status='skipped'),
            lambda value, remote: value['checks'][0].update(status='failure'),
            lambda value, remote: value['checks'][0].pop('omissions'),
            lambda value, remote: value['publication_checks']['names'].pop(),
            lambda value, remote: value['publication_checks'].update(external_id='d' * 64),
        ]
        for index, mutate in enumerate(mutations):
            with self.subTest(case=index), self.assertRaises(ValueError):
                self.verify(edit=mutate)

    def test_actual_latest_source_bound_app_checks_are_required(self):
        mutations = [
            lambda value, remote: remote.pop(),
            lambda value, remote: remote.append(dict(remote[0])),
            lambda value, remote: remote[0].update(head_sha='b' * 40),
            lambda value, remote: remote[0].update(external_id='d' * 64),
            lambda value, remote: remote[0].update(status='in_progress'),
            lambda value, remote: remote[0].update(conclusion='failure'),
            lambda value, remote: remote[0].update(app={'id': 15368}),
            lambda value, remote: value['publication_checks'].update(app_id=15368),
        ]
        for index, mutate in enumerate(mutations):
            with self.subTest(case=index), self.assertRaises(ValueError):
                self.verify(edit=mutate)
        with self.assertRaisesRegex(ValueError, 'Remote main changed'):
            self.verify(moved=True)

    def test_local_arguments_cannot_silently_select_or_fall_back_from_the_local_venue(self):
        for arguments in [
            ['check-release-ci.py', SHA, '--receipt', 'receipt.json'],
            ['check-release-ci.py', SHA, '--venue', 'local-native'],
            ['check-release-ci.py', SHA, '--venue', 'local-native', '--receipt', 'receipt.json', '--signature', 'sig'],
        ]:
            with self.subTest(arguments=arguments), patch.object(sys, 'argv', arguments), \
                    patch.object(gate, 'api') as remote, patch.object(gate, 'verify_signed_local_receipt') as signed:
                with self.assertRaisesRegex(ValueError, 'Select the local venue'):
                    gate.main()
                remote.assert_not_called()
                signed.assert_not_called()

    def test_required_checks_can_be_read_without_api_or_receipt_authority(self):
        output = io.StringIO()
        with patch.object(sys, 'argv', ['check-release-ci.py', '--required-checks']), \
                patch.object(gate, 'api') as remote, contextlib.redirect_stdout(output):
            gate.main()
        self.assertEqual(sorted(gate.REQUIRED), json.loads(output.getvalue()))
        remote.assert_not_called()

    def test_optimized_python_preserves_every_local_release_boundary(self):
        module = optimized(gate)
        self.verify(module=module)
        for mutate in [
            lambda value, remote: value['checks'].pop(),
            lambda value, remote: value.update(source_ref='refs/heads/release/core-3.0.0'),
            lambda value, remote: value.update(policy_sha256='invalid'),
            lambda value, remote: value.update(latest_status='running'),
            lambda value, remote: remote[0].update(conclusion='failure'),
            lambda value, remote: remote[0].update(external_id='d' * 64),
            lambda value, remote: value['publication_checks'].update(app_id=15368),
        ]:
            with self.subTest(mutate=mutate), self.assertRaises(ValueError):
                self.verify(module=module, edit=mutate)


class LocalReceiptCryptographyTest(unittest.TestCase):
    def test_actual_node_signature_file_and_policy_boundaries(self):
        root = pathlib.Path(__file__).resolve().parents[1]
        environment = dict(os.environ, FNLLA_TEST_PYTHON=sys.executable)
        result = subprocess.run(['node', '--test', 'tests/local_ci_receipt.test.mjs'], cwd=root,
                                env=environment, capture_output=True, text=True, timeout=180)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        # Preserve actual individual crypto/filesystem assertions in the native job log.
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
