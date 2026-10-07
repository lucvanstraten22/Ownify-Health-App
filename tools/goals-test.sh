#!/usr/bin/env bash
#
# The three goal types, end to end, over HTTP.
# ---------------------------------------------------------------------------
#
#   tools/goals-test.sh
#   BASE_URL=https://your-domain.tld tools/goals-test.sh
#
# Each type is checked for the thing that makes it that type, not just for
# its name:
#
#   Mijlpaal   60, 75, 85, 70 kg against 100 kg is 85% — the best, never the
#              sum and never the latest.
#   Streak     a failed day breaks it; days that are not consecutive do not
#              make a streak however many there are.
#   Optellen   8.000 + 11.000 + 9.000 steps against 100.000 is 28%.
#
# And around them: nothing is invented when there is no data, progress moves
# when data arrives, it survives a reload and a logout, 100% finishes the goal
# and moves it to Behaald, and one account sees nothing of another's.
#
# It uses the real endpoints with a real session. Nothing is stubbed. Health
# data and back-dated entries are written by tools/goal-verify.php, because the
# app has no screen for either. The accounts are named goaltest_<timestamp>
# and removed at the end; KEEP=1 keeps them.
#
# Works before and after migration 007: the stored-progress columns are
# checked when they exist and reported as absent when they do not.

set -u

BASE_URL="${BASE_URL:-${1:-http://127.0.0.1:8260}}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PASS=0; FAIL=0
STAMP="$(date +%Y%m%d_%H%M%S)"
PASSWORD="goal-test-password"          # a test password, not a secret

A="$(mktemp)"; S="$(mktemp)"; T="$(mktemp)"; B="$(mktemp)"; P="$(mktemp)"
USER_A="goaltest_${STAMP}a"            # Mijlpaal
USER_S="goaltest_${STAMP}s"            # Streak
USER_T="goaltest_${STAMP}t"            # Optellen
USER_B="goaltest_${STAMP}b"            # somebody else entirely
USER_P="goaltest_${STAMP}p"            # deleting the primary goal

trap 'rm -f "$A" "$S" "$T" "$B" "$P"' EXIT

ok()   { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n          %s\n' "$1" "$2"; }
same() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "got [$2] want [$3]"; }
has()  { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "no [$3] in: ${2:0:200}" ;; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "found [$3]" ;; *) ok "$1" ;; esac; }

field() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1];
  if(!is_array($d)||!array_key_exists($k,$d)){exit;} echo is_scalar($d[$k])?(string)$d[$k]:json_encode($d[$k]);' "$1"; }
csrf()  { curl -s -b "$1" -c "$1" "$BASE_URL/" | grep -o 'data-csrf="[^"]*"' | head -1 | cut -d'"' -f2; }
q()     { php "$ROOT/tools/goal-verify.php" --user="$2" --ask="$1"; }
page()  { curl -s -b "$1" -c "$1" "$BASE_URL/?page=goals"; }

# create <jar> <field=value>... — prints the JSON answer
create() {
    local jar="$1"; shift
    local args=(--data-urlencode "csrf=$(csrf "$jar")")
    for pair in "$@"; do args+=(--data-urlencode "$pair"); done
    curl -s -b "$jar" -c "$jar" -X POST "$BASE_URL/api/goals/create.php" "${args[@]}"
}

