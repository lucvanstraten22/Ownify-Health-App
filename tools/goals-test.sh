#!/usr/bin/env bash
#
# Goals, end to end, over HTTP (docs/GOALS.md).
# ---------------------------------------------------------------------------
#
#   tools/goals-test.sh
#   BASE_URL=https://your-domain.tld tools/goals-test.sh
#
# The three goal types, each checked for the thing that makes it that type:
#
#   Mijlpaal   80 → 100 kg at 90 is 50%, and back at 85 it is 25% — the
#              latest value, never the best; 100% finishes it at once.
#   Streak     every day: the share of ALL the period's days that made it,
#              a missed day just a missed day, never finished early;
#              per week: a week is met as soon as its count is reached.
#   Optellen   8.000 + 11.000 + 9.000 steps since the start against
#              100.000 is 28% — what came before the start does not count.
#
# And around them: what the wizard offers, what it refuses, a goal read from
# health data against one kept by hand, Afgerond's order, the board's limit,
# the one-time key, progress surviving a reload and a logout, and one account
# seeing nothing of another's.
#
# It uses the real endpoints with a real session. Health data and later days
# are written by tools/goal-verify.php, because the app has no screen for
# either. The accounts are named goaltest_<timestamp> and removed at the end;
# KEEP=1 keeps them. The formulas themselves, edge case by edge case, are
# tools/goal-engine-test.php's.

set -u

BASE_URL="${BASE_URL:-${1:-http://127.0.0.1:8260}}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PASS=0; FAIL=0
STAMP="$(date +%Y%m%d_%H%M%S)"
PASSWORD="goal-test-password"          # a test password, not a secret

A="$(mktemp)"; S="$(mktemp)"; T="$(mktemp)"; B="$(mktemp)"
USER_A="goaltest_${STAMP}a"            # Mijlpaal
USER_S="goaltest_${STAMP}s"            # Streak
USER_T="goaltest_${STAMP}t"            # Optellen
USER_B="goaltest_${STAMP}b"            # somebody else entirely

trap 'rm -f "$A" "$S" "$T" "$B"' EXIT

ok()   { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n          %s\n' "$1" "$2"; }
same() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "got [$2] want [$3]"; }
has()  { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "no [$3] in: ${2:0:300}" ;; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "found [$3]" ;; *) ok "$1" ;; esac; }

field() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1];
  if(!is_array($d)||!array_key_exists($k,$d)){exit;} echo is_scalar($d[$k])?var_export($d[$k],true):json_encode($d[$k]);' "$1" | tr -d "'"; }
csrf()  { curl -s -b "$1" -c "$1" "$BASE_URL/" | grep -o 'data-csrf="[^"]*"' | head -1 | cut -d'"' -f2; }
q()     { php "$ROOT/tools/goal-verify.php" --user="$2" --ask="$1"; }
page()  { curl -s -b "$1" -c "$1" "$BASE_URL/?page=goals"; }
day()   { date -d "$1 day" +%Y-%m-%d; }

# create <jar> <field=value>... — prints the JSON answer
create() {
    local jar="$1"; shift
    local args=(--data-urlencode "csrf=$(csrf "$jar")")
    for pair in "$@"; do args+=(--data-urlencode "$pair"); done
    curl -s -b "$jar" -c "$jar" -X POST "$BASE_URL/api/goals/create.php" "${args[@]}"
}

# enter <jar> <goal id> <field=value>... — an entry on the goal's page
enter() {
    local jar="$1" id="$2"; shift 2
    local args=(--data-urlencode "csrf=$(csrf "$jar")" --data-urlencode "goal_id=$id")
    for pair in "$@"; do args+=(--data-urlencode "$pair"); done
    curl -s -b "$jar" -c "$jar" -X POST "$BASE_URL/api/goals/progress.php" "${args[@]}"
}

# board <jar> <active|completed> — the names on Actief or Afgerond, as the app's state sends them
board() {
    curl -s -b "$1" -c "$1" -X POST "$BASE_URL/api/app/state.php" --data-urlencode "csrf=$(csrf "$1")" \
        | php -r '$g = json_decode(stream_get_contents(STDIN), true)["data"]["goals"] ?? [];
                  echo implode(" | ", array_map(fn ($x) => $x["name"] . " " . $x[$argv[1] === "completed" ? "final_percent" : "percent"] . "%", $g[$argv[1]] ?? []));' "$2"
}

command -v curl >/dev/null || { echo "curl is required."; exit 2; }
command -v php  >/dev/null || { echo "php is required."; exit 2; }

echo
echo "Goals, end to end"
echo "  against $BASE_URL"
echo "------------------------------------------------------------------------"

[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/")" = "200" ] || { echo "  site not answering"; exit 2; }

