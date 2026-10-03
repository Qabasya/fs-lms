# Этап 2. Схема БД и домен проведений

Зависимости: этап 0 (энумы 0.5, реестр форматов 0.3, вместимость кабинета 0.9), для 2.4 — этап 1 (`ExamAccessGuard`, `ExamVariantPolicy`).
Результат: 15 таблиц, расширенная `assessment_attempts`, DTO, репозитории, сервис проведений, сервис ключей доступа,
минутный cron. Интерфейса нет.

Перед началом прочитать `README.md` (особенно §4.2 «Новая таблица», §5 «Время», §7). SPEC: §1, §3, §11, §12.

**Порядок:** 2.1 → 2.2 → 2.7 → 2.3 → 2.4 → 2.5 → 2.6. Пункт 2.7 (регистрация и проверка миграции) идёт сразу после DDL:
репозитории проверяются на уже созданных таблицах.

---

## 2.1 DDL пятнадцати таблиц

**Зачем.** Схема проведений, записи, гостей, оплаты, отчётов и надёжной доставки событий (SPEC §11).
Таблицы гостей, оплаты и отчётов создаются сразу, а наполняются на этапах 11a и 12.

**Проверить перед началом**
- README §4.2 — рецепт. Образцы: `git show master:inc/Migrations/Migration_1_0_70.php` (таблица),
  `inc/Migrations/Migration_1_0_33.php` — статический метод с DDL, который переиспользует `Migration_1_0_0`
  (`grep -n "errorLogDdl" inc/Migrations/*.php`).
- Номер версии: `grep -n "FS_LMS_VERSION" fs-lms.php`. Миграция называется по версии релиза, в котором выйдет этап.
  Ниже она названа `Migration_1_0_71`; **точный номер спросить у владельца**, имя класса и `version()` должны совпасть с ним.
- Правила `dbDelta`: между `PRIMARY KEY` и скобкой **два пробела**, каждая колонка на своей строке, ключи — словом `KEY`,
  без `IF NOT EXISTS`, без внешних ключей.
- Все времена в этих таблицах — UTC (README §5).
- Согласия гостей отдельной таблицы не получают: используется существующая `fs_lms_consents`
  (`ConsentService::recordSelfConsent( null, … )` возвращает ID строки), ID согласий хранятся в `consent_refs`.

**Шаги**
- [ ] 2.1.1 Создать `inc/Migrations/Migration_1_0_71.php` (`implements MigrationInterface`) с публичным статическим методом
  `examDdl( string $cc ): array` — массив из 15 строк `CREATE TABLE`. Имена таблиц — `TableName::X->prefixed()`.
- [ ] 2.1.2 Вставить DDL ровно в этом виде (отступы колонок — табами, как в `Migration_1_0_0`):

