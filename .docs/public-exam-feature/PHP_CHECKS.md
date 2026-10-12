# PHP-проверки рефакторинга public-exams

## PHPStan (2026-10-10)

PHPStan 2.3.1 PHAR получен из официального GitHub release; Composer/Packagist во временном каталоге завершился timeout. Основные composer.json/lock не менялись. Временные инструменты находятся вне репозитория и могут быть удалены системой.

```sh
php /private/tmp/fs-lms-phpstan.phar analyse \
  -c /private/tmp/fs-lms-phpstan-exam-complete.neon \
  --autoload-file=/private/tmp/fs-lms-phpstan-autoload.php \
  --no-progress --memory-limit=1G --error-format=json
```

Результат: 0 errors / 0 file_errors. Конфигурация без baseline и ignoreErrors:

```neon
includes:
    - /private/tmp/fs-lms-phpstan-tools/vendor/szepeviktor/phpstan-wordpress/extension.neon
parameters:
    stubFiles:
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/tests/StaticAnalysis/WooCommerce.stub
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
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Managers/Wp/PostManager.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/OptionsRepositories/ExamPagesVersionRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamAccessTokenRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamEventRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamGuestApplicationRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamGuestSessionRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamLockRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamManualResolutionRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamOperationKeyRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamOutboxEventRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamParticipantRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamParticipationRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamPaymentLinkRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamRegistrationRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamReportMemberRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamReportRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamSessionRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/ExamSourceRepository.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Managers/Woo/WooCommerceManager.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Managers/Person/UserManager.php
        - /Users/daniil/FS-LMS/wordpress/wp-content/plugins/fs-lms/inc/Repositories/WPDBRepositories/AbstractExamRepository.php
```

Автозагрузчик подключает vendor/autoload.php проекта, определяет FS_LMS_PLUGIN_FILE реальным путём к fs-lms.php и регистрирует namespace SzepeViktor\PHPStan\WordPress\ для src установленного расширения. Сам плагин не запускается. WP stubs лежат в соседнем vendor/php-stubs/wordpress-stubs, ожидаемом расширением. Woo stubs подключены bootstrapFiles.

WooCommerce.stub уточняет nullable-сессию: в установленном WooCommerce `public $session = null`, тогда как внешние stubs объявляют только WC_Session. Это описание реального API, без baseline/ignoreErrors. Менеджер проверяет наличие методов сессии, сохраняя поддержку сторонних обработчиков; позиции заказа проверяются как WC_Order_Item_Product. На настоящем Woo проверены null, совместимый/несовместимый обработчик и сохранённая HPOS-позиция; временный заказ удалён.

## PHPUnit и DI

```sh
docker exec -e FS_LMS_HTTP_BASE_URL=http://localhost \
  -w /var/www/html/wp-content/plugins/fs-lms wp_app php vendor/bin/phpunit
```

3277 тестов / 10202 assertions, без ошибок; 6 deprecations и 1461 notices остаются в проекте. Переменная HTTP включает четыре настоящих запроса к гостевым маршрутам без доступа. Без неё HTTP-тесты пропускаются. Позитивный вход дополнительно требует FS_LMS_HTTP_ENTRY_KEY от допущенного синтетического участия: без ключа один тест пропускается. Если внутренний адрес Docker отличается от home_url, задать FS_LMS_HTTP_HOST=localhost:8080. Отдельный прогон с ключом: 5 tests / 41 assertions; обмен, гостевая cookie, чистый URL, 200 и отсутствие WP-авторизации. Число учёток до/после не изменилось. Ключи не хранятся в репозитории; создавший фикстуру удаляет её записи/сессии/токены после проверки.

DI проверяется на настоящем WordPress: новый Container, bind ClockInterface → WpClock и LogEventDispatcherInterface → LogEventDispatcher (как в Init), затем get() каждого класса Init::getServices(). Повторно register() не вызывается. Все зарегистрированные зависимости разрешились.

## Оставшиеся проверки

