#!/usr/bin/env bash
#
# Run the tests a change affects, and exit non-zero only when a test fails.
#
# `richter:affected-tests --plain` prints one path per line; its exit code is the
# contract, 0 for determinable (possibly empty) and 2 for not. A bare
# `php artisan test $(...)` loses both halves: an empty selection passes no paths, so
# `test` runs the WHOLE suite. This wrapper exists because that failure is silent.
#
# Any other non-zero exit is fatal here on purpose. Richter degrades a plain-mode crash
# to "run everything"; a pipeline step should stop loudly instead of spending the full
# suite's budget on a selection nobody computed.
#
# Usage:  tools/verify/affected-tests.sh [extra artisan test args...]
# Tests:  bash tools/verify/affected-tests.test.sh tools/verify/affected-tests.sh

# -f: the selection is word-split on purpose, but must not glob-expand.
set -fuo pipefail

selection="$(php artisan richter:affected-tests --plain)"
status=$?

if [ "$status" -eq 2 ]; then
    echo "Affected tests: selection undeterminable — running the full suite."
    exec php artisan test --compact "$@"
fi

if [ "$status" -ne 0 ]; then
    echo "Affected tests: richter exited ${status}; refusing to guess a selection." >&2
    exit "$status"
fi

if [ -z "$selection" ]; then
    echo "Affected tests: nothing affected by this change."
    exit 0
fi

echo "Affected tests: $(printf '%s\n' "$selection" | grep -c .) file(s)."

# shellcheck disable=SC2086 # word splitting is intended: one path per line.
exec php artisan test --compact $selection "$@"