```sql
CREATE TABLE {exam_events} (
	id                         int unsigned     NOT NULL AUTO_INCREMENT,
	subject_key                varchar(50)      NOT NULL,
	title                      varchar(255)     NOT NULL,
	description                text             DEFAULT NULL,
	owner_user_id              bigint unsigned  NOT NULL,
	status                     varchar(20)      NOT NULL DEFAULT 'draft',
	period_from                date             NOT NULL,
	period_to                  date             NOT NULL,
	registration_opens_at      datetime         DEFAULT NULL,
	registration_closes_at     datetime         DEFAULT NULL,
	guest_registration_enabled tinyint(1)       NOT NULL DEFAULT 0,
	default_assessment_id      bigint unsigned  DEFAULT NULL,
	variant_snapshot           longtext         DEFAULT NULL,
	cancel_reason              text             DEFAULT NULL,
	published_at               datetime         DEFAULT NULL,
	completed_at               datetime         DEFAULT NULL,
	cancelled_at               datetime         DEFAULT NULL,
	version                    int unsigned     NOT NULL DEFAULT 1,
	created_at                 datetime         NOT NULL,
	updated_at                 datetime         NOT NULL,
	PRIMARY KEY  (id),
	KEY subject_status (subject_key, status),
	KEY owner_user_id (owner_user_id)
) {cc};

CREATE TABLE {exam_sessions} (
	id                  int unsigned      NOT NULL AUTO_INCREMENT,
	event_id            int unsigned      NOT NULL,
	assessment_id       bigint unsigned   NOT NULL,
	scheduled_at        datetime          NOT NULL,
	planned_end_at      datetime          NOT NULL,
	room_id             int unsigned      NOT NULL,
	capacity            smallint unsigned NOT NULL,
	occupied_count      smallint unsigned NOT NULL DEFAULT 0,
	responsible_user_id bigint unsigned   NOT NULL,
	status              varchar(20)       NOT NULL DEFAULT 'open',
	first_started_at    datetime          DEFAULT NULL,
	cancel_reason       text              DEFAULT NULL,
	version             int unsigned      NOT NULL DEFAULT 1,
	created_at          datetime          NOT NULL,
	updated_at          datetime          NOT NULL,
	PRIMARY KEY  (id),
	KEY event_id (event_id),
	KEY room_time (room_id, scheduled_at, planned_end_at),
	KEY scheduled_at (scheduled_at)
) {cc};

CREATE TABLE {exam_participants} (
	id            int unsigned     NOT NULL AUTO_INCREMENT,
	person_id     int unsigned     DEFAULT NULL,
	name_enc      text             DEFAULT NULL,
	phone_enc     text             DEFAULT NULL,
	messenger_enc text             DEFAULT NULL,
	name_hash     char(64)         DEFAULT NULL,
	phone_hash    char(64)         DEFAULT NULL,
	school_name   varchar(255)     DEFAULT NULL,
	school_key    varchar(100)     DEFAULT NULL,
	grade         tinyint unsigned DEFAULT NULL,
	anonymized_at datetime         DEFAULT NULL,
	created_at    datetime         NOT NULL,
	updated_at    datetime         NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY person_id (person_id),
	KEY name_phone (name_hash, phone_hash)
) {cc};

CREATE TABLE {exam_participations} (
	id                     int unsigned    NOT NULL AUTO_INCREMENT,
	event_id               int unsigned    NOT NULL,
	participant_id         int unsigned    NOT NULL,
	audience               varchar(10)     NOT NULL,
	active_registration_id int unsigned    DEFAULT NULL,
	current_attempt_id     int unsigned    DEFAULT NULL,
	source_id              int unsigned    DEFAULT NULL,
	consent_refs           longtext        DEFAULT NULL,
	transfer_allowed       tinyint(1)      NOT NULL DEFAULT 0,
	admitted_at            datetime        DEFAULT NULL,
	admitted_by_user_id    bigint unsigned DEFAULT NULL,
	version                int unsigned    NOT NULL DEFAULT 1,
	created_at             datetime        NOT NULL,
	updated_at             datetime        NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY event_participant (event_id, participant_id),
	KEY participant_id (participant_id)
) {cc};

CREATE TABLE {exam_registrations} (
	id                 int unsigned     NOT NULL AUTO_INCREMENT,
	participation_id   int unsigned     NOT NULL,
	session_id         int unsigned     NOT NULL,
	status             varchar(20)      NOT NULL DEFAULT 'confirmed',
	active_slot        tinyint unsigned DEFAULT 1,
	request_key        varchar(64)      DEFAULT NULL,
	reason             text             DEFAULT NULL,
	actor_user_id      bigint unsigned  DEFAULT NULL,
	arrived_at         datetime         DEFAULT NULL,
	arrived_by_user_id bigint unsigned  DEFAULT NULL,
	created_at         datetime         NOT NULL,
	cancelled_at       datetime         DEFAULT NULL,
	transferred_at     datetime         DEFAULT NULL,
	missed_at          datetime         DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY participation_active (participation_id, active_slot),
	KEY session_status (session_id, status)
) {cc};

CREATE TABLE {exam_sources} (
	id                     int unsigned     NOT NULL AUTO_INCREMENT,
	event_id               int unsigned     NOT NULL,
	school_key             varchar(100)     DEFAULT NULL,
	school_name            varchar(255)     NOT NULL,
	school_name_normalized varchar(255)     NOT NULL,
	grade                  tinyint unsigned NOT NULL,
	teacher_name           varchar(255)     NOT NULL,
	label                  varchar(255)     NOT NULL DEFAULT '',
	is_active              tinyint(1)       NOT NULL DEFAULT 1,
	key_generation         int unsigned     NOT NULL DEFAULT 0,
	key_revoked_at         datetime         DEFAULT NULL,
	created_by_user_id     bigint unsigned  NOT NULL,
	version                int unsigned     NOT NULL DEFAULT 1,
	created_at             datetime         NOT NULL,
	updated_at             datetime         NOT NULL,
	PRIMARY KEY  (id),
	KEY event_id (event_id)
) {cc};

CREATE TABLE {exam_access_tokens} (
	id                int unsigned    NOT NULL AUTO_INCREMENT,
	purpose           varchar(20)     NOT NULL,
	target_id         int unsigned    NOT NULL,
	token_hash        char(64)        NOT NULL,
	generation        int unsigned    NOT NULL DEFAULT 1,
	expires_at        datetime        DEFAULT NULL,
	revoked_at        datetime        DEFAULT NULL,
	consumed_at       datetime        DEFAULT NULL,
	issuer_user_id    bigint unsigned NOT NULL,
	passed_at         datetime        DEFAULT NULL,
	passed_by_user_id bigint unsigned DEFAULT NULL,
	created_at        datetime        NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY purpose_target (purpose, target_id)
) {cc};

CREATE TABLE {exam_guest_sessions} (
	id               int unsigned NOT NULL AUTO_INCREMENT,
	cookie_hash      char(64)     NOT NULL,
	scope            varchar(20)  NOT NULL,
	source_id        int unsigned DEFAULT NULL,
	participation_id int unsigned DEFAULT NULL,
	registration_id  int unsigned DEFAULT NULL,
	generation       int unsigned NOT NULL DEFAULT 1,
	issued_at        datetime     NOT NULL,
	expires_at       datetime     NOT NULL,
	revoked_at       datetime     DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY cookie_hash (cookie_hash),
	KEY participation_id (participation_id),
	KEY source_id (source_id)
) {cc};

CREATE TABLE {exam_reports} (
	id                  int unsigned    NOT NULL AUTO_INCREMENT,
	event_id            int unsigned    NOT NULL,
	title               varchar(255)    NOT NULL,
	owner_user_id       bigint unsigned NOT NULL,
	recipient_source_id int unsigned    DEFAULT NULL,
	expires_at          datetime        NOT NULL,
	revoked_at          datetime        DEFAULT NULL,
	version             int unsigned    NOT NULL DEFAULT 1,
	created_at          datetime        NOT NULL,
	PRIMARY KEY  (id),
	KEY event_id (event_id)
) {cc};

CREATE TABLE {exam_report_members} (
	id               int unsigned NOT NULL AUTO_INCREMENT,
	report_id        int unsigned NOT NULL,
	participation_id int unsigned NOT NULL,
	consent_ref      int unsigned DEFAULT NULL,
	created_at       datetime     NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY report_participation (report_id, participation_id)
) {cc};

CREATE TABLE {exam_events_outbox} (
	id                bigint unsigned   NOT NULL AUTO_INCREMENT,
	event_uuid        char(36)          NOT NULL,
	type              varchar(50)       NOT NULL,
	aggregate_type    varchar(30)       NOT NULL,
	aggregate_id      int unsigned      NOT NULL,
	aggregate_version int unsigned      NOT NULL DEFAULT 0,
	payload           longtext          DEFAULT NULL,
	available_at      datetime          NOT NULL,
	processed_at      datetime          DEFAULT NULL,
	attempts          smallint unsigned NOT NULL DEFAULT 0,
	leased_until      datetime          DEFAULT NULL,
	last_error        text              DEFAULT NULL,
	created_at        datetime          NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY event_uuid (event_uuid),
	KEY pending (processed_at, available_at)
) {cc};

CREATE TABLE {exam_operation_keys} (
	id           bigint unsigned NOT NULL AUTO_INCREMENT,
	scope        varchar(60)     NOT NULL,
	operation    varchar(40)     NOT NULL,
	request_key  varchar(64)     NOT NULL,
	payload_hash char(64)        NOT NULL,
	result_ref   longtext        DEFAULT NULL,
	expires_at   datetime        NOT NULL,
	created_at   datetime        NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY scope_op_key (scope, operation, request_key),
	KEY expires_at (expires_at)
) {cc};

CREATE TABLE {exam_guest_applications} (
	id                 int unsigned     NOT NULL AUTO_INCREMENT,
	event_id           int unsigned     NOT NULL,
	session_id         int unsigned     NOT NULL,
	source_id          int unsigned     NOT NULL,
	participant_id     int unsigned     DEFAULT NULL,
	identity_hash      char(64)         NOT NULL,
	active_slot        tinyint unsigned DEFAULT 1,
	state              varchar(30)      NOT NULL DEFAULT 'hold',
	is_held            tinyint(1)       NOT NULL DEFAULT 0,
	hold_expires_at    datetime         DEFAULT NULL,
	request_key        varchar(64)      NOT NULL,
	draft_enc          longtext         DEFAULT NULL,
	source_snapshot    longtext         DEFAULT NULL,
	consent_refs       longtext         DEFAULT NULL,
	participation_id   int unsigned     DEFAULT NULL,
	registration_id    int unsigned     DEFAULT NULL,
	ip_hash            char(64)         DEFAULT NULL,
	created_by_user_id bigint unsigned  DEFAULT NULL,
	version            int unsigned     NOT NULL DEFAULT 1,
	created_at         datetime         NOT NULL,
	updated_at         datetime         NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY identity_active (event_id, identity_hash, active_slot),
	UNIQUE KEY source_request (source_id, request_key),
	KEY session_held (session_id, is_held),
	KEY hold_expiry (is_held, hold_expires_at),
	KEY ip_held (ip_hash, is_held),
	KEY source_held (source_id, is_held),
	KEY state (state)
) {cc};

CREATE TABLE {exam_payment_links} (
	id                 int unsigned    NOT NULL AUTO_INCREMENT,
	application_id     int unsigned    NOT NULL,
	wc_order_id        bigint unsigned NOT NULL,
	wc_order_item_id   bigint unsigned NOT NULL,
	product_id         bigint unsigned NOT NULL,
	amount             decimal(10,2)   NOT NULL DEFAULT 0.00,
	currency           varchar(3)      NOT NULL DEFAULT 'RUB',
	payment_state      varchar(20)     NOT NULL DEFAULT 'pending',
	last_reconciled_at datetime        DEFAULT NULL,
	created_at         datetime        NOT NULL,
	updated_at         datetime        NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY wc_order_item_id (wc_order_item_id),
	KEY application_id (application_id),
	KEY wc_order_id (wc_order_id)
) {cc};

CREATE TABLE {exam_manual_resolutions} (
	id               int unsigned    NOT NULL AUTO_INCREMENT,
	application_id   int unsigned    DEFAULT NULL,
	participation_id int unsigned    DEFAULT NULL,
	kind             varchar(30)     NOT NULL,
	reason           text            NOT NULL,
	actor_user_id    bigint unsigned NOT NULL,
	amount           decimal(10,2)   DEFAULT NULL,
	old_session_id   int unsigned    DEFAULT NULL,
	new_session_id   int unsigned    DEFAULT NULL,
	created_at       datetime        NOT NULL,
	PRIMARY KEY  (id),
	KEY application_id (application_id),
	KEY participation_id (participation_id)
) {cc};
```

