<?php
/* =========================
   ShortLink Pro - index.php
   - Redirect: /?c=xxxxxx
   - Create: POST url=...
   - Cache: Redis (TLS)
   - Store: MySQL (RDS)
   - Secrets via ENV (safe for GitHub)
========================= */

/* =========================
   CONFIG (ENV VARS)
   Set these on the server:
   DB_HOST, DB_USER, DB_PASS, DB_NAME
   REDIS_HOST, REDIS_PORT (optional; default 6379)
========================= */
$db_host = getenv('DB_HOST') ?: '';
$db_user = getenv('DB_USER') ?: '';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: '';

$redis_host = getenv('REDIS_HOST') ?: '';
$redis_port = (int)(getenv('REDIS_PORT') ?: 6379);

// App behavior
$short_code_len = 6;         // hex length like 3c8f40
$recent_limit   = 10;
$cache_ttl_sec  = 3600;      // 1 hour

/* =========================
   HELPERS
========================= */
function safe_str($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function is_valid_url($url) {
    if (!is_string($url)) return false;
    $url = trim($url);
    if ($url === '') return false;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return in_array(strtolower($scheme), ['http', 'https'], true);
}

function generate_code_hex($len = 6) {
    // 6 hex chars => 3 bytes
    $bytes = (int)ceil($len / 2);
    $hex = bin2hex(random_bytes($bytes));
    return substr($hex, 0, $len);
}

/* =========================
   DB CONNECTION
========================= */
$db_connected = false;
$db_error = '';

$pdo = null;

// If env vars missing, do not attempt DB connect
$has_db_env = ($db_host !== '' && $db_user !== '' && $db_pass !== '' && $db_name !== '');

if ($has_db_env) {
    try {
        $pdo = new PDO(
            "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2, // prevent long hangs
            ]
        );
        $db_connected = true;
    } catch (PDOException $e) {
        $db_connected = false;
        $db_error = $e->getMessage();
    }
} else {
    $db_connected = false;
    $db_error = "DB env vars not set (DB_HOST/DB_USER/DB_PASS/DB_NAME).";
}

/* =========================
   REDIS (TLS + SAFE)
========================= */
$redis = null;
$redis_connected = false;

$has_redis_env = ($redis_host !== '');

if ($has_redis_env && class_exists('Redis')) {
    try {
        $redis = new Redis();

        // TLS + timeouts to prevent hanging
        $redis_connected = $redis->connect("tls://" . $redis_host, $redis_port, 1.5);
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 1.5);

    } catch (Throwable $e) {
        $redis = null;
        $redis_connected = false;
    }
}

/* =========================
   EC2 METADATA (IMDSv2)
========================= */
function imds_get($path) {
    $token = @file_get_contents(
        'http://169.254.169.254/latest/api/token',
        false,
        stream_context_create([
            'http' => [
                'method'  => 'PUT',
                'header'  => "X-aws-ec2-metadata-token-ttl-seconds: 21600\r\n",
                'timeout' => 1
            ]
        ])
    );

    if (!$token) return 'Unknown';

    $val = @file_get_contents(
        "http://169.254.169.254/latest/meta-data/$path",
        false,
        stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "X-aws-ec2-metadata-token: $token\r\n",
                'timeout' => 1
            ]
        ])
    );

    return $val ? $val : 'Unknown';
}

$instance_id = imds_get('instance-id');
$az          = imds_get('placement/availability-zone');

