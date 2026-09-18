#!/usr/bin/env bash
# expectation-driven test for .claude/hooks/env-guard.sh
hook="${1:?hook path}"
pass=0; fail=0
run() {
  expected="$1"; shift; cmd="$1"
  got=$(echo "{\"tool_input\":{\"command\":$(jq -Rn --arg c "$cmd" '$c')}}" | bash "$hook" | jq -r '.hookSpecificOutput.permissionDecision // "allow"'); got="${got:-allow}"
  if [ "$got" = "$expected" ]; then pass=$((pass+1)); printf 'ok   %-6s %s\n' "$got" "$cmd";
  else fail=$((fail+1)); printf 'FAIL want=%s got=%s  %s\n' "$expected" "$got" "$cmd"; fi
}

# must be denied
run deny "cat .env"
run deny "grep '^APP_HOST=' .env | cut -d= -f2"
run deny "head -5 .env.production"
run deny "source .env && echo \$DB_PASSWORD"
run deny "cp .env /tmp/x"
run deny "awk -F= '/^APP_KEY/{print}' .env"
run deny "cat ./.env"
run deny "cat \"\$PWD/.env\""
run deny "read x < .env"
run deny "while read l; do echo \$l; done < .env"
run deny "python3 -c \"print(open('.env').read())\""
run deny "node -e \"console.log(require('fs').readFileSync('.env','utf8'))\""
run deny "php -r 'echo file_get_contents(\".env\");'"
run deny "php artisan tinker --execute 'echo env(\"APP_KEY\");'"
run deny "find . -name .env -exec cat {} \\;"
run deny "xargs -a .env echo"
run deny "sed -n '1,5p' .env.local"
run deny "cat .env.backup"
run deny "tail -n 3 .env; echo done"
run deny "php artisan tinker --execute 'echo file_get_contents(\".env\");'"
run deny "node --eval \"console.log(require('fs').readFileSync('.env','utf8'))\""
run deny "bun run -e \"Bun.file('.env').text()\""
run deny "cat /Users/someone/other-checkout/.env"
# codex-review findings: compound + substitution bypasses, unlisted suffixes, globs
run deny "echo \"\$(base64 .env)\""
run deny "ls .env; base64 .env"
run deny "printf '%s' \"\$(cat .env)\""
run deny "cat .env.development"
run deny "cat .env.sandbox"
run deny "echo start && od -c .env"
run deny "cat .en?"
run deny "cat .e*"
# copilot-review findings: metadata-looking readers and interpreter substitution
run deny "wc -l .env"
run deny "file .env"
run deny "echo \"\$(node --eval \\\"process.stdout.write(require('fs').readFileSync('.env','utf8'))\\\")\""
run deny "bash -c 'cat .env'"
run deny "sh -c \"head -1 .env\""
run allow "echo -e 'APP_HOST=x.test' >> .env"
# copilot-review: PHP function names are case-insensitive and allow space before (
run deny "php -r 'echo ENV (\"APP_KEY\");'"
run deny "php -r 'echo GetEnv(\"DB_PASSWORD\");'"
run deny "php artisan tinker --execute 'echo Env (\"APP_KEY\");'"
run deny "php artisan tinker --execute 'echo config(\"app.key\");'"
run deny "php artisan tinker --execute 'echo config(\"database.connections.mysql.password\");'"
run allow "php artisan tinker --execute 'echo Video::count();'"
run allow "php artisan config:show app.host --no-ansi"
# macOS filesystems are case-insensitive, so a case variant reads the same file
run deny "cat .ENV"
run deny "head -2 .Env.Production"
run allow "cat .ENV.EXAMPLE"

# must be allowed
run allow "cat .env.example"
run allow "grep DB_DATABASE .env.ci"
run allow "grep -rn '\\.env' .ai/"
run allow "php artisan config:show app.host"
run allow "php artisan config:show database.connections.mysql.database"
run allow "node tools/verify/db-isolate.mjs --apply"
run allow "node tools/verify/clone-init.mjs --status"
run allow "echo 'APP_HOST=x.test' >> .env"
run allow "ls -la .env"
run allow "test -f .env && echo present"
run allow "git status --short"
run allow "php artisan test --compact"
run allow "grep -rn 'environment' app/"

echo "---- pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
