<?php
// Core helpers: config, DB, HTTP I/O, auth tokens, AI client.

class HttpError extends Exception
{
    public $status;
    public function __construct($status, $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function abort($status, $message)
{
    throw new HttpError($status, $message);
}

function cfg($key, $default = null)
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/../config.php';
    }
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

// ───────── Database ─────────
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';port=' . cfg('db_port') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function q($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function rows($sql, $params = [])
{
    return q($sql, $params)->fetchAll();
}
function row($sql, $params = [])
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function val($sql, $params = [])
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}
function insert($sql, $params = [])
{
    q($sql, $params);
    return (int) db()->lastInsertId();
}

// ───────── Request / response ─────────
function input()
{
    static $in = null;
    if ($in === null) {
        $raw = file_get_contents('php://input');
        $json = $raw !== '' ? json_decode($raw, true) : null;
        $in = is_array($json) ? $json : $_POST;
    }
    return $in;
}

function qs($name, $default = null)
{
    return isset($_GET[$name]) && $_GET[$name] !== '' ? $_GET[$name] : $default;
}

function respond($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function str_in($key, $max = 190, $required = false, $src = null)
{
    $src = $src === null ? input() : $src;
    $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
    if ($required && $v === '') {
        abort(422, ucfirst(str_replace('_', ' ', $key)) . ' is required');
    }
    return mb_substr($v, 0, $max);
}

function money_in($key, $default = 0.0, $src = null)
{
    $src = $src === null ? input() : $src;
    if (!isset($src[$key]) || $src[$key] === '' || $src[$key] === null) {
        return (float) $default;
    }
    if (!is_numeric($src[$key])) {
        abort(422, ucfirst(str_replace('_', ' ', $key)) . ' must be a number');
    }
    $n = (float) $src[$key];
    if ($n < 0) {
        abort(422, ucfirst(str_replace('_', ' ', $key)) . ' cannot be negative');
    }
    return round($n, 2);
}

function valid_date($d, $default)
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : $default;
}

// ───────── Auth tokens (HMAC-signed, stateless) ─────────
function b64u_enc($s)
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64u_dec($s)
{
    return base64_decode(strtr($s, '-_', '+/'));
}

function make_token($uid)
{
    $p = b64u_enc(json_encode(['uid' => (int) $uid, 'exp' => time() + 60 * 86400]));
    return $p . '.' . b64u_enc(hash_hmac('sha256', $p, cfg('app_secret'), true));
}

function header_in($name)
{
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$k]) ? $_SERVER[$k] : (isset($_SERVER['REDIRECT_' . $k]) ? $_SERVER['REDIRECT_' . $k] : '');
}

function auth_user()
{
    $tok = header_in('X-Auth-Token');
    if ($tok === '') {
        $h = header_in('Authorization');
        if (stripos($h, 'Bearer ') === 0) {
            $tok = trim(substr($h, 7));
        }
    }
    $parts = explode('.', $tok);
    if (count($parts) !== 2) {
        abort(401, 'Please sign in');
    }
    $expect = b64u_enc(hash_hmac('sha256', $parts[0], cfg('app_secret'), true));
    if (!hash_equals($expect, $parts[1])) {
        abort(401, 'Invalid session');
    }
    $p = json_decode(b64u_dec($parts[0]), true);
    if (!$p || $p['exp'] < time()) {
        abort(401, 'Session expired');
    }
    $u = row('SELECT id, name, email, phone FROM users WHERE id = ?', [$p['uid']]);
    if (!$u) {
        abort(401, 'Account not found');
    }
    return $u;
}

function current_store($user)
{
    $sid = (int) header_in('X-Store-Id');
    $sql = 'SELECT s.*, t.name AS type_name FROM stores s JOIN store_types t ON t.id = s.store_type_id WHERE s.user_id = ?';
    $st = $sid ? row($sql . ' AND s.id = ?', [$user['id'], $sid]) : row($sql . ' ORDER BY s.id LIMIT 1', [$user['id']]);
    if (!$st) {
        abort(404, 'Store not found');
    }
    return $st;
}

// ───────── Router ─────────
$GLOBALS['routes'] = [];

function route($method, $pattern, $handler, $auth = true)
{
    $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '/?$#';
    $GLOBALS['routes'][] = [$method, $re, $handler, $auth];
}

function dispatch()
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim(preg_replace('#^/index\.php#', '', $path), '/');
    $method = $_SERVER['REQUEST_METHOD'];

    $pathMatched = false;
    foreach ($GLOBALS['routes'] as $r) {
        if (!preg_match($r[1], $path, $m)) {
            continue;
        }
        $pathMatched = true;
        if ($r[0] !== $method) {
            continue;
        }
        $ctx = ['params' => array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY), 'user' => null, 'store' => null];
        if ($r[3]) {
            $ctx['user'] = auth_user();
            $ctx['store'] = current_store($ctx['user']);
        }
        return call_user_func($r[2], $ctx);
    }
    abort($pathMatched ? 405 : 404, $pathMatched ? 'Method not allowed' : 'Not found');
}

// ───────── AI client (OpenAI Chat Completions; any OpenAI-compatible endpoint works) ─────────
function ai_chat($system, $messages, $maxTokens = 1500)
{
    $key = cfg('openai_key');
    if (!$key) {
        abort(503, 'AI is not configured yet. Add openai_key to api/config.local.php.');
    }
    array_unshift($messages, ['role' => 'system', 'content' => $system]);
    $payload = [
        'model'                 => cfg('ai_model'),
        'messages'              => $messages,
        'max_completion_tokens' => $maxTokens,
    ];
    $ch = curl_init(rtrim(cfg('ai_base_url'), '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($out === false) {
        abort(502, 'Could not reach the AI service: ' . $err);
    }
    $j = json_decode($out, true);
    if ($code >= 400 || !is_array($j)) {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : 'AI request failed (' . $code . ')';
        abort(502, $msg);
    }
    return isset($j['choices'][0]['message']['content']) ? (string) $j['choices'][0]['message']['content'] : '';
}