# enter <jar> <goal id> [value] — a manual entry, as the detail page sends it
enter() {
    local args=(--data-urlencode "csrf=$(csrf "$1")" --data-urlencode "goal_id=$2")
    [ $# -ge 3 ] && args+=(--data-urlencode "value=$3")
    curl -s -b "$1" -c "$1" -X POST "$BASE_URL/api/goals/progress.php" "${args[@]}"
}

# slot <page html> <goal id> — which board section the goal's card is in
slot() {
    printf '%s' "$1" | php -r '$h = stream_get_contents(STDIN);
        preg_match("~class=\"[^\"]*goal-card--(\w+)[^\"]*\"[^>]*data-goal-card=\"" . $argv[1] . "\"~", $h, $m);
        echo $m[1] ?? "(none)";' "$2"
}

command -v curl >/dev/null || { echo "curl is required."; exit 2; }
command -v php  >/dev/null || { echo "php is required."; exit 2; }

echo
echo "Goal types, end to end"
echo "  against $BASE_URL"
echo "------------------------------------------------------------------------"

[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/")" = "200" ] || { echo "  site not answering"; exit 2; }

for PAIR in "$A:$USER_A" "$S:$USER_S" "$T:$USER_T" "$B:$USER_B" "$P:$USER_P"; do
    JAR="${PAIR%%:*}"; NAME="${PAIR##*:}"
    curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/auth/register.php" \
        --data-urlencode "csrf=$(csrf "$JAR")" --data-urlencode "username=$NAME" \
        --data-urlencode "email=$NAME@ownify-test.invalid" --data-urlencode "password=$PASSWORD" > /dev/null
    # A new account starts with its setup (includes/setup.php); finished at
    # once here, so the pages under test are the app's. A server without
    # migration 016 answers 503 and has no setup to finish.
    curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/setup/finish.php" \
        --data-urlencode "csrf=$(csrf "$JAR")" > /dev/null
done
ok "five test accounts exist"

# ======================================================================
echo "== the wizard offers exactly three types =="
PAGE="$(page "$A")"
same "three type buttons" "$(printf '%s' "$PAGE" | grep -o 'data-wizard-type="[a-z]*"' | sort -u | tr '\n' ' ')" \
    'data-wizard-type="accumulate" data-wizard-type="milestone" data-wizard-type="streak" '
has  "  Mijlpaal, described" "$PAGE" 'Je voortgang is gebaseerd op je beste resultaat.'
has  "  Streak, described" "$PAGE" 'Iets een bepaald aantal dagen achter elkaar volhouden.'
has  "  Optellen, described" "$PAGE" 'over tijd bij elkaar op te tellen.'
hasnt "  no Doelwaarde" "$PAGE" 'Doelwaarde'
hasnt "  no Gewoonte as a type" "$PAGE" 'data-wizard-type="habit"'
has  "  and Geen data mogelijk is still offered" "$PAGE" 'Geen data mogelijk'

for OLD in value habit event target_value; do
    R="$(create "$A" "name=Oud type" "category=other" "type=$OLD" "target_value=10" "duration=month" "source_kind=manual")"
    has "the old type '$OLD' is refused" "$R" '"ok":false'
done

R="$(create "$A" "name=Zonder bron" "category=other" "type=milestone" "target_value=10" "direction=increase" "duration=month")"
has "a goal without a chosen source is refused, never guessed" "$R" '"ok":false'

# ======================================================================
echo "== Mijlpaal: the best result counts =="
R="$(create "$A" "name=Bankdrukken 100 kg" "category=strength" "type=milestone" \
    "target_value=100" "target_unit=kg" "direction=increase" "duration=month" "source_kind=manual")"
has "created" "$R" '"ok":true'
GOAL_BENCH="$(echo "$R" | field goal_id)"
same "  it is a Mijlpaal" "$(q "kind:$GOAL_BENCH" "$USER_A")" "milestone"
same "  kept by hand" "$(q "mode:$GOAL_BENCH" "$USER_A")" "manual"

echo "  -- with nothing recorded, nothing is invented"
same "  no percentage" "$(q "percent:$GOAL_BENCH" "$USER_A")" "(none)"
same "  no best result — not 0 kg" "$(q "best:$GOAL_BENCH" "$USER_A")" "(none)"
STORED="$(q "stored:$GOAL_BENCH" "$USER_A")"
if [ "$STORED" = "(no columns)" ]; then
    ok "  (migration 007 not imported: stored columns not checked)"
else
    same "  nothing stored either" "$STORED" "best=(none) total=(none) streak=(none) longest=(none) pct=(none)"
fi

echo "  -- 60, 75, 85, 70 kg"
same "  60 kg -> 60%" "$(enter "$A" "$GOAL_BENCH" 60 | field percent)" "60"
same "  75 kg -> 75%" "$(enter "$A" "$GOAL_BENCH" 75 | field percent)" "75"
same "  85 kg -> 85%" "$(enter "$A" "$GOAL_BENCH" 85 | field percent)" "85"
same "  70 kg -> still 85%, a worse result lowers nothing" "$(enter "$A" "$GOAL_BENCH" 70 | field percent)" "85"
same "  the best is 85, not the sum (290) and not the latest (70)" "$(q "best:$GOAL_BENCH" "$USER_A")" "85"
same "  one row for the day, holding the best" "$(q "rows:$GOAL_BENCH" "$USER_A")" "1"

echo "  -- the same four results on four different days"
R="$(create "$A" "name=Deadlift 100 kg" "category=strength" "type=milestone" \
    "target_value=100" "target_unit=kg" "direction=increase" "duration=month" "source_kind=manual")"
GOAL_DL="$(echo "$R" | field goal_id)"
q "entry:$GOAL_DL:3:60" "$USER_A" > /dev/null
q "entry:$GOAL_DL:2:75" "$USER_A" > /dev/null
q "entry:$GOAL_DL:1:85" "$USER_A" > /dev/null
q "entry:$GOAL_DL:0:70" "$USER_A" > /dev/null
same "  85%" "$(q "percent:$GOAL_DL" "$USER_A")" "85"
same "  best 85" "$(q "best:$GOAL_DL" "$USER_A")" "85"
same "  latest 70, kept but not counted" "$(q "latest:$GOAL_DL" "$USER_A")" "70"
same "  the chart draws each result" "$(q "series:$GOAL_DL" "$USER_A")" "best 60,75,85,70"
PAGE="$(page "$A")"
has "  the page says Beste resultaat" "$PAGE" 'Beste resultaat'
has "  and names the best point on the chart" "$PAGE" 'Beste 85 kg'
has "  and shows the latest result separately" "$PAGE" 'Laatste resultaat'

echo "  -- which way is better is asked, never assumed"
R="$(create "$A" "name=Zonder richting" "category=weight" "type=milestone" \
    "target_value=80" "duration=month" "source_kind=measurement" "source_key=weight")"
has "  a Mijlpaal without a direction is refused" "$R" '"ok":false'

echo "  -- lower is better, read from the scale"
R="$(create "$A" "name=Naar 80 kg" "category=weight" "type=milestone" \
    "target_value=80" "direction=decrease" "duration=month" \
    "source_kind=measurement" "source_key=weight")"
has "created" "$R" '"ok":true'
GOAL_W="$(echo "$R" | field goal_id)"
same "  no weigh-in yet: no percentage" "$(q "percent:$GOAL_W" "$USER_A")" "(none)"
q "weigh:$GOAL_W:90" "$USER_A" > /dev/null
same "  the first weigh-in is the starting point" "$(q "start:$GOAL_W" "$USER_A")" "90"
same "  90 kg from 90 -> 0%" "$(q "percent:$GOAL_W" "$USER_A")" "0"
q "weigh:$GOAL_W:84" "$USER_A" > /dev/null
same "  84 kg -> 60% of the way from 90 to 80" "$(q "percent:$GOAL_W" "$USER_A")" "60"
q "weigh:$GOAL_W:86" "$USER_A" > /dev/null
same "  86 kg afterwards -> still 60%" "$(q "percent:$GOAL_W" "$USER_A")" "60"

same "  an automatic goal refuses to be typed over (409)" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/progress.php" \
        --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "goal_id=$GOAL_W" --data-urlencode "value=1")" "409"

q "weigh:$GOAL_W:80" "$USER_A" > /dev/null
same "  80 kg -> completed by itself" "$(q "status:$GOAL_W" "$USER_A")" "completed"
q "weigh:$GOAL_W:95" "$USER_A" > /dev/null
same "  a finished goal is not rewritten by later data" "$(q "percent:$GOAL_W" "$USER_A")" "100"

# ======================================================================
echo "== Streak: consecutive days, and a miss breaks it =="
R="$(create "$S" "name=Elke dag 10.000 stappen" "category=activity" "type=streak" \
    "target_value=7" "duration=month" "source_kind=metric" "source_key=steps" "direction=increase")"
has "a data streak without a daily threshold is refused" "$R" '"ok":false'
R="$(create "$S" "name=Gewicht op rij" "category=weight" "type=streak" \
    "target_value=7" "duration=month" "source_kind=measurement" "source_key=weight" \
    "daily_target=80" "direction=decrease")"
has "a streak on a weight is refused" "$R" '"ok":false'
R="$(create "$S" "name=Te lang" "category=activity" "type=streak" "target_value=40" \
    "duration=month" "source_kind=manual")"
has "40 days in a row in a month is refused" "$R" '"ok":false'

R="$(create "$S" "name=Elke dag 10.000 stappen" "category=activity" "type=streak" \
    "target_value=7" "duration=month" "source_kind=metric" "source_key=steps" \
    "daily_target=10000" "direction=increase")"
has "created" "$R" '"ok":true'
GOAL_ST="$(echo "$R" | field goal_id)"
same "  it is a Streak" "$(q "kind:$GOAL_ST" "$USER_S")" "streak"
same "  no data: no streak, not a streak of 0" "$(q "streak:$GOAL_ST" "$USER_S")" "(none)"
same "  and no percentage" "$(q "percent:$GOAL_ST" "$USER_S")" "(none)"

echo "  -- five good days, a failed day, two good days"
q "metric:$GOAL_ST:steps:12000,11000,10500,13000,10000,3000,12000,15000" "$USER_S" > /dev/null
same "  the failed day broke it: the streak is 2" "$(q "streak:$GOAL_ST" "$USER_S")" "2"
same "  the longest run was 5" "$(q "longest:$GOAL_ST" "$USER_S")" "5"
same "  2 of 7 -> 28.57%" "$(q "percent:$GOAL_ST" "$USER_S")" "28.57"
same "  seven good days, not consecutive: not completed" "$(q "status:$GOAL_ST" "$USER_S")" "active"
same "  the calendar: 7 met" "$(q "met:$GOAL_ST" "$USER_S")" "7"
same "  and 1 missed" "$(q "missed:$GOAL_ST" "$USER_S")" "1"
same "  the chart draws the streak falling to 0" "$(q "series:$GOAL_ST" "$USER_S")" "streak 1,2,3,4,5,0,1,2"
PAGE="$(page "$S")"
has "  the page says Huidige streak" "$PAGE" 'Huidige streak'
has "  and Langste streak" "$PAGE" 'Langste streak'

echo "  -- kept by hand: a day without a tick breaks it, today is still open"
R="$(create "$S" "name=Elke dag mediteren" "category=habit" "type=streak" \
    "target_value=7" "duration=month" "source_kind=manual")"
GOAL_MED="$(echo "$R" | field goal_id)"
q "entry:$GOAL_MED:3:-" "$USER_S" > /dev/null
q "entry:$GOAL_MED:1:-" "$USER_S" > /dev/null
same "  the gap two days ago broke it: streak 1" "$(q "streak:$GOAL_MED" "$USER_S")" "1"
same "  today is open, not missed" "$(q "pending:$GOAL_MED" "$USER_S")" "1"
same "  the gap is unknown, not missed" "$(q "unknown:$GOAL_MED" "$USER_S")" "1"
same "  ticking today -> 2 in a row, 28.57%" "$(enter "$S" "$GOAL_MED" | field percent)" "28.57"
enter "$S" "$GOAL_MED" > /dev/null
same "  ticking twice is still one day" "$(q "rows:$GOAL_MED" "$USER_S")" "3"
q "entry:$GOAL_MED:2:-" "$USER_S" > /dev/null
same "  filling the gap joins the run: 4 in a row" "$(q "streak:$GOAL_MED" "$USER_S")" "4"

echo "  -- two good days of two -> done"
R="$(create "$S" "name=Twee dagen stappen" "category=activity" "type=streak" \
    "target_value=2" "duration=week" "source_kind=metric" "source_key=steps" \
    "daily_target=10000" "direction=increase")"
GOAL_S3="$(echo "$R" | field goal_id)"
q "backdate:$GOAL_S3:1" "$USER_S" > /dev/null
same "  yesterday and today make 2 in a row -> completed" "$(q "status:$GOAL_S3" "$USER_S")" "completed"
same "  100%" "$(q "percent:$GOAL_S3" "$USER_S")" "100"
same "  and it moved to Behaald" "$(slot "$(page "$S")" "$GOAL_S3")" "completed"

# ======================================================================
echo "== Optellen: everything adds up =="
R="$(create "$T" "name=Gewicht optellen" "category=weight" "type=accumulate" "measure=amount" \
    "target_value=1000" "duration=month" "source_kind=measurement" "source_key=weight")"
has "a weight cannot be added up: refused" "$R" '"ok":false'

R="$(create "$T" "name=100.000 stappen" "category=activity" "type=accumulate" "measure=amount" \
    "target_value=100000" "duration=month" "source_kind=metric" "source_key=steps")"
has "created" "$R" '"ok":true'
GOAL_TOT="$(echo "$R" | field goal_id)"
same "  it is Optellen" "$(q "kind:$GOAL_TOT" "$USER_T")" "accumulate"
same "  no steps yet: no total, not 0" "$(q "total:$GOAL_TOT" "$USER_T")" "(none)"

q "metric:$GOAL_TOT:steps:8000,11000,9000" "$USER_T" > /dev/null
same "  8.000 + 11.000 + 9.000 = 28.000" "$(q "total:$GOAL_TOT" "$USER_T")" "28000"
same "  28%" "$(q "percent:$GOAL_TOT" "$USER_T")" "28"
PAGE="$(page "$T")"
has "  the page says Totaal 28.000 stappen" "$PAGE" '28.000 stappen'

q "add_metric:$GOAL_TOT:steps,0:2000" "$USER_T" > /dev/null
same "  new steps arrive -> 30%, nobody pressed anything" "$(q "percent:$GOAL_TOT" "$USER_T")" "30"
same "  the chart is a running total" "$(q "series:$GOAL_TOT" "$USER_T")" "total 8000,19000,30000"
STORED="$(q "stored:$GOAL_TOT" "$USER_T")"
if [ "$STORED" != "(no columns)" ]; then
    same "  stored with the goal" "$STORED" "best=(none) total=30000 streak=(none) longest=(none) pct=30"
fi

q "add_metric:$GOAL_TOT:steps,0:70000" "$USER_T" > /dev/null
same "  100.000 reached -> completed" "$(q "status:$GOAL_TOT" "$USER_T")" "completed"
same "  and it moved to Behaald" "$(slot "$(page "$T")" "$GOAL_TOT")" "completed"

echo "  -- kept by hand, in km"
R="$(create "$T" "name=50 km hardlopen" "category=activity" "type=accumulate" "measure=amount" \
    "target_value=50" "target_unit=km" "duration=month" "source_kind=manual")"
GOAL_KM="$(echo "$R" | field goal_id)"
same "  5 km -> 10%" "$(enter "$T" "$GOAL_KM" 5 | field percent)" "10"
same "  3 km more the same day -> 16%, added not replaced" "$(enter "$T" "$GOAL_KM" 3 | field percent)" "16"
q "entry:$GOAL_KM:2:10" "$USER_T" > /dev/null
same "  10 km two days ago -> 18 km, 36%" "$(q "percent:$GOAL_KM" "$USER_T")" "36"

echo "  -- kept by hand, in days that need not be consecutive"
R="$(create "$T" "name=20 dagen gezond eten" "category=nutrition" "type=accumulate" "measure=days" \
    "target_value=20" "duration=month" "source_kind=manual")"
GOAL_DAYS="$(echo "$R" | field goal_id)"
same "  stored as days" "$(q "unit:$GOAL_DAYS" "$USER_T")" "dagen"
same "  a tick -> 1 of 20, 5%" "$(enter "$T" "$GOAL_DAYS" | field percent)" "5"
enter "$T" "$GOAL_DAYS" > /dev/null
same "  a second tick today changes nothing" "$(q "total:$GOAL_DAYS" "$USER_T")" "1"
q "entry:$GOAL_DAYS:5:-" "$USER_T" > /dev/null
q "entry:$GOAL_DAYS:3:-" "$USER_T" > /dev/null
same "  days with gaps between them all count: 3" "$(q "total:$GOAL_DAYS" "$USER_T")" "3"

echo "  -- from data, days that reach 10.000 steps"
R="$(create "$T" "name=Dagen met 10.000 stappen" "category=activity" "type=accumulate" "measure=days" \
    "target_value=20" "duration=month" "source_kind=metric" "source_key=steps" \
    "daily_target=10000" "direction=increase")"
GOAL_DD="$(echo "$R" | field goal_id)"
q "backdate:$GOAL_DD:2" "$USER_T" > /dev/null
same "  8.000 / 11.000 / 81.000: two days count" "$(q "total:$GOAL_DD" "$USER_T")" "2"
same "  and one is missed" "$(q "missed:$GOAL_DD" "$USER_T")" "1"

# ======================================================================
echo "== persistence: a reload, and a logout =="
page "$A" > /dev/null
same "bench press still 85% after a reload" "$(q "percent:$GOAL_BENCH" "$USER_A")" "85"
STORED="$(q "stored:$GOAL_BENCH" "$USER_A")"
if [ "$STORED" != "(no columns)" ]; then
    same "  stored on the goal row" "$STORED" "best=85 total=(none) streak=(none) longest=(none) pct=85"
fi

curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/auth/logout.php" --data-urlencode "csrf=$(csrf "$A")" > /dev/null
rm -f "$A"; A="$(mktemp)"
R="$(curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/auth/login.php" \
    --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "email=$USER_A@ownify-test.invalid" \
    --data-urlencode "password=$PASSWORD")"
