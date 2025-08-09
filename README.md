# api-stress-target.php

A **self‑contained PHP endpoint** for load/stress, latency, and configuration testing of *your own* web server.  
Pair it with a browser‑based tester (like your “Web Server Stress Tester” page) or call it directly via `curl`/scripts.

> **Important:** This script is designed to be deployed on the **target** server you own/control. It intentionally sets CORS headers so browser tools can fetch it without proxying. Do **not** point public users at arbitrary third‑party sites.

---

## What it does

- Generates dynamic responses with controllable **TTFB**, **payload size**, **throughput**, **gzip**, **status codes/redirects**, **headers/cookies**, and optional **fault injection**.
- Streams large bodies in **chunks** with optional **bandwidth throttling** (`bps`) to test network throughput.
- Can simulate **CPU** and **memory** pressure.
- Supports **Range requests** (206) for CDN/cache testing (when `gzip=0`).

---

## Quick start

1. **Copy** `api-stress-target.php` to your site (e.g., `/var/www/html/api-stress-target.php`).
2. Visit in a browser:
   ```
   https://yourdomain.example/api-stress-target.php?bytes=1048576&ttfb_ms=300&nocache=1
   ```
   This returns 1MB after ~300ms server wait.
3. From `curl`:
   ```bash
   curl -i "https://yourdomain.example/api-stress-target.php?bytes=0&status=204"
   ```

> Default CORS is `Access-Control-Allow-Origin: *`. See **CORS** below to change.

---

## Requirements

- PHP 7.4+ (tested on 7.4/8.x)
- Any common web server: NGINX + PHP‑FPM, Apache + mod_php/PHP‑FPM, Caddy, etc.

---

## Installation (generic)

1. Upload `api-stress-target.php` to a web‑served path.
2. Ensure PHP is executing on that path (create a `phpinfo()` page if unsure).
3. (Optional) Set a shared secret inside the file to restrict usage:
   ```php
   $SECRET_TOKEN = 'change-me-to-a-long-random-string';
   ```
   Then call with `&token=change-me-to-a-long-random-string`.

---

## CORS modes

The endpoint returns CORS headers so **browsers** can call it cross‑origin.

- `cors=*` *(default)* → `Access-Control-Allow-Origin: *`
- `cors=reflect` → echoes the request `Origin` and sets `Vary: Origin`
- `cors=<literal>` → allows only the specified origin

**Preflight** (`OPTIONS`) returns `204` quickly.

---

## Parameters

All parameters are optional. Values are clamped to safe caps to avoid accidents.

| Param | Type | Default | Cap | Description |
|---|---:|---:|---:|---|
| `bytes` | int | `524288` (512 KB) | **50 MB** | Total response body size. `0` for headers‑only tests. |
| `ttfb_ms` | int | `0` | **10000** | Delay **before** sending headers/body (simulate server think time / TTFB). |
| `jitter_ms` | int | `0` | **2000** | Extra random per‑chunk delay (0..N ms) while streaming. |
| `bps` | int | `0` | — | Throttle body streaming to ~bytes/sec. `0` = unlimited (subject to server). Min enforced at 1024 when non‑zero. |
| `chunk` | int | `8192` | **65536** | Chunk size used for streaming. |
| `content` | enum | `zero` | — | Body pattern: `zero`, `random`, `lorem`, `json`, `html`. |
| `gzip` | 0/1 | `0` | — | Enable gzip via `ob_gzhandler`. (Disables `Content-Length`.) |
| `status` | int | `200` | — | Response code: `200,204,301,302,304,400,401,403,404,408,429,500,502,503`. |
| `location` | url | — | — | Required for `301/302` redirects. |
| `header_kb` | int | `0` | **256** | Adds `X-Fill` header of ~N KiB to stress header parsing. |
| `cookie_n` | int | `0` | **20** | Number of cookies to set. |
| `cookie_bytes` | int | `0` | **2048** | Size of each cookie value. |
| `cookie_ttl` | int | `3600` | — | Cookie expiry in seconds. |
| `failrate` | float | `0` | `0..1` | Random failure rate; probability of returning `500`. |
| `burst_n` | int | `0` | — | Every Nth request returns `500`. |
| `cpu_ms` | int | `0` | **10000** | Approximate CPU busy‑loop time. |
| `mem_mb` | int | `0` | **256** | Allocate MB of RAM for request lifetime. |
| `nocache` | 0/1 | `1` | — | Send no‑cache headers (`no-store`, etc.). |
| `cors` | `*`/`reflect`/literal | `*` | — | CORS behavior (see above). |
| `accept_ranges` | 0/1 | `1` | — | Enable `Accept-Ranges: bytes` and process `Range` header when `gzip=0`. |
| `connection` | enum | `keep-alive` | — | Force `Connection: keep-alive` or `close`. |
| `method` | enum | — | — | Optional shim to **treat** request like `HEAD` for test harnesses. |
| `token` | string | — | — | Must match `$SECRET_TOKEN` if set; otherwise ignored. |

