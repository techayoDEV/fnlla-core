import contextlib
import importlib.util
import io
import hashlib
import json
import pathlib
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
