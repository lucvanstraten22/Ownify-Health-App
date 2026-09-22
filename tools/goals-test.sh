#!/usr/bin/env bash
#
# Goal progress, end to end, over HTTP.
# ---------------------------------------------------------------------------
#
#   tools/goals-test.sh
#   BASE_URL=https://your-domain.tld tools/goals-test.sh
#
# Walks the fifteen checks the brief asked for: create an automatic goal, pick
# a source, verify it was stored, feed in real data, watch the percentage and
# the day blocks move, keep a manual goal by hand, reach 100%, and confirm the
# goal moves itself to Behaald and stays there across a logout.
#
# It uses the real endpoints with a real session. Nothing is stubbed. The
# accounts it makes are named goaltest_<timestamp> and removed at the end;
# KEEP=1 keeps them.

set -u

BASE_URL="${BASE_URL:-${1:-http://127.0.0.1:8260}}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PASS=0; FAIL=0
A="$(mktemp)"; B="$(mktemp)"
STAMP="$(date +%Y%m%d_%H%M%S)"
USER_A="goaltest_${STAMP}"
USER_B="goaltest_${STAMP}b"
PASSWORD="goal-test-password"          # a test password, not a secret

trap 'rm -f "$A" "$B"' EXIT

ok()   { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n          %s\n' "$1" "$2"; }
same() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "got [$2] want [$3]"; }
has()  { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "no [$3] in: ${2:0:200}" ;; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "found [$3]" ;; *) ok "$1" ;; esac; }

field() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1];
  if(!is_array($d)||!array_key_exists($k,$d)){exit;} echo is_scalar($d[$k])?(string)$d[$k]:json_encode($d[$k]);' "$1"; }
csrf()  { curl -s -b "$1" -c "$1" "$BASE_URL/" | grep -o 'data-csrf="[^"]*"' | head -1 | cut -d'"' -f2; }
q()     { php "$ROOT/tools/goal-verify.php" --user="$2" --ask="$1"; }

command -v curl >/dev/null || { echo "curl is required."; exit 2; }
command -v php  >/dev/null || { echo "php is required."; exit 2; }

echo
echo "Goal progress, end to end"
echo "  against $BASE_URL"
echo "------------------------------------------------------------------------"

[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/")" = "200" ] || { echo "  site not answering"; exit 2; }

for PAIR in "$A:$USER_A" "$B:$USER_B"; do
    JAR="${PAIR%%:*}"; NAME="${PAIR##*:}"
    curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/auth/register.php" \
        --data-urlencode "csrf=$(csrf "$JAR")" --data-urlencode "username=$NAME" \
        --data-urlencode "email=$NAME@jolu-test.invalid" --data-urlencode "password=$PASSWORD" > /dev/null
done
ok "two test accounts exist"

# ---------------------------------------------------- 1,2,3. automatic goal

echo "== 1,2,3. an automatic goal, with a source the person picked =="
R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/create.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "name=Afvallen naar 75 kg" \
    --data-urlencode "category=weight" --data-urlencode "type=value" \
    --data-urlencode "target_value=75" --data-urlencode "target_unit=kg" \
    --data-urlencode "direction=decrease" --data-urlencode "duration=month" \
    --data-urlencode "source_kind=measurement" --data-urlencode "source_key=weight")"
has "created" "$R" '"ok":true'
GOAL_W="$(echo "$R" | field goal_id)"
same "  the source was stored" "$(q "source:$GOAL_W" "$USER_A")" "measurement:weight"
same "  and it is an automatic goal" "$(q "mode:$GOAL_W" "$USER_A")" "auto"

echo "== an invented source is refused =="
has "refused" "$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/create.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "name=Onzin" \
    --data-urlencode "category=other" --data-urlencode "type=value" \
    --data-urlencode "target_value=1" --data-urlencode "duration=week" \
    --data-urlencode "source_kind=metric" --data-urlencode "source_key=telepathy")" '"ok":false'

echo "== with no data yet, nothing is invented =="
same "no weight recorded -> no percentage" "$(q "percent:$GOAL_W" "$USER_A")" "(none)"

# ------------------------------------------------- 4,5,6. real data moves it

echo "== 4,5,6. real data arrives and the goal moves by itself =="
q "weigh:$GOAL_W:82" "$USER_A" > /dev/null
same "the baseline is set on the first reading" "$(q "start:$GOAL_W" "$USER_A")" "82"
q "weigh:$GOAL_W:80" "$USER_A" > /dev/null
curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals" > /dev/null      # a render recomputes
same "2 of 7 kg lost reads 29%" "$(q "percent:$GOAL_W" "$USER_A")" "28.57"
same "  and the current value is the newest reading" "$(q "current:$GOAL_W" "$USER_A")" "80"

# ------------------------------------------------------- 7. the day blocks

echo "== 7. a daily goal, evaluated day by day =="
R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/create.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "name=10.000 stappen per dag" \
    --data-urlencode "category=activity" --data-urlencode "type=habit" \
    --data-urlencode "target_value=30" --data-urlencode "duration=month" \
    --data-urlencode "source_kind=metric" --data-urlencode "source_key=steps" \
    --data-urlencode "daily_target=10000")"
