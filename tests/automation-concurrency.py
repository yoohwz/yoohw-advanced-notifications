#!/usr/bin/env python3
"""Run automation contracts including independent Completed callbacks under a held rule-edit lock."""
import argparse
import json
import os
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-command', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
out = Path(args.output).resolve()
out.mkdir(parents=True, exist_ok=False)
env = dict(os.environ, AN_AUTOMATION_WP=str(Path(args.wp_command).resolve()))
result = subprocess.run([args.wp_command, 'eval-file', str(Path(__file__).with_name('automation-concurrency-fixture.php').resolve())], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=90)
(out / 'workers.log').write_text(result.stderr)
if result.returncode:
    (out / 'failed-output.log').write_text(result.stdout)
    raise AssertionError(result.stderr + result.stdout)
data = json.loads(result.stdout)
(out / 'result.json').write_text(json.dumps(data, indent=2) + '\n')
assert data['passed']
print(json.dumps(data, indent=2))