- [ ] 2.1.3 Пояснения записать докблоком класса миграции (исполнитель следующих этапов читает их там):
  - `exam_registrations.active_slot` = `1` только у действующей брони, `NULL` у отменённой, перенесённой, пропущенной.
    Уникальный индекс `(participation_id, active_slot)` не даёт двух действующих броней; `NULL` в уникальном индексе MariaDB не конфликтуют.
  - `exam_sessions.occupied_count` считает подтверждённые записи учеников **и** действующие брони гостей (`is_held = 1`).
  - `exam_guest_applications.active_slot` = `1`, пока заявка занимает место личности в проведении (бронь или подтверждённая запись),
    `NULL` после истечения, отмены, неявки.
  - `exam_participants.person_id` уникален для ненулевых; уникальности по телефону нет (один телефон — два ребёнка).
  - `exam_events.variant_snapshot` — JSON-объект с ключом `assessment_id`.
  - Хеш ключа приглашения живёт в `exam_access_tokens` (`purpose = invitation`, `target_id = source_id`), README §8 п. 1.
- [ ] 2.1.4 `up()` миграции: `require_once ABSPATH . 'wp-admin/includes/upgrade.php';` и
  `foreach ( self::examDdl( $wpdb->get_charset_collate() ) as $sql ) { dbDelta( $sql ); }`.
- [ ] 2.1.5 `down()` миграции: `DROP TABLE IF EXISTS` для 15 **новых** таблиц (только их).
- [ ] 2.1.6 `Migration_1_0_0::up()`: в конец добавить блок `// ===== 26–40. Экзамены =====` с тем же циклом по
  `Migration_1_0_71::examDdl( $cc )`. В `down()` добавить 15 таблиц в начало списка. Обновить докблок класса (число таблиц и перечень).

**Тесты** — `tests/Unit/Migrations/ExamSchemaTest.php`:
- `test_ddl_contains_fifteen_tables`;
- `test_every_table_name_comes_from_table_name_enum` — каждое `CREATE TABLE` содержит значение одного из кейсов `TableName::Exam*`;
- `test_primary_key_has_two_spaces` — в каждом DDL есть подстрока `PRIMARY KEY  (id)`;
- `test_registrations_have_active_slot_unique_index`;
- `test_no_foreign_keys_and_no_if_not_exists`.

**Готово, когда:** тест зелёный. Проверка на базе — в 2.7.

---

## 2.2 Расширение `assessment_attempts`

**Зачем.** Попытка получает экзаменный контекст (участие, запись), гостевая попытка не имеет `Person`,
а у проведения одна официальная попытка на участие (SPEC §11).

**Проверить перед началом**
- `sed -n 486,516p inc/Migrations/Migration_1_0_0.php` — DDL: `student_person_id int unsigned NOT NULL`,
  уникальный ключ `attempt (assessment_id, student_person_id, attempt_number)`.
- `inc/DTO/Assessment/AttemptDTO.php`, `AttemptInputDTO.php` — `studentPersonId` имеет тип `int`.
- Все обращения к полю: `grep -rn "studentPersonId" inc --include="*.php" | grep -i "attempt" | wc -l` — записать число;
  после правки DTO каждое место должно пережить `null`.
- `inc/Migrations/Migration_1_0_54.php` — образец идемпотентного `ADD COLUMN`.

**Шаги**
- [ ] 2.2.1 В `Migration_1_0_71::up()` после создания таблиц добавить колонки в `TableName::AssessmentAttempts` (каждую через
  проверку `SHOW COLUMNS … LIKE`):
  | Колонка | Определение |
  |---|---|
  | `exam_participation_id` | `int unsigned DEFAULT NULL AFTER group_lesson_id` |
  | `exam_registration_id` | `int unsigned DEFAULT NULL AFTER exam_participation_id` |
  | `result_version` | `int unsigned NOT NULL DEFAULT 0 AFTER approved_by_user_id` |
- [ ] 2.2.2 Там же: `ALTER TABLE … MODIFY student_person_id int unsigned DEFAULT NULL` (повторное выполнение безвредно).
- [ ] 2.2.3 Там же: уникальный индекс одной официальной попытки на участие. Перед созданием проверить
  `SHOW INDEX FROM … WHERE Key_name = 'exam_participation'`; если нет —
  `ALTER TABLE … ADD UNIQUE KEY exam_participation (exam_participation_id)`.
  У старых попыток значение `NULL`, уникальный индекс их не затрагивает.