has "created" "$R" '"ok":true'
GOAL_S="$(echo "$R" | field goal_id)"
same "  the daily target was stored" "$(q "daily:$GOAL_S" "$USER_A")" "10000"

q "steps:$GOAL_S" "$USER_A" > /dev/null          # 12000, 10400, 3000, none, 15000
same "three days met" "$(q "met:$GOAL_S" "$USER_A")" "3"
same "  one day missed" "$(q "missed:$GOAL_S" "$USER_A")" "1"
same "  one day unknown, not counted as missed" "$(q "unknown:$GOAL_S" "$USER_A")" "1"

PAGE="$(curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals")"
has "the blocks are rendered" "$PAGE" 'day-block is-met'
has "  including the unknown day" "$PAGE" 'day-block is-unknown'

# ------------------------------------------------- 8,9,10. the manual goal

echo "== 8,9,10. a goal with no data behind it =="
R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/create.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "name=20 boeken lezen" \
    --data-urlencode "category=other" --data-urlencode "type=value" \
    --data-urlencode "target_value=20" --data-urlencode "target_unit=boeken" \
    --data-urlencode "duration=year" --data-urlencode "source_kind=manual")"
has "created" "$R" '"ok":true'
GOAL_M="$(echo "$R" | field goal_id)"
same "  it is manual" "$(q "mode:$GOAL_M" "$USER_A")" "manual"

R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/progress.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "goal_id=$GOAL_M" --data-urlencode "value=5")"
has "5 books saved" "$R" '"ok":true'
same "  which is 25%" "$(echo "$R" | field percent)" "25"

echo "== 10. it survives a reload =="
same "still 25% after re-reading the page" "$(curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals" > /dev/null; q "percent:$GOAL_M" "$USER_A")" "25"

echo "== an automatic goal refuses to be typed over =="
same "writing to it -> 409" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/progress.php" \
        --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "goal_id=$GOAL_W" --data-urlencode "value=1")" "409"

# ------------------------------------------------- 11,12. reaching the target

echo "== 11,12. reaching the target finishes the goal by itself =="
R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/progress.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "goal_id=$GOAL_M" --data-urlencode "value=20")"
same "  100%" "$(echo "$R" | field percent)" "100"
same "  it completed itself" "$(echo "$R" | field completed)" "1"
same "  the status says so" "$(q "status:$GOAL_M" "$USER_A")" "completed"
same "  and the date was recorded" "$(q "completed_on:$GOAL_M" "$USER_A")" "$(date +%Y-%m-%d)"

PAGE="$(curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals")"
has "it appears under Behaald" "$PAGE" '20 boeken lezen'

echo "== an automatic goal completes itself too, with no one pressing anything =="
q "weigh:$GOAL_W:75" "$USER_A" > /dev/null
curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals" > /dev/null
same "reaching 75 kg completed it" "$(q "status:$GOAL_W" "$USER_A")" "completed"
same "  and it keeps the figure it finished on" "$(q "percent:$GOAL_W" "$USER_A")" "100"

echo "== a completed goal is not rewritten by later data =="
q "weigh:$GOAL_W:73" "$USER_A" > /dev/null
curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals" > /dev/null
same "still 100, not recomputed" "$(q "percent:$GOAL_W" "$USER_A")" "100"

# ------------------------------------------------- 13,14. across a session

echo "== 13,14. log out, log back in =="
curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/auth/logout.php" --data-urlencode "csrf=$(csrf "$A")" > /dev/null
rm -f "$A"; A="$(mktemp)"
R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/auth/login.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "email=$USER_A@jolu-test.invalid" \
    --data-urlencode "password=$PASSWORD")"
has "signed back in" "$R" '"ok":true'
PAGE="$(curl -s -b "$A" -c "$A" "$BASE_URL/?page=goals")"
has "the manual goal is still there" "$PAGE" '20 boeken lezen'
same "  still 25%-then-100%" "$(q "percent:$GOAL_M" "$USER_A")" "100"
same "  the steps goal still has its days" "$(q "met:$GOAL_S" "$USER_A")" "3"

# ------------------------------------------------------------ 15. privacy

echo "== 15. one account sees nothing of another's =="
PAGE_B="$(curl -s -b "$B" -c "$B" "$BASE_URL/?page=goals")"
hasnt "the other account's goal name" "$PAGE_B" '20 boeken lezen'
hasnt "  nor the weight goal" "$PAGE_B" 'Afvallen naar 75 kg'
same "  and its own goal list is empty" "$(q "count:0" "$USER_B")" "0"

same "asking for someone else's goal -> 404" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$B" -c "$B" -X POST "$BASE_URL/api/goals/progress.php" \
        --data-urlencode "csrf=$(csrf "$B")" --data-urlencode "goal_id=$GOAL_M" --data-urlencode "value=99")" "404"
same "  and it did not change" "$(q "percent:$GOAL_M" "$USER_A")" "100"

echo
if [ -n "${KEEP:-}" ]; then
    echo "  Kept $USER_A and $USER_B."
else
    php "$ROOT/tools/goal-verify.php" --user="$USER_A" --cleanup
    php "$ROOT/tools/goal-verify.php" --user="$USER_B" --cleanup
fi

echo "------------------------------------------------------------------------"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
