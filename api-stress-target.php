<?php
/**
 * api-stress-target.php — Full-featured test endpoint
 *
 * Query Params (all optional, with sane caps):
 *  Security/Access:
 *    token            : if $SECRET_TOKEN is set, must match
 *
 *  Core behavior:
 *    bytes            : body size in bytes (default 512KB, max 50MB)
 *    ttfb_ms          : server delay before headers/body (0..10000)
 *    jitter_ms        : add random 0..N ms per chunk (0..2000)
 *    bps              : throttle bytes/sec (>=1024) — 0 disables
 *    chunk            : stream chunk size (1..65536, default 8192)
 *    method           : force method shim "GET|HEAD" (normally not needed)
 *    nocache          : 1 to send no-cache headers (default 1)
 *    cors             : "*" (default) | "reflect" | literal origin
 *    connection       : "keep-alive" | "close"
 *
 *  Content shaping:
 *    content          : zero|random|lorem|json|html (default zero)
 *    gzip             : 1 to enable gzip (uses ob_gzhandler, disables Content-Length)
 *    status           : 200|204|301|302|304|400|401|403|404|408|429|500|502|503 (default 200)
 *    location         : redirect target (required for 301/302)
 *    header_kb        : add X-Fill header of ~N KB (0..256)
 *    cookie_n         : set N cookies (0..20)
 *    cookie_bytes     : cookie value size each (0..2048)
 *    cookie_ttl       : seconds till expiry (default 3600)
 *
 *  Faults & performance:
 *    failrate         : 0..1 probability of forced 500
 *    burst_n          : every Nth request returns 500 (needs stateless seed -> rand used)
 *    cpu_ms           : approximate CPU spin time in ms (0..10000)
 *    mem_mb           : allocate MB of RAM for request lifetime (0..256)
 *
 *  Range/partial content:
 *    accept_ranges    : 1 to advertise/enable Range support (default 1)
 *
 * Notes:
 *  - HEAD returns headers only.
 *  - Range is only honored when gzip=0 (no compression) so we can send exact bytes.
 *  - If gzip enabled or Content-Length omitted, many servers will use chunked TE automatically.
 *  - All caps are enforced to avoid accidental outages.
 */

/////// Optional shared-secret gate ///////
$SECRET_TOKEN = ''; // e.g. 'super-long-random'; leave empty to disable
if ($SECRET_TOKEN !== '') {
  if (!hash_equals($SECRET_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: invalid token";
    exit;
  }
}

/////// Read & clamp inputs ///////
$MAX_BYTES = 50 * 1024 * 1024; // 50MB
$bytes        = max(0, min($MAX_BYTES, (int)($_GET['bytes'] ?? 524288)));
$ttfb         = max(0, min(10000, (int)($_GET['ttfb_ms'] ?? 0)));
$jitterMax    = max(0, min(2000, (int)($_GET['jitter_ms'] ?? 0)));
$bps          = max(0, (int)($_GET['bps'] ?? 0)); if ($bps && $bps < 1024) $bps = 1024;
$chunk        = max(1, min(65536, (int)($_GET['chunk'] ?? 8192)));
$methodShim   = strtoupper((string)($_GET['method'] ?? ''));
$nocache      = (int)($_GET['nocache'] ?? 1);
$cors         = (string)($_GET['cors'] ?? '*');
$conn         = (string)($_GET['connection'] ?? 'keep-alive');
$content      = strtolower((string)($_GET['content'] ?? 'zero'));
$gzip         = (int)($_GET['gzip'] ?? 0);
$status       = (int)($_GET['status'] ?? 200);
$location     = (string)($_GET['location'] ?? '');
$headerKB     = max(0, min(256, (int)($_GET['header_kb'] ?? 0)));
$cookieN      = max(0, min(20, (int)($_GET['cookie_n'] ?? 0)));
$cookieBytes  = max(0, min(2048, (int)($_GET['cookie_bytes'] ?? 0)));
$cookieTtl    = max(1, (int)($_GET['cookie_ttl'] ?? 3600));
$failrate     = max(0, min(1, (float)($_GET['failrate'] ?? 0)));
$burstN       = max(0, (int)($_GET['burst_n'] ?? 0));
$cpuMs        = max(0, min(10000, (int)($_GET['cpu_ms'] ?? 0)));
$memMB        = max(0, min(256, (int)($_GET['mem_mb'] ?? 0)));
$acceptRanges = (int)($_GET['accept_ranges'] ?? 1);

if (($status === 301 || $status === 302) && !$location) $status = 200;

/////// Method normalize ///////
$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methodShim === 'HEAD') $reqMethod = 'HEAD';
if ($methodShim === 'GET')  $reqMethod = 'GET';

