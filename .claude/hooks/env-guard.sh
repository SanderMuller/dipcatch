#!/usr/bin/env bash
# Block every Bash read of a secret-bearing environment file (see .ai/guidelines/secrets.md).
#
# `permissions.deny` covers the Read/Edit/Grep tools and the Bash commands Claude
# Code recognises as file reads (`cat`, `head`, `tail`, `sed`). It does not cover
# the rest of the shell, so this hook covers the rest.
#
# The rule is default-deny, not a list of banned readers: once a command names a
# secret path, it is denied unless every part of it is provably metadata or a
# write. A reader allowlist is unwinnable — `base64`, `xxd`, and the next tool
# nobody listed all read the file just as well (found by codex-review).
set +e
cmd=$(jq -r '.tool_input.command // empty' 2>/dev/null)

deny() {
  jq -n --arg reason "$1" '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: $reason
    }
  }'
  exit 0
}

read_reason='Reading the environment file is banned — no agent reads it, not on user request, not to satisfy a goal (.ai/guidelines/secrets.md). Read the derived config key instead, one leaf at a time: `php artisan config:show app.host`, `php artisan config:show database.connections.mysql.database`. The placeholder files (`.env.example`, `.env.ci`) stay readable. To search or edit a file that only mentions the name, escape the dot (`grep -rn "\\.env" .ai/`) or use the Write/Edit tool — a shell heredoc that contains the bare path is blocked too.'
env_reason='Printing an `env()` value is banned — it returns the same secret the file holds (.ai/guidelines/secrets.md). Use `php artisan config:show <section>.<leaf>` for one non-secret leaf key instead.'

# `env('KEY')` through tinker or a php one-liner returns the raw value. PHP function
# names are case-insensitive and allow whitespace before `(`, so match both.
# `config()` leaks the same value whenever the leaf is a credential.
env_read='(^|[^[:alnum:]_>$-])(env|getenv)[[:space:]]*\(|\$_ENV|_SERVER[[:space:]]*\['
config_secret='config[[:space:]]*\([^)]*(key|secret|password|token|credential|dsn|passphrase)'
if echo "$cmd" | grep -Eqi '(tinker|php[[:space:]]+-r)' && echo "$cmd" | grep -Eqi "$env_read|$config_secret"; then
  deny "$env_reason"
fi

# A secret path as a token: bare, or with any suffix that is not a committed
# placeholder. An escaped regex (`grep -rn '\.env' .ai/`) is not a path.
prefix='(^|[^\\[:alnum:]_.-])'
bare="${prefix}"'\.env([^[:alnum:]_.-]|$)'
suffixed="${prefix}"'\.env\.(example|ci|sample|template|dist|dusk\.local\.example)([^[:alnum:]_.-]|$)'
any_suffixed="${prefix}"'\.env\.[[:alnum:]_.-]+'
# a glob that can expand to the path (`cat .en?`, `cat .e*`) hides the name
globbed="${prefix}"'\.e[[:alnum:]_.-]*[?*[]'

names_secret=false
echo "$cmd" | grep -Eqi "$bare" && names_secret=true
if echo "$cmd" | grep -Eqi "$any_suffixed" && ! echo "$cmd" | grep -Eqi "$suffixed"; then
  names_secret=true
fi
echo "$cmd" | grep -Eqi "$globbed" && names_secret=true

[ "$names_secret" = true ] || exit 0

# From here the command names a secret path. Any command substitution can pipe the
# contents into a command that looks harmless, so no exemption survives one.
echo "$cmd" | grep -Eq '\$\(|`|<\(' && deny "$read_reason"

# Every segment that names the path must be metadata or a write. One reader
# anywhere in the chain denies the whole command.
metadata='^[[:space:]]*(ls|stat|test|\[|touch|rm|mkdir|chmod|echo|printf)[[:space:]]'
while IFS= read -r segment; do
  echo "$segment" | grep -Eqi "$bare|$any_suffixed|$globbed" || continue
  echo "$segment" | grep -Eq "$metadata" || deny "$read_reason"
  # `echo x >> .env` writes; `echo x < .env` reads
  echo "$segment" | grep -Eqi '<[[:space:]]*\.e' && deny "$read_reason"
done < <(echo "$cmd" | tr ';|&\n' '\n')

exit 0