PHPCS ещё не пройден: 39 errors / 8 warnings после описания конструкторов, назначения методов и условий исключений (2026-10-11). Последние 53 PHP-файла содержат только комментарии/пробелы: исполняемые токены совпадают с HEAD до этого этапа. Повторные PHPStan уровня 5 — 0 errors, PHPUnit — 3277 tests / 10202 assertions, 6 deprecations, 1461 notices, 1 skip (положительный HTTP-сценарий требует временного ключа). Предыдущий прогон положительного HTTP-сценария прошёл отдельно. После предыдущего сравнения 103 файлов дополнительно сравнены 23 PHP-файла AJAX/enum/отчёта: исполняемые токены не изменились. PHPStan и полный PHPUnit повторно пройдены. ExceptionNotEscaped исключён только для inc/Services/Exam (типизированные коды и сообщения для JSON/журнала); фактический небезопасный echo всё ещё отклоняется. Пробная файловая фикстура удалена. JS: 108 тестов, включая буквальное отображение серверной разметки в полевой и общей ошибке; ESLint изменённого теста пройден. FSE и валидные гостевые маршруты проверены в Chrome, форма/результат — desktop/mobile. Остаются persisted bfcache, валидная строка школьного отчёта, кабинет desktop/mobile и полный HTTP-рендер на обычной теме; ThemeCompat header/footer проверены отдельно на временной классической теме. Эти ограничения остаются в REFACTOR_PROGRESS.md. Зелёный PHPStan/ PHPUnit не заменяет их.


## Завершение PHP-стандартов F3 (2026-10-11)

PHPCS по контроллерам, коллбекам, сервисам, DTO/enum Exam, четырём гостевым страницам и миграции: **0 errors / 0 warnings**. Финальная команда:

```sh
vendor/bin/phpcs inc/Controllers/Exam inc/Callbacks/Exam inc/Services/Exam inc/DTO/Exam inc/Enums/Exam templates/frontend/exam-signup.php templates/frontend/exam-entry.php templates/frontend/exam-result.php templates/frontend/exam-report.php inc/Migrations/ExamPagesMigration.php
```

Три файла с чужими правками обработаны отдельно: собственный патч построен от HEAD, исполняемые токены собственного патча совпадают с HEAD при игнорировании необязательных завершающих запятых. Аналогично проверено сохранение чужих исполняемых изменений в рабочем дереве. Форматирование двух массивов пользовательского переключателя остаётся вместе с пользовательским кодом вне коммита.

Параметры readOnly/fn/empty переименованы в viewOnly/operation/isEmpty; именованные вызовы прежних параметров в inc/tests не найдены. Симметричное строгое сравнение аудитории приведено к Yoda. Порядок блокировок и переходы состояний не менялись. Два base64-предупреждения согласованы прямо на строках хранения и чтения шифротекста: это кодировка байтов для текстовых колонок, данные не исполняются.

Повторные PHPStan — 0 ошибок; полный PHPUnit — 3277 tests / 10202 assertions, 6 deprecations, 1461 notices, 1 skip без временного положительного HTTP-ключа. Браузерные ограничения, перечисленные выше, остаются открытыми.


## Дополнение 2026-10-12

Полный HTTP-рендер всех четырёх действительных гостевых страниц на временной классической теме пройден; постоянные настройки темы не менялись. Действительная строка школьного отчёта и её скрытие после отзыва синтетического согласия проверены в Chrome/FSE, включая разбор 27 заданий и отсутствие контактов в отчёте. Доска кабинета проверена desktop/mobile; найденное сжатие длинного селектора исправлено и повторно проверено. Остальные формы кабинета этим прогоном не подтверждены.

Наблюдатель настоящих pageshow-событий показал persisted=false при обоих возвратах; данные после отзыва скрыты, но фактическая ветка persisted=true остаётся открытой. Все временные записи, тема и MU-наблюдатель удалены. Полные JS-тесты 108/108, ESLint, Gulp build и DI 97 сервисов повторно пройдены; stylelint — 0 errors / 126 прежних warnings. Подробности, ограничения и точка продолжения — в последней контрольной точке REFACTOR_PROGRESS.md.