- [ ] 2.2.4 `Migration_1_0_0`: в DDL `assessment_attempts` внести те же три колонки, `student_person_id … DEFAULT NULL`
  и `UNIQUE KEY exam_participation (exam_participation_id)` — чтобы новая установка сразу получала итоговую схему.
- [ ] 2.2.5 `down()` миграции: удалить индекс и три колонки (`DROP COLUMN IF EXISTS`). `student_person_id` обратно в `NOT NULL` не возвращать
  (в таблице уже могут быть гостевые строки).
- [ ] 2.2.6 `AttemptDTO`: `public ?int $studentPersonId`; новые поля в конце конструктора
  `public ?int $examParticipationId = null, public ?int $examRegistrationId = null, public int $resultVersion = 0`.
  В `fromArray()`: `isset( $row['student_person_id'] ) ? (int) … : null` и три новых поля. Метод `isExam(): bool` → `null !== $this->examParticipationId`.
- [ ] 2.2.7 `AttemptInputDTO`: `public ?int $studentPersonId`, в конец — `public ?int $examParticipationId = null, public ?int $examRegistrationId = null`.
- [ ] 2.2.8 `AssessmentAttemptRepository::create()` — писать два новых поля.
- [ ] 2.2.9 Пройти список из проверки (`grep … studentPersonId`). Гостевые попытки (`null`) до этапа 11b не создаются,
  а старые экраны на этапе 6.7 перестанут видеть экзаменные попытки. Поэтому сейчас достаточно, чтобы код **компилировался и тесты
  проходили**: там, где `studentPersonId` передаётся в параметр `int`, добавить явную защиту
  (`if ( null === $attempt->studentPersonId ) { return …; }`) с комментарием «гостевая попытка экзамена, этап 11b».
  Поведение для обычных попыток не менять.

**Тесты**
- `tests/Unit/DTO/…/AttemptDTOTest.php` (создать, если нет): `test_from_array_reads_exam_fields`,
  `test_from_array_allows_null_student_for_guest`, `test_is_exam`.
- `tests/Integration/Repositories/AssessmentAttemptRepositoryTest.php` — дописать `test_create_writes_exam_context`.
- Полный прогон `vendor/bin/phpunit` — регрессий в `tests/Unit/Services/Assessment`, `tests/Unit/Services/Course` нет.

**Готово, когда:** весь PHPUnit зелёный; проверка на базе — в 2.7.

---

## 2.7 Регистрация миграции и проверка повторяемости

**Зачем.** Миграция должна доехать до живых установок и выполняться повторно без потерь (SPEC §14).

**Проверить перед началом**
- `grep -n "Migration_1_0_70" inc/Core/Activate.php inc/Init.php` — два места регистрации.
- Скилл `db-migrations`: **сброс `fs_lms_schema_version` запрещён**.
- Текущая версия схемы на dev:
  `docker exec wp_db mariadb -u root -proot wordpress -N -e "SELECT option_value FROM wp_options WHERE option_name='fs_lms_schema_version'"`.

**Шаги**
- [ ] 2.7.1 Зарегистрировать `Migration_1_0_71` в `inc/Core/Activate.php` и в `inc/Init.php` (после `Migration_1_0_70`, с `use`).
- [ ] 2.7.2 Сделать дамп: `docker exec wp_db mariadb-dump -u root -proot wordpress > .docs/db-backups/before-exam-schema.sql`.
- [ ] 2.7.3 `docker restart wp_app`, открыть `http://localhost:8080/`. Проверить:
  ```bash
  docker exec wp_db mariadb -u root -proot wordpress -e "SHOW TABLES LIKE 'wp_fs_lms_exam_%'"            # 15 строк
  docker exec wp_db mariadb -u root -proot wordpress -e "SHOW COLUMNS FROM wp_fs_lms_assessment_attempts" # 3 новые колонки, student_person_id NULL = YES
  docker exec wp_db mariadb -u root -proot wordpress -e "SHOW INDEX FROM wp_fs_lms_assessment_attempts WHERE Key_name='exam_participation'"
  ```
- [ ] 2.7.4 Повторяемость: записать число строк `SELECT COUNT(*) FROM wp_fs_lms_assessment_attempts`, затем выполнить `up()` второй раз
  `docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp eval '( new \Inc\Migrations\Migration_1_0_71() )->up(); echo "ok";'`
  — без ошибок, число строк прежнее, в `debug.log` (последние 15 строк) нет ошибок SQL.
- [ ] 2.7.5 В `ExamCommand` (этап 1.4) добавить подкоманду `fs-lms exam selftest` — заготовку, которая открывает транзакцию,
  выполняет проверки и **всегда** делает `ROLLBACK`. Сами проверки дописываются в 2.3 (шаг 2.3.9).

**Тесты.** `tests/Unit/Migrations/ExamSchemaTest.php` — дописать `test_migration_version_matches_class_name`.

**Готово, когда:** 15 таблиц и колонки на месте, повторный `up()` безошибочен, число попыток не изменилось.

---

## 2.3 DTO, репозитории, `ExamTime`, `ExamOutbox`

**Зачем.** Типизированный доступ к новым таблицам и единая точка перевода времени.

**Проверить перед началом**
- Образец репозитория: `inc/Repositories/WPDBRepositories/RoomRepository.php` (конструктор `?\wpdb $wpdb = null`, `%i` для имени таблицы).
- Образец DTO: `inc/DTO/Course/RoomDTO.php` (`readonly class`, `fromArray()`, `toArray()`).
- `inc/Contracts/ClockInterface.php`: `now( string $type = 'mysql', bool $gmt = false )`. UTC — `now( 'mysql', true )`.
- `tests/bootstrap.php`: `wp_timezone()` возвращает `UTC` или `$GLOBALS['_fs_test_timezone']`.
- `grep -rn "FOR UPDATE" inc` — посмотреть, есть ли уже образец блокирующего чтения.

**Шаги**
- [ ] 2.3.1 `inc/Services/Exam/ExamTime.php` (зависимость: `ClockInterface`):
  - `nowUtc(): string` — `$this->clock->now( 'mysql', true )`;
  - `nowLocal(): string` — `$this->clock->now()`;
  - `toUtc( string $local ): string` и `toLocal( string $utc ): string` — через `DateTimeImmutable` и `wp_timezone()`;
  - `addMinutes( string $datetime, int $minutes ): string`;
  - `endOfLocalDayUtc( string $date ): string` — `23:59:59` местного дня в UTC (для сравнения с периодом проведения).
