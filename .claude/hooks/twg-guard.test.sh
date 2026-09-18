#!/usr/bin/env bash
# Test the twg destructive-command guard. Usage: bash twg-guard.test.sh [path-to-guard]
set -uo pipefail

guard="${1:-$(dirname "$0")/twg-guard.sh}"
pass=0
fail=0

run() {
  jq -n --arg c "$1" '{tool_input: {command: $c}}' | bash "$guard" 2>/dev/null
}

expect_deny() {
  if run "$1" | grep -q '"permissionDecision": *"deny"'; then
    pass=$((pass + 1))
  else
    fail=$((fail + 1))
    echo "FAIL (expected deny): $1"
  fi
}

expect_allow() {
  local out
  out=$(run "$1")
  if [[ -z "$out" ]]; then
    pass=$((pass + 1))
  else
    fail=$((fail + 1))
    echo "FAIL (expected allow): $1"
  fi
}

# Destructive subcommands, however they are reached.
expect_deny 'twg jira workitem delete HPB-1234'
expect_deny 'twg jira workitem comment delete --id 1 --issue-id HPB-1234'
expect_deny 'twg jira workitem attachment delete --attachment-id 27510'
expect_deny 'twg jira space delete --key HPB'
expect_deny 'twg jira workitem archive --id HPB-1234'
expect_deny 'twg jira workitem unlink --id HPB-1234'
# Path and prefix forms a `permissions.deny` prefix rule misses.
expect_deny '~/.local/bin/twg jira workitem delete HPB-1234'
expect_deny '/Users/someone/.local/bin/twg jira workitem delete HPB-1234'
expect_deny 'cd /tmp && twg jira workitem delete HPB-1234'
expect_deny 'TWG_AGENT_DEFAULTS=1 twg jira workitem delete HPB-1234'
# The documented `twg api` bypass, both flag spellings.
expect_deny 'twg api jira:/rest/api/3/issue/HPB-1234 -X DELETE'
expect_deny 'twg api jira:/rest/api/3/issue/HPB-1234 --method DELETE'
expect_deny 'twg api jira:/rest/api/3/issue/HPB-1234 -X PUT --input /tmp/x.json'

# Every read stays allowed.
expect_allow 'twg jira workitem get HPB-1234 --fields summary,status'
expect_allow 'twg jira workitem query --jql "project = HPB" --fields summary'
expect_allow 'twg jira workitem comment query --issue-id HPB-1234 --first 100'
expect_allow 'twg jira workitem attachment download --attachment-id 1 --output-path x.png'
expect_allow 'twg doctor'
# Every write the Jira skills need stays allowed.
expect_allow 'twg jira workitem create --space HPB --type Task --summary x'
expect_allow 'twg jira workitem update --id HPB-1234 --add-labels risk-low'
expect_allow 'twg jira workitem transition --id HPB-1234 --transition-id "In Review"'
expect_allow 'twg jira workitem comment create --issue-id HPB-1234 --body x --body-format markdown'
expect_allow 'twg jira workitem attachment upload --issue-id HPB-1234 --file x.png'
expect_allow 'twg api jira:/rest/api/2/issue/HPB-1234/comment -X POST --input /tmp/c.json'
expect_allow 'twg api jira:/rest/api/3/project/HPB/versions'
# A non-twg command that merely contains the words is not ours to block.
expect_allow 'git log --oneline -- twg'
expect_allow 'rm -rf /tmp/twg-scratch'

# Prose that merely names a command is not a command. A reply explaining this guard
# quotes `Bash(twg get:*)` and the word that follows, and an earlier version of the
# pattern denied that reply — which is how these cases got here.
expect_allow 'gh api graphql -f body="Scoping needs a hook: Bash(twg get:*) removes the whole tool. The guard blocks every write."'
expect_allow 'echo "twg jira workitem get HPB-1 --fields summary" && echo "never remove one"'
expect_allow 'grep -rn "archive" .claude/hooks/twg-guard.sh'
# ...but a real invocation hidden after a separator still is one.
expect_deny 'echo hi; twg jira workitem archive --id HPB-1234'
expect_deny 'echo "safe" && twg jira workitem unlink --id HPB-1'

# Read-only subagents: the same write is allowed in the main session and denied for them.
run_as() {
  jq -n --arg c "$2" --arg a "$1" '{agent_id: "x", agent_type: $a, tool_input: {command: $c}}' | bash "$guard" 2>/dev/null
}

expect_agent_deny() {
  if run_as "$1" "$2" | grep -q '"permissionDecision": *"deny"'; then
    pass=$((pass + 1))
  else
    fail=$((fail + 1))
    echo "FAIL (expected deny for $1): $2"
  fi
}

expect_agent_allow() {
  local out
  out=$(run_as "$1" "$2")
  if [[ -z "$out" ]]; then
    pass=$((pass + 1))
  else
    fail=$((fail + 1))
    echo "FAIL (expected allow for $1): $2"
  fi
}

for agent in jira-researcher product-owner-reviewer; do
  expect_agent_deny "$agent" 'twg jira workitem create --space HPB --type Task --summary x'
  expect_agent_deny "$agent" 'twg jira workitem update --id HPB-1234 --add-labels risk-low'
  expect_agent_deny "$agent" 'twg jira workitem transition --id HPB-1234 --transition-id "In Review"'
  expect_agent_deny "$agent" 'twg jira workitem comment create --issue-id HPB-1234 --body x'
  expect_agent_deny "$agent" 'twg jira workitem attachment upload --issue-id HPB-1234 --file x.png'
  expect_agent_deny "$agent" 'twg jira workitem link workitem --id HPB-1 --target-id HPB-2'
  expect_agent_deny "$agent" 'twg api jira:/rest/api/2/issue/HPB-1234/comment -X POST --input /tmp/c.json'
  expect_agent_deny "$agent" 'twg jira workitem delete HPB-1234'
  # Reads stay available, or the agent cannot do its job.
  expect_agent_allow "$agent" 'twg jira workitem get HPB-1234 --fields summary,status'
  expect_agent_allow "$agent" 'twg jira workitem query --jql "project = HPB" --fields summary'
  expect_agent_allow "$agent" 'twg jira workitem comment query --issue-id HPB-1234 --first 100'
  expect_agent_allow "$agent" 'twg api jira:/rest/api/3/project/HPB/versions'
  expect_agent_allow "$agent" 'git diff --stat origin/develop...HEAD'
done

# A writing agent is not in the read-only list, so the same command passes.
expect_agent_allow 'backend-specialist' 'twg jira workitem create --space HPB --type Task --summary x'

echo "passed: $pass, failed: $fail"
[[ $fail -eq 0 ]]