/* =========================
   REDIRECT HANDLER
   /?c=abc123  -> redirects
========================= */
if (isset($_GET['c'])) {
    $code = trim((string)$_GET['c']);

    // allow only hex codes, avoids weird inputs
    if (!preg_match('/^[a-f0-9]{4,32}$/i', $code)) {
        http_response_code(400);
        echo "Invalid code";
        exit;
    }

    // 1) Try Redis cache first (never block redirect)
    if ($redis_connected) {
        try {
            $cached = $redis->get("url:$code");
            if (!empty($cached)) {
                header("Location: " . $cached, true, 302);
                exit;
            }
        } catch (Throwable $e) {
            $redis_connected = false; // fall back to DB
        }
    }

    // 2) Fallback to DB
    if ($db_connected) {
        try {
            $stmt = $pdo->prepare("SELECT original_url FROM urls WHERE short_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $row = $stmt->fetch();

            if ($row && !empty($row['original_url'])) {
                // Save to Redis for 1 hour (best-effort)
                if ($redis_connected) {
                    try {
                        $redis->setex("url:$code", 3600, $row['original_url']);
                    } catch (Throwable $e) {
                        // ignore cache errors
                    }
                }

                header("Location: " . $row['original_url'], true, 302);
                exit;
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo "DB error";
            exit;
        }
    }

    http_response_code(404);
    echo "Not found";
    exit;
}

/* =========================
   CREATE SHORTLINK (POST)
========================= */
$success = '';
$error   = '';
$short_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_url = isset($_POST['url']) ? trim((string)$_POST['url']) : '';

    if (!is_valid_url($input_url)) {
        $error = "Please enter a valid http/https URL.";
    } elseif (!$db_connected) {
        $error = "Database not connected.";
    } else {

        // ✅ Prevent shortening our own shortlinks again
        $in_host  = strtolower((string)parse_url($input_url, PHP_URL_HOST));
        $in_query = (string)parse_url($input_url, PHP_URL_QUERY);

        $my_hosts = [
            strtolower($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''),
            strtolower($_SERVER['HTTP_HOST'] ?? ''),
        ];
        $my_hosts = array_values(array_filter(array_unique($my_hosts)));

        $is_our_shortlink =
            $in_host !== ''
            && in_array($in_host, $my_hosts, true)
            && $in_query !== ''
            && preg_match('/(^|&)c=([a-f0-9]{4,32})(&|$)/i', $in_query);

        if ($is_our_shortlink) {
            $success   = "This is already a ShortLink.";
            $short_url = $input_url;   // show the same URL back
            $error     = '';
        } else {
            try {
                // Optional: de-duplicate if URL exists already
                $stmt = $pdo->prepare("SELECT short_code FROM urls WHERE original_url = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$input_url]);
                $existing = $stmt->fetch();

                if ($existing && !empty($existing['short_code'])) {
                    $code = $existing['short_code'];
                } else {
                    // generate unique code
                    $max_tries = 10;
                    $code = '';
                    for ($i = 0; $i < $max_tries; $i++) {
                        $candidate = generate_code_hex($short_code_len);

                        // check uniqueness
                        $chk = $pdo->prepare("SELECT 1 FROM urls WHERE short_code = ? LIMIT 1");
                        $chk->execute([$candidate]);
                        $found = $chk->fetchColumn();

                        if (!$found) {
                            $code = $candidate;
                            break;
                        }
                    }

                    if ($code === '') {
                        throw new RuntimeException("Failed to generate unique short code.");
                    }

                    // insert (assumes table has id, short_code, original_url, created_at)
                    // If your table doesn't have created_at, remove it from query.
                    $ins = $pdo->prepare("INSERT INTO urls (short_code, original_url, created_at) VALUES (?, ?, NOW())");
                    $ins->execute([$code, $input_url]);
                }

                // cache in Redis (best-effort)
                if ($redis_connected) {
                    try {
                        $redis->setex("url:$code", $cache_ttl_sec, $input_url);
                    } catch (Throwable $e) {
                        // ignore cache errors
                    }
                }

                // build short URL for display (CloudFront/ALB host)
                $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
                    ? $_SERVER['HTTP_X_FORWARDED_PROTO']
                    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

                $host = (!empty($_SERVER['HTTP_X_FORWARDED_HOST']))
                    ? $_SERVER['HTTP_X_FORWARDED_HOST']
                    : (($_SERVER['HTTP_HOST'] ?? 'localhost'));

                $short_url = $scheme . "://" . $host . "/?c=" . $code;
                $success = "Short link created!";
            } catch (Throwable $e) {
                $error = "Failed to create short link.";
            }
        }
    }
}

/* =========================
   FETCH RECENTS
========================= */
$recent = [];
if ($db_connected) {
    try {
        $stmt = $pdo->prepare("SELECT short_code, original_url FROM urls ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, (int)$recent_limit, PDO::PARAM_INT);
        $stmt->execute();
        $recent = $stmt->fetchAll();
    } catch (Throwable $e) {
        $recent = [];
    }
}

/* =========================
   UI
========================= */
?>
<!DOCTYPE html>
<html>
<head>
    <title>ShortLink Pro</title>
    <style>
        body{font-family:Arial;max-width:900px;margin:50px auto;padding:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)}
        .container{background:white;border-radius:12px;padding:30px;box-shadow:0 10px 40px rgba(0,0,0,0.2)}
        h1{color:#667eea;text-align:center;margin-bottom:10px}
        .subtitle{text-align:center;color:#666;margin-bottom:30px}
        .card{background:#f8f9fa;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #667eea}
        .instance-info{background:#667eea;color:white;padding:15px;border-radius:8px;margin-bottom:20px;font-size:14px}
        input[type="url"]{width:100%;padding:12px;border:2px solid #ddd;border-radius:6px;font-size:16px;box-sizing:border-box}
        button{background:#667eea;color:white;border:none;padding:12px 30px;border-radius:6px;cursor:pointer;font-size:16px;margin-top:10px}
        button:hover{background:#764ba2}
        .success{background:#4CAF50;color:white;padding:12px;border-radius:6px;margin:15px 0}
        .error{background:#f44336;color:white;padding:12px;border-radius:6px;margin:15px 0}
        .short-url{background:#e3f2fd;padding:15px;border-radius:6px;margin:15px 0;font-size:18px;word-break:break-all}
        .url-list{list-style:none;padding:0}
        .url-item{background:white;padding:12px;margin:8px 0;border-radius:6px;border:1px solid #ddd}
        a{color:#667eea;text-decoration:none}
        a:hover{text-decoration:underline}
    </style>
</head>
<body>
    <div class="container">
        <h1>🔗 ShortLink Pro</h1>
        <p class="subtitle">Professional URL Shortener</p>

        <div class="instance-info">
            🖥️ <?= safe_str($instance_id) ?> |
            📍 <?= safe_str($az) ?> |
            <?= $db_connected ? "✅ DB Connected" : "❌ DB Not Connected" ?> |
            <?= $redis_connected ? "⚡ Redis Connected" : "❌ Redis Not Connected" ?>
        </div>

        <?php if (!empty($success)): ?>
            <div class="success"><?= safe_str($success) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error"><?= safe_str($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($short_url)): ?>
            <div class="short-url">
                <strong>Short URL:</strong><br>
                <a href="<?= safe_str($short_url) ?>"><?= safe_str($short_url) ?></a>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>✂️ Shorten URL</h2>
            <form method="POST">
                <input type="url" name="url" placeholder="Enter long URL..." required>
                <button type="submit">🚀 Shorten</button>
            </form>
        </div>

        <div class="card">
            <h2>📋 Recent URLs (<?= (int)$recent_limit ?>)</h2>
            <?php if (empty($recent)): ?>
                <p>No URLs yet.</p>
            <?php else: ?>
                <ul class="url-list">
                    <?php foreach ($recent as $r): ?>
                        <?php
                            $code = $r['short_code'] ?? '';
                            $orig = $r['original_url'] ?? '';
                            $display = $orig;
                            if (strlen($display) > 30) $display = substr($display, 0, 30) . '...';
                        ?>
                        <li class="url-item">
                            <strong>
                                <a href="/?c=<?= safe_str($code) ?>">
                                    /?c=<?= safe_str($code) ?>
                                </a>
                            </strong><br>
                            <small><?= safe_str($display) ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
