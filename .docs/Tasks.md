сквозное ревью проекта

Безопасность

2S1 — высокий. Публичное перечисление пользователей без ограничения частоты

inc/Callbacks/Enrollment/ApplicationCallbacks.php:427-437 и :439-449

ajaxCheckUsernameAvailable и ajaxCheckEmailAvailable зарегистрированы как nopriv
(ApplicationController::publicAjaxActions():79-82). Оба проверяют нонс — и на этом всё.
Остальные четыре публичных действия того же класса поголовно проходят через RateLimitService
(:123, :255, :270, :298, :382), эти два — нет.

Нонс не барьер: он печатается в публичную страницу заявки (fs_lms_apply_vars.nonces), для
разлогиненного посетителя живёт 12-24 часа и одинаков для всех. Достаточно взять его один раз и
дальше без ограничений опрашивать username_exists() / email_exists().

Что утекает: факт регистрации по произвольному email — это подтверждение персональных данных
(«такой-то учится в этой школе»), проверяемое пакетно по списку адресов. Плюс перебор логинов
под будущий подбор пароля.

Фикс: те же RateLimitService-лимиты по IP, что и у соседних действий класса; для email —
жёстче (это проверка ПД, а не удобство ввода). Ответ стоит унифицировать по времени.

2S2 — средний. IDOR в статусе провижна AD

inc/Modules/AdSync/Controllers/AdSyncController.php:85-91

nopriv-эндпоинт принимает $_POST['ref'] — сырой ID заявки — и отдаёт состояние провижна
(none / pending / done / failed). Владение не проверяется, лимита частоты нет, нонс
публичный (Nonce::Apply). ID заявок последовательные → анонимный перебор даёт факт
существования заявок, их количество и темп поступления.

Фикс: опрашивать не по ID, а по одноразовому непредсказуемому токену, который выдаётся
подавшему в filterApplyResponse():73-79 и живёт в транзиенте рядом с ID.

2S3 — информационно. SQL-слой чист

Прогон по всем $wpdb-вызовам: идентификаторы через %i, списки IN (…) собираются
array_fill()-плейсхолдерами и уходят в prepare() (NotificationRepository:148-154,
ApplicationRepository:89-121, PersonRepository:73-77, AbstractLogRepository:196-198,
TermManager:307-314). Конкатенации пользовательского ввода в SQL не нашёл. Инъекционных
находок нет — фиксирую, чтобы приоритет ушёл в 2S1/2S2, а не в перепроверку SQL.

2S4 — информационно. Escaping в шаблонах: безопасно, но не проверяемо

Все 30+ срабатываний «echo без esc_» в templates/ разобраны вручную: во фронтовых шаблонах
это тернарники с литералами (all-tasks-body.php, player.php, step-task.php и т.д.), в
админских — переменные, экранированные заранее
(settings-9-rooms.php:52-53: $row_name = esc_attr( $room->name ), дальше echo $row_name).

Уязвимости нет. Но приём «экранируем в одном месте, печатаем в семи» ломает и ревью, и phpcs:
одна новая строка $row_name = $room->name без esc_attr превращается в хранимую XSS
незаметно. Экранировать стоит в точке вывода — settings-9-rooms.php, settings-3-periods.php.

2S5. Все phpcs-подавления обоснованы

200 phpcs:ignore по проекту, каждое с причиной; 4 подавления NonceVerification.Missing
проверены поимённо — во всех случаях нонс действительно проверен строкой выше либо это ранний
хук до фактического сейва. Отдельных находок нет.

Архитектура и правила проекта

2A1. Прямые WP data API вне Managers/Repositories

Правило: «Do NOT use WP_Query, get_posts, update_option, update_post_meta directly».
Нарушители (по одному-двум вызовам, но в слоях, которым это запрещено):
- inc/Controllers/Task/MetaBoxController.php:162 — get_post_meta в контроллере;
- inc/MetaBoxes/Templates/BaseTemplate.php:84, ThreeInOneTemplate.php:91;
- inc/Services/Template/TemplateResolver.php:79,81;
- inc/Services/Assessment/TaskPreviewService.php:91,93.

Во всех пяти читается одна и та же PostMetaName::Meta — просится один метод
PostManager::taskMeta( int $post_id ): array и полное исчезновение прямых вызовов.

2A2. Прямая работа с суперглобалами вместо трейта Sanitizer

69 обращений к $_POST/$_GET/$_REQUEST/$_FILES в 35 файлах при живом трейте с
sanitizeInt()/sanitizeKey()/sanitizeText(). Крупнейший — Controllers/Course/CoursePreviewController.php
(9 обращений, :40-88), причём разбор ?course=&lesson=&step= продублирован в loadTemplate()
и currentDeepLink(). Данные там кастуются и проверяются на доступ — это не дыра, а
разъезжающаяся конвенция и лишний код.

2A3. current_user_can() напрямую в Callback-классе

inc/Callbacks/Subject/SubjectValidationCallbacks.php:323 — прямой вызов вместо
$this->authorize(). По CLAUDE.md это запрещено явно («Never call check_ajax_referer() or
current_user_can() directly in Callback methods»). Единственный такой случай во всех Callbacks
— остальные 150+ AJAX-методов дисциплинированы: сверка «методов ajax* против guard'ов» по всем
59 Callback-классам дала совпадение или перевес guard'ов везде.

2A4. AJAX мимо всей конвенции сразу

inc/Modules/AdSync/Controllers/AdSyncController.php:35-36,85 — хук регистрируется сырой строкой
('wp_ajax_nopriv_' . self::STATUS_ACTION) мимо enum AjaxHook, а обработчик живёт в
контроллере, хотя в том же модуле есть AdSyncSettingsCallbacks. Нарушены три правила:
ключи-только-через-энумы, «AJAX-логика только в Callbacks», «контроллеры только регистрируют».

2A5. Транзиенты мимо TransientManager

inc/Modules/VideoLibrary/Services/RecordingAlertService.php:42,53 — сырые
set_transient/get_transient. Документированное исключение из CLAUDE.md покрывает только
RateLimitService, EmailOtpService, TaskPublishGuard; этот сервис в список не входит.
Либо ключ переезжает в Enums/Wp/TransientKey, либо сервис дописывается в исключения.

2A6. declare(strict_types=1) отсутствует в 37 файлах из 738

Правило требует его в каждом. Среди отсутствующих — не периферия, а базовые вещи:
Shared/Traits/Sanitizer.php, Shared/Traits/ErrorHandler.php, Shared/Traits/TemplateRenderer.php,
Managers/Wp/CPTManager.php, Enums/Access/UserRole.php, Controllers/Subject/SubjectController.php.
В файле без строгой типизации (int) "12abc" и "1" == 1 ведут себя иначе, чем в соседнем — это
не косметика.

2A7. Инлайновый <svg> в PHP-шаблонах

Правило: иконки только из Inc\Enums\Ui\Icon. Нарушают два файла —
templates/admin/components/modals/partials/provider-logo.php и
inc/Modules/SocialAuth/templates/settings-tab.php. Оба про логотипы OAuth-провайдеров: их в
Icon нет и, возможно, быть не должно (бренд ≠ UI-глиф) — тогда исключение нужно записать в
CLAUDE.md, а не оставлять молча. В JS-бандлах правило соблюдено полностью: инлайновых <svg>
вне common/icons.js нет ни одного.

