#!/usr/bin/env python3
"""Real DB privacy publication/erasure races. Requires a disposable reliability database."""
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
worker = Path(__file__).with_suffix('.php').resolve()
tag = 'privacy-' + uuid.uuid4().hex[:10]
checks = {}


def spawn(mode, id, **kwargs):
    env = dict(os.environ, AN_PRIVACY_EVIDENCE=str(out), AN_PRIVACY_INPUT=json.dumps(dict(mode=mode, id=id, **kwargs)))
    return subprocess.Popen([args.wp_command, 'eval-file', str(worker)], env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)


def collect(p):
    log, _ = p.communicate(timeout=35)
    with (out / 'workers.log').open('a') as f:
        f.write(f'PID={p.pid} EXIT={p.returncode}\n{log}\n')
    if p.returncode:
        raise AssertionError(log)
    lines = [line.removeprefix('AN_PRIVACY_RESULT=') for line in log.splitlines() if line.startswith('AN_PRIVACY_RESULT=')]
    if not lines:
        raise AssertionError(log)
    return json.loads(lines[-1])


def run(mode, id, **kwargs):
    return collect(spawn(mode, id, **kwargs))


def barrier():
    deadline = time.monotonic() + 20
    while not (out / 'ready').exists():
        if time.monotonic() > deadline:
            raise AssertionError('Worker did not reach write barrier')
        time.sleep(.02)


def release():
    (out / 'go').touch()


def reset():
    for name in ['ready', 'go']:
        (out / name).unlink(missing_ok=True)


def check(name, passed):
    checks[name] = bool(passed)
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)
    if not passed:
        raise AssertionError(name)


try:
    for kind in ['subscription', 'optout', 'log', 'ensure', 'prepare', 'draft', 'audience', 'preview', 'import', 'snapshot', 'manifest']:
        reset()
        id = tag + '-' + kind
        p = spawn('publish', id, kind=kind, pause=True)
        try:
            barrier()
            erased = run('erase', id)
            check(kind + '_held_writer_erasure_truthful', not erased['items_removed'] and erased['items_retained'])
        finally:
            release()
        collect(p)
        check(kind + '_retry_erasure_completes', run('erase', id)['items_removed'])
        # Simulate stale request state that resumes publication after a completed erase.
        run('publish', id, kind=kind)
        state = run('inspect', id)
        check(kind + '_stale_publication_cannot_restore_plaintext', state['plaintext_options'] == 0 and all(not r['email'] for r in state['rows']))
    id = tag + '+part/name'
    run('erase', id)
    run('publish', id, kind='draft')
    check('stale_free_text_handles_valid_special_email_characters', run('inspect', id)['plaintext_options'] == 0)
    check('ordinary_unclaimed_legacy_identity_can_be_restored', run('ordinary_identity', tag + '-ordinary'))
    reset()
    id = tag + '-shared-race'
    p = spawn('publish', id, kind='shared', pause=True)
    try:
        barrier()
        erased = run('erase', id)
        check('shared_writer_busy_erasure_truthful', not erased['items_removed'] and erased['items_retained'])
    finally:
        release()
    collect(p)
    for locks in [['__privacy__'], ['__site_rate__', '__privacy__']]:
        reset()
        id = tag + '-busy-' + str(len(locks))
        run('seed', id)
        before = run('inspect', id)['rows'][0]
        p = spawn('lock', id, locks=locks)
        try:
            barrier()
            run('worker', id)
            during = run('inspect', id)['rows'][0]
            check('busy_' + str(len(locks)) + '_keeps_attempts', before['attempts'] == during['attempts'])
        finally:
            release()
        collect(p)
        run('worker', id)
        run('worker', id)
        after = run('inspect', id)['rows'][0]
        check('busy_' + str(len(locks)) + '_one_final_handoff', after['status'] == 'sent' and before['attempts'] == after['attempts'])
    result = run('preparation', tag + '-preparation')
    check('placeholder_write_failure_reports_incomplete', result['failure_reported'])
    check('interrupted_preparation_erasure_preserves_cardinality', result['erased'] and result['manifest_count'] == 2 and result['placeholder']['erased'] and not result['placeholder'].get('email'))
    check('interrupted_preparation_finishes_without_unresolved', result['status'] == 'completed_with_errors' and result['unresolved'] == 0 and result['row_status'] == 'cancelled' and not result['row_email'] and result['due_preserved'])
    for status in ['sent', 'unknown']:
        result = run('preservation', tag + '-preserve-' + status, status=status)
        check('erasure_preserves_' + status + '_claim', result['erased'] and result['row']['status'] == status and result['row']['claim_token'] == 'preserved-token' and not result['row']['email'] and not result['row']['recipient_name'] and not result['row']['last_error'])
    result = run('preservation', tag + '-preserve-processing', status='processing')
    check('inflight_erasure_reports_retained_and_preserves_claim', not result['erased'] and result['row']['status'] == 'processing' and result['row']['claim_token'] == 'preserved-token' and bool(result['row']['email']))
    result = run('shared_policy', tag + '-shared')
    check('active_shared_content_checksum_preserved', result['active_preserved'])
    check('terminal_shared_content_erased_on_repeat', result['terminal_scrubbed'])
    check('no_duplicate_transport_after_busy', len((out / 'transport.jsonl').read_text().splitlines()) == 3)
finally:
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=worker.parent.parent, text=True).strip()
    dirty = bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=worker.parent.parent, text=True).strip())
    (out / 'results.json').write_text(json.dumps(dict(head=head, dirty=dirty, checks=checks), indent=2) + '\n')
