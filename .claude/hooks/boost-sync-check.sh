#!/usr/bin/env bash
# Regenerate the agent files boost-core builds from .ai/ (.claude/skills,
# .claude/commands, CLAUDE.md, AGENTS.md) at session start, so a `git pull` that
# changed .ai/ without a `composer install` cannot leave the agent on stale
# instructions. The only other regeneration is the composer post-install hook.
#
# Writes are atomic (boost-core FileWriter: tempfile + rename), so a session that
# enumerates skills while this runs never reads a half-written file. A sync that
# wrote something also asks Claude Code to re-scan the skill and command
# directories (`reloadSkills`), because discovery already ran before this hook.
# Regenerated gitignored output is reported as context; a regenerated CLAUDE.md is a warning,
# because that file is tracked and someone merged a .ai/guidelines/ change without
# committing the regenerated copy.
set +e
root="${CLAUDE_PROJECT_DIR:-$PWD}"
[ -n "${BOOST_SKIP_AUTOSYNC:-}" ] && exit 0

# BOOST_SYNC_CHECK_CMD is a test seam: an executable that stands in for the sync.
run_sync() {
  if [ -n "${BOOST_SYNC_CHECK_CMD:-}" ]; then
    "$BOOST_SYNC_CHECK_CMD"
  else
    php "$root/artisan" project-boost:sync --no-ansi --no-interaction
  fi
}

# emit <context> [reload]
emit() {
  jq -n --arg context "$1" --argjson reload "${2:-false}" '{
    hookSpecificOutput: {
      hookEventName: "SessionStart",
      additionalContext: $context,
      reloadSkills: $reload
    }
  }'
  exit 0
}

# Without vendor the sync cannot boot; the vendor-sync-check hook reports that
# case on the first artisan command, so stay silent here.
[ -f "$root/vendor/autoload.php" ] || exit 0

before=$(git hash-object "$root/CLAUDE.md" 2>/dev/null)
errors=$(mktemp)
output=$(run_sync 2>"$errors")
status=$?
if [ "$status" -ne 0 ]; then
  # Artisan renders command errors on stdout and a bootstrap fatal there too, so
  # the reason is taken from stderr only when stdout carries nothing.
  reason=$(printf '%s\n' "$output" | grep -v '^[[:space:]]*$' | tail -1)
  [ -n "$reason" ] || reason=$(grep -v '^[[:space:]]*$' "$errors" | tail -1)
  rm -f "$errors"
  emit "The agent-file sync failed at session start (exit ${status}${reason:+: $reason}), so the generated skills, commands, CLAUDE.md and AGENTS.md may be stale. Run \`php artisan project-boost:sync\` and fix what it reports before relying on any skill or guideline."
fi
rm -f "$errors"
after=$(git hash-object "$root/CLAUDE.md" 2>/dev/null)

written=$(echo "$output" | grep -E '^[[:space:]]*(wrote|deleted) ' | sed -E 's/^[[:space:]]*//' | paste -sd' ' -)
[ -n "$written" ] || [ "$before" != "$after" ] || exit 0

context="Regenerated stale agent files from .ai/ at session start (${written:-CLAUDE.md}). The skill and command directories are re-scanned after this hook, so the regenerated skills and commands are the ones this session uses."
if [ "$before" != "$after" ]; then
  context="$context WARNING: CLAUDE.md changed too. It is tracked, so a merged change to .ai/guidelines/ was committed without its regenerated CLAUDE.md. The regenerated file is now an uncommitted change in this tree; commit it, or expect the qa-boost-sync check to fail on the next PR."
fi

emit "$context" true
