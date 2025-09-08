<?php
/**
 * sms.php — Forward SMS → Telegram
 * Accepts:
 *  A) application/x-www-form-urlencoded  (fields: from, content, timestamp)
 *  B) application/json                   (fields: from, text, sentStamp, receivedStamp, sim, secret)
 *
 * Tested with: https://github.com/pppscn/SmsForwarder
 */

//////////////////////// CONFIG ////////////////////////
// Fill these with your own values (or use environment variables)
$BOT_TOKEN     = getenv('TG_BOT_TOKEN') ?: "YOUR_TELEGRAM_BOT_TOKEN";
$CHAT_ID       = getenv('TG_CHAT_ID')   ?: 0; // numeric Telegram user/group/channel id
$SHARED_SECRET = getenv('SHARED_SECRET')?: ""; // optional; if empty, auth check is skipped
$LOG_DIR       = __DIR__ . "/logs";           // log directory
$LOCAL_TZ      = getenv('LOCAL_TZ')     ?: 'UTC'; // e.g., 'Asia/Tehran', 'Europe/Paris'
////////////////////////////////////////////////////////

date_default_timezone_set('UTC');
@mkdir($LOG_DIR, 0775, true);
$REQ_LOG_FILE  = $LOG_DIR . "/sms-req-" . date("Ymd") . ".log";
$TG_LOG_FILE   = $LOG_DIR . "/sms-tg-"  . date("Ymd") . ".log";

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
function epochms_to_dt($ms, $tz = 'UTC') {
  if ($ms === '' || $ms === null) return ['', ''];
  $ms = (float)$ms;
  if ($ms > 1e12) { $ms = $ms / 1e6; } // guard ns
  $sec = (int) floor($ms / 1000);
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

// --- Method ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_fail(405, "Method Not Allowed");
}

$headers = get_all_headers_safe();
$ct      = $_SERVER['CONTENT_TYPE'] ?? '';
$raw     = file_get_contents('php://input');

// --- Request snapshot log ---
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

// --- Optional shared-secret auth (form & json) ---
if ($SHARED_SECRET !== '') {
  $secret =
    ($_POST['secret'] ?? '') ?:
    ($_GET['secret'] ?? '')  ?:
    ($headers['X-Shared-Secret'] ?? $headers['X-Shared-Secret'.chr(0)] ?? '');

  // For JSON we also check after decode; if form and mismatch => fail now
  if ($secret !== $SHARED_SECRET && stripos($ct, 'application/json') === false) {
    json_fail(401, "Unauthorized");
  }
}

// ---------------- Parse payload ----------------
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$from = $text = $sim = '';
$sentStamp = $receivedStamp = null;
$sentUTC = $sentLocal = $receivedUTC = $receivedLocal = '';

// A) form-urlencoded (SmsForwarder default webhook)
if (stripos($ct, 'application/x-www-form-urlencoded') !== false || (!empty($_POST) && $raw !== '')) {
  $from      = trim((string)($_POST['from'] ?? ''));
  $text      = trim((string)($_POST['content'] ?? ''));
  $sentStamp = $_POST['timestamp'] ?? null; // epoch ms

  // Try to extract SIM from a line like: SIM1_Operator_XXXX...
  if ($text !== '') {
    $lines = preg_split("/\r\n|\n|\r/u", $text);
    foreach ($lines as $ln) {
      if (stripos($ln, 'SIM') === 0) { $sim = trim($ln); break; }
    }
    // Last line as local datetime? e.g. "2025-09-08 03:50:23"
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
    [$sentUTC, $sentLocal] = epochms_to_dt($sentStamp, $LOCAL_TZ);
  }

// B) JSON (backward/alternate)
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
  [$sentUTC, $sentLocal]         = epochms_to_dt($sentStamp,     $LOCAL_TZ);
  [$receivedUTC, $receivedLocal] = epochms_to_dt($receivedStamp, $LOCAL_TZ);

} else {
  json_fail(400, "Unsupported content-type or empty body", ['content_type' => $ct, 'len' => strlen($raw)]);
}

// ---------------- Build Telegram message ----------------
$parts = [];
$parts[] = "<b>📩 New SMS</b>";
if ($from !== "") $parts[] = "<b>From:</b> " . $h($from);
if ($text !== "") $parts[] = "<b>Message:</b>\n" . $h($text);

$meta = [];
if ($sim           !== "") $meta[] = "SIM: " . $h($sim);
if ($sentUTC       !== "") $meta[] = "Sent(UTC): " . $h($sentUTC);
if ($sentLocal     !== "") $meta[] = "Sent(Local): " . $h($sentLocal);
if ($receivedUTC   !== "") $meta[] = "Recv(UTC): " . $h($receivedUTC);
if ($receivedLocal !== "") $meta[] = "Recv(Local): " . $h($receivedLocal);
if ($meta) $parts[] = "<i>" . implode(" | ", $meta) . "</i>";

$textOut = implode("\n\n", $parts);
if ($textOut === "") $textOut = "📩 New SMS (empty body)";
if (mb_strlen($textOut, 'UTF-8') > 4000) {
  $textOut = mb_substr($textOut, 0, 3990, 'UTF-8') . "<br>...(truncated)";
}

// ---------------- Send to Telegram ----------------
if (!$BOT_TOKEN || !$CHAT_ID) {
  json_fail(500, "Missing BOT_TOKEN or CHAT_ID");
}

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

if ($http >= 200 && $http < 300 && !$err) {
  json_out(['ok' => true], 200);
} else {
  json_fail(500, "telegram_failed", ['http' => $http, 'curl_error' => $err, 'telegram_response' => $res]);
}
