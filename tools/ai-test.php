<?php
/**
 * Ownify AI, tested end to end over HTTP — without Google and without a key.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/ai-test.php
 *
 * Starts the app on PHP's built-in server with GEMINI_API_BASE pointing at a
 * stand-in (tools/ai-fake-gemini.php) that answers what this test queues and
 * writes down what Ownify sent it. Then, through the real endpoints, the real
 * authentication and the database named by DB_NAME (with migration 015):
 *
 *   auth         no session, a token that is nobody's, a sync token: refused;
 *                the app's account token and the website's session: allowed
 *   consent      nothing is sent to Gemini without it; yes persists; no, or
 *                yes to older terms, stops it again; the Privacy switch is
 *                the same answer
 *   chat         the answer comes back and both messages are stored; a
 *                reload shows them; a follow-up carries the conversation,
 *                each earlier answer with its thought signature;
 *                the real data goes along — the same few blocks every time,
 *                the rest only when the question is about it
 *   isolation    one account cannot read, write to, delete or confirm in
 *                another's conversations, or have the assistant touch its goals
 *   limit        counted per person, refused past the limit without asking
 *                Gemini, fresh the next day
 *   failures     429 per minute and per day, 500, no JSON, a refusal, a
 *                timeout, no key: each a sentence, never Gemini's text; the
 *                question is given back to the day's count
 *   tools        look-ups carried out and sent back; wrong arguments and
 *                unknown tools refused; changes only proposed, done only once
 *                confirmed — with the button or with "ja" — and only for the
 *                account whose they are
 *
 * Accounts are named `ai_…` and removed at the end, with everything hanging
 * off them. Never point it at a live database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 015 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/ai/config.php';

if (!db_available() || !ai_installed()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 015.\n");
    exit(2);
}

/* ======================================================================
   PLUMBING
   ====================================================================== */

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        printf("  PASS  %s\n", $label);
        return;
    }

    $fail++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : "\n          " . $detail);
}

function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** @return resource */
function serve(int $port, array $args, array $env)
{
    global $root;

    $server = proc_open(['php', '-S', '127.0.0.1:' . $port, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);

    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(50_000);
    }

    return $server;
}

/**
 * One HTTP request. $o: form (array), json (array), bearer, jar, get (bool), base.
 *
 * @return array{status: int, body: ?array, raw: string}
 */
function http(string $path, array $o = []): array
{
    global $base;

    $handle  = curl_init(($o['base'] ?? $base) . $path);
    $headers = [];

    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false]);

    if (($o['get'] ?? false) !== true) {
        curl_setopt($handle, CURLOPT_POST, true);

        if (isset($o['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($o['json']));
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($o['form'] ?? []));
        }
    }

    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (isset($o['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $o['jar']);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $o['jar']);
    }

    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);

    $body = json_decode($raw, true);

    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 400);
}

function csrf(string $jar): string
{
    $page = http('/', ['get' => true, 'jar' => $jar]);
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);

    return $m[1] ?? '';
}

/* ---------------------------------------------------- the stand-in Gemini */

$fakeDir = sys_get_temp_dir() . '/ownify-ai-test-' . bin2hex(random_bytes(4));
mkdir($fakeDir);

/** Queues answers for the stand-in, in order. */
function gemini(array ...$answers): void
{
    global $fakeDir;
    file_put_contents($fakeDir . '/queue.json', json_encode($answers));
}

/** What the stand-in was sent, oldest first. */
function sent(): array
{
    global $fakeDir;
    $file = $fakeDir . '/requests.jsonl';

    return is_file($file)
        ? array_map(static fn (string $line) => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($file))))
        : [];
}

/** What the stand-in was sent, as the JSON it received: an empty object is still {}. */
function sent_raw(int $n): string
{
    global $fakeDir;
    $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($fakeDir . '/requests.jsonl'))));

    return (string) ($lines[$n] ?? '');
}

/** Whether a message came back without a proposal on it. */
function no_proposal(array $r): bool
{
    $message = $r['body']['messages'][1] ?? null;

    return is_array($message) && array_key_exists('action', $message) && $message['action'] === null;
}

function forget_sent(): void
{
    global $fakeDir;
    @unlink($fakeDir . '/requests.jsonl');
}

/** A text answer, with the thought signature Gemini 3 puts on it. */
function says(string $text, array $usage = ['promptTokenCount' => 1500, 'candidatesTokenCount' => 120, 'thoughtsTokenCount' => 30]): array
{
    return ['status' => 200, 'body' => [
        'candidates'    => [['content' => ['role' => 'model', 'parts' => [['text' => $text, 'thoughtSignature' => 'YW50d29vcmQ']]], 'finishReason' => 'STOP']],
        'usageMetadata' => $usage,
    ]];
}

/** A function call, with a thought signature and empty-object args where given, as Gemini 3 sends them. */
function calls(string $name, array|stdClass $args, string $id = 'call-1'): array
{
    return ['status' => 200, 'body' => [
        'candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => $name, 'args' => $args, 'id' => $id], 'thoughtSignature' => 'c2lnbmF0dXJl'],
        ]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1400, 'candidatesTokenCount' => 20],
    ]];
}

function quota(bool $daily): array
{
    return ['status' => 429, 'body' => ['error' => [
        'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota.',
        'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [[
                'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                'quotaId'     => $daily ? 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' : 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier',
            ]]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '41s'],
        ],
    ]]];
}

/* --------------------------------------------------------- the accounts */

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'ai-test-password';
$made     = [];