for PAIR in "$A:$USER_A" "$S:$USER_S" "$T:$USER_T" "$B:$USER_B"; do
    JAR="${PAIR%%:*}"; NAME="${PAIR##*:}"
    curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/auth/register.php" \
        --data-urlencode "csrf=$(csrf "$JAR")" --data-urlencode "username=$NAME" \
        --data-urlencode "email=$NAME@ownify-test.invalid" --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirm=$PASSWORD" > /dev/null
    # A new account starts with its setup (includes/setup.php); finished at
    # once here, so the pages under test are the app's.
    curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/setup/finish.php" \
        --data-urlencode "csrf=$(csrf "$JAR")" > /dev/null
done
ok "four test accounts exist"

# ======================================================================
echo "== the wizard: three steps and a review, six categories, three types =="
PAGE="$(page "$A")"
same "three steps on the indicator" "$(printf '%s' "$PAGE" | grep -o 'data-wizard-bar="[0-9]"' | sort -u | tr '\n' ' ')" \
    'data-wizard-bar="1" data-wizard-bar="2" data-wizard-bar="3" '
has  "  and a review without a number" "$PAGE" 'data-wizard-step="review"'
same "six category bubbles" "$(printf '%s' "$PAGE" | grep -o 'data-wizard-category="[a-z]*"' | sort -u | tr '\n' ' ')" \
    'data-wizard-category="activity" data-wizard-category="body" data-wizard-category="nutrition" data-wizard-category="recovery" data-wizard-category="sleep" data-wizard-category="training" '
for LABEL in Slaap Voeding Training Activiteit Lichaam "Herstel &amp; balans"; do
    has "  named $LABEL" "$PAGE" ">$LABEL</button>"
done
same "three type cards" "$(printf '%s' "$PAGE" | grep -o 'data-wizard-type="[a-z]*"' | sort -u | tr '\n' ' ')" \
    'data-wizard-type="accumulate" data-wizard-type="milestone" data-wizard-type="streak" '
has  "a search field" "$PAGE" 'data-wizard-search'

# ======================================================================
echo "== what the wizard may not make =="
R="$(create "$A" "name=Oud type" "measurement=custom" "category=training" "type=habit" "unit=times" "target_value=10" "duration=month")"
same "an old type: refused" "$(printf '%s' "$R" | field ok)" "false"
R="$(create "$A" "name=Zonder meting" "type=milestone" "target_value=10" "duration=month")"
has  "no measurement: refused, saying so" "$R" "Kies wat je wilt bijhouden."
R="$(create "$A" "name=Gewicht optellen" "measurement=weight" "type=accumulate" "target_value=1000" "duration=month")"
has  "adding up a weight: refused" "$R" "kun je niet zo meten"
R="$(create "$A" "name=Al voorbij" "measurement=custom" "category=training" "type=accumulate" "unit=times" "target_value=10" \
    "start_date=$(day -10)" "end_date=$(day -1)")"
has  "an end date already behind you: refused" "$R" "De einddatum ligt al achter je"
same "  and nothing was made" "$(q "count:" "$USER_A")" "0"

# ======================================================================
echo "== Mijlpaal, kept by hand: the latest value, not the best =="
R="$(create "$A" "name=Bankdrukken 100 kg" "measurement=custom" "category=training" "type=milestone" \
    "unit=kg" "target_value=100" "baseline_value=80" "direction=increase" "duration=month")"
BENCH="$(printf '%s' "$R" | field goal_id)"
[ -n "$BENCH" ] && ok "made (goal $BENCH)" || bad "made" "$R"
same "  kept by hand" "$(q "tracking:$BENCH" "$USER_A")" "manual"
same "  the first goal is primary" "$(q "primary:" "$USER_A")" "$BENCH"
same "  0% before anything is logged" "$(q "percent:$BENCH" "$USER_A")" "0"

same "80 → 100 at 90: 50%" "$(enter "$A" "$BENCH" "value=90" | field percent)" "50"
same "back at 85: 25% — it follows the latest value" "$(enter "$A" "$BENCH" "value=85" | field percent)" "25"
same "  stored" "$(q "percent:$BENCH" "$USER_A")" "25"
same "  the board says 25% too" "$(board "$A" active)" "Bankdrukken 100 kg 25%"

curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/auth/logout.php" --data-urlencode "csrf=$(csrf "$A")" > /dev/null
curl -s -b "$A" -c "$A" -X POST "$BASE_URL/api/auth/login.php" --data-urlencode "csrf=$(csrf "$A")" \
    --data-urlencode "username=$USER_A" --data-urlencode "password=$PASSWORD" > /dev/null
same "after logging out and in: still 25%" "$(board "$A" active)" "Bankdrukken 100 kg 25%"

