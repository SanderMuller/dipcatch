#!/usr/bin/env bash
# Warn (non-blocking) when composer.lock is newer than the installed vendor
# snapshot before a QA/boot command. Catches branch-switch vendor desync that
# otherwise fails the run with a "class not found" error at app bootstrap.
#
# Sentinel is vendor/composer/installed.json (rewritten on every composer
# install/update) — NOT vendor/autoload.php, which is a stable stub composer
# does not touch on install and would false-positive every time.
set +e
root="${CLAUDE_PROJECT_DIR:-$PWD}"
cmd=$(jq -r '.tool_input.command // empty' 2>/dev/null)

# only QA/boot commands; never the install/update that fixes the desync
echo "$cmd" | grep -Eq 'artisan|phpstan|rector|composer (qa|test)' || exit 0
echo "$cmd" | grep -Eq 'composer (install|update)' && exit 0

lock="$root/composer.lock"
sentinel="$root/vendor/composer/installed.json"
if [ -f "$lock" ] && [ "$lock" -nt "$sentinel" ]; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "allow",
      additionalContext: "composer.lock is newer than the installed vendor snapshot (vendor/composer/installed.json) — dependencies are likely out of sync. Run `composer install` before this QA/boot command, or it may fail at bootstrap with a missing-class error."
    }
  }'
fi
exit 0
