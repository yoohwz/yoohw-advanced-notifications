#!/usr/bin/env python3
"""Real DB/Action Scheduler fault and concurrency checks. Requires a disposable WP install.
Example: python3 tests/reliability.py --wp-command /tmp/an-wp --output /tmp/an-evidence
The WP command must load WooCommerce + this checkout. Outgoing mail is intercepted.
"""
import argparse
import collections
import json
import os
from pathlib import Path
import subprocess
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-command', required=True, help='Path to a WP-CLI executable/wrapper selecting a DISPOSABLE database')
parser.add_argument('--output', required=True)
parser.add_argument('--review-only', action='store_true', help='Run only the additional recovery regressions')
args = parser.parse_args()
out = Path(args.output).resolve()
out.mkdir(parents=True, exist_ok=False)
worker = Path(__file__).with_name('reliability-worker.php').resolve()
checks = {}
evidence = {}


def spawn(mode, **kw):
    env = dict(os.environ, AN_RELIABILITY_FIXTURES=str(out), AN_INPUT=json.dumps(dict(mode=mode, **kw)))
    return subprocess.Popen([args.wp_command, 'eval-file', str(worker)], env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)


def collect(p, allow_killed=False):
    log, _ = p.communicate(timeout=45)
    with (out / 'workers.log').open('a') as f:
        f.write(f'PID={p.pid} EXIT={p.returncode}\n{log}\n')
    if not allow_killed and p.returncode:
        raise AssertionError(log)
    lines = [x[len('AN_RESULT='):] for x in log.splitlines() if x.startswith('AN_RESULT=')]
    return json.loads(lines[-1]) if lines else None


def run(mode, **kw):
    return collect(spawn(mode, **kw))


def wait(name):
    deadline = time.monotonic() + 30
    while not (out / name).exists():
        if time.monotonic() > deadline:
            raise AssertionError('Timed out waiting for ' + name)
        time.sleep(.02)


def signal(name):
    (out / name).touch()


def reset_signals():
    for name in ['go', 'execute', 'a-ready', 'b-ready', 'a-claimed', 'b-claimed']:
        (out / name).unlink(missing_ok=True)


def state(id):
    data = run('inspect', id=id)
    evidence[id] = data
    return data


def statuses(id):
    return collections.Counter(x['status'] for x in state(id)['recipients'])


def mail_count(subject=None):
    path = out / 'transport.jsonl'
    rows = [json.loads(x) for x in path.read_text().splitlines()] if path.exists() else []
    return sum(subject is None or x['subject'] == subject for x in rows)


def check(name, passed):
    checks[name] = bool(passed)
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)
    if not passed:
        raise AssertionError(name)


def campaign(id, **kw):
    run('create', id=id, **kw)
    run('confirm', id=id, **kw)


def dispatch(**kw):
    return run('queue', dispatch=True, **kw)


def drain(**kw):
    return run('queue', **kw)