- [ ] 2.3.2 `inc/Repositories/WPDBRepositories/Exam/AbstractExamRepository.php` — общая база:
  конструктор `?\wpdb $wpdb = null`; `protected function write( string $sql ): int` — выполняет запрос и возвращает число
  затронутых строк; при `false` читает номер ошибки (`$this->wpdb->dbh instanceof \mysqli ? $this->wpdb->dbh->errno : 0`):
  `1213` или `1205` → `throw new RetryableDbException( $this->wpdb->last_error )`, иначе `throw new \RuntimeException( … )`.
  Класс исключения — `inc/Repositories/WPDBRepositories/Exam/RetryableDbException.php` (`extends \RuntimeException`).
- [ ] 2.3.3 DTO в `inc/DTO/Exam/` — по одному на таблицу, поля в camelCase один к одному с колонками, статусы — энумами из 0.5:
  `ExamEventDTO`, `ExamSessionDTO`, `ExamParticipantDTO`, `ExamParticipationDTO`, `ExamRegistrationDTO`, `ExamSourceDTO`, `ExamAccessTokenDTO`.
  У каждого `fromArray( array $row ): self`. Дополнительно:
  - `ExamEventDTO::snapshotFor( int $assessmentId ): ?array` — разбирает `variant_snapshot`;
  - `ExamSessionDTO::freeSeats(): int` → `max( 0, capacity − occupiedCount )`; `isLocked(): bool` → `null !== firstStartedAt`;
  - `ExamParticipationDTO::hasAttempt(): bool`.
  DTO остальных таблиц (`GuestApplicationDTO`, `ExamPaymentLinkDTO`, `ExamReportDTO`) создаются на этапах 3.4, 11a, 12.
- [ ] 2.3.4 `ExamEventRepository`: `create( array $data ): int`, `find( int $id ): ?ExamEventDTO`, `findForUpdate( int $id ): ?ExamEventDTO`
  (`SELECT … FOR UPDATE`), `update( int $id, array $data, int $expectedVersion ): bool`, `listBySubjects( array $subjectKeys, array $statuses ): array`,
  `listByOwner( int $userId ): array`.
  **Правило `update()` для всех репозиториев с `version`:** `UPDATE … SET <поля>, version = version + 1, updated_at = %s WHERE id = %d AND version = %d`;
  `true`, только если затронута ровно одна строка. Вызывающий сервис при `false` бросает `CodedException( ErrorCode::ExamStale, … )`.
- [ ] 2.3.5 `ExamSessionRepository`: `create`, `find`, `findForUpdate`, `update( …, int $expectedVersion )`, `listByEvent( int $eventId ): array`,
  `lockInOrder( array $ids ): array` (`WHERE id IN (…) ORDER BY id ASC FOR UPDATE`),
  `occupySeat( int $id ): bool` и `releaseSeat( int $id ): bool`:
  ```sql
  UPDATE {t} SET occupied_count = occupied_count + 1 WHERE id = %d AND status = 'open' AND occupied_count < capacity
  UPDATE {t} SET occupied_count = occupied_count - 1 WHERE id = %d AND occupied_count > 0
  ```
  (обе возвращают `true` при одной затронутой строке; `version` эти запросы не меняют),
  `isRoomBusy( int $roomId, string $startUtc, string $endUtc, int $excludeSessionId = 0 ): bool` — пересечение с сеансами
  не в статусе `cancelled`: `scheduled_at < %s(end) AND planned_end_at > %s(start)`.
- [ ] 2.3.6 `ExamParticipantRepository`: `find`, `findByPersonId( int $personId )`, `getOrCreateForPerson( int $personId ): int`
  (`INSERT IGNORE` по уникальному `person_id`, затем `SELECT id`), `create( array $data ): int`.
  `ExamParticipationRepository`: `find`, `findForUpdate`, `findByEventAndParticipant`, `getOrCreateLocked( int $eventId, int $participantId, ExamAudience $audience ): ExamParticipationDTO`
  (`INSERT IGNORE`, затем `SELECT … FOR UPDATE`), `setActiveRegistration( int $id, ?int $registrationId ): void`,
  `setCurrentAttempt( int $id, ?int $attemptId ): void`, `listByEvent( int $eventId ): array`.
  `ExamRegistrationRepository`: `create( array $data ): int`, `find`, `findActiveByParticipation( int $participationId ): ?ExamRegistrationDTO`,
  `listBySession( int $sessionId, array $statuses = array() ): array`, `listByParticipation( int $participationId ): array`,
  `deactivate( int $id, ExamRegistrationStatus $status, string $atUtc, ?string $reason, ?int $actorUserId ): bool`
  (ставит статус, `active_slot = NULL` и соответствующую отметку времени, только если `active_slot = 1`).
- [ ] 2.3.7 `ExamOutboxRepository`: `insert( array $row ): void`, `leaseBatch( string $nowUtc, string $leaseUntilUtc, int $limit ): array`,
  `markProcessed( int $id, string $atUtc ): void`, `markFailed( int $id, string $error, string $nextAvailableAtUtc ): void`.
  `ExamOperationKeyRepository`: `find( string $scope, string $operation, string $requestKey ): ?array`,
  `insert( … ): void`, `purgeExpired( string $nowUtc ): int`.
  `ExamAccessTokenRepository`: `insert( array $row ): int`, `findByHash( string $hash ): ?ExamAccessTokenDTO`,
  `findActive( ExamTokenPurpose $purpose, int $targetId ): ?ExamAccessTokenDTO`, `maxGeneration( ExamTokenPurpose $purpose, int $targetId ): int`,
  `revokeByTarget( ExamTokenPurpose $purpose, int $targetId, string $atUtc ): int`, `markPassed( int $id, int $userId, string $atUtc ): void`.
  `ExamSourceRepository` — создаётся на этапе 4.6.
- [ ] 2.3.8 `inc/Services/Exam/ExamOutbox.php` (зависимости: `ExamOutboxRepository`, `ExamTime`):
  `add( ExamOutboxEvent $type, string $aggregateType, int $aggregateId, int $aggregateVersion, array $payload, ?string $availableAtUtc = null ): void`.
  `event_uuid` — `wp_generate_uuid4()` (если функции нет в `tests/bootstrap.php` — добавить заглушку). **В payload нельзя класть ключи доступа,
  ФИО, телефоны** — только идентификаторы. Метод вызывается внутри транзакции вызывающего сервиса и сам транзакций не открывает.