> **Caps are enforced** server‑side regardless of input. You can still hit upstream proxy limits (see **Troubleshooting**).

---

## Examples

### Latency / TTFB only
```text
/api-stress-target.php?bytes=0&ttfb_ms=600&nocache=1
```

### Throughput (~200 KB/s)
```text
/api-stress-target.php?bytes=1048576&bps=200000&content=random
```

### Header + cookie pressure
```text
/api-stress-target.php?header_kb=64&cookie_n=8&cookie_bytes=128
```

### Random failures (10%) with jitter
```text
/api-stress-target.php?failrate=0.1&jitter_ms=100&bytes=10240
```

### CPU + memory pressure (careful)
```text
/api-stress-target.php?cpu_ms=200&mem_mb=64&bytes=0
```

### Gzip small HTML (chunked transfer)
```text
/api-stress-target.php?content=html&gzip=1&bytes=2048
```

### Range request support
```text
/api-stress-target.php?bytes=524288&accept_ranges=1&gzip=0
# Then issue a Range header from the client:
# curl -H "Range: bytes=0-999" "https://.../api-stress-target.php?bytes=524288&accept_ranges=1&gzip=0" -i
```

---

## Using with a browser test page

Point your front‑end **base URL** at your domain (the tool can auto‑append `api-stress-target.php`), and toggle parameters via the UI.  
If you set `$SECRET_TOKEN`, ensure the UI passes `&token=...` with each request.

---

## Troubleshooting

### “Blocked by CORS” in the browser
- If the server returns **5xx/4xx from the proxy before PHP runs** (e.g., header too large), there will be **no** CORS headers, and the browser shows a CORS error. Fix the underlying proxy issue first.
- Otherwise, set `cors=*` (default) or `cors=reflect` and confirm PHP actually executes (check server logs).

### 502 / 400 when using `header_kb` / many cookies
Your reverse proxy or FastCGI buffers are too small. Increase limits or dial down the preset.

**NGINX (examples):**
```nginx
large_client_header_buffers 8 64k;
proxy_buffer_size           64k;
proxy_buffers               16 64k;
proxy_busy_buffers_size     128k;

# If using php-fpm:
fastcgi_buffer_size         64k;
fastcgi_buffers             16 64k;
```

**Apache:**
```apache
LimitRequestFieldSize 65536
LimitRequestFields    200
```

Reload the server after changes.

### 404 Not Found
- The file path is wrong or not deployed where you think. Browse to `https://yourdomain.example/api-stress-target.php` directly.
- If your test page **auto‑appends** the filename, make sure your base URL has a trailing slash or a path where adding the file name makes sense.

### Mixed content / TLS errors
- If your test page is `https://`, the endpoint must also be `https://`, or the browser will block requests.

---

## Security & safety notes

- **Token protect**: Set `$SECRET_TOKEN` in the PHP to require `&token=...`.  
- **IP allowlist**: Optionally restrict by source IP at your web server / WAF.  
- **Rate limiting**: Consider basic per‑IP limits at the web server or CDN if exposing publicly.  
- **Robots**: Add `Disallow: /api-stress-target.php` in `robots.txt` to avoid crawlers poking it.  
- **Caps**: Hard caps (50MB payload, 10s TTFB/CPU, 256MB mem, 256KB headers) are baked in to reduce risk.

---

## Return details

- **200/204/206/etc.** with headers tailored to parameters.
- `Content-Length` is set when `gzip=0`. With `gzip=1`, output is typically chunked.
- `Accept-Ranges: bytes` advertised when `accept_ranges=1` and `gzip=0`; valid `Range` requests produce `206 Partial Content`.

---

## Integration hints

- Your front‑end can record per‑request stats (status, ms, bytes) and export a JSON bundle including an **AI prompt** for quick analysis.
- Consider presets like:
  - Latency (TTFB‑only)
  - Throughput (1–10 MB)
  - Header/cookie pressure
  - Random failures
  - CPU/MEM pressure
  - Range/206 testing

---

## Example minimal NGINX location (php‑fpm)

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;

    # Optional: larger buffers to survive header/cookie tests
    fastcgi_buffer_size 64k;
    fastcgi_buffers 16 64k;
}
```

---

## License

Use internally or with your clients at your discretion. If you need a formal license, MIT is a good default; let me know and I’ll include it.

---

## Changelog (brief)

- v1.0 — Initial release with payload shaping, throttling, gzip, status/redirect, header/cookies, fault injection, CPU/MEM load, CORS, Range support, safety caps.
