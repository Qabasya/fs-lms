<?php
// тут просто константы, которые должны быть в wp-config.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Тестовый ключ шифрования: 32 валидных байта в base64
define('FS_LMS_ENC_KEY', base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
define('FS_LMS_HASH_SALT', 'test-hash-salt-for-unit-tests');
define('FS_LMS_OTP_BYPASS_CODE', 'TEST_BYPASS_000');

// WP constants
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT',  'OBJECT'); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS'))   { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS'))    { define('DAY_IN_SECONDS', 86400); }

// WP class stubs
if (!class_exists('wpdb')) {
    class wpdb {
        public int $insert_id = 1;
        public string $last_error = '';
        public string $prefix = 'wp_';
        public function query(string $sql): bool|int { return 1; }
        public function prepare(string $sql, ...$args): string { return $sql; }
        public function insert(string $table, array $data, ?array $format = null): int|false { return 1; }
        public function update(string $table, array $data, array $where, $format = null, $where_format = null): int|false { return 1; }
        public function delete(string $table, array $where, $where_format = null): int|false { return 1; }
        public function get_var(string $query, int $x = 0, int $y = 0): mixed { return null; }
        public function get_row(string $query, string $output = 'OBJECT', int $y = 0): mixed { return null; }
        public function get_results(string $query, string $output = 'OBJECT'): array { return []; }
        public function get_col(string $query, int $x = 0): array { return []; }
        public function esc_like(string $str): string { return $str; }
        public function get_charset_collate(): string { return ''; }
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_email = '';
    }
}

// WP function stubs
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 0; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false {
        return json_encode($data, $flags, $depth);
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = '', string $scheme = 'http'): string {
        return 'http://example.com' . $path;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool {
        $GLOBALS['_test_transients'][$transient] = $value;
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed {
        return $GLOBALS['_test_transients'][$transient] ?? false;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool {
        unset($GLOBALS['_test_transients'][$transient]);
        return true;
    }
}
if (!function_exists('wp_count_terms')) {
    function wp_count_terms(array $args = []): int {
        return $GLOBALS['_test_wp_count_terms'] ?? 0;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        return $value;
    }
}
if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string {
        return '2024-01-01 12:00:00';
    }
}
if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed {
        return $GLOBALS['_test_options'][$option] ?? $default;
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata(int $userId): WP_User|false {
        return false;
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string {
        return substr(str_repeat('aB3$xY7!', 16), 0, max(1, $length));
    }
}

// HTTP API stubs (Yandex SmartCaptcha validation). Per-test response via $GLOBALS['_test_http_response'].
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(private string $msg = 'error') {}
        public function get_error_message(): string { return $this->msg; }
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = array()): mixed {
        $GLOBALS['_test_http_last'] = array('url' => $url, 'args' => $args);
        return $GLOBALS['_test_http_response'] ?? array('response' => array('code' => 200), 'body' => '{"status":"ok"}');
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $r): int {
        return is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0;
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $r): string {
        return is_array($r) ? (string) ($r['body'] ?? '') : '';
    }
}

// Программируемый дубль wpdb для интеграционных тестов репозиториев.
require_once __DIR__ . '/Support/FakeWpdb.php';

// Global wpdb instance used by TransactionRunner trait
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['_test_transients'] = [];
$GLOBALS['_test_wp_count_terms'] = 0;

// ---- Минимальное in-memory хранилище постов для unit-тестов банков контента ----
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_title = '';
        public string $post_status = 'publish';
        public int $post_author = 0;
        public string $post_content = '';
        public string $post_name = '';
        public function __construct(array $data = []) {
            foreach ($data as $k => $v) {
                if (property_exists($this, $k)) { $this->$k = $v; }
            }
        }
    }
}

$GLOBALS['_fs_test_posts'] = [];   // id => WP_Post
$GLOBALS['_fs_test_meta']  = [];   // id => [key => value]

/** Сбрасывает хранилище постов между тестами. */
function fs_test_reset_posts(): void {
    $GLOBALS['_fs_test_posts'] = [];
    $GLOBALS['_fs_test_meta']  = [];
}

/** Создаёт пост в хранилище; meta — массив или null. */
function fs_test_seed_post(array $data, ?array $meta = null): WP_Post {
    static $auto = 1;
    if (empty($data['ID'])) { $data['ID'] = $auto++; }
    $post = new WP_Post($data);
    $GLOBALS['_fs_test_posts'][$post->ID] = $post;
    if (null !== $meta) { $GLOBALS['_fs_test_meta'][$post->ID] = $meta; }
    return $post;
}

if (!function_exists('get_post')) {
    function get_post($id = null) {
        return $GLOBALS['_fs_test_posts'][(int) $id] ?? null;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $id, string $key = '', bool $single = false): mixed {
        $meta = $GLOBALS['_fs_test_meta'][$id] ?? [];
        if ('' === $key) { return $meta; }
        return $meta[$key] ?? ($single ? '' : []);
    }
}
if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array {
        $type     = $args['post_type'] ?? '';
        $statuses = (array) ($args['post_status'] ?? ['publish']);
        $out      = [];
        foreach ($GLOBALS['_fs_test_posts'] as $post) {
            if ($type && $post->post_type !== $type) { continue; }
            if (!in_array('any', $statuses, true) && !in_array($post->post_status, $statuses, true)) { continue; }
            $out[] = $post;
        }
        return $out;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post(array $arr): int {
        $id = (int) ($arr['ID'] ?? 0);
        if (!isset($GLOBALS['_fs_test_posts'][$id])) { return 0; }
        foreach ($arr as $k => $v) {
            if ('ID' !== $k && property_exists($GLOBALS['_fs_test_posts'][$id], $k)) {
                $GLOBALS['_fs_test_posts'][$id]->$k = $v;
            }
        }
        return $id;
    }
}

// ---- Простые функции санитайзинга (для полей и Sanitizer-трейта) ----
if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed { return is_string($value) ? stripslashes($value) : $value; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($str))); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
}
if (!function_exists('absint')) {
    function absint(mixed $n): int { return abs((int) $n); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string { return $content; }
}
if (!function_exists('user_can')) {
    function user_can(int $userId, string $cap): bool { return $GLOBALS['_fs_test_user_caps'][$userId][$cap] ?? false; }
}