- [ ] 2.3.9 `ExamCommand::selftest` (заготовка из 2.7.5) — проверки на настоящей базе внутри транзакции с откатом:
  1. создать проведение и сеанс с `capacity = 1`;
  2. `occupySeat()` → `true`, второй вызов → `false`, `occupied_count = 1`;
  3. `releaseSeat()` → `true`, второй → `false`;
  4. две брони одного участия с `active_slot = 1` — вторая вставка падает по уникальному индексу;
  5. `deactivate()` первой, после этого новая бронь вставляется;
  6. `update()` проведения с неверной версией → `false`;
  7. `getOrCreateForPerson()` дважды даёт один ID.
  Вывод — таблица «проверка / OK или FAIL», код выхода `1` при любом FAIL.

**Тесты**
- `tests/Unit/Services/Exam/ExamTimeTest.php`: `test_to_utc_and_back_round_trip` (пояс `Europe/Kaliningrad` через `$GLOBALS['_fs_test_timezone']`),
  `test_end_of_local_day_utc`, `test_add_minutes_crosses_midnight`.
- `tests/Integration/Repositories/Exam/ExamSessionRepositoryTest.php` (FakeWpdb): `test_occupy_seat_sql_is_conditional` — в запросе есть
  `occupied_count < capacity` и `status = 'open'`; `test_occupy_returns_false_when_no_row_affected` (`queueQuery( 0 )`);
  `test_lock_in_order_sorts_ids_ascending`; `test_is_room_busy_excludes_cancelled_and_given_session`.
- `tests/Integration/Repositories/Exam/ExamEventRepositoryTest.php`: `test_update_requires_expected_version`, `test_update_increments_version`.
- `tests/Integration/Repositories/Exam/ExamRegistrationRepositoryTest.php`: `test_deactivate_clears_active_slot`, `test_deactivate_only_active_row`.
- `tests/Unit/Services/Exam/ExamOutboxTest.php`: `test_add_writes_uuid_type_and_payload`, `test_default_available_at_is_now_utc`.

**Готово, когда:** PHPUnit зелёный; `docker compose … run --rm wpcli wp fs-lms exam selftest` — все строки OK; после команды
`SELECT COUNT(*) FROM wp_fs_lms_exam_events` → `0` (откат сработал).

---

## 2.4 `ExamEventService`

**Зачем.** Создание, правка, публикация и отмена проведения; сеансы с проверкой дат, кабинета и варианта;
снимок варианта (SPEC §3, §12).

**Проверить перед началом**
- Этап 1 выполнен: `ExamAccessGuard`, `ExamVariantPolicy`.
- `inc/DTO/Course/RoomDTO.php`: `allowsSubject()`, `hasCapacity()` (0.9.6), `isActive`.
- `inc/Shared/Traits/TransactionRunner.php` — `inTransaction( callable $fn )`.
- `inc/Repositories/WPDBRepositories/RoomRepository.php::isBusy()` — время **местное** (`group_lessons.scheduled_at`).
- `RoomCallbacks::ajaxSaveRoom()` — место, куда встанет пересинхронизация вместимости (шаг 2.4.9).

**Шаги**
- [ ] 2.4.1 `ExamAccessGuard::canManageEvent( int $userId, ExamEventDTO $event ): bool` — `isGlobal()` **или**
  (`$event->ownerUserId === $userId` **и** `canManageSubject( $userId, $event->subjectKey )`).
  Преподаватель того же предмета чужим проведением не управляет. Тест — в `ExamAccessGuardTest`.
- [ ] 2.4.2 `inc/Services/Exam/ExamRoomService.php` (зависимости: `RoomRepository`, `ExamSessionRepository`, `ExamTime`):
  `assertUsable( int $roomId, string $subjectKey ): RoomDTO` — кабинет существует, активен, `allowsSubject()`, `hasCapacity()`;
  иначе `CodedException( ErrorCode::ExamRoom, … )`. Текст для нулевой вместимости: «Укажите вместимость кабинета в „Настройки → Кабинеты“.»
  `assertFree( int $roomId, string $startUtc, string $endUtc, int $excludeSessionId = 0 ): void` — два условия:
  нет пересечения с другим сеансом (`ExamSessionRepository::isRoomBusy`) и с занятием
  (`RoomRepository::isBusy( $roomId, toLocal( $startUtc ), toLocal( $endUtc ) )`). Иначе `CodedException( ErrorCode::ExamConflict, 'Кабинет занят в это время.' )`.
  Обратное направление (занятие видит экзамен), сериализация и предупреждение о позднем старте — этап 4.4.
- [ ] 2.4.3 `inc/Services/Exam/ExamEventService.php`, `use TransactionRunner;`. Зависимости: репозитории проведений и сеансов,
  `ExamAccessGuard`, `ExamVariantPolicy`, `ExamRoomService`, `ExamFormatRegistry`, `AssessmentManager`, `ExamOutbox`, `ExamTime`.
  Каждый публичный метод первой строкой проверяет право (`canManageSubject` для создания, `canManageEvent` для остального),
  иначе `CodedException( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.' )`.
- [ ] 2.4.4 `createDraft( int $actorUserId, array $input ): ExamEventDTO`. Вход: `subject_key`, `title`, `description`, `period_from`, `period_to`
  (даты местные `Y-m-d`), `registration_opens_at`, `registration_closes_at` (местное время), `default_assessment_id`, `guest_registration_enabled`.
  Проверки и тексты:
  | Условие | Текст |
  |---|---|
  | пустое название | «Укажите название проведения.» |
  | `period_from > period_to` | «Дата начала позже даты окончания.» |
  | `opens > closes` | «Открытие записи позже её закрытия.» |
  | `closes` позже конца `period_to` | «Запись не может закрываться позже последнего дня проведения.» |
  | вариант не подходит | текст из `ExamVariantPolicy::check()` |
  Времена записи перевести в UTC. `owner_user_id = $actorUserId`, статус `draft`.
- [ ] 2.4.5 `updateEvent( int $actorUserId, int $eventId, array $input, int $expectedVersion ): ExamEventDTO` — те же проверки;
  правка разрешена только при `status->isEditable()`; предмет после создания не меняется.
- [ ] 2.4.6 `saveSession( int $actorUserId, int $eventId, array $input, ?int $sessionId, ?int $expectedVersion ): ExamSessionDTO`.
  Вход: `date` (`Y-m-d`), `time` (`H:i`) — местные; `assessment_id`; `room_id`. Логика:
  1. `scheduled_at` = местные дата и время → UTC; дата внутри `[period_from, period_to]`, иначе «Дата сеанса вне периода проведения.»;
  2. длительность — `ExamFormatRegistry::for( $assessment->kind )->durationMinutes`; `planned_end_at = scheduled_at + длительность`.
     Поля «конец» и «вместимость» во входе нет;
  3. `ExamVariantPolicy::assert( assessment_id, subject_key )`;
  4. `$room = ExamRoomService::assertUsable(...)`, `capacity = $room->seats`; `assertFree(...)`;
  5. `responsible_user_id = owner_user_id` проведения (SPEC §3: другой ответственный — только передачей владения);
  6. при правке существующего сеанса: если `isLocked()` (уже был старт) — менять вариант, дату, время и кабинет нельзя
     («Сеанс уже начат: изменить можно только индивидуально.»); если `occupied_count > новой capacity` — отказ
     («В сеансе уже занято N мест, в кабинете их меньше.», число — подстановкой).
  Всё внутри `inTransaction()`.
