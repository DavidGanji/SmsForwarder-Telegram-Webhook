<?php
/**
 * sms.php — Forward SMS → Telegram
 * Accepts:
 *  A) application/x-www-form-urlencoded  (fields: from, content, timestamp)
 *  B) application/json                   (fields: from, text, sentStamp, receivedStamp, sim, secret)
 *
 * Tested with: https://github.com/pppscn/SmsForwarder
 *
 * Env vars (set in server, not here):
 *   BOT_TOKEN       = "123456:ABC..."        (required)
 *   CHAT_ID         = "123456789"            (required; numeric chat/user/channel id)
 *   SHARED_SECRET   = "your-shared-secret"   (optional but recommended)
 *   LOCAL_TZ        = "Asia/Tehran"          (optional; default Asia/Tehran)
 *   LOG_DIR         = "/path/to/logs"        (optional; default __DIR__/logs)
 */

//////////////////////// CONFIG ////////////////////////
$BOT_TOKEN     = getenv('BOT_TOKEN') ?: '';
$CHAT_ID       = getenv('CHAT_ID') ?: '';
$SHARED_SECRET = getenv('SHARED_SECRET') ?: '';
$LOCAL_TZ      = getenv('LOCAL_TZ') ?: 'Asia/Tehran';
$LOG_DIR       = getenv('LOG_DIR') ?: (__DIR__ . '/logs');
$REQ_LOG_FILE  = $LOG_DIR . '/sms-req-' . date('Ymd') . '.log';
$TG_LOG_FILE   = $LOG_DIR . '/sms-tg-'  . date('Ymd') . '.log';
////////////////////////////////////////////////////////

date_default_timezone_set('UTC');
@mkdir($LOG_DIR, 0775, true);

function log_to($file, $line) {
  @file_put_contents($file, "[".date("H:i:s")."] ".$line."\n", FILE_APPEND);
}
function json_out($arr, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_fail($code, $msg, $detail = null) {
  $out = ['ok' => false, 'error' => $msg];
  if ($detail !== null) $out['detail'] = $detail;
  json_out($out, $code);
}

/** Detect ns/µs/ms/s by digit-length and convert to UTC/Local */
function epoch_to_dt_flex($val, $tz = 'UTC') {
  if ($val === '' || $val === null) return ['', ''];
  if (!is_numeric($val)) return ['', ''];

  $digits = strlen(preg_replace('/\D+/', '', (string)$val));
  $x = (float)$val;

  if ($digits >= 19) {          // ns
    $sec = (int) floor($x / 1e9);
  } elseif ($digits >= 16) {    // µs
    $sec = (int) floor($x / 1e6);
  } elseif ($digits >= 13) {    // ms
    $sec = (int) floor($x / 1e3);
  } else {                      // s
    $sec = (int) round($x);
  }

  $utc = (new DateTime("@$sec"))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $loc = (new DateTime("@$sec"))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s');
  return [$utc, $loc];
}

function get_all_headers_safe() {
  if (function_exists('getallheaders')) return getallheaders();
  $headers = [];
  foreach ($_SERVER as $k => $v) {
    if (substr($k, 0, 5) === 'HTTP_') {
      $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
      $headers[$name] = $v;
    }
  }
  if (isset($_SERVER['CONTENT_TYPE']))   $headers['Content-Type']   = $_SERVER['CONTENT_TYPE'];
  if (isset($_SERVER['CONTENT_LENGTH'])) $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
  return $headers;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_fail(405, "Method Not Allowed");
}

// Require essential envs
if ($GLOBALS['BOT_TOKEN'] === '' || $GLOBALS['CHAT_ID'] === '') {
  json_fail(500, "Server not configured: BOT_TOKEN/CHAT_ID missing");
}

$headers = get_all_headers_safe();
$ct      = $_SERVER['CONTENT_TYPE'] ?? '';
$raw     = file_get_contents('php://input');

$req_snapshot = [
  'time'       => gmdate('Y-m-d H:i:s'),
  'method'     => $_SERVER['REQUEST_METHOD'] ?? '',
  'uri'        => $_SERVER['REQUEST_URI'] ?? '',
  'query'      => $_GET ?? [],
  'headers'    => $headers,
  'raw_body'   => $raw,
  'post'       => $_POST ?? [],
  'files'      => $_FILES ?? [],
  'server_ip'  => $_SERVER['SERVER_ADDR'] ?? '',
  'client_ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
  'ua'         => $headers['User-Agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
];
log_to($REQ_LOG_FILE, json_encode($req_snapshot, JSON_UNESCAPED_UNICODE));

// ---------------- AUTH (optional, works for both json & form) ----------------
if ($SHARED_SECRET !== '') {
  $secret =
    ($_POST['secret'] ?? '') ?:
    ($_GET['secret'] ?? '')  ?:
    ($headers['X-Shared-Secret'] ?? $headers['X-Shared-Secret'.chr(0)] ?? '');

  if ($secret !== $SHARED_SECRET) {
    if (stripos($ct, 'application/json') === false) {
      json_fail(401, "Unauthorized");
    }
  }
}

// ---------------- Parse payload ----------------
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$from = $text = $sim = '';
$sentStamp = $receivedStamp = null;
$sentUTC = $sentLocal = $receivedUTC = $receivedLocal = '';

// Case A: form-urlencoded
if (stripos($ct, 'application/x-www-form-urlencoded') !== false || (!empty($_POST) && $raw !== '')) {
  $from      = trim((string)($_POST['from'] ?? ''));
  $text      = trim((string)($_POST['content'] ?? ''));
  // try multiple keys
  $sentStamp = $_POST['timestamp'] ?? $_POST['time'] ?? $_POST['sent'] ?? null;

  if ($text !== '') {
    $lines = preg_split("/\r\n|\n|\r/u", $text);
    foreach ($lines as $ln) {
      if (stripos($ln, 'SIM') === 0) { $sim = trim($ln); break; }
    }
    $last = trim(end($lines));
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $last)) {
      try {
        $dt = new DateTime($last, new DateTimeZone($LOCAL_TZ));
        $receivedUTC   = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $receivedLocal = $dt->setTimezone(new DateTimeZone($LOCAL_TZ))->format('Y-m-d H:i:s');
      } catch (Throwable $e) {}
    }
  }

  if ($sentStamp !== null && $sentStamp !== '') {
    [$sentUTC, $sentLocal] = epoch_to_dt_flex($sentStamp, $LOCAL_TZ);
  }

// Case B: JSON
} elseif (stripos($ct, 'application/json') !== false && $raw !== '') {
  $payload = json_decode($raw, true);
  if (!is_array($payload)) json_fail(400, "Bad Request: invalid JSON");

  if ($SHARED_SECRET !== '') {
    $secret = $payload['secret'] ?? '';
    if ($secret !== $SHARED_SECRET) json_fail(401, "Unauthorized");
  }

  $from          = trim((string)($payload['from']          ?? ''));
  $text          = trim((string)($payload['text']          ?? ''));
  $sim           = trim((string)($payload['sim']           ?? ''));
  $sentStamp     = $payload['sentStamp']     ?? null;
  $receivedStamp = $payload['receivedStamp'] ?? null;

  [$sentUTC, $sentLocal]         = epoch_to_dt_flex($sentStamp,     $LOCAL_TZ);
  [$receivedUTC, $receivedLocal] = epoch_to_dt_flex($receivedStamp, $LOCAL_TZ);

} else {
  json_fail(400, "Unsupported content-type or empty body", ['content_type' => $ct, 'len' => strlen($raw)]);
}