same "somebody else cannot log on it: 404" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$B" -c "$B" -X POST "$BASE_URL/api/goals/progress.php" \
        --data-urlencode "csrf=$(csrf "$B")" --data-urlencode "goal_id=$BENCH" --data-urlencode "value=100")" "404"
hasnt "  nor see it" "$(board "$B" active)" "Bankdrukken"

R="$(enter "$A" "$BENCH" "value=101")"
same "101: 100%, finished at once" "$(printf '%s' "$R" | field status)" "completed"
same "  final 100%" "$(q "final:$BENCH" "$USER_A")" "100"
same "  under Afgerond" "$(board "$A" completed)" "Bankdrukken 100 kg 100%"
has  "  and nothing more is taken" "$(enter "$A" "$BENCH" "value=50")" "al afgerond"
same "  its final percentage stays" "$(q "final:$BENCH" "$USER_A")" "100"

# ======================================================================
echo "== Mijlpaal, read from data: the baseline is measured, not asked =="
q "weigh:3:82.4" "$USER_A" > /dev/null
q "weigh:0:82" "$USER_A" > /dev/null
R="$(create "$A" "name=Naar 80 kg" "measurement=weight" "type=milestone" "target_value=80" "direction=decrease" "duration=quarter")"
WEIGHT="$(printf '%s' "$R" | field goal_id)"
[ -n "$WEIGHT" ] && ok "made (goal $WEIGHT)" || bad "made" "$R"
same "  read from the weigh-ins" "$(q "tracking:$WEIGHT" "$USER_A")" "auto"
same "  its baseline: today's 82 kg, from the start date" "$(q "baseline:$WEIGHT" "$USER_A")" "82 start_day"
has  "  nothing to log by hand" "$(enter "$A" "$WEIGHT" "value=81")" "leest zijn voortgang zelf"
q "weigh:0:81" "$USER_A" > /dev/null
same "a weigh-in of 81: 50%" "$(q "percent:$WEIGHT" "$USER_A")" "50"

# ======================================================================
echo "== Streak, every day, kept by hand =="
R="$(create "$S" "name=Elke dag lezen" "measurement=custom" "category=recovery" "type=streak" "variant=daily" \
    "unit=times" "target_value=1" "start_date=$(day -4)" "end_date=$(day 5)")"
READ="$(printf '%s' "$R" | field goal_id)"
[ -n "$READ" ] && ok "made, 10 days (goal $READ)" || bad "made" "$R"
for D in -4 -2 -1; do enter "$S" "$READ" "done=1" "date=$(day $D)" > /dev/null; done
same "ticked, not, ticked, ticked, and today still open" "$(q "days:$READ" "$USER_S")" "met,missed,met,met,pending"
same "  3 of the period's 10 days: 30%" "$(q "percent:$READ" "$USER_S")" "30"
same "today ticked: 40%" "$(enter "$S" "$READ" "done=1" | field percent)" "40"
same "un-ticked: 30% again" "$(enter "$S" "$READ" "done=0" | field percent)" "30"
enter "$S" "$READ" "done=1" > /dev/null
same "  every day so far is not the period: still running" "$(q "status:$READ" "$USER_S")" "active"
has  "a day before the start cannot be ticked" "$(enter "$S" "$READ" "done=1" "date=$(day -5)")" "buiten de periode"
has  "nor tomorrow" "$(enter "$S" "$READ" "done=1" "date=$(day 1)")" "buiten de periode"
q "refresh:$READ:6" "$USER_S" > /dev/null
same "the day after its end date: ended" "$(q "status:$READ" "$USER_S")" "ended"
same "  with 4 of 10 days: 40%, kept" "$(q "final:$READ" "$USER_S")" "40"

# ======================================================================
echo "== Streak, per week, read from workouts =="
for D in 9 8 1; do q "workout:$D" "$USER_S" > /dev/null; done
R="$(create "$S" "name=2 keer per week trainen" "measurement=workouts" "type=streak" "variant=period" \
    "frequency=2" "period=week" "start_date=$(day -10)" "end_date=$(day 10)")"
GYM="$(printf '%s' "$R" | field goal_id)"
[ -n "$GYM" ] && ok "made, three weeks from its start date (goal $GYM)" || bad "made" "$R"
same "  read from workouts, counted in trainingen" "$(q "tracking:$GYM" "$USER_S") $(q "unit:$GYM" "$USER_S")" "auto sessions"
same "week 1 met, week 2 still open at 1 of 2, week 3 to come" "$(q "periods:$GYM" "$USER_S")" "met 2/2,pending 1/2,pending 0/2"
same "  1 of 3 weeks: 33,33%" "$(q "percent:$GYM" "$USER_S")" "33.33"
q "workout:0:07:00" "$USER_S" > /dev/null
same "a second training this week: met before the week is over" "$(q "periods:$GYM" "$USER_S")" "met 2/2,met 2/2,pending 0/2"
same "  66,67%" "$(q "percent:$GYM" "$USER_S")" "66.67"