def review_regressions():
    campaign('failed-optout'); dispatch(); run('missing_payload', id='failed-optout'); drain()
    check('review_fixture_is_pretransport_failure', statuses('failed-optout') == {'failed': 1})
    run('unsubscribe', email='failed-optout-0@example.com'); run('retry', id='failed-optout'); dispatch(); drain()
    check('pretransport_failure_optout_becomes_skipped_without_resend', statuses('failed-optout') == {'skipped_unsubscribed': 1} and mail_count('Reliability failed-optout') == 0)
    check('opted_out_failure_is_no_longer_retryable', not run('render', id='failed-optout')['recovery'])

    campaign('interruption-limit')
    for attempt in range(3):
        (out / 'before-job').unlink(missing_ok=True)
        p = spawn('queue', dispatch=True, fault='dispatch_before_job')
        wait('before-job'); p.kill(); collect(p, True)
        run('reconcile', id='interruption-limit')
    dispatch(fault='queue_fail')
    data = state('interruption-limit')
    check('dispatcher_interruptions_do_not_bypass_three_attempts', int(data['recipients'][0]['attempts']) == 3)
    check('exhausted_dispatch_has_reason_and_no_pending_dispatcher', data['campaign']['status'] == 'needs_attention' and '3' in data['campaign']['recovery_message'] and not any(j['hook'] == 'yoohw_an_dispatch_campaign' and j['status'] == 'pending' for j in data['jobs']))
    run('retry', id='interruption-limit'); dispatch(); drain()
    check('manual_recovery_cannot_bypass_interrupted_dispatch_limit', int(state('interruption-limit')['recipients'][0]['attempts']) == 3 and mail_count('Reliability interruption-limit') == 0)

    # A job saved on the final attempt must still be allowed to finish.
    campaign('last-stored-job')
    for attempt in range(2):
        (out / 'before-job').unlink(missing_ok=True)
        p = spawn('queue', dispatch=True, fault='dispatch_before_job')
        wait('before-job'); p.kill(); collect(p, True)
        run('reconcile', id='last-stored-job')
    (out / 'dispatch-partial').unlink(missing_ok=True)
    p = spawn('queue', dispatch=True, fault='dispatch_crash')
    wait('dispatch-partial'); p.kill(); collect(p, True)
    run('reconcile', id='last-stored-job'); dispatch(); drain(); run('reconcile', id='last-stored-job')
    check('job_saved_before_final_attempt_interruption_finishes_once', statuses('last-stored-job') == {'sent': 1} and int(state('last-stored-job')['recipients'][0]['attempts']) == 3 and mail_count('Reliability last-stored-job') == 1)

    due = run('manifest', id='elapsed-preparation', count=2)
    run('reconcile', id='elapsed-preparation')
    data = state('elapsed-preparation')
    check('interrupted_scheduled_preparation_keeps_confirmation_due_time', len(data['recipients']) == 2 and all(row['scheduled_at_gmt'] == due for row in data['recipients']))
    dispatch(); drain()
    check('overdue_missing_preparation_rows_send_once_without_next_day_rollover', statuses('elapsed-preparation') == {'sent': 2} and mail_count('Reliability elapsed-preparation') == 2)

    run('manifest', id='failed-preparation-deactivation', count=2, failed=True)
    run('deactivate'); run('reconcile', id='failed-preparation-deactivation'); dispatch(); drain()
    data = state('failed-preparation-deactivation')
    check('deactivation_preserves_failed_preparation_cancellation', data['campaign'].get('cancelled') and len(data['recipients']) == 1 and statuses('failed-preparation-deactivation') == {'cancelled': 1} and mail_count('Reliability failed-preparation-deactivation') == 0 and not run('render', id='failed-preparation-deactivation')['recovery'])

    # Queue submission failures have no pending job, but must also stop on deactivation.
    for fault, future in [('queue_fail', False), ('schedule_fail', True)]:
        id = 'deactivate-' + fault
        campaign(id, future=future); dispatch(fault=fault)
        expected = 'schedule_failed' if future else 'queue_failed'
        check(id + '_fixture_is_retryable_failure', statuses(id) == {expected: 1})
        run('deactivate'); run('age', id=id); run('reconcile', id=id); dispatch(); drain()
        run('retry', id=id); dispatch(); drain()
        data = state(id)
        check(id + '_cannot_resume_after_reactivation', data['campaign'].get('cancelled') and statuses(id) == {'cancelled': 1} and mail_count('Reliability ' + id) == 0 and not run('render', id=id)['recovery'])

