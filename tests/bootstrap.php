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
if (!defined('WEEK_IN_SECONDS'))   { define('WEEK_IN_SECONDS', 604800); }
if (!defined('YEAR_IN_SECONDS'))   { define('YEAR_IN_SECONDS', 31536000); }

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
    // Управляется $GLOBALS['_fs_test_user_id'] (сбрасывается в fs_test_reset_ajax).
    function get_current_user_id(): int { return $GLOBALS['_fs_test_user_id'] ?? 0; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return $GLOBALS['_test_logged_in'] ?? true; }
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
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'http://example.com/wp-admin/' . $path;
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
// Термы таксономии {key}_task_number для EgeCompletenessChecker.
// Хранилище: $GLOBALS['_fs_test_terms'][taxonomy] = [ ['slug'=>.., 'name'=>..], ... ]
//            $GLOBALS['_fs_test_post_terms'][postId][taxonomy] = ['slug', ...]
if (!function_exists('get_terms')) {
    function get_terms(array $args = []): array {
        $taxonomy = is_array($args['taxonomy'] ?? null) ? ($args['taxonomy'][0] ?? '') : ($args['taxonomy'] ?? '');
        $rows     = $GLOBALS['_fs_test_terms'][$taxonomy] ?? [];
        return array_map(static function (array $r) {
            $o = new stdClass();
            $o->slug = (string) $r['slug'];
            $o->name = (string) ($r['name'] ?? $r['slug']);
            return $o;
        }, $rows);
    }
}
if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms(int $postId, string $taxonomy = '', array $args = []): array {
        return $GLOBALS['_fs_test_post_terms'][$postId][$taxonomy] ?? [];
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        return $value;
    }
}
if (!function_exists('do_action')) {
    // Колбэки не вызываются (как и в apply_filters выше), но факт вызова записывается —
    // тесты могут проверить, что сервис дёрнул generic-шов (например, fs_lms_video_registered).
    function do_action(string $hook, mixed ...$args): void {
        $GLOBALS['_fs_test_actions'][] = ['hook' => $hook, 'args' => $args];
    }
}
if (!function_exists('add_filter')) {
    // apply_filters() выше — заглушка-passthrough, зарегистрированные колбэки
    // никогда не вызываются, поэтому add_filter() здесь тоже no-op.
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        return true;
    }
}
if (!function_exists('has_filter')) {
    // add_filter() выше не запоминает регистрации — по умолчанию считаем «не подписан»
    // (модуль выключен/не настроен); тест может переопределить через $GLOBALS.
    function has_filter(string $hook, callable|bool $callback = false): int|bool {
        return $GLOBALS['_fs_test_has_filter'][$hook] ?? false;
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
if (!function_exists('user_can')) {
    function user_can(int $userId, string $cap): bool {
        return $GLOBALS['_test_user_can'][$userId][$cap] ?? false;
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
if (!function_exists('get_the_title')) {
    function get_the_title($id = 0): string {
        $p = get_post(is_object($id) ? $id->ID : (int) $id);
        return $p instanceof WP_Post ? $p->post_title : '';
    }
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id = 0, $context = 'display'): string {
        return 'post.php?post=' . (int) (is_object($id) ? $id->ID : $id) . '&action=edit';
    }
}
if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision($post): bool { return false; }
}
if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($post): bool { return false; }
}
if (!function_exists('wp_editor')) {
    function wp_editor(string $content, string $editor_id, array $settings = []): void {
        echo '<textarea id="' . $editor_id . '">' . $content . '</textarea>';
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
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $id, string $key, mixed $value): bool {
        $GLOBALS['_fs_test_meta'][$id][$key] = $value;
        return true;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $arr): int {
        static $auto = 9000;
        $id = (int) ($arr['ID'] ?? 0);
        if ($id <= 0) { $id = ++$auto; }
        $post = $GLOBALS['_fs_test_posts'][$id] ?? new WP_Post(['ID' => $id]);
        foreach ($arr as $k => $v) {
            if ('ID' !== $k && property_exists($post, $k)) { $post->$k = $v; }
        }
        $post->ID = $id;
        $GLOBALS['_fs_test_posts'][$id] = $post;
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
if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type(int $postId): string|false {
        return $GLOBALS['_fs_test_post_mime_types'][$postId] ?? false;
    }
}
if (!function_exists('user_can')) {
    function user_can(int $userId, string $cap): bool { return $GLOBALS['_fs_test_user_caps'][$userId][$cap] ?? false; }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9_\-]+/', '-', $title);
        return trim($title, '-');
    }
}

// ────────────────────────────────────────────────────────────────
//  AJAX-callback harness — позволяет тестировать *Callbacks::ajax*().
//  Транспорт (BaseController ctor, authorize, success/error) застаблен;
//  wp_send_json_* бросает FsTestJsonResponse вместо exit, чтобы тест
//  перехватил ответ. Управление авторизацией — через глобалы ниже.
// ────────────────────────────────────────────────────────────────
if (!function_exists('plugin_dir_path')) { function plugin_dir_path(string $f): string { return rtrim($f, '/\\') . '/'; } }
if (!function_exists('plugin_dir_url'))  { function plugin_dir_url(string $f): string { return 'http://example.test/'; } }
if (!function_exists('plugin_basename')) { function plugin_basename(string $f): string { return basename($f); } }

