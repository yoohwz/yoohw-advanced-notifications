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


def collect(p, allow_killed=False):
    log, _ = p.communicate(timeout=35)
    with (out / 'workers.log').open('a') as f:
        f.write(f'PID={p.pid} EXIT={p.returncode}\n{log}\n')
    if p.returncode and not allow_killed:
        raise AssertionError(log)
    lines = [line.removeprefix('AN_PRIVACY_RESULT=') for line in log.splitlines() if line.startswith('AN_PRIVACY_RESULT=')]
    if not lines:
        if allow_killed:
            return None
        raise AssertionError(log)
    return json.loads(lines[-1])


def run(mode, id, **kwargs):
    return collect(spawn(mode, id, **kwargs))


def barrier(name='ready'):
    deadline = time.monotonic() + 20
    while not (out / name).exists():
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
    check('posthandoff_privacy_read_failure_preserves_sent_outcome', run('posthandoff_privacy_fault', tag + '-diagnostic-fault'))
    references = run('subscription_reference', tag + '-source')
    for name, passed in references.items():
        check(name + '_cannot_restore_erased_third_party', passed)
    for case in ['normal', 'crash', 'marker-failure', 'erased', 'generic-failed', 'tampered', 'snapshot-failure', 'association-failure', 'control-read', 'record-read']:
        reset()
        for name in ['checkpoint-written', 'release-checkpoint', 'fault-ready', 'fault-go']:
            (out / name).unlink(missing_ok=True)
        id = tag + '-legacy-' + case
        lock = spawn('lock', id, locks=['__privacy__'])
        barrier()
        seed = run('legacy_seed', id)
        aid = seed['action_id']
        if case == 'generic-failed':
            run('legacy_fail', id, action_id=aid)
        else:
            process = spawn('legacy_run', id, action_id=aid, hold_checkpoint=case == 'crash', fail_checkpoint=case == 'marker-failure')
            if case == 'crash':
                barrier('checkpoint-written')
            else:
                check('legacy_' + case + '_busy_action_is_failed', collect(process) == 'failed')
            busy = run('legacy_inspect', id, action_id=aid)
            check('legacy_' + case + '_no_early_recipient_or_handoff', not busy['row'])
            if case != 'marker-failure':
                check('legacy_' + case + '_hash_checkpoint_without_plaintext', busy['marker'] and set(busy['marker']) == {'action_id', 'args_hash', 'email_hash'} and '@' not in json.dumps(busy['marker']))
        if case == 'erased':
            run('erase', id)  # Tombstone commits while the identity writer/importer remains blocked.
        elif case == 'tampered':
            run('legacy_tamper', id, action_id=aid)
        if case in ['snapshot-failure', 'association-failure', 'control-read', 'record-read']:
            failed_import = spawn('legacy_import_fault', id, action_id=aid, case=case)
            barrier('fault-ready')
        release()
        collect(lock)
        if case in ['snapshot-failure', 'association-failure', 'control-read', 'record-read']:
            (out / 'fault-go').touch()
            check('legacy_' + case + '_retains_checkpoint_until_persisted', collect(failed_import))
        if case == 'crash':
            waiting = run('legacy_inspect', id, action_id=aid)
            check('legacy_crash_inprogress_is_not_imported_or_replayed', waiting['action_status'] == 'in-progress' and waiting['marker'] and not waiting['row'])
            process.kill()
            collect(process, allow_killed=True)
            run('legacy_fail', id, action_id=aid)  # Same terminal transition as the existing AS timeout cleaner.
        if case == 'erased':
            run('erase', id)
        run('legacy_import', id)
        state = run('legacy_inspect', id, action_id=aid)
        if case in ['marker-failure', 'generic-failed', 'erased', 'tampered']:
            check('legacy_' + case + '_does_not_reconstruct_or_replay', not state['row'])
            continue
        check('legacy_' + case + '_imports_checkpoint_once_after_unlock', state['row'] and state['history'] and not state['marker'] and state['row']['scheduled_at_gmt'] == seed['due'] and int(state['row']['attempts']) == 0)
        run('legacy_import', id)
        duplicate = run('legacy_inspect', id, action_id=aid)
        check('legacy_' + case + '_duplicate_import_preserves_due_attempts', duplicate['row'] == state['row'])
        run('legacy_recover', id, action_id=aid)
        run('legacy_recover', id, action_id=aid)
        after = run('legacy_inspect', id, action_id=aid)
        check('legacy_' + case + '_actual_as_recovery_handoffs_once', after['row']['status'] == 'sent' and int(after['row']['attempts']) == 1 and after['row']['scheduled_at_gmt'] == seed['due'])
    for case in ['failed-marker', 'late-publish', 'gate-write', 'gate-readback', 'gate-marker-read']:
        reset()
        for name in ['before-checkpoint', 'publish-checkpoint']:
            (out / name).unlink(missing_ok=True)
        id = tag + '-deactivate-' + case
        lock = spawn('lock', id, locks=['__privacy__'])
        barrier()
        seed = run('legacy_seed', id)
        aid = seed['action_id']
        process = spawn('legacy_run', id, action_id=aid, hold_before_checkpoint=case == 'late-publish')
        if case == 'late-publish':
            barrier('before-checkpoint')
        else:
            check('deactivate_' + case + '_pretransport_action_failed', collect(process) == 'failed')
        fault = {'gate-write': 'write', 'gate-readback': 'readback', 'gate-marker-read': 'marker-read'}.get(case, '')
        result = run('legacy_deactivate', id, action_id=aid, fault=fault)
        if fault:
            check('deactivate_' + case + '_failure_truthful_before_unschedule_retire', result['failed'] and result['marker'] and result['pending_preserved'])
            result = run('legacy_deactivate', id, action_id=aid)
        check('deactivate_' + case + '_durable_gate_without_privacy_lock', not result['failed'] and result['cancelled'])
        if case == 'late-publish':
            (out / 'publish-checkpoint').touch()
            check('late_marker_writer_finishes_without_handoff', collect(process) == 'failed')
        release()
        collect(lock)
        run('legacy_import', id)
        run('legacy_recover', id, action_id=aid)
        run('legacy_recover', id, action_id=aid)
        state = run('legacy_inspect', id, action_id=aid)
        check('deactivate_' + case + '_reactivation_cannot_reconstruct_or_send', state['cancelled'] and not state['row'] and not state['history'] and not state['marker'])
    for case in ['normal', 'write', 'eligibility-read']:
        reset()
        id = tag + '-manifest-deactivate-' + case
        run('manifest_seed', id)
        lock = spawn('lock', id, locks=['__privacy__'])
        barrier()
        result = run('legacy_deactivate', id, action_id=0, fault='' if case == 'normal' else case)
        if case != 'normal':
            check('manifest_deactivation_' + case + '_failure_truthful_before_unschedule', result['failed'] and result['pending_preserved'])
            result = run('legacy_deactivate', id, action_id=0)
        check('manifest_only_' + case + '_cancellation_does_not_need_privacy_lock', not result['failed'] and result['cancelled'])
        check('manifest_only_' + case + '_preserves_preview_test_retained_and_final_outcomes', result['unconfirmed_and_retained_untouched'] and result['final_outcomes_preserved'])
        release()
        collect(lock)
        run('legacy_recover', id, action_id=0)
        run('legacy_recover', id, action_id=0)
        state = run('manifest_inspect', id)
        check('manifest_only_' + case + '_reactivation_cannot_prepare_or_send', state['cancelled'] and state['status'] == 'cancelled' and state['rows'] == 0)
    id = tag + '-stale-resume'
    for name in ['resume-before-admit', 'resume-admit']:
        (out / name).unlink(missing_ok=True)
    run('stale_resume_seed', id)
    process = spawn('stale_resume', id)
    barrier('resume-before-admit')
    run('legacy_deactivate', id, action_id=0)
    (out / 'resume-admit').touch()
    resumed = collect(process)
    check('stale_resume_cannot_overwrite_deactivation_gate', resumed['error'] == 'cancelled' and resumed['cancelled'])
    id = tag + '-control-read-fault'
    run('stale_resume_seed', id)
    check('fresh_control_read_fault_cannot_overwrite_control', run('control_read_fault', id))
    check('legacy_recovery_no_duplicate_transport', len((out / 'transport.jsonl').read_text().splitlines()) == 10)

finally:
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=worker.parent.parent, text=True).strip()
    dirty = bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=worker.parent.parent, text=True).strip())
    (out / 'results.json').write_text(json.dumps(dict(head=head, dirty=dirty, checks=checks), indent=2) + '\n')
