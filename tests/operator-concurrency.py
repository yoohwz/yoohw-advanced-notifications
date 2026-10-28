#!/usr/bin/env python3
"""Campaign controls, limits and privacy against real DB/Action Scheduler in independent workers."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-command', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
out = Path(args.output).resolve()
out.mkdir(parents=True, exist_ok=False)
worker = Path(__file__).with_name('reliability-worker.php').resolve()
checks = {}
evidence = {}


def spawn(mode, **data):
    env = dict(os.environ, AN_RELIABILITY_FIXTURES=str(out), AN_INPUT=json.dumps(dict(mode=mode, **data)))
    return subprocess.Popen([args.wp_command, 'eval-file', str(worker)], env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)


def collect(process):
    log, _ = process.communicate(timeout=45)
    with (out / 'workers.log').open('a') as file:
        file.write(f'PID={process.pid} EXIT={process.returncode}\n{log}\n')
    if process.returncode:
        raise AssertionError(log)
    result = [line[len('AN_RESULT='):] for line in log.splitlines() if line.startswith('AN_RESULT=')]
    return json.loads(result[-1]) if result else None


def run(mode, **data):
    return collect(spawn(mode, **data))


def wait(name):
    deadline = time.monotonic() + 30
    while not (out / name).exists():
        if time.monotonic() > deadline:
            raise AssertionError('Timed out: ' + name)
        time.sleep(.02)


def check(name, passed):
    checks[name] = bool(passed)
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)
    if not passed:
        raise AssertionError(name)


def state(id):
    evidence[id] = run('inspect', id=id)
    return evidence[id]['recipients']


def transport_count(subject=None):
    path = out / 'transport.jsonl'
    rows = [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []
    return sum(subject is None or row['subject'] == subject for row in rows)


try:
    run('reset')
    for operation in ['pause', 'cancel']:
        id = 'inflight-' + operation
        run('confirm', id=id, count=2)
        run('queue', dispatch=True)
        # Batch one recipient so the next remains unstarted while the transport is held.
        process = spawn('queue', batch=1, fault='hold_transport')
        wait('transport-held')
        check(operation + '_while_transport_active', run('control', id=id, operation=operation) is True)
        (out / 'release-transport').touch()
        collect(process)
        run('direct', id=id)
        rows = state(id)
        check(operation + '_records_inflight_handoff', rows[0]['status'] == 'sent')
        expected = 'paused_wait' if operation == 'pause' else 'cancelled'
        check(operation + '_blocks_unstarted_recipient', rows[1]['status'] == expected and transport_count('Reliability ' + id) == 1)
        run('reconcile', id=id)
        run('retry', id=id)
        check(operation + '_survives_recovery', state(id)[1]['status'] == expected)
        if operation == 'pause':
            check('resume_succeeds', run('control', id=id, operation='resume') is True)
            run('queue', dispatch=True)
            run('direct', id=id)
            rows = state(id)
            check('resume_sends_remaining_once', [row['status'] for row in rows] == ['sent', 'sent'] and transport_count('Reliability ' + id) == 2)
            check('resume_keeps_attempts', all(int(row['attempts']) == 1 for row in rows))
        else:
            check('cancel_cannot_resume', run('control', id=id, operation='resume') == {'error': 'cancelled'})
        run('queue')  # Finish duplicate/stale pending fixture actions before the next campaign.
        (out / 'transport-held').unlink()
        (out / 'release-transport').unlink()
    # Concurrent site admissions use a shared minute/day counter across campaign locks.
    run('reset')
    run('limits', limits={'minute': 1, 'day': 1}, reset_rate=True)
    rate_baseline = transport_count()
    for id in ['rate-a', 'rate-b']:
        run('confirm', id=id)
    run('queue', dispatch=True)
    a = spawn('direct', id='rate-a', barrier='a')
    b = spawn('direct', id='rate-b', barrier='b')
    wait('a-ready'); wait('b-ready'); (out / 'go').touch()
    collect(a); collect(b)
    rows = state('rate-a') + state('rate-b')
    check('parallel_campaigns_never_exceed_site_limit', sum(row['status'] == 'sent' for row in rows) == 1 and transport_count() == rate_baseline + 1)
    check('parallel_limit_wait_is_unclaimed', all(not row['claim_token'] for row in rows if row['status'] == 'rate_wait'))
    check('parallel_wait_does_not_consume_attempts', all(int(row['attempts']) == 1 for row in rows))
    waiting = next(id for id in ['rate-a', 'rate-b'] if state(id)[0]['status'] == 'rate_wait')
    run('reconcile', id=waiting)
    check('limit_wait_has_future_dispatcher', any(row['hook'] == 'yoohw_an_dispatch_campaign' and row['status'] == 'pending' for row in run('inspect', id=waiting)['jobs']))
    run('limits', limits={'minute': 1, 'day': 1}, reset_rate=True)
    run('clear_wait', id=waiting)
    run('direct', id=waiting)
    check('next_window_continues_same_campaign', state(waiting)[0]['status'] == 'sent' and transport_count() == rate_baseline + 2 and int(state(waiting)[0]['attempts']) == 1)
    run('reset')
    privacy_baseline = transport_count()
    run('confirm', id='privacy', email='guest-privacy@example.com')
    run('queue', dispatch=True)
    process = spawn('queue', batch=1, fault='hold_transport')
    wait('transport-held')
    result = run('erase', email='guest-privacy@example.com')
    check('erasure_reports_active_worker', result['items_retained'] and not result['items_removed'])
    (out / 'release-transport').touch(); collect(process)
    run('erase', email='guest-privacy@example.com')
    rows = state('privacy')
    check('erasure_after_handoff_removes_identity_keeps_claim', rows[0]['email'] == '' and rows[0]['status'] == 'sent' and bool(rows[0]['claim_token']))
    run('reconcile', id='privacy')
    check('erased_identity_does_not_reappear', state('privacy')[0]['email'] == '' and transport_count() == privacy_baseline + 1)
finally:
    meta = {'commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip(), 'working_tree': subprocess.check_output(['git', 'status', '--porcelain'], text=True), 'checks': checks, 'evidence': evidence}
    (out / 'results.json').write_text(json.dumps(meta, indent=2))
print(f'{len(checks)} checks passed. Evidence: {out}')