function app_account(string $who): array
{
    global $run, $made, $password;

    $username = 'ai_' . $run . '_' . $who;
    $made[]   = $username;

    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => 'AI test phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);

    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }

    return ['username' => $username, 'token' => (string) $r['body']['token'], 'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

function paired_phone(string $accountToken): string
{
    $code = http('/api/integrations/pairing-code.php', ['bearer' => $accountToken, 'form' => ['provider' => 'google_health_connect']]);
    $pair = http('/api/integrations/pair.php', ['json' => [
        'code' => $code['body']['code'] ?? '', 'label' => 'Paired phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);

    return (string) ($pair['body']['token'] ?? '');
}

function chat(array $who, string $message, ?int $conversation = null): array
{
    $form = ['message' => $message];
    if ($conversation !== null) {
        $form['conversation_id'] = (string) $conversation;
    }

    return http('/api/ai/chat.php', ['bearer' => $who['token'], 'form' => $form]);
}

function used_today(int $userId): int
{
    return (int) db_value('SELECT messages FROM ai_usage WHERE user_id = ? AND usage_date = CURDATE()', [$userId]);
}

/* ---------------------------------------------------------- the servers */

$fakePort = free_port();
$fake     = serve($fakePort, [$root . '/tools/ai-fake-gemini.php'], ['FAKE_GEMINI_DIR' => $fakeDir] + getenv());

$appEnv = [
    'GEMINI_API_KEY'         => 'test-key-not-real',
    'GEMINI_API_BASE'        => 'http://127.0.0.1:' . $fakePort,
    'GEMINI_MODEL'           => 'gemini-3.8-flash',
    'GEMINI_TIMEOUT'         => '2',
    'AI_DAILY_MESSAGE_LIMIT' => '20',
    'AI_GLOBAL_DAILY_LIMIT'  => '100000',
    /* The temporary diagnostic, on for this run (checked at the end). */
    'AI_DIAGNOSTIC_LOG'      => $fakeDir . '/diagnostic.log',
] + getenv();

$port   = free_port();
$base   = 'http://127.0.0.1:' . $port;
$server = serve($port, ['-t', $root], $appEnv);

/* A second app with no key at all. */
$noKeyEnv = $appEnv;
unset($noKeyEnv['GEMINI_API_KEY']);
$noKeyPort   = free_port();
$noKeyServer = serve($noKeyPort, ['-t', $root], $noKeyEnv);

db_run("DELETE FROM ai_service_state WHERE name = 'quota_block'");

$unknown = str_repeat('ab', 32);

try {

    $sanne = app_account('sanne');
    $bram  = app_account('bram');
    $sync  = paired_phone($sanne['token']);

    /* Some of Sanne's own data, through the website's endpoints. */
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    http('/api/auth/login.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar), 'username' => $sanne['username'], 'password' => $password]]);
    $token = csrf($jar);

    foreach ([3, 2, 1] as $daysAgo) {
        $end = new DateTimeImmutable("today -$daysAgo days 07:05");
        http('/api/health/sleep.php', ['jar' => $jar, 'form' => [
            'csrf' => $token,
            'started_at' => $end->modify('-7 hours -35 minutes')->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'deep_minutes' => '81', 'rem_minutes' => '104', 'light_minutes' => '230', 'awake_minutes' => '40', 'efficiency_pct' => '91',
        ]]);
    }
    http('/api/health/training.php', ['jar' => $jar, 'form' => [
        'csrf' => $token, 'activity_type' => 'running', 'started_at' => date('Y-m-d 18:00:00', strtotime('-2 days')),
        'duration_minutes' => '31', 'distance_m' => '5120', 'avg_hr' => '152',
    ]]);
    http('/api/health/rating.php', ['bearer' => $sanne['token'], 'form' => ['rating' => '7']]);
    http('/api/profile/update.php', ['bearer' => $sanne['token'], 'form' => ['first_name' => 'Sanne']]);

    /* ==================================================================
       AUTH
       ================================================================== */

    section('only a signed-in account reaches the assistant');
    foreach (['state', 'chat', 'consent', 'action', 'delete'] as $endpoint) {
        $path = '/api/ai/' . $endpoint . '.php';
        check("$endpoint: no session, no CSRF: 419", http($path, ['form' => ['message' => 'x']])['status'] === 419);
        check("$endpoint: a token that is nobody's: 401", http($path, ['bearer' => $unknown, 'form' => ['message' => 'x']])['status'] === 401);
        check("$endpoint: a sync token: 403", http($path, ['bearer' => $sync, 'form' => ['message' => 'x']])['status'] === 403);
    }
    check('GET: 405', http('/api/ai/state.php', ['get' => true, 'bearer' => $sanne['token']])['status'] === 405);

    $state = http('/api/ai/state.php', ['bearer' => $sanne['token']]);
    check('the app\'s account token: 200, never asked yet, available, 20 messages today',
        $state['status'] === 200 && ($state['body']['consent'] ?? null) === 'unknown'
        && ($state['body']['available'] ?? null) === true
        && ($state['body']['usage'] ?? null) === ['used' => 0, 'limit' => 20, 'remaining' => 20], summary($state));
    check('the state says she has data', ($state['body']['has_data'] ?? null) === true);
    check('the website\'s session and CSRF token: 200', http('/api/ai/state.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar)]])['status'] === 200);
    check('no key anywhere in what the apps get', !str_contains($state['raw'], 'test-key-not-real')
        && !str_contains(http('/', ['get' => true, 'jar' => $jar])['raw'], 'test-key-not-real')
        && !str_contains(http('/api/app/state.php', ['bearer' => $sanne['token']])['raw'], 'test-key-not-real'));

    /* ==================================================================
       CONSENT
       ================================================================== */

    section('consent: nothing goes to Gemini without it');
    forget_sent();
    $r = chat($sanne, 'Hoe heb ik geslapen?');
    check('asked before saying yes: 403 consent, with the sentence', $r['status'] === 403 && ($r['body']['code'] ?? null) === 'consent'
        && str_contains((string) ($r['body']['error'] ?? ''), 'Gemini'), summary($r));
    check('and Gemini was not asked', sent() === []);
    check('nothing was stored', (int) db_value('SELECT COUNT(*) FROM ai_messages WHERE user_id = ?', [$sanne['id']]) === 0);

    $r = http('/api/ai/consent.php', ['bearer' => $sanne['token'], 'form' => ['decision' => 'decline']]);
    check('saying no: 200, declined', $r['status'] === 200 && ($r['body']['consent'] ?? null) === 'declined', summary($r));
    check('declined: still no chat, still nothing sent', chat($sanne, 'Hallo')['status'] === 403 && sent() === []);
    check('declined: the state says so, with no conversations',
        (http('/api/ai/state.php', ['bearer' => $sanne['token']])['body']['consent'] ?? null) === 'declined');
    check('a decision that is neither: 422', http('/api/ai/consent.php', ['bearer' => $sanne['token'], 'form' => ['decision' => 'maybe']])['status'] === 422);

    $r = http('/api/ai/consent.php', ['bearer' => $sanne['token'], 'form' => ['decision' => 'accept']]);
    check('saying yes: 200, accepted', $r['status'] === 200 && ($r['body']['consent'] ?? null) === 'accepted', summary($r));
    check('stored with the wording it was given to', db_value('SELECT ai_consent_version FROM user_profiles WHERE user_id = ?', [$sanne['id']]) === ai_config()['consent_version']);
    check('it lasts: a new request still finds it', (http('/api/ai/state.php', ['bearer' => $sanne['token']])['body']['consent'] ?? null) === 'accepted');

    /* ==================================================================
       CHAT
       ================================================================== */

    section('a question, its answer, and what went to Gemini');
    forget_sent();
    gemini(says("Je sliep de afgelopen drie nachten **gemiddeld 6 u 55 min**.\n\nWat opvalt:\n- je bedtijd was steeds rond 23:30\n- je efficiëntie lag rond 91%"));
    $r = chat($sanne, 'Hoe heb ik de afgelopen nachten geslapen?');
    check('200 with the answer, and the question and answer back', $r['status'] === 200
        && ($r['body']['messages'][0]['role'] ?? null) === 'user' && ($r['body']['messages'][1]['role'] ?? null) === 'assistant'
        && str_contains((string) ($r['body']['messages'][1]['text'] ?? ''), '6 u 55 min'), summary($r));
    $conversation = (int) ($r['body']['conversation']['id'] ?? 0);
    check('a conversation, named after the question', $conversation > 0
        && ($r['body']['conversation']['title'] ?? null) === 'Hoe heb ik de afgelopen nachten geslapen?');
    check('the answer in blocks: a paragraph with bold, a heading-like line, bullets',
        ($r['body']['messages'][1]['blocks'][0]['spans'][1] ?? null) === ['t' => 'gemiddeld 6 u 55 min', 'b' => true]
        && ($r['body']['messages'][1]['blocks'][2]['type'] ?? null) === 'ul'
        && count($r['body']['messages'][1]['blocks'][2]['items'] ?? []) === 2, json_encode($r['body']['messages'][1]['blocks'] ?? null));
    check('one of today\'s twenty used', ($r['body']['usage'] ?? null) === ['used' => 1, 'limit' => 20, 'remaining' => 19]);
    check('both stored, as hers', (int) db_value('SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ? AND user_id = ?', [$conversation, $sanne['id']]) === 2);

    $request = sent()[0] ?? [];
    $system  = (string) ($request['body']['systemInstruction']['parts'][0]['text'] ?? '');
    check('sent to the model named in the config, with the key in a header, not the URL',
        str_contains((string) ($request['path'] ?? ''), '/v1beta/models/gemini-3.8-flash:generateContent')
        && ($request['key'] ?? null) === 'test-key-not-real' && !str_contains((string) ($request['path'] ?? ''), 'key='), json_encode($request['path'] ?? null));
    check('the instruction: who the assistant is, and its rules', str_contains($system, "You are Ownify's personal health assistant.")
        && str_contains($system, 'You must distinguish observed data from interpretations.')
        && str_contains($system, 'You must not fabricate missing data.'));
    $goalLimit = (int) ((require $root . '/config/goals.php')['limits']['active']);
    check("the goal limit it is told is the board's own ($goalLimit, config/goals.php)",
        str_contains($system, "At most $goalLimit goals are active at a time") && !str_contains($system, '{goal_limit}'));
    check('her real data went along: her name and her nights, as recorded', str_contains($system, '"first_name":"Sanne"')
        && str_contains($system, '"asleep_minutes":455') && str_contains($system, '"deep_minutes":81'));
    check('only what fits: a sleep question brings no days of nutrition or measurements',
        !str_contains($system, '"per_day":{"nutrition_rating"') && !str_contains($system, '"body":'));
    check('what is unknown is said to be unknown', str_contains($system, '"unknown":["age","gender","height_cm","weight_kg","activity_level"]'));
    check('the question is the last thing sent, as the user', ($request['body']['contents'][count($request['body']['contents'] ?? []) - 1] ?? null)
        === ['role' => 'user', 'parts' => [['text' => 'Hoe heb ik de afgelopen nachten geslapen?']]]);
    check('the tools are declared, the thinking kept low', count($request['body']['tools'][0]['functionDeclarations'] ?? []) === 9
        && ($request['body']['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'low'
        && ($request['body']['toolConfig']['functionCallingConfig']['mode'] ?? null) === 'AUTO');
    check('no other account\'s name anywhere in it', !str_contains(json_encode($request), $bram['username']));

    section('the conversation lasts, and carries on');
    $state = http('/api/ai/state.php', ['bearer' => $sanne['token']]);
    check('reopened: the last conversation, with both messages', ($state['body']['conversation']['id'] ?? null) === $conversation
        && count($state['body']['messages'] ?? []) === 2 && ($state['body']['conversations'][0]['id'] ?? null) === $conversation, summary($state));
    check('the website sees the same conversation', (http('/api/ai/state.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar)]])['body']['conversation']['id'] ?? null) === $conversation);

    forget_sent();
    gemini(says('Dat ging om je slaap: die was stabiel.'));
    $r = chat($sanne, 'En hoe zit het daarmee vergeleken met vorige week?', $conversation);
    $contents = sent()[0]['body']['contents'] ?? [];
    check('a follow-up: 200, in the same conversation', $r['status'] === 200 && ($r['body']['conversation']['id'] ?? null) === $conversation, summary($r));
    check('Gemini got what was said before: question, answer, question', count($contents) === 3
        && ($contents[0]['role'] ?? null) === 'user' && ($contents[1]['role'] ?? null) === 'model'
        && str_contains((string) ($contents[1]['parts'][0]['text'] ?? ''), '6 u 55 min'), json_encode($contents));
    check('the earlier answer went back as Gemini sent it, thought signature and all (a model turn without one is refused)',
        ($contents[1]['parts'][0]['thoughtSignature'] ?? null) === 'YW50d29vcmQ' && count($contents[1]['parts']) === 1, json_encode($contents[1] ?? null));
    check('"daarmee" points back, so the sleep data came along again',
        str_contains((string) (sent()[0]['body']['systemInstruction']['parts'][0]['text'] ?? ''), '"asleep_minutes":455'));

    forget_sent();
    gemini(says('Nieuw gesprek.'));
    $r = chat($sanne, 'Hoeveel eiwit heb ik nodig?');
    check('without a conversation id: a new conversation', $r['status'] === 200 && ($r['body']['conversation']['id'] ?? 0) !== $conversation);
    $second = (int) ($r['body']['conversation']['id'] ?? 0);
    $system = (string) (sent()[0]['body']['systemInstruction']['parts'][0]['text'] ?? '');
    check('a nutrition question brings nutrition, and no nights', str_contains($system, '"nutrition":{') && !str_contains($system, '"asleep_minutes"'));
    check('and nothing from the other conversation', count(sent()[0]['body']['contents'] ?? []) === 1);
    check('state with new=1: no conversation open, both listed', ($s = http('/api/ai/state.php', ['bearer' => $sanne['token'], 'form' => ['new' => '1']])['body'])['conversation'] === null
        && count($s['conversations'] ?? []) === 2);

    section('what is not a question');
    check('empty: 422', chat($sanne, "  \n ")['status'] === 422);
    check('too long: 422', chat($sanne, str_repeat('a', 2001))['status'] === 422);
    check('a conversation that does not exist: 404', chat($sanne, 'Hoi', 999999999)['status'] === 404);

    /* ==================================================================
       ISOLATION
       ================================================================== */

    section('one account, its own conversations only');
    http('/api/ai/consent.php', ['bearer' => $bram['token'], 'form' => ['decision' => 'accept']]);
    forget_sent();
    $r = http('/api/ai/state.php', ['bearer' => $bram['token'], 'form' => ['conversation_id' => (string) $conversation]]);
    check('Bram asks for Sanne\'s conversation: 404, nothing of it', $r['status'] === 404 && !str_contains($r['raw'], '6 u 55 min'), summary($r));
    check('Bram writes into Sanne\'s conversation: 404, Gemini not asked', chat($bram, 'Wat zei je tegen haar?', $conversation)['status'] === 404 && sent() === []);
    check('Bram deletes Sanne\'s conversation: 404', http('/api/ai/delete.php', ['bearer' => $bram['token'], 'form' => ['conversation_id' => (string) $conversation]])['status'] === 404);
    check('Bram\'s own state: none of hers', ($s = http('/api/ai/state.php', ['bearer' => $bram['token']])['body'])['conversations'] === [] && $s['messages'] === []);
    check('hers is untouched', (int) db_value('SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ?', [$conversation]) === 4);

    forget_sent();
    gemini(says('Je hebt nog geen gegevens.'));
    chat($bram, 'Hoe heb ik geslapen?');
    $system = (string) (sent()[0]['body']['systemInstruction']['parts'][0]['text'] ?? '');
    check('Bram\'s question carries Bram\'s data, not hers', !str_contains($system, 'Sanne') && !str_contains($system, '"asleep_minutes":455')
        && str_contains($system, 'No nights recorded'));

    /* ==================================================================
       TOOLS AND PROPOSALS
       ================================================================== */

    section('look-ups: carried out here, sent back as Gemini asked');
    forget_sent();
    gemini(calls('get_sleep_summary', ['days' => 30]), says('Over 30 dagen: drie nachten.'));
    $r = chat($sanne, 'Laat mijn slaap van de afgelopen maand zien', $conversation);
    $requests = sent();
    check('two requests, one answer', $r['status'] === 200 && count($requests) === 2
        && ($r['body']['messages'][1]['text'] ?? null) === 'Over 30 dagen: drie nachten.', summary($r));
    $second2 = $requests[1]['body']['contents'] ?? [];
    $last    = $second2[count($second2) - 1] ?? [];
    check('the call went back as Gemini sent it, thought signature and all',
        ($second2[count($second2) - 2]['parts'][0]['thoughtSignature'] ?? null) === 'c2lnbmF0dXJl');
    check('then the result: her nights over 30 days, with the call\'s id',
        ($last['parts'][0]['functionResponse']['name'] ?? null) === 'get_sleep_summary'
        && ($last['parts'][0]['functionResponse']['id'] ?? null) === 'call-1'
        && str_contains(json_encode($last), '"asleep_minutes":455')
        && str_contains((string) ($last['parts'][0]['functionResponse']['response']['result']['days_covered'] ?? ''), date('Y-m-d')), json_encode($last));
    check('the look-up is noted on the answer', str_contains((string) db_value('SELECT meta_json FROM ai_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$sanne['id']]), 'get_sleep_summary'));

    forget_sent();
    gemini(calls('get_sleep_summary', ['days' => 500]), says('Dat kon niet.'));
    chat($sanne, 'Slaap van 500 dagen', $conversation);
    check('a look-up out of range is refused, in words Gemini can use',
        str_contains(json_encode(sent()[1]['body']['contents'] ?? []), 'days must be a whole number from 1 to 90'));

    forget_sent();
    gemini(calls('drop_tables', new stdClass()), says('Dat kan ik niet.'));
    chat($sanne, 'Doe iets geks', $conversation);
    check('a tool that does not exist: refused; empty args stay an object', str_contains(sent_raw(1), 'Unknown tool')
        && str_contains(sent_raw(1), '"args":{}'), sent_raw(1));

    forget_sent();
    gemini(calls('get_goals', ['include_completed' => false]), calls('get_recent_activity', ['days' => 7], 'call-2'), says('Nog een ronde.'));
    chat($sanne, 'Hoe gaat het met mijn doelen en stappen?', $conversation);
    $third = sent()[2]['body'] ?? [];
    check('three rounds at most: the last one must answer in words', count(sent()) === 3
        && ($third['toolConfig']['functionCallingConfig']['mode'] ?? null) === 'NONE');

    section('a change is proposed, not made');
    $goalsBefore = (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$sanne['id']]);
    forget_sent();
    gemini(calls('create_goal', [
        'name' => '5 km onder 25 minuten', 'type' => 'milestone', 'category' => 'performance',
        'source_kind' => 'manual', 'target_value' => 25, 'target_unit' => 'min', 'direction' => 'decrease', 'duration' => 'halfyear',
    ]), says('Ik kan een doel toevoegen: 5 km in minder dan 25 minuten, binnen een half jaar. Zal ik het toevoegen?'));
    $r = chat($sanne, 'Maak een doel om 5 km onder de 25 minuten te lopen', $conversation);
    $proposal = $r['body']['messages'][1] ?? [];
    check('the answer carries the proposal, waiting', ($proposal['action']['state'] ?? null) === 'pending'
        && ($proposal['action']['title'] ?? null) === 'Nieuw doel: 5 km onder 25 minuten'
        && str_contains((string) ($proposal['action']['summary'] ?? ''), 'Mijlpaal · 25 min · lager is beter · zelf bijhouden · looptijd: half jaar')
        && ($proposal['action']['confirm'] ?? null) === 'Doel toevoegen', json_encode($proposal['action'] ?? null));
    check('Gemini was told nothing was created', str_contains(json_encode(sent()[1]['body']['contents'] ?? []), 'Nothing has been created'));
    check('and nothing was: no new goal', (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$sanne['id']]) === $goalsBefore);

    check('Bram cannot confirm it: 404, still no goal', http('/api/ai/action.php', ['bearer' => $bram['token'], 'form' => ['message_id' => (string) $proposal['id'], 'decision' => 'confirm']])['status'] === 404
        && (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$sanne['id']]) === $goalsBefore);
    check('nor decline it', http('/api/ai/action.php', ['bearer' => $bram['token'], 'form' => ['message_id' => (string) $proposal['id'], 'decision' => 'decline']])['status'] === 404);

    $r = http('/api/ai/action.php', ['bearer' => $sanne['token'], 'form' => ['message_id' => (string) $proposal['id'], 'decision' => 'confirm']]);
    $goal = db_one("SELECT id, name, goal_type, target_value, target_unit, direction, source_kind FROM goals WHERE user_id = ? AND name = '5 km onder 25 minuten'", [$sanne['id']]);
    check('Sanne confirms: the goal is there, as the wizard would have made it', $r['status'] === 200 && $goal !== null
        && $goal['goal_type'] === 'milestone' && (float) $goal['target_value'] === 25.0 && $goal['target_unit'] === 'min'
        && $goal['direction'] === 'decrease' && $goal['source_kind'] === 'manual', summary($r));
    check('the proposal says done, and Ownify says so in a note', ($r['body']['messages'][0]['action']['state'] ?? null) === 'done'
        && ($r['body']['messages'][1]['role'] ?? null) === 'system'
        && str_contains((string) ($r['body']['messages'][1]['text'] ?? ''), 'toegevoegd'), json_encode($r['body']['messages'] ?? null));
    check('confirming twice does it once: 409', http('/api/ai/action.php', ['bearer' => $sanne['token'], 'form' => ['message_id' => (string) $proposal['id'], 'decision' => 'confirm']])['status'] === 409
        && (int) db_value("SELECT COUNT(*) FROM goals WHERE user_id = ? AND name = '5 km onder 25 minuten'", [$sanne['id']]) === 1);
    check('the goals page shows it', str_contains(json_encode(http('/api/app/state.php', ['bearer' => $sanne['token']])['body']['data']['goals'] ?? []), '5 km onder 25 minuten'));

    forget_sent();
    gemini(calls('create_goal', ['name' => 'Afvallen', 'type' => 'milestone', 'category' => 'weight', 'source_kind' => 'measurement', 'source_key' => 'weight', 'target_value' => 70]),
        says('Wil je dat een lager gewicht beter is?'));
    $r = chat($sanne, 'Maak een doel: 70 kilo', $conversation);
    check('a proposal the wizard would refuse is not prepared; Gemini hears why',
        no_proposal($r) && str_contains(sent_raw(1), 'Kies of een hoger of een lager resultaat beter is'), summary($r));

    $bramGoal = (int) db_value('SELECT id FROM goals WHERE user_id = ? LIMIT 1', [$sanne['id']]);
    forget_sent();
    gemini(calls('update_goal', ['goal_id' => $bramGoal, 'action' => 'pause']), says('Dat doel ken ik niet.'));
    $r = chat($bram, 'Pauzeer doel ' . $bramGoal);
    check('Bram\'s assistant cannot even propose a change to Sanne\'s goal',
        no_proposal($r) && str_contains(sent_raw(1), 'The user has no goal with this goal_id')
        && db_value('SELECT status FROM goals WHERE id = ?', [$bramGoal]) === 'active');

    section('"ja" and "nee" answer a proposal without asking Gemini');
    forget_sent();
    gemini(calls('update_goal', ['goal_id' => (int) $goal['id'], 'action' => 'pause']), says('Zal ik je 5 km-doel pauzeren?'));
    $r = chat($sanne, 'Pauzeer mijn 5 km-doel even', $conversation);
    check('proposed: pause, still active', ($r['body']['messages'][1]['action']['state'] ?? null) === 'pending'
        && db_value('SELECT status FROM goals WHERE id = ?', [$goal['id']]) === 'active', json_encode($r['body']['messages'][1]['action'] ?? null));
    $used = used_today($sanne['id']);
    forget_sent();
    $r = chat($sanne, 'Ja, graag!', $conversation);
    check('"Ja, graag!": paused, a note, and no request to Gemini', $r['status'] === 200 && sent() === []
        && db_value('SELECT status FROM goals WHERE id = ?', [$goal['id']]) === 'paused'
        && ($r['body']['messages'][2]['role'] ?? null) === 'system' && str_contains((string) ($r['body']['messages'][2]['text'] ?? ''), 'gepauzeerd'), summary($r));
    check('and it did not count as a message', used_today($sanne['id']) === $used);

    gemini(calls('update_goal', ['goal_id' => (int) $goal['id'], 'action' => 'resume']), says('Zal ik het hervatten?'));
    chat($sanne, 'Hervat het maar weer', $conversation);
    $r = chat($sanne, 'nee', $conversation);
    check('"nee": declined, the goal stays paused', ($r['body']['messages'][1]['action']['state'] ?? null) === 'declined'
        && db_value('SELECT status FROM goals WHERE id = ?', [$goal['id']]) === 'paused', summary($r));

    gemini(calls('update_goal', ['goal_id' => (int) $goal['id'], 'action' => 'resume']), says('Zal ik het hervatten?'), says('Prima, dan niet.'));
    $r = chat($sanne, 'Hervat het', $conversation);
    $waiting = (int) ($r['body']['messages'][1]['id'] ?? 0);
    chat($sanne, 'Eigenlijk wil ik eerst iets anders weten: hoe was mijn week?', $conversation);
    check('asked about something else: the proposal lapses', db_value('SELECT action_state FROM ai_messages WHERE id = ?', [$waiting]) === 'expired'
        && http('/api/ai/action.php', ['bearer' => $sanne['token'], 'form' => ['message_id' => (string) $waiting, 'decision' => 'confirm']])['status'] === 409);

    /* ==================================================================
       LIMIT
       ================================================================== */

    section('twenty questions a day each (AI_DAILY_MESSAGE_LIMIT), counted on the server');
    $used = used_today($sanne['id']);
    check('every question Gemini answered counted once; a "ja" or "nee" did not', $used === 13, (string) $used);
    db_run('UPDATE ai_usage SET messages = 19 WHERE user_id = ? AND usage_date = CURDATE()', [$sanne['id']]);
    gemini(says('De laatste van vandaag.'));
    $r = chat($sanne, 'Nog een vraag', $conversation);
    check('the twentieth: answered, none left', $r['status'] === 200 && ($r['body']['usage'] ?? null) === ['used' => 20, 'limit' => 20, 'remaining' => 0], summary($r));
    forget_sent();
    $r = chat($sanne, 'Nog eentje?', $conversation);
    check('the twenty-first: 429, today\'s sentence, Gemini not asked', $r['status'] === 429 && ($r['body']['code'] ?? null) === 'limit'
        && str_contains((string) ($r['body']['error'] ?? ''), 'Morgen') && sent() === []
        && ($r['body']['usage']['remaining'] ?? null) === 0, summary($r));
    check('the refusal is counted as such', (int) db_value('SELECT limit_hits FROM ai_usage WHERE user_id = ? AND usage_date = CURDATE()', [$sanne['id']]) >= 1);
    check('Bram\'s count is his own', (http('/api/ai/state.php', ['bearer' => $bram['token']])['body']['usage']['used'] ?? null) === 2);

    db_run('UPDATE ai_usage SET usage_date = CURDATE() - INTERVAL 1 DAY WHERE user_id = ? AND usage_date = CURDATE()', [$sanne['id']]);
    gemini(says('Een nieuwe dag.'));
    $r = chat($sanne, 'Goedemorgen', $conversation);
    check('the next day: fresh again', $r['status'] === 200 && ($r['body']['usage'] ?? null) === ['used' => 1, 'limit' => 20, 'remaining' => 19], summary($r));

    /* ==================================================================
       WHEN GEMINI FAILS
       ================================================================== */

    section('when Gemini cannot answer');
    $messages = (int) db_value('SELECT COUNT(*) FROM ai_messages WHERE user_id = ?', [$sanne['id']]);
    $used     = used_today($sanne['id']);

    gemini(quota(false));
    $r = chat($sanne, 'Hoe was mijn week?', $conversation);
    check('free quota used up (per minute): 503 quota, the free-limit sentence', $r['status'] === 503 && ($r['body']['code'] ?? null) === 'quota'
        && str_contains((string) ($r['body']['error'] ?? ''), 'gratis gebruikslimiet'), summary($r));
    check('Gemini\'s own text is not in it', !str_contains($r['raw'], 'exceeded your current quota') && !str_contains($r['raw'], 'RESOURCE_EXHAUSTED'));
    check('the question is given back, nothing stored', used_today($sanne['id']) === $used
        && (int) db_value('SELECT COUNT(*) FROM ai_messages WHERE user_id = ?', [$sanne['id']]) === $messages);
    $until = db_value("SELECT until FROM ai_service_state WHERE name = 'quota_block'");
    check('Gemini is left alone for its retry delay', $until !== null && strtotime((string) $until) > time() + 30 && strtotime((string) $until) < time() + 60);
    forget_sent();
    check('meanwhile nobody asks it: 503 quota for Bram too, no request', chat($bram, 'Hallo')['status'] === 503 && sent() === []);
    check('and the sheet says why', (($s = http('/api/ai/state.php', ['bearer' => $bram['token']])['body'])['available'] ?? null) === false
        && ($s['unavailable'] ?? null) === 'quota');
    db_run("DELETE FROM ai_service_state WHERE name = 'quota_block'");

    gemini(quota(true));
    chat($sanne, 'Hoe was mijn week?', $conversation);
    $until = new DateTimeImmutable((string) db_value("SELECT until FROM ai_service_state WHERE name = 'quota_block'"));
    $pacific = $until->setTimezone(new DateTimeZone('America/Los_Angeles'));
    check('used up for the day: left alone until midnight Pacific time, when Google resets it',
        $pacific->format('H:i:s') === '00:00:00' && $until > new DateTimeImmutable('now'), $pacific->format(DATE_ATOM));
    db_run("DELETE FROM ai_service_state WHERE name = 'quota_block'");

    foreach ([
        'Gemini down (500)'           => [['status' => 500, 'body' => ['error' => ['code' => 500, 'status' => 'INTERNAL']]], 503, 'unavailable'],
        'an answer that is not JSON'  => [['status' => 200, 'raw' => '<html>oops'], 503, 'unavailable'],
        'a 200 with nothing in it'    => [['status' => 200, 'body' => ['candidates' => []]], 503, 'unavailable'],
        'refused for safety'          => [['status' => 200, 'body' => ['promptFeedback' => ['blockReason' => 'SAFETY']]], 422, 'blocked'],
        'a key Gemini does not take'  => [['status' => 400, 'body' => ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid.']]], 503, 'unavailable'],
        'too slow'                    => [['sleep' => 4, 'status' => 200, 'body' => says('Te laat.')['body']], 503, 'timeout'],
    ] as $label => [$answer, $status, $code]) {
        gemini($answer);
        $r = chat($sanne, 'Hoe was mijn week?', $conversation);
        check("$label: $status $code, and nothing stored or counted", $r['status'] === $status && ($r['body']['code'] ?? null) === $code
            && is_string($r['body']['error'] ?? null) && used_today($sanne['id']) === $used
            && (int) db_value('SELECT COUNT(*) FROM ai_messages WHERE user_id = ?', [$sanne['id']]) === $messages, summary($r));
    }
    check('the failures are counted, without their content', (int) db_value('SELECT errors FROM ai_usage WHERE user_id = ? AND usage_date = CURDATE()', [$sanne['id']]) >= 8);

    forget_sent();
    gemini(['status' => 400, 'body' => ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Thinking level is not supported for this model.']]], says('Zonder nadenken.'));
    $r = chat($sanne, 'Hoe was mijn week?', $conversation);
    check('a model that takes no thinking setting is asked again without it', $r['status'] === 200 && count(sent()) === 2
        && !isset(sent()[1]['body']['generationConfig']['thinkingConfig']), summary($r));

    forget_sent();
    gemini(says('Zo sta je ervoor.'));
    chat($sanne, 'Hoe sta ik in de ranglijst?', $conversation);
    $system = (string) (sent()[0]['body']['systemInstruction']['parts'][0]['text'] ?? '');
    check('a leaderboard question: her own place on the Community page\'s boards (Vrienden, Nederland), nobody else\'s',
        str_contains($system, '"your_place":{') && str_contains($system, '"friends_month":') && str_contains($system, '"netherlands_month":')
        && str_contains($system, '"netherlands_alltime":') && !str_contains($system, $bram['username']), mb_substr($system, -600));

    $r = http('/api/ai/chat.php', ['base' => 'http://127.0.0.1:' . $noKeyPort, 'bearer' => $sanne['token'], 'form' => ['message' => 'Hallo']]);
    check('no key configured: 503 unavailable, in words', $r['status'] === 503 && ($r['body']['code'] ?? null) === 'unavailable'
        && str_contains((string) ($r['body']['error'] ?? ''), 'tijdelijk niet beschikbaar'), summary($r));
    check('and the sheet knows before anyone asks', (http('/api/ai/state.php', ['base' => 'http://127.0.0.1:' . $noKeyPort, 'bearer' => $sanne['token']])['body']['available'] ?? null) === false);

    /* ==================================================================
       CONSENT, AGAIN
       ================================================================== */

    section('saying no later, and new terms');
    $r = http('/api/profile/privacy.php', ['bearer' => $sanne['token'], 'form' => ['ai_consent' => '0']]);
    check('the Privacy switch off: 200', $r['status'] === 200 && ($r['body']['ai_consent'] ?? null) === false, summary($r));
    forget_sent();
    check('and from then on nothing is sent', chat($sanne, 'Hallo', $conversation)['status'] === 403 && sent() === []);
    $page = json_encode(http('/api/app/state.php', ['bearer' => $sanne['token']])['body']['data']['settings']['pages']['privacy'] ?? []);
    check('Privacy shows it off, and "Ownify AI: Uit"', str_contains($page, '"key":"ai_consent"') && str_contains($page, '"label":"Ownify AI","value":"Uit"'));
    check('the switch back on: the same yes as in the sheet', http('/api/profile/privacy.php', ['bearer' => $sanne['token'], 'form' => ['ai_consent' => '1']])['status'] === 200
        && ai_consent_state_for($sanne['id']) === 'accepted');
    check('the website\'s switch needs its CSRF token', http('/api/profile/privacy.php', ['jar' => $jar, 'form' => ['ai_consent' => '1']])['status'] === 419);

    db_run('UPDATE user_profiles SET ai_consent_version = ? WHERE user_id = ?', ['2025-older-terms', $sanne['id']]);
    forget_sent();
    check('a yes to older terms is asked again: unknown, 403, nothing sent',
        (http('/api/ai/state.php', ['bearer' => $sanne['token']])['body']['consent'] ?? null) === 'unknown'
        && chat($sanne, 'Hallo', $conversation)['status'] === 403 && sent() === []);
    http('/api/ai/consent.php', ['bearer' => $sanne['token'], 'form' => ['decision' => 'accept']]);

    /* ==================================================================
       WIPING
       ================================================================== */

    section('wiping conversations: one, or all of one\'s own');
    $r = http('/api/ai/delete.php', ['bearer' => $sanne['token'], 'form' => ['conversation_id' => (string) $second]]);
    check('one conversation: gone, with its messages', $r['status'] === 200
        && db_value('SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ?', [$second]) === 0);
    $bramBefore = (int) db_value('SELECT COUNT(*) FROM ai_conversations WHERE user_id = ?', [$bram['id']]);
    $r = http('/api/ai/delete.php', ['bearer' => $sanne['token'], 'form' => ['all' => '1']]);
    check('all of Sanne\'s: gone', $r['status'] === 200 && (int) db_value('SELECT COUNT(*) FROM ai_conversations WHERE user_id = ?', [$sanne['id']]) === 0);
    check('Bram\'s are all still there', $bramBefore > 0 && (int) db_value('SELECT COUNT(*) FROM ai_conversations WHERE user_id = ?', [$bram['id']]) === $bramBefore);
    check('the goals the assistant helped with stay: they are goals, not chat', db_value('SELECT id FROM goals WHERE id = ?', [$goal['id']]) !== null);

    section('the diagnostic (AI_DIAGNOSTIC_LOG): structure only, never content');
    $diagnostic = (string) @file_get_contents($fakeDir . '/diagnostic.log');
    check('one line per Gemini request, with the status, the outcome, the model and the outline',
        str_contains($diagnostic, 'gemini  HTTP 200  outcome=ok  model=gemini-3.8-flash  sent: contents=')
        && str_contains($diagnostic, 'question answered after') && str_contains($diagnostic, 'text(') && str_contains($diagnostic, '+sig'));
    check('a refusal: Gemini\'s status and message', str_contains($diagnostic, 'HTTP 400  outcome=thinking  model=gemini-3.8-flash  error=400 INVALID_ARGUMENT  message="Thinking level is not supported for this model."'));
    check('readable by the server\'s own user only (0600)', (fileperms($fakeDir . '/diagnostic.log') & 0777) === 0600);
    $leaked = array_filter(['test-key-not-real', 'Hoe heb ik de afgelopen nachten geslapen?', '6 u 55 min', 'asleep_minutes', 'Sanne',
        $bram['username'], 'c2lnbmF0dXJl', 'YW50d29vcmQ'], static fn (string $s) => str_contains($diagnostic, $s));
    check('no key, no question, no answer, no health data, no name, no signature', $diagnostic !== '' && $leaked === [], implode(', ', $leaked));
    require_once $root . '/includes/ai/diagnostic.php';
    $quoting = ['contents' => [['role' => 'user', 'parts' => [['text' => 'Hoe heb ik de afgelopen nachten geslapen?']]]]];
    check('a Gemini message that repeats the conversation is withheld; one that names a field or function is kept',
        ai_diagnostic_message('Invalid content near: afgelopen nachten geslapen', $quoting) === '(withheld: it repeated part of the conversation)'
        && ai_diagnostic_message("Invalid value at 'contents[1].role', function call `default_api:get_health_summary`", $quoting)
            === "Invalid value at 'contents[1].role', function call `default_api:get_health_summary`"
        && ai_diagnostic_message('Bad value: {"result":{"asleep_minutes":455}}', $quoting) === 'Bad value: (value)');
    exec('AI_DIAGNOSTIC_LOG=' . escapeshellarg($root . '/uploads/diagnostic.log') . ' ' . escapeshellarg(PHP_BINARY)
        . ' -r ' . escapeshellarg('require "' . $root . '/includes/ai/diagnostic.php"; var_export(ai_diagnostic_path());'), $out);
    check('never inside the app, where the web server could hand it out', ($out[0] ?? '') === 'NULL' && !is_file($root . '/uploads/diagnostic.log'));

} finally {
    foreach ($made as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    db_run("DELETE FROM ai_service_state WHERE name = 'quota_block'");

    foreach ([$server, $noKeyServer, $fake] as $process) {
        proc_terminate($process);
    }
    array_map('unlink', glob($fakeDir . '/*') ?: []);
    @rmdir($fakeDir);
    @unlink($jar ?? '');
}

/** The consent as the server stores it, read without the app's caches. */
function ai_consent_state_for(int $userId): string
{
    $row = db_one('SELECT ai_consent, ai_consent_version FROM user_profiles WHERE user_id = ?', [$userId]);

    return (int) ($row['ai_consent'] ?? -1) === 1 && $row['ai_consent_version'] === ai_config()['consent_version'] ? 'accepted' : 'other';
}

printf("\n  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
