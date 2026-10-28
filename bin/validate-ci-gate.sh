#!/usr/bin/env bash
set -euo pipefail

# Missing, failed, skipped, or cancelled required jobs all fail closed.
for result in PLAN CONTRACTS QUALITY JAVASCRIPT REGRESSION; do
  if [[ "${!result:-}" != success ]]; then
    echo "Required job $result did not succeed: ${!result:-missing}" >&2
    exit 1
  fi
done

case "${FULL:-}" in
  true) [[ "${PLUGIN_CHECK:-}" == success ]] ;;
  false) [[ "${PLUGIN_CHECK:-}" == skipped ]] ;;
  *) echo 'Missing or invalid CI intensity' >&2; exit 1 ;;
esac