/////// CORS ///////
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if ($cors === 'reflect' && !empty($_SERVER['HTTP_ORIGIN'])) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
} elseif ($cors === '*') {
  header('Access-Control-Allow-Origin: *');
} else {
  header('Access-Control-Allow-Origin: ' . $cors);
  header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Max-Age: 600');

if ($conn) header('Connection: ' . ($conn === 'close' ? 'close' : 'keep-alive'));

if ($nocache) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

if ($acceptRanges) header('Accept-Ranges: bytes');
header('Vary: Accept-Encoding'); // good hygiene for gzip tests

if ($headerKB > 0) {
  // Add a big header to test header parsing/memory (X-Fill approx headerKB kilobytes)
  $fill = str_repeat('X', $headerKB * 1024);
  header('X-Fill: ' . $fill);
}

/////// Preflight ///////
if ($reqMethod === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/////// Fault injection decisions (before doing work) ///////
$random500 = (mt_rand() / mt_getrandmax()) < $failrate;
$burstHit  = ($burstN > 0) ? (mt_rand(1, $burstN) === 1) : false;
if ($random500 || $burstHit) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Injected failure";
  exit;
}

/////// CPU spin (approx) ///////
if ($cpuMs > 0) {
  $end = microtime(true) + ($cpuMs / 1000.0);
  $x = 0.0;
  while (microtime(true) < $end) {
    // tight FP loop
    $x += sqrt(12345.6789) * cos($x + 0.123) / 1.000001;
  }
  // prevent optimization
  if ($x === -INF) { echo ""; }
}

/////// Memory pressure ///////
$mem = null;
if ($memMB > 0) {
  $toAlloc = min($memMB, 256) * 1024 * 1024;
  // allocate in 1MB steps to avoid hitting memory limit suddenly
  $mem = [];
  for ($i=0; $i<($toAlloc/1048576); $i++) {
    $mem[] = str_repeat('M', 1048576);
  }
}

/////// TTFB ///////
if ($ttfb > 0) usleep($ttfb * 1000);

/////// Status & redirects ///////
http_response_code($status);
if ($status === 301 || $status === 302) {
  header('Location: ' . $location);
  header('Content-Length: 0');
  exit;
}

/////// Cookies flood ///////
if ($cookieN > 0) {
  $val = ($cookieBytes > 0) ? substr(str_repeat('C', $cookieBytes), 0, $cookieBytes) : '1';
  $exp = time() + $cookieTtl;
  for ($i=1; $i<=$cookieN; $i++) {
    setcookie("stress_cookie_$i", $val, [
      'expires' => $exp,
      'path' => '/',
      'secure' => isset($_SERVER['HTTPS']),
      'httponly' => false,
      'samesite' => 'Lax'
    ]);
  }
}

/////// Content-Type selection ///////
$ctype = 'application/octet-stream';
$templateJSON = json_encode([
  'ok' => true,
  'note' => 'stress target',
  'bytes' => $bytes,
  'ts' => time()
], JSON_UNESCAPED_SLASHES);

$templateHTML = "<!doctype html><meta charset=utf-8><title>Stress Target</title><pre>bytes={$bytes} ts=" . time() . "</pre>";

switch ($content) {
  case 'json': $ctype = 'application/json'; break;
  case 'html': $ctype = 'text/html; charset=utf-8'; break;
  default: $ctype = 'application/octet-stream';
}
header("Content-Type: $ctype");
@ini_set('zlib.output_compression', '0');
if ($gzip) { @ob_start('ob_gzhandler'); }

/////// HEAD → headers only ///////
if ($reqMethod === 'HEAD') {
  if (!$gzip) header('Content-Length: ' . $bytes);
  exit;
}

/////// Range support (only if not gzipped and content length known) ///////
$doRange = (!$gzip && $acceptRanges && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $m));
$start = 0; $end = max(0, $bytes - 1);
if ($doRange) {
  $start = (int)$m[1];
  $end = isset($m[2]) ? (int)$m[2] : $end;
  if ($start > $end || $start >= $bytes) {
    // invalid range
    header('Content-Range: bytes */' . $bytes);
    http_response_code(416);
    exit;
  }
  $end = min($end, $bytes - 1);
  $len = $end - $start + 1;
  http_response_code(206);
  header('Content-Range: bytes ' . $start . '-' . $end . '/' . $bytes);
  header('Content-Length: ' . $len);
} else {
  if (!$gzip) header('Content-Length: ' . $bytes);
}

/////// Generators ///////
$genZero = function($n) { return str_repeat("\0", $n); };
$genRandom = function($n) {
  // fast-ish pseudo random filler
  $buf = '';
  while (strlen($buf) < $n) { $buf .= md5(mt_rand(), true); }
  return substr($buf, 0, $n);
};
$lorem = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. ";
$genLorem = function($n) use ($lorem) {
  $buf = '';
  while (strlen($buf) < $n) { $buf .= $lorem; }
  return substr($buf, 0, $n);
};

// Content templating for json/html
$genContent = function($n) use ($content, $templateJSON, $templateHTML, $genZero, $genRandom, $genLorem) {
  if ($content === 'json') {
    $s = $templateJSON;
    if ($n <= strlen($s)) return substr($s, 0, $n);
    return $s . str_repeat(' ', $n - strlen($s));
  }
  if ($content === 'html') {
    $s = $templateHTML;
    if ($n <= strlen($s)) return substr($s, 0, $n);
    return $s . str_repeat(' ', $n - strlen($s));
  }
  if ($content === 'random') return $genRandom($n);
  if ($content === 'lorem')  return $genLorem($n);
  return $genZero($n);
};

/////// Stream body ///////
$timePerChunk = $bps > 0 ? ($chunk / $bps) : 0.0;
$sent = 0;
$offset = $doRange ? $start : 0;
$toSend = $doRange ? ($end - $start + 1) : $bytes;

while ($sent < $toSend) {
  $remaining = $toSend - $sent;
  $now = ($remaining >= $chunk) ? $chunk : $remaining;

  echo $genContent($now);

  $sent += $now;
  $offset += $now;

  @ob_flush();
  @flush();

  if ($timePerChunk > 0 && $sent < $toSend) {
    usleep((int)round($timePerChunk * 1_000_000));
  }
  if ($jitterMax > 0 && $sent < $toSend) {
    usleep((int)round(mt_rand(0, $jitterMax) * 1000));
  }
}
