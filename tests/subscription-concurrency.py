#!/usr/bin/env python3
"""Mailbox request/confirmation and erasure races in separate PHP processes against real DB."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-command', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
out = Path(args.output).resolve()
out.mkdir(parents=True, exist_ok=False)
worker = Path(__file__).with_name('subscription-worker.php').resolve()
tag = 'subscription-race-' + uuid.uuid4().hex[:10]
checks, evidence = {}, {}
ids, clients, live = [], [], []


def spawn(mode, id=None, **kwargs):
    id = id or tag
    if id not in ids:
        ids.append(id)
    client = kwargs.pop('client', tag + '-' + id)
    if client not in clients:
        clients.append(client)
    env = dict(os.environ, AN_SUBSCRIPTION_EVIDENCE=str(out), AN_SUBSCRIPTION_INPUT=json.dumps(dict(mode=mode, id=id, tag=tag, client=client, **kwargs)))
    process = subprocess.Popen([args.wp_command, 'eval-file', str(worker)], env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    live.append(process)
    return process


def collect(process):
    log, _ = process.communicate(timeout=40)
    with (out / 'workers.log').open('a') as file:
        file.write(f'PID={process.pid} EXIT={process.returncode}\n{log}\n')
    if process.returncode:
        raise AssertionError(log)
    values = [json.loads(line.removeprefix('AN_SUBSCRIPTION_RESULT=')) for line in log.splitlines() if line.startswith('AN_SUBSCRIPTION_RESULT=')]
    if not values:
        raise AssertionError(log)
    return values[-1]


def run(mode, id=None, **kwargs):
    return collect(spawn(mode, id, **kwargs))


def wait(name):
    end = time.monotonic() + 30
    while not (out / name).exists():
        if time.monotonic() >= end:
            raise AssertionError('Subscription worker did not reach ' + name)
        time.sleep(.02)


def release(name):
    (out / name).touch()


def check(name, passed):
    checks[name] = bool(passed)
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)
    if not passed:
        raise AssertionError(name)


def state(id):
    value = run('inspect', id)
    evidence[id] = value
    return value


def mails(id):
    path = out / 'transport.jsonl'
    return [row for row in map(json.loads, path.read_text().splitlines()) if row['email'] == id + '@example.com'] if path.exists() else []


def token(id):
    rows = mails(id)
    if len(rows) != 1 or len(rows[0]['token']) != 64:
        raise AssertionError('Expected exactly one intercepted mailbox confirmation token for ' + id)
    return rows[0]['token']


try:
    run('quota_snapshot')
    id = tag + '-parallel'
    a = spawn('request', id, start='request-a', start_group='requests')
    b = spawn('request', id, start='request-b', start_group='requests')
    wait('request-a-ready'); wait('request-b-ready'); release('requests-go')
    request_results = [collect(a), collect(b)]
    current = state(id)
    check('concurrent_requests_send_one_confirmation', len(mails(id)) == 1)
    check('concurrent_requests_reserve_one_address_and_site_slot', current['address_quota']['used'] == 1 and current['site_quota']['used'] == 1)
    check('request_does_not_create_subscription', not current['subscription'] and current['eligible_count'] == 0)
    captured = token(id)
    check('pending_stores_only_token_hash', captured not in json.dumps(current['pending']) and len(current['pending']['token_hash']) == 64)
    a = spawn('confirm', id, token=captured, start='confirm-a', start_group='confirmations')
    b = spawn('confirm', id, token=captured, start='confirm-b', start_group='confirmations')
    wait('confirm-a-ready'); wait('confirm-b-ready'); release('confirmations-go')
    outcomes = [collect(a), collect(b)]
    check('concurrent_confirmation_consumes_authority_once', outcomes.count(True) == 1 and sum(isinstance(value, dict) and bool(value.get('error')) for value in outcomes) == 1)
    current = state(id)
    check('parallel_confirmation_produces_one_effective_subscriber', current['eligible_count'] == 1 and current['effective']['status'] == 'subscribed')
    replay = run('confirm', id, token=captured)
    check('parallel_confirmation_cannot_replay_token', isinstance(replay, dict) and bool(replay.get('error')) and current['pending']['token_hash'] == '')

    id = tag + '-unsubscribe'
    run('request', id); captured = token(id)
    check('unsubscribe_after_request_is_durable', run('unsubscribe', id) is True)
    rejected = run('confirm', id, token=captured); current = state(id)
    check('confirmation_before_latest_unsubscribe_cannot_revive_consent', isinstance(rejected, dict) and bool(rejected.get('error')) and current['eligible_count'] == 0 and current['effective']['status'] == 'unsubscribed' and bool(current['optout']))

    # Confirmation entered first, then erasure publishes its gate while the writer is held.
    id = tag + '-confirm-first'
    run('request', id); captured = token(id)
    process = spawn('confirm', id, token=captured, barrier='before-subscription-write')
    wait('before-subscription-write-ready')
    erasure = run('erase', id)
    check('held_confirmation_erasure_reports_incomplete_truthfully', not erasure['items_removed'] and erasure['items_retained'])
    release('before-subscription-write-go'); confirmed = collect(process); current = state(id)
    check('erasure_gate_rejects_inflight_confirmation_success', isinstance(confirmed, dict) and bool(confirmed.get('error')) and current['erased'] and current['eligible_count'] == 0 and current['effective']['status'] == 'unsubscribed')
    check('erasure_retry_after_confirmation_completes', run('erase', id)['items_removed'])
    current = state(id)
    check('erased_confirmation_leaves_no_plaintext_or_eligible_subscriber', current['plaintext_options'] == 0 and current['eligible_count'] == 0)
    before = len(mails(id)); run('request', id)
    replay = run('confirm', id, token=captured)
    check('erased_address_cannot_reconstruct_request_or_replay', len(mails(id)) == before and isinstance(replay, dict) and bool(replay.get('error')) and state(id)['plaintext_options'] == 0)

    # Erasure entered first and holds privacy authority; confirmation waits behind it.
    id = tag + '-erase-first'
    run('request', id); captured = token(id)
    eraser = spawn('erase', id, barrier='before-erasure-scrub')
    wait('before-erasure-scrub-ready')
    confirmer = spawn('confirm', id, token=captured, barrier='notify-privacy-lock')
    wait('confirm-at-privacy-lock'); release('before-erasure-scrub-go')
    erasure = collect(eraser); confirmed = collect(confirmer); current = state(id)
    check('erasure_before_confirmation_finishes_without_republication', erasure['items_removed'] and isinstance(confirmed, dict) and bool(confirmed.get('error')) and current['plaintext_options'] == 0 and current['eligible_count'] == 0)
    check('completed_erasure_keeps_confirmation_suppressed', current['erased'] and current['effective']['status'] == 'unsubscribed')
finally:
    # Release a worker even when an assertion fails, before restoring only fixture-owned state.
    for name in ['requests-go', 'confirmations-go', 'before-subscription-write-go', 'before-erasure-scrub-go']:
        release(name)
    for process in live:
        if process.poll() is None:
            try:
                collect(process)
            except Exception:
                process.kill(); process.communicate()
    try:
        if (out / 'site-quota-before.json').exists():
            run('cleanup', ids=list(ids), clients=list(clients))
    finally:
        metadata = {'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=worker.parent.parent, text=True).strip(), 'dirty': subprocess.check_output(['git', 'status', '--porcelain'], cwd=worker.parent.parent, text=True), 'checks': checks, 'evidence': evidence}
        (out / 'results.json').write_text(json.dumps(metadata, indent=2) + '\n')
print(f'{len(checks)} checks passed. Evidence: {out}')