# ======================================================================
echo "== Optellen: from zero, only inside the period =="
q "metric:5:steps,50000" "$USER_T" > /dev/null
q "metric:2:steps,8000" "$USER_T" > /dev/null
q "metric:1:steps,11000" "$USER_T" > /dev/null
R="$(create "$T" "name=100.000 stappen" "measurement=steps" "type=accumulate" "target_value=100.000" \
    "start_date=$(day -2)" "duration=month")"
STEPS="$(printf '%s' "$R" | field goal_id)"
[ -n "$STEPS" ] && ok "made (goal $STEPS)" || bad "made" "$R"
same "  \"100.000\", as Dutch writes it, is a hundred thousand" "$(q "target:$STEPS" "$USER_T")" "100000"
same "  read from steps" "$(q "tracking:$STEPS" "$USER_T")" "auto"
q "metric:0:steps,9000" "$USER_T" > /dev/null
same "8.000 + 11.000 + 9.000 since the start: 28% — the 50.000 before it does not count" "$(q "percent:$STEPS" "$USER_T")" "28"

R="$(create "$T" "name=50 km hardlopen" "measurement=custom" "category=activity" "type=accumulate" \
    "unit=km" "target_value=50" "duration=month")"
RUN="$(printf '%s' "$R" | field goal_id)"
same "by hand: 20 km is 40%" "$(enter "$T" "$RUN" "value=20" | field percent)" "40"
same "  and 15 more is 70%" "$(enter "$T" "$RUN" "value=15" | field percent)" "70"
has  "  nothing is not an amount" "$(enter "$T" "$RUN" "value=0")" "Vul in hoeveel er bij komt"
R="$(enter "$T" "$RUN" "value=20")"
same "  55 of 50: 100%, finished at once" "$(printf '%s' "$R" | field percent) $(printf '%s' "$R" | field status)" "100 completed"
same "  the steps goal stays primary" "$(q "primary:" "$USER_T")" "$STEPS"

# ======================================================================
echo "== Afgerond: the highest final percentage first, then the newest =="
R="$(create "$S" "name=Klein doel" "measurement=custom" "category=training" "type=accumulate" "unit=times" "target_value=2" "duration=week")"
SMALL="$(printf '%s' "$R" | field goal_id)"
enter "$S" "$SMALL" "value=2" > /dev/null
same "100% before 40%" "$(board "$S" completed)" "Klein doel 100% | Elke dag lezen 40%"
same "  and Actief keeps only what runs" "$(board "$S" active)" "2 keer per week trainen 66%"

# ======================================================================
echo "== the one-time key: sent twice, one goal =="
KEY="goaltest-${STAMP//_/-}"
R1="$(create "$T" "name=Twee keer verstuurd" "measurement=custom" "category=sleep" "type=accumulate" "unit=times" "target_value=5" "duration=week" "create_token=$KEY")"
R2="$(create "$T" "name=Twee keer verstuurd" "measurement=custom" "category=sleep" "type=accumulate" "unit=times" "target_value=5" "duration=week" "create_token=$KEY")"
same "the same goal both times" "$(printf '%s' "$R2" | field goal_id)" "$(printf '%s' "$R1" | field goal_id)"
same "  three goals on the account, not four" "$(q "count:" "$USER_T")" "3"

# ======================================================================
LIMIT="$(php -r 'echo (require $argv[1])["limits"]["active"];' "$ROOT/config/goals.php")"
echo "== the board holds $LIMIT active goals (config/goals.php), and no more =="
for N in $(seq 1 "$LIMIT"); do
    R="$(create "$B" "name=Doel $N" "measurement=custom" "category=training" "type=accumulate" "unit=times" "target_value=10" "duration=month")"
    has "  goal $N of $LIMIT fits" "$R" '"ok":true'
done
R="$(create "$B" "name=Een te veel" "measurement=custom" "category=training" "type=accumulate" "unit=times" "target_value=10" "duration=month")"
has "  one more is refused" "$R" '"ok":false'
has "  saying how many fit" "$R" "Je hebt al $LIMIT actieve doelen"
has "  and the board says it is full, with the same number" "$(page "$B")" "Je $LIMIT doelplekken zijn bezet"
hasnt "another account's goals are not on this board" "$(board "$B" active)" "Bankdrukken"

echo
if [ -n "${KEEP:-}" ]; then
    echo "  Kept $USER_A, $USER_S, $USER_T and $USER_B."
else
    for NAME in "$USER_A" "$USER_S" "$USER_T" "$USER_B"; do
        php "$ROOT/tools/goal-verify.php" --user="$NAME" --cleanup > /dev/null
    done
fi

echo "------------------------------------------------------------------------"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
