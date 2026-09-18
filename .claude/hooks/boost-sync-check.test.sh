#!/usr/bin/env bash
# expectation-driven test for .claude/hooks/boost-sync-check.sh
hook="${1:?hook path}"
pass=0; fail=0
run() {
  name="$1"; expected="$2"; fake="$3"; skip="${4:-}"
  got=$(BOOST_SKIP_AUTOSYNC="$skip" BOOST_SYNC_CHECK_CMD="$fake" CLAUDE_PROJECT_DIR="$root" bash "$hook" | jq -r '.hookSpecificOutput.additionalContext // "silent"'); got="${got:-silent}"
  case "$got" in
    silent) short=silent ;;
    *WARNING*) short=warn ;;
    *failed*) short=error ;;
    *Regenerated*) short=info ;;
    *) short=other ;;
  esac
  if [ "$short" = "$expected" ]; then pass=$((pass+1)); printf 'ok   %-6s %s\n' "$short" "$name";
  else fail=$((fail+1)); printf 'FAIL want=%s got=%s  %s\n%s\n' "$expected" "$short" "$name" "$got"; fi
}

base=$(mktemp -d)
root="$base/with space"
mkdir -p "$root"
printf 'guidance\n' > "$root/CLAUDE.md"
mkdir -p "$root/vendor"; : > "$root/vendor/autoload.php"
printf '#!/bin/sh\nprintf "  unchanged .claude/skills/bug-fixing/SKILL.md\\n  wrote .claude/skills/interview/SKILL.md\\n  wrote .claude/commands/release-notes.md\\n [OK] Sync done. wrote=2, unchanged=252, deleted=0.\\n"\n' > "$root/drift"
printf '#!/bin/sh\nprintf "  unchanged .claude/skills/bug-fixing/SKILL.md\\n [OK] Sync done. wrote=0, unchanged=254, deleted=0.\\n"\n' > "$root/clean"
printf '#!/bin/sh\nprintf "  deleted .claude/skills/old-skill/SKILL.md\\n [OK] Sync done. wrote=0, unchanged=253, deleted=1.\\n"\n' > "$root/delete"
printf '#!/bin/sh\nprintf "regenerated\\n" > "$CLAUDE_PROJECT_DIR/CLAUDE.md"\nprintf "  wrote CLAUDE.md\\n [OK] Sync done. wrote=1, unchanged=253, deleted=0.\\n"\n' > "$root/guidance"
printf '#!/bin/sh\necho "  [ERROR] Skill source collision: foo"; exit 1\n' > "$root/broken"
printf '#!/bin/sh\necho "PHP Fatal error: bootstrap died" >&2; exit 255\n' > "$root/broken-stderr"
chmod +x "$root"/drift "$root"/clean "$root"/delete "$root"/guidance "$root"/broken "$root"/broken-stderr

run "drift: two generated files rewritten"      info   "$root/drift"
run "clean: nothing written"                    silent "$root/clean"
run "delete-only sync still reports"            info   "$root/delete"
run "sync fails with vendor present: report it"  error  "$root/broken"
run "sync dies before any output: report it"    error  "$root/broken-stderr"
rm -rf "$root/vendor"
run "no vendor yet: stay silent"                silent "$root/broken"
mkdir -p "$root/vendor"; : > "$root/vendor/autoload.php"
run "BOOST_SKIP_AUTOSYNC=1 skips the sync"      silent "$root/drift" 1
run "tracked CLAUDE.md regenerated: warn"       warn   "$root/guidance"

# the failure context carries the reason, from whichever stream the sync used
reason_has() {
  name="$1"; needle="$2"; fake="$3"
  hits=$(BOOST_SYNC_CHECK_CMD="$fake" CLAUDE_PROJECT_DIR="$root" bash "$hook" | jq -r '.hookSpecificOutput.additionalContext' | grep -c "$needle")
  if [ "$hits" = "1" ]; then pass=$((pass+1)); printf 'ok   reason %s\n' "$name";
  else fail=$((fail+1)); printf 'FAIL reason missing (%s)  %s\n' "$needle" "$name"; fi
}

reason_has "stdout error reaches the context" "Skill source collision" "$root/broken"
reason_has "stderr error reaches the context" "bootstrap died"         "$root/broken-stderr"

# reloadSkills asks Claude Code to re-scan the skill and command directories; only a
# sync that wrote something needs it.
reload_is() {
  name="$1"; expected="$2"; fake="$3"
  got=$(BOOST_SYNC_CHECK_CMD="$fake" CLAUDE_PROJECT_DIR="$root" bash "$hook" | jq -r '.hookSpecificOutput.reloadSkills | tostring')
  if [ "$got" = "$expected" ]; then pass=$((pass+1)); printf 'ok   reload=%-6s %s\n' "$got" "$name";
  else fail=$((fail+1)); printf 'FAIL want reload=%s got=%s  %s\n' "$expected" "$got" "$name"; fi
}

reload_is "drift: request a skill re-scan"       true  "$root/drift"
reload_is "delete-only sync: request a re-scan"  true  "$root/delete"
reload_is "sync failed: no re-scan"              false "$root/broken"

# the context names the regenerated files so the agent knows what changed under it
named=$(BOOST_SYNC_CHECK_CMD="$root/drift" CLAUDE_PROJECT_DIR="$root" bash "$hook" | jq -r '.hookSpecificOutput.additionalContext' | grep -c 'interview/SKILL.md')
if [ "$named" = "1" ]; then pass=$((pass+1)); echo "ok   info   context lists the regenerated files"; else fail=$((fail+1)); echo "FAIL context does not list the regenerated files"; fi

rm -rf "$base"
echo "---- pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