has "signed back in" "$R" '"ok":true'
PAGE="$(page "$A")"
has "  the bench press is still there" "$PAGE" 'Bankdrukken 100 kg'
same "  still 85%" "$(q "percent:$GOAL_BENCH" "$USER_A")" "85"
same "  on the active board" "$(slot "$PAGE" "$GOAL_BENCH")" "primary"

echo "== 100% finishes a hand-kept goal and moves it to Behaald =="
R="$(enter "$A" "$GOAL_BENCH" 100)"
same "  100%" "$(echo "$R" | field percent)" "100"
same "  it completed itself" "$(echo "$R" | field completed)" "1"
same "  the date was recorded" "$(q "completed_on:$GOAL_BENCH" "$USER_A")" "$(date +%Y-%m-%d)"
same "  and it is under Behaald" "$(slot "$(page "$A")" "$GOAL_BENCH")" "completed"
same "  a finished goal takes no more entries (409)" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -c "$A" -X POST "$BASE_URL/api/goals/progress.php" \
        --data-urlencode "csrf=$(csrf "$A")" --data-urlencode "goal_id=$GOAL_BENCH" --data-urlencode "value=120")" "409"

# ======================================================================
echo "== privacy: one account sees nothing of another's =="
PAGE_B="$(page "$B")"
hasnt "no other account's goal names" "$PAGE_B" 'Bankdrukken 100 kg'
hasnt "  nor its streaks" "$PAGE_B" 'Elke dag mediteren'
same "  its own list is empty" "$(q "count:0" "$USER_B")" "0"
same "entering progress on someone else's goal -> 404" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$B" -c "$B" -X POST "$BASE_URL/api/goals/progress.php" \
        --data-urlencode "csrf=$(csrf "$B")" --data-urlencode "goal_id=$GOAL_KM" --data-urlencode "value=99")" "404"
