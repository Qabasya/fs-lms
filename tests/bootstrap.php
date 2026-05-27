<?php
// тут просто константы, которые должны быть в wp-config.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Тестовый ключ шифрования: 32 валидных байта в base64
define('FS_LMS_ENC_KEY', base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
define('FS_LMS_HASH_SALT', 'test-hash-salt-for-unit-tests');