- [ ] 2.4.7 `deleteSession( int $actorUserId, int $sessionId ): void` — только если в сеансе нет ни одной записи (`occupied_count = 0`
  и `listBySession()` пуст). Иначе «В сеансе есть записи: используйте отмену сеанса.» (отмена с участниками — этап 8.3).
- [ ] 2.4.8 `publish( int $actorUserId, int $eventId, int $expectedVersion ): ExamEventDTO`:
  - статус должен быть `draft`;
  - есть хотя бы один сеанс `open` с `scheduled_at` в будущем, иначе «Добавьте хотя бы один сеанс в будущем.»;
  - заданы `registration_opens_at` и `registration_closes_at`;
  - для каждого варианта сеансов собрать снимок `buildSnapshot( int $assessmentId ): array` и записать объект в `variant_snapshot`:
    `assessment_id`, `kind`, `task_ids` (в порядке работы), `task_numbers`, `task_points`, `duration_minutes`, `primary_max`,
    `secondary_max`, `scale`, `built_at` (UTC). Это небольшой конфиг, не копия банка (SPEC §12);
  - статус `published`, `published_at = nowUtc()`;
  - `ExamOutbox::add( ExamOutboxEvent::EventPublished, 'event', $eventId, $version, array( 'event_id' => … ) )` в той же транзакции.
- [ ] 2.4.9 `rebuildSnapshot( int $actorUserId, int $eventId, int $assessmentId ): void` — пересборка снимка после правки опечатки.
  Разрешена, только если **ни один сеанс этого варианта не идёт сейчас** (`scheduled_at <= now < planned_end_at`) и у варианта в этом
  проведении нет незавершённых попыток. Иначе «Идёт сеанс с этим вариантом: снимок менять нельзя.»
- [ ] 2.4.10 `cancelEvent( int $actorUserId, int $eventId, string $reason, int $expectedVersion ): void` — причина обязательна
  («Укажите причину отмены.»); статус `cancelled`, `cancel_reason`, `cancelled_at`; все сеансы `open` → `cancelled`;
  outbox `EventCancelled`. Отмена записей участников и уведомления — этап 8.3; здесь оставить `// TODO(8.3)` с номером задачи.
- [ ] 2.4.11 `syncCapacityForRoom( int $roomId, int $newSeats ): void` — для будущих сеансов `open` этого кабинета:
  увеличение применяется всегда; уменьшение — только если `newSeats >= occupied_count` у каждого такого сеанса, иначе
  `CodedException( ErrorCode::ExamConflict, … )` с названием проведения и числом занятых мест. Метод зовёт
  `RoomCallbacks::ajaxSaveRoom()` **до** сохранения кабинета; при исключении кабинет не сохраняется, пользователь видит текст ошибки.
  В репозиторий сеансов добавить `listFutureOpenByRoom( int $roomId, string $nowUtc ): array` и `setCapacity( int $id, int $capacity ): void`.

**Тесты** — `tests/Unit/Services/Exam/ExamEventServiceTest.php` (репозитории и зависимости — моки, время — мок `ClockInterface`):
- создание: `test_create_draft_requires_subject_scope`, `test_create_rejects_reversed_period`,
  `test_create_rejects_registration_closing_after_period`, `test_create_rejects_variant_of_other_subject`,
  `test_period_may_span_weekend_and_month_boundary` (например 2026-10-30 … 2026-11-08);
- сеанс: `test_session_end_is_start_plus_format_duration` (10:00 + 235 минут = 13:55 местного),
  `test_session_capacity_copied_from_room_seats`, `test_session_rejects_room_without_capacity`,
  `test_session_rejects_room_not_allowing_subject`, `test_session_rejects_date_outside_period`,
  `test_session_rejects_busy_room`, `test_locked_session_cannot_change_variant`,
  `test_capacity_cannot_drop_below_occupied`;
- публикация: `test_publish_requires_future_session`, `test_publish_builds_snapshot_per_variant`,
  `test_publish_writes_outbox_event_in_transaction`, `test_publish_with_stale_version_fails`;
- права: `test_teacher_cannot_manage_foreign_event`, `test_admin_manages_any_event`;
- прочее: `test_cancel_requires_reason`, `test_rebuild_snapshot_blocked_during_running_session`,
  `test_sync_capacity_increase_applies`, `test_sync_capacity_decrease_below_occupied_throws`.
- `tests/Unit/Services/Exam/ExamRoomServiceTest.php`: `test_zero_seats_room_is_rejected_with_settings_hint`,
  `test_lesson_overlap_is_checked_in_local_time` — аргументы `RoomRepository::isBusy` получены после `toLocal()`.
- `tests/Unit/Callbacks/Course/RoomCallbacksTest.php`: `test_save_room_is_blocked_when_capacity_sync_fails`.

**Готово, когда:** `vendor/bin/phpunit --filter "ExamEventService|ExamRoomService|RoomCallbacks"` зелёный.

---

## 2.5 `ExamAccessTokenService`

**Зачем.** Единый механизм секретных ссылок: приглашение формы, вход гостя, результат, школьный отчёт.
Ключ не короче 128 бит, в базе только хеш, перевыпуск отзывает прежний ключ (SPEC §6, §8).

**Проверить перед началом**
- `inc/Services/Security/PiiCryptoService.php::hash()` — SHA-256 с солью; **приводит строку к нижнему регистру**,
  поэтому ключ генерируется сразу в нижнем регистре (hex).
- `inc/Services/Application/JoinCodeService.php` — образец подхода. Его сроки (72 часа, 20 дней), формат `JOIN-…` и обработчик
  **не переиспользовать** (SPEC §8).
- `ExamAccessTokenRepository` из 2.3.7.

**Шаги**
- [ ] 2.5.1 `inc/Services/Exam/ExamAccessTokenService.php`. Зависимости: `ExamAccessTokenRepository`, `PiiCryptoService`, `ExamTime`.
- [ ] 2.5.2 `issue( ExamTokenPurpose $purpose, int $targetId, int $issuerUserId, ?string $expiresAtUtc = null ): string`:
  1. `revokeByTarget( $purpose, $targetId, nowUtc )` — прежние ключи этой цели перестают действовать;
  2. `$plain = bin2hex( random_bytes( 32 ) )` — 256 бит, 64 hex-символа;
  3. `generation = maxGeneration( … ) + 1`;
  4. вставить строку с `token_hash = PiiCryptoService::hash( $plain )`;
  5. вернуть `$plain`. Открытый ключ **нигде не сохраняется и не логируется**; показать его можно только в ответе на этот вызов.
