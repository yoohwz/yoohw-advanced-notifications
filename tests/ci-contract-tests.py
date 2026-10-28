#!/usr/bin/env python3
"""Check support boundaries and prevent incomplete CI from passing the gate."""

import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ci_plan", ROOT / "bin/ci-plan.py")
ci_plan = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci_plan)


class CIContracts(unittest.TestCase):
    def test_support_matrix_and_intensity(self):
        fixture = "Requires at least: 6.6\nTested up to: 7.1\nRequires PHP: 8.1\n"
        full = ci_plan.plan("pull_request", "false", fixture)
        self.assertEqual(full["full"], "true")
        self.assertEqual(json.loads(full["matrix"])["include"], [
            {"wordpress": "6.6", "php": "8.1"},
            {"wordpress": "6.6", "php": "8.2"},
            {"wordpress": "7.1", "php": "8.1"},
            {"wordpress": "7.1", "php": "8.4"},
        ])
        draft = ci_plan.plan("pull_request", "true", fixture)
        self.assertEqual(draft["full"], "false")
        self.assertEqual(json.loads(draft["matrix"])["include"], [{"wordpress": "7.1", "php": "8.4"}])
        for event in ("push", "workflow_dispatch"):
            self.assertEqual(ci_plan.plan(event, "true", fixture), full)
        for invalid in ("", fixture.replace("8.1", "8.0"), fixture.replace("Tested up to:", "Missing:")):
            with self.assertRaises(ValueError):
                ci_plan.plan("pull_request", "false", invalid)

    def test_metadata_consistency(self):
        plugin = (ROOT / "yoohw-advanced-notifications.php").read_text()
        readme = (ROOT / "readme.txt").read_text()

        def value(text, key):
            return re.search(r"^\s*(?:\*\s*)?" + re.escape(key) + r":\s*(\S+)", text, re.M).group(1)

        self.assertEqual(value(plugin, "Version"), value(readme, "Stable tag"))
        for key in ("Requires at least", "Requires PHP"):
            self.assertEqual(value(plugin, key), value(readme, key))
        version = re.search(r"define\( 'YOOHW_AN_VERSION', '([^']+)' \)", plugin).group(1)
        self.assertEqual(version, value(plugin, "Version"))
        ci_plan.plan("workflow_dispatch", "false", readme)

    def test_gate_rejects_incomplete_results(self):
        env = dict(os.environ, FULL="true", PLAN="success", CONTRACTS="success", QUALITY="success",
                   JAVASCRIPT="success", REGRESSION="success", PLUGIN_CHECK="success")

        def gate(values):
            return subprocess.run(["bash", str(ROOT / "bin/validate-ci-gate.sh")], env=values,
                                  capture_output=True, text=True).returncode

        self.assertEqual(gate(env), 0)
        for job in ("PLAN", "CONTRACTS", "QUALITY", "JAVASCRIPT", "REGRESSION", "PLUGIN_CHECK"):
            for result in ("failure", "cancelled", "skipped", ""):
                with self.subTest(job=job, result=result):
                    self.assertNotEqual(gate(dict(env, **{job: result})), 0)
        self.assertEqual(gate(dict(env, FULL="false", PLUGIN_CHECK="skipped")), 0)
        self.assertNotEqual(gate(dict(env, FULL="")), 0)
        self.assertNotEqual(gate(dict(env, FULL="false", REGRESSION="skipped", PLUGIN_CHECK="skipped")), 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
