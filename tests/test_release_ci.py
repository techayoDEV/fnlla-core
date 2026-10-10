import contextlib
import importlib.util
import io
import pathlib
import sys
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('release_ci', pathlib.Path(__file__).parents[1] / 'scripts/check-release-ci.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
SHA = 'a' * 40


class ReleaseCITest(unittest.TestCase):
    def verify(self, missing=None, conclusion='success', run_status='completed', newer=None):
        jobs = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'html_url': 'fixture'} for name in sorted(gate.REQUIRED) if name != missing]
        if conclusion != 'success': jobs[0]['conclusion'] = conclusion
        run = {'id': 1, 'head_sha': SHA, 'head_branch': 'main', 'event': 'push', 'status': run_status, 'conclusion': 'success', 'run_attempt': 1, 'html_url': 'fixture'}
        def api(path):
            if path == 'git/ref/heads/main': return {'object': {'sha': SHA}}
            if '/runs?' in path: return {'workflow_runs': [run] + ([dict(run, id=2, conclusion=newer)] if newer else [])}
            return {'jobs': jobs}
        with patch.object(gate, 'api', side_effect=api), patch.object(sys, 'argv', ['check-release-ci.py', SHA]), contextlib.redirect_stdout(io.StringIO()):
            gate.main()

    def test_exact_success(self):
        self.verify()

    def test_stable_protected_checks_and_every_underlying_job_are_required(self):
        for name in gate.REQUIRED:
            with self.subTest(name=name), self.assertRaises(AssertionError): self.verify(missing=name)

    def test_skipped_cancelled_failed_and_active_evidence_is_rejected(self):
        for value in ['skipped', 'cancelled', 'failure']:
            with self.subTest(value=value), self.assertRaises(AssertionError): self.verify(conclusion=value)
        with self.assertRaises(AssertionError): self.verify(run_status='in_progress')
        with self.assertRaises(AssertionError): self.verify(newer='failure')