- [ ] 2.5.3 `exchange( ExamTokenPurpose $purpose, string $plain ): ?ExamAccessTokenDTO` — `null` при любой причине отказа (без различения):
  формат не `/^[a-f0-9]{64}$/` (до обращения к базе); не найден; `purpose` не совпал; `revoked_at` задан; `expires_at` в прошлом.
- [ ] 2.5.4 `revoke( ExamTokenPurpose $purpose, int $targetId ): int` — число отозванных.
- [ ] 2.5.5 `currentGeneration( ExamTokenPurpose $purpose, int $targetId ): int` — поколение действующего ключа (`0`, если ключа нет).
  Гостевые сессии (этап 11a/11b) хранят поколение и сравнивают с текущим: перевыпуск делает старые куки недействительными.
- [ ] 2.5.6 `markPassed( int $tokenId, int $actorUserId ): void` — ручная отметка «Ссылка передана». Копирование ссылки отметку не ставит.
- [ ] 2.5.7 Проверка назначения цели (чей `target_id`) — забота вызывающего сервиса: токен `entry` не открывает `result` и наоборот.

**Тесты** — `tests/Unit/Services/Exam/ExamAccessTokenServiceTest.php`:
- `test_issue_returns_64_hex_chars_and_stores_only_hash` — в `insert()` нет открытого ключа;
- `test_issue_revokes_previous_token_and_increments_generation`;
- `test_exchange_returns_null_for_malformed_key_without_db_lookup` — `findByHash` не вызывается;
- `test_exchange_rejects_other_purpose`, `test_exchange_rejects_revoked`, `test_exchange_rejects_expired`;
- `test_exchange_accepts_valid_token`;
- `test_two_issued_tokens_differ`.

**Готово, когда:** `vendor/bin/phpunit --filter ExamAccessTokenService` зелёный; `grep -n "PluginLogger" inc/Services/Exam/ExamAccessTokenService.php` не логирует ключ.

---

## 2.6 Минутный cron-запуск

**Зачем.** Неявка, автоистечение попыток, освобождение броней гостей и доставка событий требуют минутной точности
(SPEC §10). Здесь создаётся только каркас: интервал, хуки, блокировка от параллельного запуска. Содержимое тиков
появляется на этапах 3.4, 6.2, 6.3, 9.2, 11a.8.

**Проверить перед началом**
- `inc/Controllers/System/CronController.php::register()` — интервал `every_15_minutes`, регистрация `NotificationsTick`.
- `inc/Managers/Wp/CronManager.php`: `addCustomInterval()`, `schedule()` (планирует, только если хук ещё не запланирован),
  `unregisterAll()` (снимает все кейсы `CronHook` при деактивации).
- README §8 п. 2: расписание `NotificationsTick` **не меняется**.
- `grep -rn "GET_LOCK" inc` → пусто.

**Шаги**
- [ ] 2.6.1 `inc/Repositories/WPDBRepositories/Exam/ExamLockRepository.php`: `acquire( string $name ): bool` —
  `SELECT GET_LOCK( %s, 0 )` (`1` → `true`), `release( string $name ): void` — `SELECT RELEASE_LOCK( %s )`.
  Имя блокировки — с префиксом базы: `$this->wpdb->prefix . $name`.
- [ ] 2.6.2 `inc/Services/Exam/ExamTickLock.php` (зависимость: `ExamLockRepository`):
  `run( string $name, callable $fn ): bool` — взять блокировку; не получилось → `false` без выполнения;
  иначе выполнить `$fn` в `try { … } finally { release }` и вернуть `true`. Исключение из `$fn` логировать
  `PluginLogger::exception( 'ExamTick', $e, array( 'tick' => $name ), true )` и не пробрасывать (cron не должен падать).
- [ ] 2.6.3 `CronController::register()`:
  - `$this->cron_manager->addCustomInterval( 'every_minute', 60, 'Every minute' );` рядом с 15-минутным;
  - `add_action( CronHook::ExamAutoExpireTick->value, array( $this, 'handleExamAutoExpireTick' ) );`
    `add_action( CronHook::ExamHoldReleaseTick->value, array( $this, 'handleExamHoldReleaseTick' ) );`
  - `$this->cron_manager->schedule( …->value, 'every_minute' );` для обоих.
- [ ] 2.6.4 Два обработчика в `CronController` — только делегирование:
  `$this->tickLock->run( 'exam_auto_expire', fn() => $this->examTicks->autoExpire() );` и аналог для броней.
  Класс `inc/Services/Exam/ExamTickService.php` с методами `autoExpire(): void` и `releaseHolds(): void` — пока пустыми,
  с докблоком, какой этап что добавляет. В контроллере бизнес-логики быть не должно.
- [ ] 2.6.5 Серверный cron на dev не настраивается. В `ExamCommand` добавить `fs-lms exam tick [--name=<auto-expire|hold-release>]`
  — ручной запуск тика для проверок на следующих этапах.
- [ ] 2.6.6 Инструкцию серверного cron (раз в минуту `wp-cron.php`, `DISABLE_WP_CRON`) в этом пункте не писать — она входит в 13.7.

**Тесты**
- `tests/Unit/Services/Exam/ExamTickLockTest.php`: `test_runs_callable_when_lock_acquired`,
  `test_skips_callable_when_lock_busy`, `test_releases_lock_when_callable_throws`, `test_exception_is_logged_not_thrown`.
- `tests/Unit/Controllers/…/CronControllerTest.php` (если есть — дописать; если нет — создать минимальный):
  `test_register_adds_every_minute_interval_and_exam_hooks`.

**Готово, когда:** тесты зелёные; на dev
`docker compose … run --rm wpcli wp cron event list --fields=hook,recurrence | grep exam` показывает два хука с `every_minute`;
`wp fs-lms exam tick --name=auto-expire` отрабатывает без ошибок; два одновременных запуска (во втором окне) — второй сообщает «тик уже выполняется».

---

## Проверка этапа

- [ ] `npm run ci` зелёный.
- [ ] `wp fs-lms exam selftest` — все проверки OK на настоящей MariaDB.
- [ ] Миграция выполняется дважды без ошибок и без изменения существующих данных.
- [ ] Минутные хуки запланированы, блокировка не даёт выполнить тик параллельно.
- [ ] В `inc/Services/Exam` нет `$wpdb`; в `inc/Controllers` нет бизнес-логики тиков.