2A8. Правило «JS не задаёт стили» соблюдается наполовину

Две конкурирующие идиомы на одну задачу. Правильная — отдать число в CSS-переменную
(article-aside.js:49, modules/task-condition.js:140, modal-base.js:55). Прямое присваивание
стилей: profile/utils.js:130-179 (7 мест), admin/services/step-editor.js:893-961 (6),
player/shell.js:19, player/step-work.js:87, player/step-video.js:46-47, player/core.js:100,
frontend/components/article-carousel.js:50-51.

Позиционирование поповеров по геометрии — честное исключение. Но четыре прогресс-бара
(style.width = '…%') переводятся в --progress механически, а article-carousel.js:50
(style.transition = …) — это анимационный конфиг в JS, ему место в классе.

Производительность

2P1. getMeta() в цикле по ID, не пришедшим из WP_Query

PostManager::primeMetaCache() существует и используется ровно в 3 местах на весь плагин. При
этом есть циклы, где ID берутся из своих таблиц (ответы, попытки), то есть мета-кэш WP не
прогрет ничем, и каждый виток — отдельный запрос:
- Services/Assessment/AttemptTaskViewBuilder.php:58,60 — по два getMeta на задание попытки;
- Services/Assessment/AttemptResultService.php:45;
- Services/Course/WorkDetailService.php:150;
- Services/Course/ContentUsageService.php:156,317,344,370 — четыре разных цикла в одном сервисе.

Контрольная на 30 заданий — это 60+ лишних запросов на просмотр результата. Лечится одной
строкой primeMetaCache( $ids ) перед каждым циклом.

2P2. Выборки без лимита

posts_per_page => -1 / numberposts => -1 в 8 местах. Ограниченные по природе (все статьи
одного номера задания) вопросов не вызывают, но Controllers/Builders/AllTasksDataBuilder.php:366
и Managers/Wp/PostManager.php:44,62,130,283 растут вместе с банком заданий — на большом
предмете это выборка всего банка в память. Нужен явный потолок с логированием обрезки.

Дублирование и мёртвый код

2D1. Пять локальных копий общих утилит

Правило src/js/CLAUDE.md: «Доменные бандлы реэкспортируют их под своими именами — своих копий
не заводить». Заведены:
- escapeHtml: admin/services/step-editor.js:62, admin/services/tables/students-table.js:183,
  admin/modals/enrollment/teacher-view-modal.js:116, frontend/components/sidebar-articles.js:16;
- debounce: второй экземпляр в frontend/services/assessment.js:61.

Три из четырёх esc реализованы через $('<div>').text().html(), четвёртая — через String() с
заменами: то есть ещё и разное экранирование в разных бандлах.

2D2. Файлы, переросшие свою роль

src/js/admin/services/step-editor.js (46 КБ), src/js/profile/ktp.js (44 КБ),
inc/Core/Enqueue.php (30 КБ), inc/Services/Profile/LearnerService.php (25 КБ),
inc/Callbacks/Enrollment/EnrollmentCallbacks.php (23 КБ, 17 AJAX-методов),
inc/Controllers/Course/LearningMenuController.php (11 хуков, 30 методов: меню + фильтры
list-table + модалка + «хром» банка).

Enqueue.php раздувается по прямому требованию CLAUDE.md («все wp_localize_script — только
здесь»), то есть правило и размер связаны: разумный выход — оставить правило, но разложить файл
на per-bundle энкьюеры с общим фасадом.

2D3. Проверенные гипотезы, оказавшиеся ложными

Чтобы не тратить время повторно: templates/frontend/join.php (35 КБ) — чистая разметка без
логики и не дублирует apply-fields.php (13 общих строк из 286); XSS в JS-рендерах нет —
frontend/components/task-card.js экранирует каждое поле, админские таблицы вставляют
серверный HTML целиком; ref-selector.js:161 экранирует через jQuery.

Комментарии и гигиена

2H1. 170 «учебных» комментариев

