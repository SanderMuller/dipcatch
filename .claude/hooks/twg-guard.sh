#!/usr/bin/env bash
# Block every destructive `twg` command, however it is spelled.
#
# `permissions.deny` matches a command prefix, so `Bash(twg jira workitem delete:*)`
# covers the named subcommands and nothing else. It cannot see a method flag in the
# middle of a command, so `twg api jira:/rest/api/3/issue/X -X DELETE` walks straight
# past the deny list and deletes the issue anyway (found in review on PR #11037).
#
# This hook closes that hole and the absolute-path variant (`~/.local/bin/twg …`),
# which a prefix rule also misses. It denies only what no workflow needs: deletes,
# archives, unlinks, and any non-GET method on `twg api`. The write path the Jira
# skills use — create, update, transition, comment create, attachment upload, and
# `twg api … -X POST` for an inline-image comment — stays allowed.
#
# It also enforces the read-only contract of the researcher subagents. `tools:` and
# `disallowedTools:` in a subagent's frontmatter take bare tool names only — a
# specifier such as `Bash(twg … get:*)` removes the whole tool rather than scoping
# it — so a hook is the documented way to scope Bash. PreToolUse fires for subagent
# calls and carries `agent_type`, verified live: a call from `jira-researcher`
# arrives as `{"agent_id":"…","agent_type":"jira-researcher"}`, and a main-session
# call has both fields null.
set +e
payload=$(cat)
cmd=$(echo "$payload" | jq -r '.tool_input.command // empty' 2>/dev/null)
agent=$(echo "$payload" | jq -r '.agent_type // empty' 2>/dev/null)

[[ -z "$cmd" ]] && exit 0

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

destructive_reason='Destructive `twg` commands are blocked (.ai/docs/atlassian-twg.md). A deleted Jira issue, comment, or attachment cannot be restored through the API. Report the state to the user and let them run it, rather than cleaning up after a mistake.'
api_reason='A non-GET method on `twg api` is blocked — it reaches the same destructive Jira REST endpoints the deny list covers by name (.ai/docs/atlassian-twg.md). `twg api … -X POST` for an inline-image comment is the one write this hook allows; anything else goes through a named `twg jira …` subcommand so the guard can see it.'

# The guard reads the command text, so a shell command that merely *contains* a
# destructive invocation — writing this file, or a heredoc quoting one — is denied
# too. That is the right trade: use the Write/Edit tool for those, exactly as the
# `.env` guard requires.
#
# `twg` bare, via an absolute or ~ path, or with a leading env assignment.
twg='(^|[;&|(]|&&|\|\|)[[:space:]]*([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*[[:space:]]+)*([^[:space:];&|]*/)?twg[[:space:]]'
# What may sit between the invocation and the verb: flags, values and subcommand
# words, never a quote, backtick, separator or ellipsis. Without this, prose that
# merely names a command — `Bash(twg … get:*)` — reaches a `delete` written far
# later in the same string and denies a reply that is only talking about the guard.
seg='[[:space:]]*([-A-Za-z0-9_.:/=,]+[[:space:]]+)*'

# Subcommands that destroy data. `delete` and `archive` cover the workitem, comment,
# attachment, space and worklog forms in one pattern each.
if echo "$cmd" | grep -Eq "${twg}${seg}(delete|archive|unarchive|unlink)([[:space:]]|$)"; then
  deny "$destructive_reason"
fi

# `twg api` with any method other than GET. Both spellings of the flag.
if echo "$cmd" | grep -Eqi "${twg}api${seg}(-X|--method)[[:space:]]*=?[[:space:]]*(DELETE|PUT|PATCH)([[:space:]]|$)"; then
  deny "$api_reason"
fi

# Agents whose contract is read-only. They hold `Bash` because their data source is a
# CLI, so the contract needs an enforcer rather than a paragraph. Add an agent here
# when it gets `Bash` and must not write.
readonly_agents='^(jira-researcher|product-owner-reviewer)$'
readonly_reason='This agent is read-only (.ai/subagents/'"'"'s contract, enforced by .claude/hooks/twg-guard.sh). Only `twg` read subcommands are available: get, query, search, bulk-get, transitions query, link query, link-types query, attachment download. Report what the lead should run instead of running it.'

if echo "$agent" | grep -Eq "$readonly_agents"; then
  # Every twg verb that is not a read.
  if echo "$cmd" | grep -Eq "${twg}${seg}(create|create-bulk|clone|update|transition|bulk-transition|link|unlink|upload|vote|watcher|property|worklog|import|restore|delete|archive|unarchive)([[:space:]]|$)"; then
    deny "$readonly_reason"
  fi
  # `twg api` is a write unless it is a plain GET.
  if echo "$cmd" | grep -Eqi "${twg}api${seg}(-X|--method)[[:space:]]*=?[[:space:]]*(POST|PUT|PATCH|DELETE)([[:space:]]|$)"; then
    deny "$readonly_reason"
  fi
fi

exit 0