// ---------------- Build Telegram message ----------------
$lines = [];
$lines[] = "<b>📩 New SMS</b>";
if ($from !== "") $lines[] = "<b>From:</b> " . $h($from);
if ($text !== "") $lines[] = "<b>Message:</b>\n" . $h($text);

$meta = [];
if ($sim           !== "") $meta[] = "SIM: " . $h($sim);
if ($sentUTC       !== "") $meta[] = "Sent(UTC): " . $h($sentUTC);
if ($sentLocal     !== "") $meta[] = "Sent(Local): " . $h($sentLocal);
if ($receivedUTC   !== "") $meta[] = "Recv(UTC): " . $h($receivedUTC);
if ($receivedLocal !== "") $meta[] = "Recv(Local): " . $h($receivedLocal);
if ($meta) $lines[] = "<i>" . implode(" | ", $meta) . "</i>";

$textOut = implode("\n\n", $lines);
if ($textOut === "") $textOut = "📩 New SMS (empty body)";
if (mb_strlen($textOut, 'UTF-8') > 4000) {
  $textOut = mb_substr($textOut, 0, 3990, 'UTF-8') . "<br>...(truncated)";
}

// ---------------- Send to Telegram ----------------
$tgUrl = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
$post  = [
  'chat_id' => $CHAT_ID,
  'text'    => $textOut,
  'parse_mode' => 'HTML',
  'disable_web_page_preview' => 1,
];

$ch = curl_init($tgUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $post,
  CURLOPT_TIMEOUT        => 20,
]);
$res  = curl_exec($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

log_to($TG_LOG_FILE, "HTTP=$http | CURL_ERR=" . ($err ?: '-') . " | RESP=" . $res);

// ---------------- Response ----------------
if ($http >= 200 && $http < 300 && !$err) {
  json_out(['ok' => true], 200);
} else {
  json_fail(500, "telegram_failed", ['http' => $http, 'curl_error' => $err, 'telegram_response' => $res]);
}