def base_regressions():
    # Concurrent confirmations persist the same manifest; real queued dispatcher jobs follow.
    run('create', id='race')
    reset_signals()
    a = spawn('confirm', id='race', barrier='a')
    b = spawn('confirm', id='race', barrier='b')
    wait('a-ready'); wait('b-ready'); signal('go')
    collect(a); collect(b)
    dispatch()
    run('duplicate', id='race')
    reset_signals()
    a = spawn('queue', batch=1, race='a')
    wait('a-claimed')
    b = spawn('queue', batch=1, race='b')
    wait('b-claimed'); signal('execute')
    jobs_a, jobs_b = collect(a), collect(b)
    run('reconcile', id='race')
    check('two_workers_claim_distinct_real_jobs_for_same_recipient', len(jobs_a) == len(jobs_b) == 1 and jobs_a != jobs_b)
    check('concurrent_confirmation_and_duplicate_jobs_handoff_once', mail_count('Reliability race') == 1 and statuses('race') == {'sent': 1})
    check('recipient_action_id_is_recorded', int(state('race')['recipients'][0]['action_id']) > 0)
    run('confirm', id='race', count=0); dispatch()
    check('repeated_confirmation_after_completion_keeps_result', statuses('race') == {'sent': 1} and mail_count('Reliability race') == 1)
    # A deliberate new campaign can reach the same address.
    campaign('intentional-new', email='race-0@example.com'); dispatch(); drain()
    check('new_campaign_can_send_same_customer', mail_count('Reliability intentional-new') == 1)

    # Force handoff to finish before dispatcher receives the action ID from AS storage.
    campaign('late')
    p = spawn('queue', dispatch=True, fault='late_dispatch')
    wait('job-stored'); drain(); signal('handoff-complete'); collect(p)
    run('reconcile', id='late')
    check('late_dispatch_cannot_regress_final_status', statuses('late') == {'sent': 1} and state('late')['campaign']['status'] == 'completed')

    # Independent HTTP-handler-equivalent requests, including opt-out after queueing.
    campaign('optout'); dispatch()
    reset_signals()
    a = spawn('unsubscribe', id='optout', email='optout-0@example.com', barrier='a')
    b = spawn('unsubscribe', id='optout', email='concurrent-b@example.com', barrier='b')
    wait('a-ready'); wait('b-ready'); signal('go')
    check('concurrent_unsubscribe_reports_saved_success', collect(a) == collect(b) == 'unsubscribe_response_200')
    drain()
    opts = state('optout')['optouts']
    check('both_optouts_survive_and_queued_recipient_is_skipped', 'optout-0@example.com' in opts and 'concurrent-b@example.com' in opts and statuses('optout') == {'skipped_unsubscribed': 1} and mail_count('Reliability optout') == 0)
    check('unsubscribe_repeated_request_is_successful', run('unsubscribe', email='optout-0@example.com') == 'unsubscribe_response_200')
    check('unsubscribe_write_failure_returns_503', run('unsubscribe', email='not-saved@example.com', fault='storage_fail') == 'unsubscribe_response_503' and 'not-saved@example.com' not in state('optout')['optouts'])
    run('legacy_optouts')
    check('all_three_legacy_optout_formats_preserved', all(x in state('optout')['optouts'] for x in ['old-a@example.com', 'old-b@example.com', 'old-c@example.com']))

    # A killed preparation has inserted one real SQL batch, but no mail job yet.
    run('create', id='preparation', count=260)
    p = spawn('confirm', id='preparation', count=260, fault='preparation_crash')
    wait('preparation-partial'); p.kill(); collect(p, True)
    check('interrupted_preparation_preserves_partial_rows_and_manifest', len(state('preparation')['recipients']) == 250)
    run('reconcile', id='preparation')
    check('reconciliation_completes_original_recipient_snapshot', len(state('preparation')['recipients']) == 260)
    dispatch(); dispatch(); dispatch(); drain()
    run('reconcile', id='preparation')
    check('recovered_preparation_sends_each_original_recipient_once', statuses('preparation') == {'sent': 260} and mail_count('Reliability preparation') == 260)

    campaign('batch-gap', count=105)
    dispatch(fault='next_batch_fail')
    check('next_batch_enqueue_failure_leaves_work_visible', statuses('batch-gap') == {'queued': 100, 'dispatch_pending': 5})
    run('reconcile', id='batch-gap'); dispatch(); drain(); run('reconcile', id='batch-gap')
    check('missing_dispatcher_is_recreated_from_real_queue', statuses('batch-gap') == {'sent': 105} and mail_count('Reliability batch-gap') == 105)

    campaign('dispatch-crash')
    p = spawn('queue', dispatch=True, fault='dispatch_crash')
    wait('dispatch-partial'); p.kill(); collect(p, True)
    run('reconcile', id='dispatch-crash'); dispatch(); drain(); run('reconcile', id='dispatch-crash')
    check('dispatcher_killed_after_job_storage_recovers_without_duplicate_handoff', statuses('dispatch-crash') == {'sent': 1} and mail_count('Reliability dispatch-crash') == 1)

    campaign('lost-job'); dispatch(); run('cancel_delivery', id='lost-job'); run('age', id='lost-job')
    run('reconcile', id='lost-job'); dispatch(); drain()
    check('missing_delivery_job_is_requeued_safely', statuses('lost-job') == {'sent': 1} and mail_count('Reliability lost-job') == 1)
    campaign('overdue'); dispatch(); run('age', id='overdue'); run('reconcile', id='overdue')
    check('pending_overdue_jobs_have_actionable_reason', state('overdue')['campaign']['status'] == 'needs_attention' and 'cron' in state('overdue')['campaign']['recovery_message'])
    drain()

    campaign('schedule-recovery', future=True); dispatch(fault='schedule_fail')
    due = state('schedule-recovery')['recipients'][0]['scheduled_at_gmt']
    run('age_updated_only', id='schedule-recovery'); run('reconcile', id='schedule-recovery'); dispatch()
    check('schedule_failure_recovery_preserves_original_future_time', statuses('schedule-recovery') == {'scheduled': 1} and state('schedule-recovery')['recipients'][0]['scheduled_at_gmt'] == due and mail_count('Reliability schedule-recovery') == 0)
    run('cancel_delivery', id='schedule-recovery'); run('age', id='schedule-recovery'); run('reconcile', id='schedule-recovery'); dispatch(); drain()
    check('overdue_scheduled_recipient_can_recover_and_send_now', statuses('schedule-recovery') == {'sent': 1} and mail_count('Reliability schedule-recovery') == 1)

    campaign('retry-limit')
    for attempt in range(1, 4):
        dispatch(fault='queue_fail')
        data = state('retry-limit')
        check(f'queue_failure_attempt_{attempt}_recorded', data['recipients'][0]['status'] == 'queue_failed' and int(data['recipients'][0]['attempts']) == attempt)
        run('age', id='retry-limit'); run('reconcile', id='retry-limit')
    check('automatic_queue_retry_stops_at_three', statuses('retry-limit') == {'queue_failed': 1})
    run('retry', id='retry-limit'); dispatch(); drain()
    check('manual_recovery_does_not_bypass_retry_limit', mail_count('Reliability retry-limit') == 0 and statuses('retry-limit') == {'queue_failed': 1})

    campaign('retry-optout'); dispatch(fault='queue_fail')
    run('unsubscribe', email='retry-optout-0@example.com')
    run('age', id='retry-optout'); run('reconcile', id='retry-optout'); dispatch(); drain()
    check('queue_recovery_preserves_later_optout', statuses('retry-optout') == {'skipped_unsubscribed': 1} and mail_count('Reliability retry-optout') == 0)

    # Missing payload fails before transport; bounded manual recovery sends only that row.
    campaign('config-failure'); dispatch(); run('missing_payload', id='config-failure'); drain()
    check('missing_payload_is_known_configuration_failure', statuses('config-failure') == {'failed': 1} and mail_count('Reliability config-failure') == 0)
    run('retry', id='config-failure'); dispatch(); drain()
    check('known_configuration_failure_can_recover_without_other_recipients', statuses('config-failure') == {'sent': 1} and mail_count('Reliability config-failure') == 1)

    for case in ['transport_false', 'transport_exception', 'crash_before', 'crash_after']:
        campaign(case); dispatch()
        if case.startswith('crash'):
            p = spawn('queue', fault=case)
            wait('transport-' + ('before' if case == 'crash_before' else 'after'))
            p.kill(); collect(p, True)
            run('age', id=case)
        else:
            drain(fault=case)
        run('reconcile', id=case)
        count = mail_count('Reliability ' + case)
        run('retry', id=case); dispatch(); drain()
        check(case + '_is_unknown_and_never_automatically_resent', statuses(case) == {'unknown': 1} and mail_count('Reliability ' + case) == count)
    ui = run('render', id='crash_after')
    check('ui_distinguishes_handoff_and_unknown_outcome', ui['unknown_label'] and 'needs_attention' == state('crash_after')['campaign']['status'])

    campaign('active-history'); run('cancel_dispatch', id='active-history'); run('legacy_history', id='active-history')
    run('truncate_schema', id='active-history')
    run('history'); run('reconcile', id='active-history'); dispatch(); drain(); run('reconcile', id='active-history')
    check('legacy_active_campaign_survives_over_100_history_and_schema_upgrade', state('active-history')['history_count'] > 100 and statuses('active-history') == {'sent': 1})
    campaign('legacy-sent'); dispatch(); run('legacy_claim', id='legacy-sent', state='sent'); drain()
    check('legacy_successful_claim_prevents_resend', statuses('legacy-sent') == {'sent': 1} and mail_count('Reliability legacy-sent') == 0)
    campaign('legacy-sending'); dispatch(); run('legacy_claim', id='legacy-sending', state='sending'); drain()
    check('legacy_inflight_claim_is_unknown_without_resend', statuses('legacy-sending') == {'unknown': 1} and mail_count('Reliability legacy-sending') == 0)
    run('legacy_inline', id='legacy-inline', email='old-b@example.com'); drain(); run('reconcile', id='legacy-inline')
    check('pending_inline_legacy_job_is_imported_and_respects_optout', state('legacy-inline')['campaign'] is not None and statuses('legacy-inline') == {'skipped_unsubscribed': 1} and mail_count('Legacy legacy-inline') == 0)
    campaign('orphan'); run('orphan', id='orphan'); dispatch(); drain(); run('reconcile', id='orphan')
    check('trimmed_campaign_is_recovered_from_shared_payload_without_expanding_audience', statuses('orphan') == {'sent': 1} and state('orphan')['campaign']['audience_incomplete'])
    campaign('deactivation'); run('deactivate', id='deactivation')
    run('reconcile', id='deactivation'); dispatch(); drain()
    check('deactivation_does_not_recreate_cancelled_work', statuses('deactivation') == {'cancelled': 1} and mail_count('Reliability deactivation') == 0)

try:
    run('reset')
    if not args.review_only:
        base_regressions()
        # The schema-2 fixture drops counters across old rows. Do not let an admin
        # render recover that old test work while a new fault barrier is armed.
        run('reset')
    review_regressions()
finally:
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    dirty = subprocess.check_output(['git', 'status', '--porcelain'], text=True)
    (out / 'results.json').write_text(json.dumps({'commit': commit, 'dirty': dirty, 'checks': checks, 'evidence': evidence}, indent=2))
print(f'{len(checks)} checks passed. Evidence: {out}')