if (!function_exists('current_user_can')) {
    // Управляется $GLOBALS['_fs_test_can'] (по умолчанию — есть права).
    function current_user_can(string $cap): bool { return $GLOBALS['_fs_test_can'] ?? true; }
}
if (!function_exists('check_ajax_referer')) {
    // Управляется $GLOBALS['_fs_test_nonce_ok']; при невалидном — поведение WP (json error).
    function check_ajax_referer($action, $query_arg = false, $die = true) {
        if (($GLOBALS['_fs_test_nonce_ok'] ?? true) === false) { wp_send_json_error('Неверный nonce'); }
        return 1;
    }
}

/** Ответ AJAX-хендлера, перехваченный вместо wp_send_json_*()+exit. */
class FsTestJsonResponse extends \Exception {
    public bool $success;
    public mixed $payload;
    public function __construct(bool $success, mixed $payload) {
        parent::__construct('fs-test-json');
        $this->success = $success;
        $this->payload = $payload;
    }
}
// Записываем ПЕРВЫЙ json-ответ в глобал и бросаем (как exit в проде). Если хендлер
// оборачивает success() в catch(\Throwable) и затем зовёт error(), первый (success)
// уже зафиксирован и не перезатирается — тест видит реальный ответ.
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null): void {
        if (!isset($GLOBALS['_fs_test_json'])) { $GLOBALS['_fs_test_json'] = new FsTestJsonResponse(true, $data); }
        throw $GLOBALS['_fs_test_json'];
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null): void {
        if (!isset($GLOBALS['_fs_test_json'])) { $GLOBALS['_fs_test_json'] = new FsTestJsonResponse(false, $data); }
        throw $GLOBALS['_fs_test_json'];
    }
}

/**
 * Вызывает AJAX-хендлер и возвращает ПЕРВЫЙ перехваченный JSON-ответ.
 * Бросает, если хендлер не вызвал success/error.
 */
function fs_test_capture_json(callable $fn): FsTestJsonResponse {
    $GLOBALS['_fs_test_json'] = null;
    try {
        $fn();
    } catch (FsTestJsonResponse $r) {
        // ответ зафиксирован в глобале
    }
    if (!isset($GLOBALS['_fs_test_json'])) {
        throw new \RuntimeException('AJAX-хендлер не вызвал wp_send_json_success/error');
    }
    return $GLOBALS['_fs_test_json'];
}

// i18n / escaping — заглушки (возвращают вход без изменений).
if (!function_exists('__'))           { function __($text, $domain = null) { return $text; } }
if (!function_exists('_e'))           { function _e($text, $domain = null): void { echo $text; } }
if (!function_exists('esc_html'))     { function esc_html($text) { return $text; } }
if (!function_exists('esc_attr'))     { function esc_attr($text) { return $text; } }
if (!function_exists('esc_html__'))   { function esc_html__($text, $domain = null) { return $text; } }
if (!function_exists('esc_attr__'))   { function esc_attr__($text, $domain = null) { return $text; } }

/** Управляется $GLOBALS['_fs_test_users_by'][$field][$value] (WP_User); нет записи — false. */
function get_user_by( string $field, mixed $value ): WP_User|false {
    return $GLOBALS['_fs_test_users_by'][$field][(string) $value] ?? false;
}

/** Возвращает пустую строку — миниатюры не нужны в unit-тестах. */
function get_the_post_thumbnail_url( int $post_id, string $size = 'post-thumbnail' ): string {
    return '';
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
}
if (!function_exists('wp_timezone')) {
    // Таймзона сайта; управляется $GLOBALS['_fs_test_timezone'] (по умолчанию UTC).
    function wp_timezone(): DateTimeZone { return new DateTimeZone($GLOBALS['_fs_test_timezone'] ?? 'UTC'); }
}
if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool {
        $GLOBALS['_test_options'][$option] = $value;
        return true;
    }
}

// ---- REST-стабы (модульные REST-контроллеры: AdSync, VideoLibrary) ----
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $headers = [];
        private array $params  = [];
        private string $body   = '';
        public function set_header(string $key, string $value): void { $this->headers[strtolower($key)] = $value; }
        public function get_header(string $key): ?string { return $this->headers[strtolower($key)] ?? null; }
        public function set_body(string $body): void { $this->body = $body; }
        public function get_body(): string { return $this->body; }
        public function set_param(string $key, mixed $value): void { $this->params[$key] = $value; }
        public function get_param(string $key): mixed { return $this->params[$key] ?? ($this->get_json_params()[$key] ?? null); }
        public function get_json_params(): mixed { return '' !== $this->body ? json_decode($this->body, true) : $this->params; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public function __construct(private mixed $data = null, private int $status = 200) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string { return $url; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($args);
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink(int|object $post = 0): string|false {
        $id = is_object($post) ? $post->ID : $post;
        return get_post($id) instanceof WP_Post ? "http://example.com/?p={$id}" : false;
    }
}
if (!function_exists('wp_get_attachment_url')) {
    // Управляется $GLOBALS['_fs_test_attachment_urls'][id] (нет записи — false, как в WP).
    function wp_get_attachment_url(int $id): string|false { return $GLOBALS['_fs_test_attachment_urls'][$id] ?? false; }
}
if (!function_exists('get_attached_file')) {
    function get_attached_file(int $id): string|false { return $GLOBALS['_fs_test_attached_files'][$id] ?? false; }
}
if (!function_exists('size_format')) {
    function size_format(int|float $bytes, int $decimals = 0): string|false { return $bytes . ' B'; }
}

/** Сбрасывает флаги авторизации харнесса к «всё разрешено» (вызывать в setUp). */
function fs_test_reset_ajax(): void {
    $GLOBALS['_fs_test_can']      = true;
    $GLOBALS['_fs_test_nonce_ok'] = true;
    unset($GLOBALS['_fs_test_user_id']);
    $_POST = [];
    $_GET  = [];
}