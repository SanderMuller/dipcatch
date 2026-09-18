#!/usr/bin/env bash
# expectation-driven test for tools/verify/affected-tests.sh
#
# Stubs `php` on PATH so the branches run without richter or the suite.
# Usage: bash tools/verify/affected-tests.test.sh tools/verify/affected-tests.sh

script="${1:?script path}"
script="$(cd "$(dirname "$script")" && pwd)/$(basename "$script")"

pass=0; fail=0
stub_dir="$(mktemp -d)"
trap 'rm -rf "$stub_dir"' EXIT

cat > "$stub_dir/php" <<'STUB'
#!/usr/bin/env bash
# $1 is always `artisan`; $2 is the command.
case "$2" in
    richter:affected-tests)
        shift 2
        printf '%s' "$*" > "$STUB_LOG.richter"
        [ -n "$STUB_SELECTION" ] && printf '%s\n' "$STUB_SELECTION"
        exit "$STUB_STATUS"
        ;;
    test)
        shift 2
        printf 'TEST_ARGS:%s' "$*" > "$STUB_LOG"
        exit "${STUB_TEST_EXIT:-0}"
        ;;
esac
exit 99
STUB
chmod +x "$stub_dir/php"

# run <description> <richter-exit> <richter-output> <want-exit> <want-suite-invocation>
# Optional environment: STUB_TEST_EXIT (suite exit code), EXTRA_ARGS (wrapper arguments).
run() {
    local description="$1" status="$2" selection="$3" want_exit="$4" want_args="$5"
    local got_exit got_args richter_args
    : > "$stub_dir/log"; : > "$stub_dir/log.richter"

    # shellcheck disable=SC2086 # EXTRA_ARGS is a deliberate argument list.
    PATH="$stub_dir:$PATH" STUB_STATUS="$status" STUB_SELECTION="$selection" \
        STUB_LOG="$stub_dir/log" STUB_TEST_EXIT="${STUB_TEST_EXIT:-0}" \
        bash "$script" ${EXTRA_ARGS:-} > /dev/null 2>&1
    got_exit=$?
    got_args="$(cat "$stub_dir/log")"
    [ -n "$got_args" ] || got_args='NONE'
    richter_args="$(cat "$stub_dir/log.richter")"

    if [ "$got_exit" = "$want_exit" ] && [ "$got_args" = "$want_args" ] && [[ "$richter_args" == *--plain* ]]; then
        pass=$((pass + 1)); printf 'ok   %s\n' "$description"
    else
        fail=$((fail + 1))
        printf 'FAIL %s\n     want exit=%s args=%s\n     got  exit=%s args=%s richter=%s\n' \
            "$description" "$want_exit" "$want_args" "$got_exit" "$got_args" "$richter_args"
    fi
}

selection='tests/Unit/ATest.php
tests/Unit/BTest.php'

run 'empty selection runs no tests'                0 ''          0 'NONE'
run 'undeterminable selection runs the suite'      2 ''          0 'TEST_ARGS:--compact'
run 'a selection runs only those files'            0 "$selection" 0 'TEST_ARGS:--compact tests/Unit/ATest.php tests/Unit/BTest.php'
run 'an unexpected richter exit is not guessed at' 1 ''          1 'NONE'

# A wrapper that cannot report a failing suite is a false-green gate, so both paths
# that reach the suite must hand its exit code back.
STUB_TEST_EXIT=3
run 'a failing selection run propagates its exit code' 0 "$selection" 3 'TEST_ARGS:--compact tests/Unit/ATest.php tests/Unit/BTest.php'
run 'a failing full-suite run propagates its exit code' 2 ''         3 'TEST_ARGS:--compact'
STUB_TEST_EXIT=0

EXTRA_ARGS='--stop-on-failure'
run 'extra arguments reach the suite' 0 "$selection" 0 'TEST_ARGS:--compact tests/Unit/ATest.php tests/Unit/BTest.php --stop-on-failure'
EXTRA_ARGS=''

printf '\n%d passed, %d failed\n' "$pass" "$fail"

# Without the pass count, a file whose cases were all deleted would still exit 0.
[ "$pass" -gt 0 ] && [ "$fail" -eq 0 ]
