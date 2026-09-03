#!/usr/bin/env bash
# Fetch repo metadata for addons not yet present in ofxaddons_repo_info.jsonl.
# Idempotent: skips ids already recorded. Re-run to pick up 429/403/network retries.
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
TOKEN="$(tr -d '[:space:]' < ~/github_token.txt)"
OUT="$DIR/ofxaddons_repo_info.jsonl"
HAVE="$DIR/ofxaddons_have.tmp"
RETRY="$DIR/retry_ids.txt"
: > "$RETRY"

jq -r '.id' "$OUT" 2>/dev/null | sort -u > "$HAVE"

ok=0; miss=0; retry_n=0; total=0
while IFS=$'\t' read -r id full_name; do
    [ -z "${id:-}" ] && continue
    if grep -qx "$id" "$HAVE"; then continue; fi
    total=$((total+1))
    body="$(curl -s --max-time 30 -w '\n%{http_code}' -H "Authorization: token $TOKEN" -H 'User-Agent: hermes-agent' -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$full_name")"
    code="${body##*$'\n'}"
    body="${body%$'\n'*}"
    case "$code" in
        200)
            printf '%s\n' "$body" | jq -c --arg id "$id" '{id:$id,status:"ok",name:.name,desc:.description,archived:.archived,topics:.topics,pushed:.pushed_at,stars:.stargazers_count,forks:.forks_count,lang:.language}' >> "$OUT"
            echo "$id" >> "$HAVE"
            ok=$((ok+1))
            ;;
        404)
            printf '{"id":%s,"status":"404"}\n' "$id" >> "$OUT"
            echo "$id" >> "$HAVE"
            miss=$((miss+1))
            ;;
        403|429)
            sleep 10
            echo "$id" >> "$RETRY"
            retry_n=$((retry_n+1))
            ;;
        *)
            echo "$id $code" >> "$RETRY"
            retry_n=$((retry_n+1))
            ;;
    esac
done < "$DIR/remaining.tsv"

echo "processed=$total ok=$ok miss=$miss retry=$retry_n"
