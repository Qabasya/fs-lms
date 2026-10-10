# PHP-проверки рефакторинга public-exams

## PHPStan (2026-10-10)

PHPStan 2.3.1 PHAR получен из официального GitHub release; Composer/Packagist во временном каталоге завершился timeout. Основные composer.json/lock не менялись. Временные инструменты находятся вне репозитория и могут быть удалены системой.

```sh
php /private/tmp/fs-lms-phpstan.phar analyse \
  -c /private/tmp/fs-lms-phpstan-exam.neon \
  --autoload-file=/private/tmp/fs-lms-phpstan-autoload.php \
  --no-progress --memory-limit=1G --error-format=json
```

Результат: 0 errors / 0 file_errors. Конфигурация без baseline и ignoreErrors:

```neon
includes:
    - /private/tmp/fs-lms-phpstan-tools/vendor/szepeviktor/phpstan-wordpress/extension.neon
parameters:
    level: 5
    phpVersion: 80300
    tmpDir: /private/tmp/fs-lms-phpstan-cache-analysis
    bootstrapFiles:
        - /private/tmp/fs-lms-woocommerce-stubs/woocommerce-stubs.php
        - /private/tmp/fs-lms-woocommerce-stubs/woocommerce-packages-stubs.php
    paths:
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Controllers/Exam
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Callbacks/Exam
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Services/Exam
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/DTO/Exam
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Enums/Exam
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Migrations/ExamPagesMigration.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Services/System/PageGeneratorService.php
```

Автозагрузчик подключает vendor/autoload.php проекта, определяет FS_LMS_PLUGIN_FILE реальным путём к fs-lms.php и регистрирует namespace SzepeViktor\PHPStan\WordPress\ для src установленного расширения. Сам плагин не запускается. WP stubs лежат в соседнем vendor/php-stubs/wordpress-stubs, ожидаемом расширением. Woo stubs подключены bootstrapFiles.

## PHPUnit и DI

```sh
docker exec -e FS_LMS_HTTP_BASE_URL=http://localhost \
  -w /var/www/html/wp-content/plugins/fs-lms wp_app php vendor/bin/phpunit
```

3273 теста / 10200 assertions, без ошибок; 6 deprecations и 1461 notices остаются в проекте. Переменная HTTP включает четыре настоящих запроса к гостевым маршрутам без доступа. Без неё HTTP-тесты пропускаются.

DI проверяется на настоящем WordPress: новый Container, bind ClockInterface → WpClock и LogEventDispatcherInterface → LogEventDispatcher (как в Init), затем get() каждого класса Init::getServices(). Повторно register() не вызывается. Все зарегистрированные зависимости разрешились.

## Оставшиеся проверки

PHPCS ещё не пройден. Финальные браузерные проверки FSE, валидных entry/result/report, desktop/mobile и bfcache также остаются в REFACTOR_PROGRESS.md. Зелёный PHPStan/ PHPUnit не заменяет их.
