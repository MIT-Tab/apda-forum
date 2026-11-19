import importlib.machinery
import importlib.util
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[4]
class WorkflowTests(unittest.TestCase):
    def test_shell_helpers_parse(self):
        for name in ('start-task', 'shot', 'pr-images'):
            subprocess.run(['bash', '-n', str(ROOT / '.agents/skills/forum-task/scripts' / name)], check=True)

    def test_claude_skill_resolves(self):
        self.assertEqual((ROOT / '.claude/skills/forum-task').resolve(), ROOT / '.agents/skills/forum-task')

    def test_ci_states(self):
        path = ROOT / '.agents/skills/forum-task/scripts/ci-watch'
        loader = importlib.machinery.SourceFileLoader('watch', str(path))
        spec = importlib.util.spec_from_loader(loader.name, loader)
        watch = importlib.util.module_from_spec(spec)
        loader.exec_module(watch)
        self.assertFalse(watch.done({'__typename': 'CheckRun', 'status': 'IN_PROGRESS'}))
        self.assertTrue(watch.done({'__typename': 'CheckRun', 'status': 'COMPLETED'}))
        self.assertTrue(watch.ok({'__typename': 'CheckRun', 'conclusion': 'SUCCESS'}))
        self.assertFalse(watch.ok({'__typename': 'CheckRun', 'conclusion': 'FAILURE'}))