Только по одному узкому шаблону (// имя_функции() — что она делает) находится 170 штук.
Плотнее всего: Managers/Wp/TermManager.php (10), Repositories/OptionsRepositories/UserRepository.php
(8), Services/Security/PiiCryptoService.php (7), Shared/Traits/TaxonomySeeder.php (6),
Shared/Traits/Sanitizer.php (6), Services/Email/EmailOtpService.php (6),
Migrations/MigrationRunner.php (6), Controllers/Pages/BoilerplatePageController.php (6).
Реальный объём шума больше: сюда не попали заголовки-разделы вида «### Основные обязанности» и
пересказы строк («Получение типа поста через статический метод PostTypeResolver»).

Чистку по проекту пользователь отложил — фиксирую объём и точки входа.

2H2. Пять TODO в рабочем коде

AdSyncController.php:72,92 — оба помечают тексты, которые видит посетитель («TODO(текст):
сообщения статусов»); Services/Course/ContentUsageService.php:452 — незакрытый этап
кросс-предметного поиска; MetaBoxes/Templates/ThreeInOneTemplate.php:87 — «вынести отсюда html
и стили»; templates/frontend/profile.php:36.

---

# Задачи этапа фиксов по ревью

Сопоставление: каждая задача ссылается на пункт ревью. Без задач остаются: 2S3, 2S5, 2D3
(информационные, действий нет), 2H1 (чистка комментариев отложена решением пользователя),
TODO AdSync-текстов из 2H2 (тексты не финализируем — маркеры `TODO(текст)` остаются) и
ContentUsageService:452 (кросс-предметный поиск — фича «Этап 2», не долг этого этапа).

Рекомендуемый порядок: Т1–Т3 (безопасность) → Т4 (нужна Т10 и Т13) → Т5–Т9 → Т10–Т12 →
Т13 → Т14 (распил — в самом конце, чтобы фиксы не переезжали по файлам).

---

## Т1 (2S1, высокий) — rate limit на публичные проверки логина/email

**Файлы:** `inc/Services/Security/RateLimitService.php`,
`inc/Callbacks/Enrollment/ApplicationCallbacks.php:427-449`.

**Проблема.** `ajaxCheckUsernameAvailable` / `ajaxCheckEmailAvailable` — nopriv, защищены только
нонсом, который печатается в публичную страницу и одинаков для всех посетителей. Без лимита это
пакетная проверка `username_exists()` / `email_exists()` — перечисление пользователей и
подтверждение ПД по списку адресов.

**Шаги.**
1. В `RateLimitService` добавить константы `LIMIT_USERNAME_CHECK = 20` и
   `LIMIT_EMAIL_CHECK = 10` (email жёстче — это проверка ПД; значения — стартовые, окно — общий
   `WINDOW` 1 час) и методы по образцу соседей (`allowApplicationCreation`):
   - `allowUsernameCheck( string $ip ): bool` → `check( $this->ipKey( 'unamechk', $ip ), … )`;
   - `allowEmailCheck( string $ip ): bool` → `check( $this->ipKey( 'emailchk', $ip ), … )`.
   Оба обязаны начинаться с байпаса `if ( $this->pluginConfig->isTestEnv() ) { return true; }`
   (иначе лягут e2e-прогоны формы). Дописать оба ключа в докблок класса «Ключи transient-ов».
2. `ajaxCheckUsernameAvailable()`: после `Nonce::CheckUsernameAvailable->verify()` —
   `$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );` и при `! allowUsernameCheck( $ip )` →
   `$this->error( 'Слишком много запросов. Попробуйте позже.' )` — дословно тот же текст, что у
   соседних методов (унификация ответа из ревью).
3. `ajaxCheckEmailAvailable()`: то же с `allowEmailCheck`.

**JS не менять.** `apply-form.js:253-274` и `join-form.js:14-37` уже fail-open: поле блокируется
только при явном `available === false`; error-ответ (в т.ч. лимит) игнорируется, а финальная
проверка занятости всё равно выполняется на создании заявки/учётки. Вручную убедиться, что при
исчерпанном лимите форма по-прежнему отправляется.

**Грабли.**
- Не менять форму успешного ответа (`{ available: bool }`) — на неё завязаны обе формы.
- Не добавлять per-email-счётчик (как у `allowOtpSendForEmail`) — здесь защита от перебора
  Источником (IP), а не от бомбинга цели; per-target-лимит сломает легитимный ввод.
- IP за школьным NAT общий на класс — лимиты ниже 20/час не ставить.

**Готово, когда:** 21-й запрос `check_username` (11-й — `check_email`) с одного IP за час получает
`error`; в тест-окружении лимита нет; blur-проверка на формах работает.

---

## Т2 (2S2 + 2A4, средний) — статус AD-провижна по токену и вынос обработчика из контроллера

**Файлы:** `inc/Modules/AdSync/Controllers/AdSyncController.php`;
новые `inc/Modules/AdSync/Services/AdStatusTokenService.php` и
`inc/Modules/AdSync/Callbacks/AdSyncStatusCallbacks.php`;
`src/js/frontend/services/apply-form.js:138` (только JSDoc).

**Проблема.** nopriv-эндпоинт `fs_lms_ad_status` принимает сырой последовательный ID заявки
(`$_POST['ref']`) — анонимный перебор отдаёт существование, количество и темп заявок. Плюс
AJAX-обработчик живёт в контроллере, а не в Callbacks.

**Шаги.**
1. Новый `AdStatusTokenService` (в `Services/` модуля):
   - `private const PREFIX = 'fs_lms_ad_ref_';`, `private const TTL = 15 * MINUTE_IN_SECONDS;`
     (окно поллинга ~100 с, запас на ретраи);
   - `issue( int $applicationId ): string` — `bin2hex( random_bytes( 16 ) )`,
     `set_transient( self::PREFIX . $token, $applicationId, self::TTL )`, вернуть токен;
   - `resolve( string $token ): int` — `(int) get_transient( self::PREFIX . $token )`
     (0 — нет/протух). Читает, НЕ удаляет: поллинг до 40 запросов.
   Сырые `set_/get_transient` здесь легальны: ключ инкапсулирован в одном классе модуля —
   паттерн `RateLimitService`/`EmailOtpService` (фиксируется в Т7).
2. Новый `AdSyncStatusCallbacks extends BaseController` + `use Sanitizer` (образец —
   `AdSyncSettingsCallbacks`): метод `ajaxStatus()`:
   - `Nonce::Apply->verify();`
   - `$token = $this->sanitizeKey( 'ref' );` (hex проходит `sanitize_key`);
   - `$appId = '' !== $token ? $this->tokens->resolve( $token ) : 0;`
   - дальше — текущая логика статуса и массив `$messages` (переезжают из контроллера как есть,
     вместе с комментарием `TODO(текст)` — тексты в этом этапе не финализируем).
   - Невалидный/протухший токен = `state 'none'` с тем же сообщением, что и `pending`, — ответ
     не должен различать «заявки нет» и «токен истёк».
3. `AdSyncController`:
   - `filterApplyResponse()`: `'ref' => $this->tokens->issue( $applicationId )` вместо сырого ID
     (остальные поля `poll` без изменений);
   - `ajaxStatus()` удалить; обе регистрации `wp_ajax_(nopriv_)` перевести на
     `array( $this->statusCallbacks, 'ajaxStatus' )`; зависимости — через конструктор (DI);
   - `STATUS_ACTION` ОСТАВИТЬ module-local константой с докблоком как у
     `AdSyncSettingsController::SAVE_ACTION` («вне core AjaxHook — изоляция»).
4. `apply-form.js:138`: JSDoc `{ref:number}` → `{ref:string}`. Код не меняется — `poll.ref`
   пересылается как есть (`:157`).

**Грабли.**
- НЕ заводить кейс в core `AjaxHook`/`TransientKey`, как буквально предлагает ревью: ядро не
  должно знать о модулях (тот же принцип, что для модульных опций; прецедент —
  `SAVE_ACTION`). Настоящие нарушения 2A4 — логика в контроллере и хардкод-тексты, их и чиним.
- Токен НЕ одноразовый (`take()` нельзя) — фронт опрашивает многократно.
- `statusForApplication()` по 0 не звать (короткое замыкание как сейчас: `$appId > 0`).

**Готово, когда:** в ответе apply нет числового ID заявки; POST с `ref=<число>` или произвольным
токеном возвращает `none` без утечки; штатный поллинг доходит до `done` (docker, тестовая заявка).

---

## Т3 (2S4, низкий) — экранирование в точке вывода в двух табах настроек

**Файлы:** `templates/admin/components/tabs/settings-tabs/settings-9-rooms.php`,
`templates/admin/components/tabs/settings-tabs/settings-3-periods.php`.

**Проблема.** Приём «экранируем в переменную, печатаем в N местах» (`$row_name =
esc_attr( … )` → голые `echo $row_name`) не уязвим сейчас, но одна новая строка без `esc_attr`
превращается в хранимую XSS незаметно для ревью и phpcs.

**Шаги.**
1. `settings-9-rooms.php`: убрать `esc_attr()` из присваиваний `:51-52` (оставить
   `$row_name = $room->name;` и т.п.), добавить `esc_attr()` в каждую точку вывода:
   `$row_name` — `:61, :84, :93`; `$row_subjects` — `:62, :85`. `$row_id` — `(int)`-каст,
   не трогать.
2. `settings-3-periods.php`: то же для `$row_id` (`:60, :65, :100, :111`) и `$row_name`
   (`:66, :101, :112`); присваивания `:50-51` — без esc. `$start_date`/`$end_date` уже
   экранируются на месте — не трогать.

**Готово, когда:** в обоих файлах нет `echo $var` без `esc_*` в самой точке вывода; вёрстка табов
не изменилась; phpcs зелёный без новых подавлений.

---

## Т4 (2A1) — `PostManager::taskMeta()` вместо прямых `get_post_meta`

**Файлы:** `inc/Managers/Wp/PostManager.php` (+5 потребителей ниже).

**Шаги.**
1. В `PostManager` добавить (рядом с `getMeta`, `:413`):
   ```php
   public function taskMeta( int $post_id ): array {
       $meta = get_post_meta( $post_id, PostMetaName::Meta->value, true );
       return is_array( $meta ) ? $meta : array();
   }
   ```
   (импортировать `PostMetaName`; guard `is_array` — общий для всех пяти мест).
2. Заменить прямые вызовы:
   - `inc/Controllers/Task/MetaBoxController.php:162-163` → `taskMeta( $post->ID )`
     (проверить конструктор: если `PostManager` не внедрён — добавить);
   - `inc/MetaBoxes/Templates/BaseTemplate.php:84-87` → защищённый метод
     `protected function taskValues( \WP_Post $post ): array` поверх `taskMeta()`;
   - `inc/MetaBoxes/Templates/ThreeInOneTemplate.php:91-94` — удалить дословную копию чтения,
     звать `$this->taskValues( $post )` (комментарий «точно так же, как в BaseTemplate» умрёт);
   - `inc/Services/Assessment/TaskPreviewService.php:91-92` → `taskMeta()`; `:93`
     (`TemplateType`) → существующий `getMeta( $task_id, PostMetaName::TemplateType->value )`;
   - `inc/Services/Template/TemplateResolver.php:79,81` → `getMeta()` (два ключа:
     `PostMetaName::TemplateType` и `self::LEGACY_TEMPLATE_META`); внедрить `PostManager`.
3. Способ внедрения в Templates выяснить по факту: если шаблоны метабоксов создаёт контейнер —
   конструкторная зависимость в `BaseTemplate` (наследники зовут `parent::__construct`); если
   инстанцируются вручную в `TemplateRegistry` — передать `PostManager` из места создания.

**Грабли.** Поведение 1:1: `taskMeta` возвращает `array()` на любое не-массивное значение — это
ровно текущие guard-ы. Не подменять `TemplateResolver`-у порядок фолбэков (сначала
`TemplateType`, при пустом — legacy-ключ, потом `TaskTemplate::Standard`).

**Готово, когда:** `grep -rn "get_post_meta" inc/ --include=*.php` вне `inc/Managers/` пуст;
метабокс задания, inline-модалка (`GetTaskEditorForm`) и предпросмотр работы рендерятся как до
правки.

---

## Т5 (2A2) — полная зачистка суперглобалов через трейт `Sanitizer`

**Объём:** все ~69 обращений к `$_POST` / `$_GET` / `$_REQUEST` / `$_FILES` в ~35 файлах `inc/`
(решение пользователя — полная зачистка). Список получить на месте:
`grep -rnE '\$_(POST|GET|REQUEST|FILES)' inc/ --include=*.php`.

**Правила замены (поведение строго 1:1).**
- `isset( $_GET['x'] ) ? (int) $_GET['x'] : 0` → `sanitizeGetInt( 'x' )` (`Sanitizer:290`);
- `sanitize_key( wp_unslash( $_GET['x'] ?? '' ) )` → `sanitizeGetKey( 'x' )` (`Sanitizer:282`);
- POST-строки/ключи/числа → `sanitizeText` / `sanitizeKey` / `sanitizeInt`; списки —
  `sanitizeIntList` / `sanitizeKeyList`; произвольные структуры — `unslashArray()` +
  `sanitize*Value()` поэлементно;
- `require*` использовать ТОЛЬКО там, где пустое значение и сейчас приводит к ошибке — появление
  нового исключения = изменение поведения;
- если нужного варианта в трейте нет (например, GET-текст) — добавить метод В ТРЕЙТ по образцу
  соседей, а не городить локальный разбор;
- `$_FILES`: трейт файлы не покрывает — эти места не переписывать вслепую; либо завести хелпер в
  `Sanitizer`/`MediaManager`, либо оставить прямое обращение с `phpcs:ignore` и причиной
  (молча не оставлять);
- `$_SERVER` (`REMOTE_ADDR`, `HTTP_USER_AGENT`) в объём НЕ входит — не трогать.
- В классах вне Callbacks трейт подключать явно (`use Inc\Shared\Traits\Sanitizer;`).

**Отдельно — `inc/Controllers/Course/CoursePreviewController.php` (худший, 7 обращений, дубль).**
1. Подключить `Sanitizer` (сейчас нет).
2. Единый приватный разбор deep-link вместо двух копий:
   `private function deepLinkParams(): array` → `['course' => sanitizeGetInt('course'),
   'lesson' => isset($_GET['lesson']) ? sanitizeGetInt('lesson') : null,
   'step' => sanitizeGetKey('step')]` — и `loadTemplate()` (`:40-71`), и `currentDeepLink()`
   (`:82-88`) используют его.
3. Сохранить разницу поведения: фолбэк `lesson` на первый урок курса существует ТОЛЬКО в
   `loadTemplate()` (`:61`) — он остаётся там, `deepLinkParams()` отдаёт `null`;
   `currentDeepLink()` не добавляет `lesson`/`step`, если их не было в запросе.

**Готово, когда:** grep из шапки возвращает только `Sanitizer.php` и согласованные
`phpcs:ignore`-места с причиной; `npm run ci` зелёный; смоук: превью курса с deep-link
(`?course=&lesson=&step=`), сохранение форм в админке, импорт CSV, загрузка вложений.

---

## Т6 (2A3) — `SubjectValidationCallbacks:323`: НЕ `authorize()`, а вынос из цикла

**Файл:** `inc/Callbacks/Subject/SubjectValidationCallbacks.php:321-338`.

**Важно — поправка к ревью.** Метод `showEmptyRequiredTaxNotice()` — колбек `admin_notices`, а не
AJAX: предложенный ревью `$this->authorize()` слал бы JSON-403 и ломал бы рендер админки.
Слепое исполнение рекомендации здесь и было бы «повторить ошибку ревью».

**Шаги.**
1. Вынести `$canManage = current_user_can( Capability::ManageTerms->value );` из
   `foreach ( $emptyTaxes … )` (`:321`) перед цикл — вызов от `$tax` не зависит.
2. В CLAUDE.md (вместе с Т7) уточнить правило: запрет прямых `current_user_can()` /
   `check_ajax_referer()` относится к **AJAX-методам** (там — `Authorizer`); в не-AJAX
   хук-колбеках (`admin_notices` и т.п.) прямая проверка права допустима, право — только кейсом
   `Capability`.

**Готово, когда:** вызов один на рендер нотиса; правило в CLAUDE.md уточнено; других прямых
`current_user_can` в Callbacks нет (по ревью этот был единственным).

---

## Т7 (2A5 + 2A7) — зафиксировать исключения в CLAUDE.md + дедуп SVG-партиала

**Файлы:** `CLAUDE.md` (раздел «Принятые исключения»),
`inc/Modules/SocialAuth/templates/settings-tab.php`.

**Решения пользователя:** логотипы OAuth в `Icon` не переносим — фиксируем исключение.

**Шаги.**
1. Дописать в «Принятые исключения» CLAUDE.md (датировать):
   - **Бренд-логотипы OAuth** — цветные, не `currentColor`, поэтому живут не в `Icon`, а в
     партиале `templates/admin/components/modals/partials/provider-logo.php` (его докблок уже
     это объясняет). Новые бренд-SVG — только через этот партиал.
   - **Модульные AJAX-экшены** — локальные константы модуля
     (`AdSyncSettingsController::SAVE_ACTION`, `AdSyncController::STATUS_ACTION`), вне core
     `AjaxHook`: ядро не знает о модулях. Обработчики — всё равно в Callbacks-классах модуля.
   - **Модульные транзиенты** — ключи в модуле, вне core `TransientKey`, сырые
     `set_/get_transient` при ключе-константе в одном классе:
     `VideoLibrary/RecordingAlertService::COUNT_TRANSIENT`, `AdSync/AdStatusTokenService` (Т2).
     Это закрывает 2A5 без правки кода.
   - Уточнение правила `current_user_can` из Т6.
2. `settings-tab.php:29,84,137`: три инлайновых `<svg>` — дословные копии путей из
   `provider-logo.php`, но БЕЗ `viewBox`/`aria-hidden` (то есть ещё и хуже). Заменить каждую на
   подключение партиала: `$provider = 'google'; require <plugin_path>/templates/admin/components/
   modals/partials/provider-logo.php;` (модулю можно использовать core-партиал; обратное
   направление запрещено). Путь строить хелпером `path()`, если шаблон рендерится из
   контроллера с `BaseController`.

**Готово, когда:** grep `<svg` по `templates/` и `inc/Modules/**/templates/` находит только
`provider-logo.php`; вкладка настроек SocialAuth выглядит как раньше; CLAUDE.md дополнен.

---

## Т8 (2A6) — `declare(strict_types=1)` в 37 файлах `inc/` + 3 корневых

**Объём (ровно 37 из ревью, agent-перепроверено):**
Callbacks: `Task/BoilerplateCallbacks.php`, `Task/TemplateManagerCallbacks.php`;
Contracts: `ServiceInterface.php`;
Controllers: `Builders/SubjectsMenuBuilder.php`, `Subject/SubjectController.php`,
`System/AdminController.php`, `Task/BoilerplateController.php`;
Core: `BaseController.php`, `Container.php`, `Deactivate.php`;
Enums: `Access/UserRole.php`, `Log/AuditAction.php`, `Person/ConsentType.php`,
`Subject/TaskTemplate.php`, `Wp/PageRoutes.php`;
Managers: `Wp/CPTManager.php`, `Wp/MenuManager.php`, `Wp/TaxonomyManager.php`;
MetaBoxes/Templates: `BaseTemplate.php`, `CodeTaskTemplate.php`, `CommonConditionTemplate.php`,
`FileAnswerTaskTemplate.php`, `FileCodeTaskTemplate.php`, `FileTaskTemplate.php`,
`StandardTaskTemplate.php`, `TaskTextSolution.php`, `ThreeInOneTemplate.php`,
`TwoFileCodeTaskTemplate.php`;
Registrars: `MenuRegistrar.php`, `MetaBoxRegistrar.php`, `SubjectCPTRegistrar.php`,
`SubjectTaxonomyRegistrar.php`;
Services: `System/PageGeneratorService.php`;
Shared/Traits: `ErrorHandler.php`, `NumericSorter.php`, `Sanitizer.php`, `TemplateRenderer.php`.
Плюс корневые: `fs-lms.php`, `uninstall.php`, `tests/Unit/Services/PiiMaskingServiceTest.php`.

**Шаги.**
1. В каждом — `declare( strict_types=1 );` сразу после `<?php` (формат с пробелами — как во всех
   остальных файлах проекта).
2. Это НЕ чисто механика: strict_types меняет семантику вызовов внутри файла. Перед коммитом
   пройти по перечисленным файлам глазами на предмет вызовов, куда прилетают «числа-строки» из
   меты/опций/запросов (типичный риск: `(int)`-некастованный параметр, `'1' == 1`-сравнения не
   ломаются, а вот передача `"5"` в `int`-параметр — теперь TypeError).
3. Смоук обязательный (файлы бутстрап-критичные: `Container`, `CPTManager`, Registrars,
   `BaseController`, `Sanitizer`): реактивация плагина в docker, создание/сохранение задания
   (все шаблоны метабоксов из списка!), страница настроек, фронт задания и статьи, `npm run ci`.

**Вне объёма:** 60 файлов `templates/` тоже без строгой типизации — ревью считало только `inc/`.
Решить отдельно: либо добить вторым проходом, либо уточнить правило в CLAUDE.md
(«каждый PHP-файл с логикой»); в этой задаче шаблоны не трогать.

**Готово, когда:** grep файлов без `strict_types` по `inc/` пуст; смоук пройден.

---

## Т9 (2A8) — прогресс-бары на CSS-переменную, transition/transform — в классы

**Проблема.** Четыре прогресс-бара двигаются `style.width = '…%'` из JS; правильная идиома в
проекте — CSS-переменная (`modal-base.js:55` → `--scrollbar-width`, `task-condition.js:140` →
`--tcr-full`). Позиционирование поповеров по геометрии — честное исключение, его НЕ трогаем
(`profile/utils.js:130-179`, `step-editor.js:893-961` top/left — остаются как есть).

**Шаги.**
1. SCSS — задать ширину от переменной (сейчас ширина живёт только в инлайне из JS):
   - `src/scss/player/components/_shell.scss:101-114` — `.sp-bar span { width: var(--progress, 0%); }`;
   - `src/scss/player/components/_step-work.scss:46-58` — `.ap-bar span { width: var(--progress, 0%); }`;
   - `src/scss/player/components/_rail.scss:128-141` — `.bar span { width: var(--progress, 0%); }`;
   - `src/scss/player/components/_step-video.scss:100-113` — `.fill { width: var(--progress, 0%); }`,
     `.knob { left: var(--progress, 0%); }` (одна переменная двигает и заливку, и бегунок).
2. JS — вместо `style.width`/`style.left` ставить переменную:
   - `player/shell.js:19` (гидрация `data-width`) →
     `el.style.setProperty( '--progress', pct + '%' )`;
   - `player/step-work.js:87`; `player/core.js:100` — аналогично;
   - `player/step-video.js:46-47` — ОДИН `setProperty('--progress', …)` на общем контейнере
     прогресса (заливка и бегунок — дети, возьмут `var()`), вместо двух присваиваний.
3. `frontend/components/article-carousel.js:50` — `style.transition = …` убрать: класс-модификатор
   (напр. `.is-animating` с `transition: transform …` из токена таймингов, см. `src/scss/CLAUDE.md`)
   навешивать/снимать вместо инлайна. `:51` (`translateX` от `itemWidth()`) — динамическая
   геометрия, допустимо оставить; по желанию — та же схема с `--carousel-shift`.
4. `admin/services/step-editor.js:957` — `style.transform = 'translateY(calc(-100% - 6px))'` —
   константа: класс (напр. `.is-above`) в admin-SCSS, JS только вешает класс.

**Грабли.** Прогресс — проценты строкой (`'42%'`), не число; stylelint не пропустит сырой тайминг
`0.3s ease` — брать токен; `hidden`-логику не трогать.

**Готово, когда:** grep `style\.width|style\.transition` по `src/js` пуст; `style.transform` —
только динамическая геометрия (carousel `:51`); все четыре бара и бегунок видео работают
(топбар урока, рельса, «Отвечено n из N», прогресс видео); `npx gulp build` + stylelint зелёные.

---

## Т10 (2P1) — `primeMetaCache()` перед циклами по ID из своих таблиц

**Проблема.** ID приходят из таблиц ответов/попыток — мета-кэш WP не прогрет, каждый `getMeta()`
в цикле — отдельный запрос (контрольная на 30 заданий = 60+ запросов).

**Шаги** (везде `PostManager` уже внедрён):
1. `inc/Services/Assessment/AttemptTaskViewBuilder.php` — `build( array $taskIds, … )`: перед
   `foreach` (`:56`) — `$this->posts->primeMetaCache( $taskIds );`.
2. `inc/Services/Assessment/AttemptResultService.php` — `studentPerTask()`: материализовать
   `$rows = $this->answers->listByAttempt( $attemptId );`, собрать
   `array_map( fn( $a ) => $a->taskId, $rows )` → `primeMetaCache()`, затем цикл по `$rows`.
3. `inc/Services/Course/WorkDetailService.php` — `fromAttempt()` (`:144`): так же (в цикле два
   чтения на задание — `:150` и `condition()` `:164`, прогрев закрывает оба).
4. `inc/Services/Assessment/TaskPreviewService.php` — цикл по `$item_ids` (`:72-77`):
   `primeMetaCache( $item_ids )` перед циклом (делать после Т4).

**НЕ трогать `ContentUsageService` (:156, :317, :344, :370) — поправка к ревью.** Его циклы идут
по результатам `consumers()` → `PostManager::search()` → `get_posts` (полные посты, без
`fields=>ids`): WP_Query сам прогревает postmeta-кэш при выборке (`update_post_meta_cache`
по умолчанию). N+1 там нет; добавлять `primeMetaCache` — шум. Пункт ревью в этой части
ложноположительный.

**Готово, когда:** с Query Monitor/`SAVEQUERIES` просмотр результата контрольной на ~30 заданий
даёт 1-2 постмета-запроса вместо ~60; предпросмотр работы — аналогично.

---

## Т11 (2P2) — потолки на безлимитные выборки + точный счёт вместо загрузки ID

**Файлы:** `inc/Managers/Wp/PostManager.php:44,62,130,283,453`,
`inc/Controllers/Builders/AllTasksDataBuilder.php:361-380`.

**Принцип.** Потолок — страховочный и БОЛЬШОЙ: часть потребителей (reference-guard
`ContentUsageService`, экспорт предмета) обязаны видеть ВСЁ — тихая обрезка там даст ложное
«контент не используется» и разрешит удаление используемого. Поэтому: большой потолок + громкий
`PluginLogger::warning` при упоре в него (сигнал переделывать на SQL), поведение до потолка —
неизменное.

**Шаги.**
1. `PostManager`: константа `private const HARD_CAP = 5000;` (комментарий: страховка, не бизнес-
   лимит). В `getIds()` (`:44`), `getAll()` (`:62`) и `search()` (дефолт `limit` `:453`) заменить
   `-1` на `self::HARD_CAP`; после выборки: если `count(...) === self::HARD_CAP` —
   `PluginLogger::warning( 'PostManager', 'выборка упёрлась в потолок', [ 'post_type' => …, 'method' => … ] )`.
2. `countByTerm()` (`:124-130`) — переписать честно: не тянуть все ID ради `count()`, а
   `$this->query( $post_type, [ 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => …,
   'no_found_rows' => false ] )['total']`. Потолок не нужен — счёт точный при любом размере.
3. `getPostsByTerm()` (`:283`) — `posts_per_page => 1000` + warning при упоре (выдача статей
   по терму; 1000 — заведомо больше реального употребления).
4. `AllTasksDataBuilder::matchingIds()` (`:363`) — `posts_per_page => 3000` (константа
   `FACET_IDS_CAP`) + warning; комментарий в код: при упоре фасетные счётчики становятся нижней
   оценкой — приемлемо, точность вернёт только SQL-подсчёт.

**Грабли.** Явно передаваемый вызывающим `limit` в `search()` уважать (потолок — только на
дефолт). Ничего не менять в `query()` — он и так постраничный.

**Готово, когда:** в `PostManager`/`AllTasksDataBuilder` нет `-1`; warning реально пишется
(проверить, временно снизив потолок в dev); счётчики `countByTerm` совпадают с прежними.

---

## Т12 (2D1) — убрать шесть локальных копий `escapeHtml`/`debounce`

**Канон:** `src/js/common/utils.js` — `escapeHtml` (`:18`, экранирует и `'`), `debounce` (`:99`).
Admin-хаб реэкспортов уже есть: `src/js/admin/modules/utils.js:25-27`.

**Шаги.**
1. `admin/services/step-editor.js:62` — тело `export const esc = …` заменить реэкспортом:
   `export { escapeHtml as esc } from '../../common/utils.js';`
   ВНИМАНИЕ: из него импортируют `esc` ещё 4 файла (`course-builder.js:3`,
   `course-persistence.js:1`, `slot-builder.js:3`) — реэкспорт сохраняет их API. 17 внутренних
   использований не трогать.
2. `admin/services/tables/students-table.js:183` — удалить локальную стрелку и комментарий-
   оправдание `:181-182`; вверху `import { escapeHtml as esc } from '../../modules/utils.js';`
   (4 использования `:189-192` не меняются).
3. `admin/modals/enrollment/teacher-view-modal.js:116` — то же: удалить локальную из `_fill()`,
   импорт из `../../modules/utils.js`, `esc` объявить на уровне модуля.
4. `frontend/components/sidebar-articles.js:16` — удалить `function esc`, добавить
   `import { escapeHtml as esc } from '../../common/utils.js';` (первый импорт файла — норм).
5. `frontend/services/assessment.js:61` — `debounce` заменить реэкспортом
   `export { debounce } from '../../common/utils.js';` (экспорт обязан остаться — его тянет
   бандл `kege/`, см. комментарий `:9-15`).
6. Там же `assessment.js:16` — ревью пропустило ШЕСТУЮ копию: `export function escHtml` →
   `export { escapeHtml as escHtml } from '../../common/utils.js';`.

**Поведенческая разница** (осознанная, в безопасную сторону): канон экранирует ещё `'` →
`&#039;`; копии в step-editor/sidebar-articles этого не делали. Для атрибутов в двойных кавычках
и текста — без визуальных отличий.

**Готово, когда:** в `src/js` ровно одна реализация экранирования (`common/utils.js`; `grep -rn
"'<div>'\)\.text\|=> String( s ==\|function escHtml\|function esc(" src/js`); `npx gulp build`
зелёный; смоук: таблица учеников (экспелл-партиал), модалка учителя, сайдбар статей, автосейв
эссе в тренажёре, конструктор курса (esc из step-editor).

---

## Т13 (2H2, выбранные) — закрыть TODO: ThreeInOneTemplate и логотип профиля

**А. `inc/MetaBoxes/Templates/ThreeInOneTemplate.php:87`** («вынести отсюда html и стили»).
1. Инлайновый `<style>` (`:96-100`) перенести в admin-SCSS (`src/scss/admin/…`, файл про
   метабоксы; классам — префикс, сырые значения — токенами) — инлайн-стили в PHP запрещены
   правилами проекта.
2. Разметку рендера вынести из класса: либо в partial `templates/admin/metaboxes/…`, либо
   собрать из `Fields/*` как у остальных шаблонов. КРИТИЧНО: тот же HTML отдаётся inline-модалке
   по AJAX (`AjaxHook::GetTaskEditorForm` → `BaseTemplate::render()`) — менять можно
   местоположение кода, но не итоговую разметку/имена полей `fs_lms_meta[...]`.
3. Делать после Т4 (там в этот же файл приезжает `taskValues()`).
**Готово:** в PHP-классах MetaBoxes нет `<style>` и «простыней» HTML; метабокс и модалка
рендерятся идентично прежнему.

**Б. `templates/frontend/profile.php:36`** («заменить на логотип в меню настройки стилей»).
Сейчас в шапке профиля захардкожен `Icon::BrandMark->svg()`. Закрыть TODO = сделать логотип
настраиваемым:
1. Поле «Логотип» (attachment ID) в существующем табе настроек оформления админки (если таба
   оформления нет — в общий таб настроек); сохранение — через опцию в `OptionName` +
   репозиторий (не сырой `update_option`), загрузка — стандартной медиатекой.
2. В `profile.php` выводить логотип из настройки, фолбэк — текущий `Icon::BrandMark`.
3. Проверить, где ещё на фронте выводится `BrandMark` (grep) — использовать ту же настройку.
**Это мини-фича**: если в ходе этапа решим не делать — TODO из кода удалить, завести тикет.

---

## Т14 (2D2) — распил переросших файлов (решение пользователя: всё сейчас)

**Общие правила всех шести подпунктов.**
- Распил ≠ рефакторинг логики: поведение, разметка, имена хуков/нонсов/AJAX-экшенов, форматы
  ответов — байт-в-байт. Меняется только раскладка кода.
- Один подпункт = один коммит/PR со своим смоуком. Делать ПОСЛЕ Т1–Т13 (иначе фиксы будут
  переезжать по файлам под ногами).
- Новые PHP-контроллеры реализуют `ServiceInterface` и добавляются в `Init::getServices()`;
  новые Callbacks-классы — `extends BaseController` + `Authorizer`/`Sanitizer`, регистрируются
  существующими контроллерами. Новые JS-модули следуют паттернам бандла (admin — объекты,
  frontend/profile — функции; см. `src/js/CLAUDE.md`).
- Порядок — от дешёвого к дорогому: 14.1 → 14.2 → 14.3 → 14.4 → 14.5 → 14.6.

### Т14.1 — `inc/Controllers/Course/LearningMenuController.php` (425 строк → 4 контроллера)

Группы не пересекаются по состоянию, каждая тянет ровно одну зависимость — самый дешёвый распил.
1. `LearningMenuBuilder.php` (Controllers/Course/) — меню «Обучение» + подсветка:
   `registerLearningMenu` :209, `subjectBankSlug` :271, `subjectBankSubpage` :289,
   `highlightLearningParent` :305, `highlightLearningSubmenu` :314, `learningSubmenuFor` :323;
   владеет `$bank_slugs`/`$learning_parent_slug` (группы 1–2 разделять НЕЛЬЗЯ — общее состояние).
   Хуки: `admin_menu`, `parent_file`, `submenu_file`. Зависимости: MenuRegistrar,
   TeacherSubjectsService.
2. `BankListTableController.php` — фильтры list-table: `renderTypeFilter` :164,
   `applyTypeFilter` :194, `filterTaskDraftState` :180. Хуки: `restrict_manage_posts`,
   `pre_get_posts`, `display_post_states`. Зависимость: BankListFilters.
3. `BankChromeController.php` — «хром» банка: `renderBankChrome` :353, `currentBankType` :336,
   `renderBank` :389 + 6 лендинг-фолбэков `render*` :132-154. Хук `admin_notices` + колбеки
   сабстраниц (через LearningMenuBuilder → MenuRegistrar: колбеки передаются при построении).
4. `BankRowActionsController.php` (тоже Controllers/Course/ — новых каталогов не заводить) —
   «довесок»: `addCloneRowAction` :94 (контракт `data-clone-*` для `content-clone.js` не менять),
   `renderDraftCreatorModal` :118. Хуки: `post_row_actions`, `admin_footer`. Без зависимостей.

**Готово:** меню «Обучение» со всеми сабстраницами, табы предметов, фильтры банка, статус
«Незавершённая», «Дублировать» и модалка черновика работают; старый файл удалён; все четыре
класса в `Init::getServices()`.

### Т14.2 — `inc/Callbacks/Enrollment/EnrollmentCallbacks.php` (636 строк, 17 ajax → 5 классов)

Границы подтверждены структурой JS-менеджеров (enrollment-api, application-*-manager,
person-*-manager). `EnrollmentController` меняется тривиально — только объект-получатель в
регистрациях.
1. `EnrollmentLifecycleCallbacks.php` — `ajaxStartEnrollment` :355, `ajaxEnrollStudent` :89,
   `ajaxCancelEnrollment` :431, `ajaxRestoreFromArchive` :559 + справочник
   `ajaxGetStudentGroups` :455.
2. `ApplicationDataCallbacks.php` — самый тяжёлый кусок (~185 строк, вся PII-крипта):
   `ajaxUpdateApplicationData` :215, `ajaxUpdateReviewData` :278, `ajaxGetApplicationData` :382.
3. `ApplicationTrashCallbacks.php` — корзина: `ajaxMoveApplicationToTrash` :139,
   `ajaxRestoreApplicationFromTrash` :162, `ajaxDeleteApplication` :191,
   `ajaxEmptyApplicationsTrash` :476.
4. `ParentLinkCallbacks.php` — `ajaxSelectExistingParent` :578, `ajaxRemoveParentAssignment`
   :594, `ajaxSearchParents` :612.
5. `UserCredentialsCallbacks.php` — `ajaxRevealUserCredentials` :515,
   `ajaxRegenerateUserPassword` :547 (отдельно от родителей: другие нонсы/права; PII-выгрузки —
   помнить стандарт `authorizeAll(ManageLmsPlatform, ExportPII)` там, где он уже стоит).
Конструкторы забирают только свои зависимости (сейчас 9 на всех).

**Готово:** все 17 экшенов отвечают как раньше (прогнать модалки заявок, корзину, зачисление,
reveal кредов); grep по `EnrollmentCallbacks` пуст; каждый класс ≤ ~200 строк.

### Т14.3 — `inc/Services/Profile/LearnerService.php` (595 строк, 16 зависимостей → фасад + 4 секции)

Внешний контракт не трогать: `LearnerCallbacks` продолжает звать `build( $personId )->toArray()`.
Новые классы — в `inc/Services/Profile/Learner/`; все получают готовый `LearnerContextDTO`.
1. `LearnerContextBuilder.php` — `context` :99, `groupCards` :141, `lessonCard` :183,
   `topicOf` :582, `lessonStatus` :588, `courseTitleForGroup` :577 (+ кэши roomNames/teacherNames).
   Зависимости: records, groups, groupLessons, rooms, clock, effectiveTeacher.
2. `LearnerScheduleSection.php` — `upcoming` :221, `deadlines` :245 (submissions, worksResolver,
   lessons). Дип-линк `?step=` дублируется в `NotificationService` — на будущее общая точка,
   в этом распиле НЕ дедуплицировать (правило «распил без изменений логики»).
3. `LearnerPerformanceSection.php` — `grades` :298, `recentGrades` :327, `attendance` :342
   (gradebook, attendance).
4. `LearnerCoursesSection.php` — `buildCourses` :440, `buildCatalog` :401, `courseLessonItem`
   :547, `examLock` :381 (courses, lessons, gate, progress, subjects, examLock).
`LearnerService::build()` остаётся тонкой оркестрацией секций; конструктор — с 16 до ~5
зависимостей.

**Готово:** кабинет ученика (все 10 секций DTO) рендерится идентично (сверить JSON ответа до/
после на одном ученике); контейнер собирает граф без ручных биндингов.

### Т14.4 — `inc/Core/Enqueue.php` (790 строк → фасад + 4 класса в `inc/Core/Assets/`)

Правило «все wp_localize_script — только здесь» СОХРАНЯЕТСЯ, но «здесь» становится слоем
`Core/Assets/*`: обновить формулировки в корневом CLAUDE.md и `src/js/CLAUDE.md` (раздел
Globals). `Enqueue` остаётся фасадом: регистрирует 4 хука и делегирует.
1. `AdminAssets.php` — `enqueue_admin_assets` :88, `enqueueAdminBase` :128, гейт
   `AdminScreenContext`, media/editor.
2. `AdminLocalizations.php` — `adminLocalizations` :175 + 6 vars-провайдеров (`lessonVars`,
   `taskDataVars`, `taskEditorVars`, `articleDataVars`, `applicationsVars`, `globalAdminVars`) +
   `getRequiredTaxonomies` :728. Забирает 4 из 6 зависимостей конструктора.
3. `FrontendAssets.php` — `enqueue_frontend_assets` :534 (роутинг SPA vs общий стек),
   `enqueueFrontendBase` :594, `frontendLocalizations` :640, `applyVars`, `assessmentVars`,
   `joinVars`.
4. `BundleLoader.php` — `enqueueBundle` :371, `enqueueUiFont` :362, `enqueueMathJax` :400,
   `fontResourceHints` :585 + 4 SPA-бандла (profile/player/assessment/kege) как реестр
   `slug → varName → data-фабрика`.
Плюс: `render_confirm_modal` :771 + `isPluginAdminScreen` :749 — это не ассеты, вынести в
контроллер admin-футера (Controllers/System/).
Известные дубли устранить ПРИ переносе (единственное разрешённое «изменение»):
`assessmentVars()` :681 ≈ inline-массив в `enqueue_assessment_assets` :477 (свести к одному
провайдеру с флагом `previewSolve`); Font Awesome/common-стили задублированы в admin/frontend
base (:130 и :597) — один общий приватный метод.

**Готово:** на каждой странице (админка, apply, join, профиль, плеер, тренажёр, kege, статья)
`wp_scripts`-очередь и window-глобалы идентичны до/после (сравнить `console.log` ключевых
глобалов); CLAUDE.md-правило переформулировано.

### Т14.5 — `src/js/admin/services/step-editor.js` (1017 строк → ядро + 4 модуля)

Внешние потребители: `course-builder.js` (createStepEditor, esc, ajax, tmpKey, openPicker),
`lesson-step-editor.js` (createStepEditor, readSteps), `course-persistence.js` (esc, ajax),
`slot-builder.js` (openPicker, esc, readSteps). Их импорты обновить на новые модули; реэкспорты-
мостики в step-editor.js не оставлять (один символ — один путь). `esc` после Т12 — реэкспорт
`common/utils.js`: при распиле потребители переходят на импорт из `admin/modules/utils.js`,
реэкспорт из step-editor.js удаляется.
1. `admin/services/step-ajax.js` — `ajax` :79, `nonceFor` :66, `tmpKey` :61, `acts` :54.
2. `admin/modules/picker.js` — `openPicker` :949 целиком + `openLibraryPicker` :906 как обёртка
   (модуль общего назначения → каталог `admin/modules/`, паттерн named function exports).
3. `admin/services/step-preview.js` — превью ссылочного контента (~165 строк, автономно):
   `buildAnswerSection` :87, `buildRefTaskBody` :139, `loadRefPreview` :149,
   `loadTaskPreview` :183, `renderTaskPreview` :192.
4. `admin/services/step-editors/` — по файлу на тип тела шага: `inline-editor.js` (`inlineEditor`
   :406, `setupLatexButtons` :422, `destroyTiny` :290 — TinyMCE/LaTeX), `video-editor.js`
   (`fmtChapterTime` :565, `parseChapterTime` :570, `renderChapterRows` :577,
   `renderAttachmentRows` :608), `ref-editor.js` (`refEditor` :636, ~175 строк).
Ядро `step-editor.js` (~250 строк): `TYPE_UI`/`ADD_TYPES`/`MAX_STEPS`, каркас/чипы/drag
(`render` :306, `renderStepsRow` :319, `attachStepDrag` :347), CRUD шагов (:811-:876), автосейв
(`payloadForSave`/`saveSteps`/`scheduleSave` :922-:936), `readSteps` :1000.

**Готово:** `npx gulp build` зелёный; конструктор курса/урока: создание шагов всех типов,
inline-редактор с LaTeX, видео-главы и вложения, ссылочные шаги с превью, drag, дублирование,
автосейв, пикеры в slot-builder — всё работает; ни один модуль не превышает ~300 строк.

### Т14.6 — `src/js/profile/ktp.js` (865 строк → ядро + 4 модуля в `profile/ktp/`)

Единственный внешний потребитель — `profile/app.js:8` (`renderKTP`): внешний контракт не
меняется, `profile/ktp.js` остаётся точкой входа (или переезжает в `profile/ktp/index.js` с
обновлением одного импорта).
1. `profile/ktp/ktp-individual.js` — весь режим «Индивидуальные занятия» (~250 строк, самый
   автономный): `fetchIndividual` :369, `loadIndividual` :376, `renderIndividual` :414,
   `renderIndiCalendar` :480, `openAddIndi` :541, `openEditIndi` :552, `selectIndiSlot` :576,
   `loadIndiCandidates` :586, `renderIndiBank` :599, `assignIndiLesson` :617,
   `renderLessonCandidates` :629, `indiMonths`/`indiInitialCursor`/`shiftIndiMonth`,
   `indiSlotChip` :527. Выносить ПЕРВЫМ.
2. `profile/ktp/ktp-calendar-model.js` — чистые вычисления без DOM (тестируемо):
   `computeMonths` :70, `initialCursor` :83, `shiftMonth` :352, `toLocalInputValue` :768,
   `fromLocalInputValue` :772.
3. `profile/ktp/ktp-templates.js` — все `*Html`-шаблоны: `themeCardHtml` :304,
   `recordingIconHtml` :318, `placedThemeHtml` :331, `emptyStateHtml` :845, `noGroupsHtml` :859,
   `errorHtml` :863, `openProgramHtml` :172, `partLabel` :300.
4. `profile/ktp/ktp-popovers.js` — пары `attach*Click`/`open*` (:672-:806): дедлайны, запись
   занятия, меню действий темы.
Ядро: стейт (`root/state/api/coursesApi`), `renderKTP`, `loadCalendar`, `render`, `renderBank`,
`renderCalendar`, drag-drop (:807-:833), reflow/publish/unpublish (:638-:660),
`wireCoursePicker` :187.

**Готово:** сборка зелёная; КТП: календарь групп, банк тем, drag закрепления, reflow,
публикация/снятие, дедлайны, привязка записей, меню темы, режим индивидуальных занятий
(добавление/правка/кандидаты) — всё работает как до распила.

---

## Сводка объёма этапа

| Блок | Задачи |
|---|---|
| Безопасность | Т1, Т2, Т3 |
| Архитектура/конвенции | Т4, Т5, Т6, Т7, Т8 |
| UI-идиомы | Т9 |
| Производительность | Т10, Т11 |
| Дедупликация | Т12 |
| TODO | Т13 |
| Распил (в конце) | Т14.1–Т14.6 |

Поправки к ревью, учтённые в задачах (чтобы не «чинить» лишнего): 2A3 — метод не AJAX,
`authorize()` там сломал бы админку (Т6); 2A4 — module-local константы экшенов легальны,
нарушение только в месте жизни обработчика (Т2/Т7); 2P1 — `ContentUsageService` уже прогрет
самим WP_Query, правится только четвёрка сервисов с ID из своих таблиц (Т10); 2D1 — копий не 5,
а 6 (`assessment.js:16 escHtml`) (Т12).