same "  and it did not change" "$(q "percent:$GOAL_KM" "$USER_T")" "36"

# ======================================================================
echo "== deleting the primary goal: the first of Secundaire doelen takes its place =="
# Before, the server gave the place to the oldest goal while both apps showed
# the first secondary card moving up — and the board changed again on the
# next read. Now the goal stored is the goal shown, wherever it is read.

# milestone <name> <duration> [percent] — a manual Mijlpaal to 100, with one entry; prints its id
milestone() {
    local id; id="$(create "$P" "name=$1" "category=other" "type=milestone" "target_value=100" \
        "direction=increase" "duration=$2" "source_kind=manual" | field goal_id)"
    [ $# -ge 3 ] && enter "$P" "$id" "$3" > /dev/null
    echo "$id"
}
# drop <goal id> [successor] — the delete as the board sends it; prints the answer's `primary`
drop() {
    local args=(--data-urlencode "csrf=$(csrf "$P")" --data-urlencode "goal_id=$1")
    [ $# -ge 2 ] && args+=(--data-urlencode "successor=$2")
    curl -s -b "$P" -c "$P" -X POST "$BASE_URL/api/goals/delete.php" "${args[@]}" | field primary
}
# web_primary / app_primary — the primary goal as the website renders the board and as the app is sent it
web_primary() { page "$P" | php -r '$h = stream_get_contents(STDIN);
    preg_match("~data-goal-slot=\"primary\"(.*?)(data-goal-slot=|$)~s", $h, $slot);
    preg_match("~data-goal-card=\"(\d+)\"~", $slot[1] ?? "", $m); echo $m[1] ?? "(none)";'; }
app_primary() { curl -s -b "$P" -c "$P" -X POST "$BASE_URL/api/app/state.php" --data-urlencode "csrf=$(csrf "$P")" \
    | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["goals"]["primary"]["id"] ?? "(none)";'; }
web_secondary() { page "$P" | php -r '$h = stream_get_contents(STDIN);
    preg_match("~data-goal-slot=\"secondary\"(.*?)(data-goal-slot=|data-goal-panel=|$)~s", $h, $slot);
    preg_match_all("~data-goal-card=\"(\d+)\"~", $slot[1] ?? "", $m); echo implode(",", $m[1]);'; }
# stored <label> <goal id or (none)> — the answer, the database, the website and the app all say the same
stored() {
    same "$1: stored" "$(q "primary:" "$USER_P")" "$2"
    same "  the website, read again, shows it" "$(web_primary)" "$2"
    same "  the app, read again, is sent it" "$(app_primary)" "$2"
}

MAIN="$(milestone "Hoofddoel" month 50)"
G41="$(milestone "41 procent" month 41)"
G64="$(milestone "64 procent" month 64)"
G82="$(milestone "82 procent" month 82)"
q "backdate:$G41:30" "$USER_P" > /dev/null          # the oldest by far: the goal the old rule picked
same "the board: 82%, 64%, 41% under the primary goal" "$(web_secondary)" "$G82,$G64,$G41"
same "deleting the primary goal answers with 82% as primary — not the oldest goal" "$(drop "$MAIN" "$GOAL_BENCH")" "$G82"
#      (sent along: another account's goal — never taken, the board's own first is)
stored "82% is the primary goal" "$G82"
same "  and the others stay in order: 64%, 41%" "$(web_secondary)" "$G64,$G41"

same "a page showing an older order moved 41% up: 41% is the goal stored" "$(drop "$G82" "$G41")" "$G41"
stored "41% is the primary goal" "$G41"
same "one secondary goal left: it takes the place" "$(drop "$G41")" "$G64"
stored "64% is the primary goal" "$G64"
same "no secondary goal left: no primary goal, and nothing made up" "$(drop "$G64")" "null"
stored "no goal" "(none)"
same "  the board is empty" "$(q "count:" "$USER_P")" "0"

MAIN="$(milestone "Hoofddoel" month 50)"
WEEK="$(milestone "60 procent, een week" week 60)"
MONTH="$(milestone "60 procent, een maand" month 60)"
same "equal percentages: the board shows the week first" "$(web_secondary)" "$WEEK,$MONTH"
same "  and the week becomes primary" "$(drop "$MAIN")" "$WEEK"
stored "the goal ending sooner is the primary goal" "$WEEK"
drop "$WEEK" > /dev/null; drop "$MONTH" > /dev/null

MAIN="$(milestone "Hoofddoel" month 50)"
HALF="$(milestone "geen data, half jaar" halfyear)"
NONE="$(milestone "geen data, een week" week)"
ZERO="$(milestone "nul procent" year 0)"
same "the board: 0%, then no data by end date" "$(web_secondary)" "$ZERO,$NONE,$HALF"
same "a real 0% before no data: 0% becomes primary" "$(drop "$MAIN")" "$ZERO"
stored "0% is the primary goal" "$ZERO"
same "only goals without data: the one ending soonest" "$(drop "$ZERO")" "$NONE"
stored "the week without data is the primary goal" "$NONE"
drop "$NONE" > /dev/null; drop "$HALF" > /dev/null

MAIN="$(milestone "Hoofddoel" month 50)"
RUN="$(milestone "20 procent" month 20)"
PAUSED="$(milestone "90 procent, gepauzeerd" month 90)"
curl -s -b "$P" -c "$P" -X POST "$BASE_URL/api/goals/update.php" --data-urlencode "csrf=$(csrf "$P")" \
    --data-urlencode "goal_id=$PAUSED" --data-urlencode "action=pause" > /dev/null
same "a paused goal comes after the running one" "$(web_secondary)" "$RUN,$PAUSED"
same "  sent the paused goal while a running one is left: the running goal becomes primary" "$(drop "$MAIN" "$PAUSED")" "$RUN"
stored "the running goal is the primary goal" "$RUN"
same "only a paused goal left: it takes the place, as before" "$(drop "$RUN" "$PAUSED")" "$PAUSED"
stored "the paused goal is the primary goal" "$PAUSED"
drop "$PAUSED" > /dev/null

# ======================================================================
LIMIT="$(php -r 'echo (require $argv[1])["limits"]["active"];' "$ROOT/config/goals.php")"
echo "== the board holds $LIMIT active goals (config/goals.php), and no more =="
for N in $(seq 1 "$LIMIT"); do
    R="$(create "$B" "name=Doel $N" "category=other" "type=milestone" \
        "target_value=10" "target_unit=keer" "direction=increase" "duration=month" "source_kind=manual")"
    has "  goal $N of $LIMIT fits" "$R" '"ok":true'
done
R="$(create "$B" "name=Een te veel" "category=other" "type=milestone" \
    "target_value=10" "target_unit=keer" "direction=increase" "duration=month" "source_kind=manual")"
has "  one more is refused" "$R" '"ok":false'
has "  saying how many fit" "$R" "Je hebt al $LIMIT actieve doelen"
has "  and the board says it is full, with the same number" "$(page "$B")" "Je $LIMIT doelplekken zijn bezet"

echo
if [ -n "${KEEP:-}" ]; then
    echo "  Kept $USER_A, $USER_S, $USER_T, $USER_B and $USER_P."
else
    for NAME in "$USER_A" "$USER_S" "$USER_T" "$USER_B" "$USER_P"; do
        php "$ROOT/tools/goal-verify.php" --user="$NAME" --cleanup
    done
fi

echo "------------------------------------------------------------------------"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
