# План: импорт с полным зачислением (CSV → ученик + учётки)

> Аудит: ветка `stage_11`, 2026-07-25.
> Цель: к существующему «сухому» импорту (архивные записи без учёток) добавить второй режим —
> **полное зачисление**: тот же CSV, но с созданием WP-учёток ученика и родителя, логином/паролем
> ученика из файла и без колонок отчисления. Сценарий — разовая миграция ~20 действующих учеников.
>
> Порядок этапов = порядок исполнения; каждый этап — отдельный коммит.
> После каждого этапа: `npx gulp build`, `npm run lint:js`, PHPUnit, проверка в Docker
> (`docker restart wp_app` после PHP-правок).

## Зафиксированные решения (подтверждено с заказчиком)

- **Два режима импорта.** Существующий `archive` (записи прошлых лет, без учёток) остаётся как есть.
  Новый `enrolled` (полное зачисление) — добавляется. Выбор режима — радио на табе «Импорт».
- **Enrolled всегда создаёт учётки** и ученику (`FSStudent`), и родителю (`FSParent`).
  Учётки создаются безусловно; чекбокс управляет **только** отправкой писем.
- **Логин и пароль ученика — обязательные колонки CSV.** Генерации нет: у заказчика есть все
  логины/пароли учеников. Пустой логин или пароль в enrolled-строке → ошибка строки (в отчёт).
- **Родитель:** отдельных колонок логин/пароль у родителя нет. Логин = e-mail родителя, пароль —
  генерируется (как в `EnrollmentService::enroll()`), выдаётся администратору в отчёте/CSV и в письме
  (если чекбокс включён). Поэтому `Родитель: Email` — обязательная колонка в enrolled-режиме.
- **Письма — по чекбоксу** «Отправить письма родителям» (по умолчанию **выкл**). Письмо —
  существующее `WelcomeWithCredentials` (родителю, `EmailService::sendWelcomeWithCredentials`).
- **Отчёт с учётными данными.** После enrolled-импорта отчёт показывает таблицу
  ученик → логин/пароль (+ родитель → логин/пароль) и кнопку **«Скачать логины и пароли (CSV)»**.
- **Колонок отчисления в enrolled-режиме нет** — все зачисляются активными (`status = active`).
- **Транспорт переиспользуется:** тот же AJAX-хук `ImportStudentsCsv`, `Nonce::Manager`,
  `Capability::Admin`. Новый режим отличается параметром `mode` и флагом `send_emails`.

## Ключевой факт (упрощает работу)

`StudentRowImporter` при пустых колонках отчисления **уже** создаёт активную запись (группа + persons
ученика/родителя + зашифрованные документы + `student_records` со `status = active`). Единственная
дельта enrolled-режима — **создание WP-учёток**. Логика создания учёток сейчас зашита внутри
`EnrollmentService::enroll()` (строки 199–297) и намертво связана с потоком заявок. Её нужно вынести
в переиспользуемый сервис.

---

## Контекст (что уже есть — переиспользуем, не переписываем)

| Механизм | Где | Используем |
|---|---|---|
| Скелет импорта | `inc/Services/Import/ImportService.php` (parse → validate headers → per-row transaction → report) | обобщаем: `run()` принимает импортёр + режим |
| Импорт строки (архив) | `inc/Services/Import/StudentRowImporter.php` | рефактор на общий writer + `RowImporterInterface` |
| Резолв дублей person | `inc/Services/Import/PersonImportResolver.php` (doc/email/ФИО) | как есть |
| Создание person + документы | `inc/Services/Person/PersonService.php::createOrFindBy()` | как есть |
| Группы find-or-create | `GroupsRepository::findByNameSubjectPeriod` / `create` | как есть, через общий writer |
| Дедуп записи | `StudentRecordRepository::existsByContract()` | как есть, в обоих импортёрах |
| **Создание WP-учётки** | `EnrollmentService::enroll()` строки 199–297 (ученик+родитель, `loginPassword`/`username`, привязка person↔user, `storeEncrypted`, лог `UserCreated`) | **вынести** в `AccountProvisioningService` |
| Установка/хранение пароля | `PasswordGeneratorService::setFromPlain()` / `generatePlain()` / `storeEncrypted()` | как есть |
| Создание WP-пользователя | `UserManager::create(UserInputDTO)` + `setPersonId()`; `findByEmail`/`findByLogin` | как есть |
| Письмо с кредами | `EmailService::sendWelcomeWithCredentials(userId, password, extraVars, personId)` | как есть, родителю |
| UI-таб импорта | `templates/admin/components/tabs/settings-tabs/settings-6-import.php` | + режим, чекбокс, mode-aware шаблон |
| Фронт импорта | `src/js/admin/services/import-csv.js` | + mode/send_emails, таблица кредов, скачивание |
| Колонки CSV | `inc/Enums/Import/ImportColumn.php` (единый источник) | + `Логин`/`Пароль`, mode-aware `headers()`/`required()` |

---

## Этап 1 — вынести создание учётки в сервис (`AccountProvisioningService`)

Изолированный, тестируемый сервис создания WP-учётки — общий для будущего использования.
`EnrollmentService::enroll()` **в этой итерации не трогаем** (экзамен-критичный поток под тестами;
переезд `enroll()` на этот сервис — опциональный follow-up, вынесен в раздел «После плана»).

- Новый `inc/DTO/Import/AccountCredentialsDTO.php` (readonly): `{ int userId, string login, string password, bool created }`
  (`created` — учётка создана в этом вызове против «нашли существующую»).
- Новый `inc/Services/Enrollment/AccountProvisioningService.php` (зависимости: `UserManager`,
  `PasswordGeneratorService`, `PersonRepository`, `LogEventDispatcherInterface`):
  - `provisionStudent(int $personId, PersonInputDTO $data, string $username, string $password): AccountCredentialsDTO`
    — ветки как в `enroll()`:
    1. у person есть `wpUserId` → `setFromPlain($userId, $password)`, логин = текущий;
    2. иначе email занят (`findByEmail`) → привязать существующего + `setFromPlain`;
    3. иначе `UserManager::create(UserInputDTO{ userLogin: $username, userEmail: $data->email, userPass: $password, role: FSStudent })`
       + `storeEncrypted` + `personRepository->setWpUser` + `setPersonId` + лог `UserCreated`;
    - логин/пароль — из аргументов (в enrolled-импорте всегда заданы; генерации нет);
    - коллизия логина (`wp_insert_user` → `WP_Error`) пробрасывается как `RuntimeException` (импортёр
      превратит в ошибку строки).
  - `provisionParent(int $personId, ParentDataDTO|PersonInputDTO $data): AccountCredentialsDTO`
    — как `enroll()` для родителя: логин = email (фолбэк `parent_{personId}` при пустом),
    пароль = `PasswordGenerator::generatePlain()` → `create(... FSParent)` → `storeEncrypted`;
    существующий email/`wpUserId` → привязать + `generateAndSet`.
- Тесты `tests/Unit/Services/Enrollment/AccountProvisioningServiceTest.php`: новая учётка с заданными
  кредами; person с `wpUserId` → `setFromPlain` без создания; занятый email → привязка;
  коллизия логина → исключение; родитель без email → фолбэк-логин.

---

## Этап 2 — enum-и режима и колонок

- Новый `inc/Enums/Import/ImportMode.php`: `enum ImportMode: string { case Archive = 'archive'; case Enrolled = 'enrolled'; }`
  + `label()` («Архивные записи» / «Полное зачисление») + `fromRequest(string): self` (фолбэк `Archive`).
- `inc/Enums/Import/ImportColumn.php`:
  - новые кейсы в секции «Ученик»: `case Username = 'Логин';`, `case Password = 'Пароль';`
    (+ `examples()`: `('ivanov','petrov')` / `('Passw0rd!7','Qwerty12x')`);
  - `headers(ImportMode $mode)` — режим-зависимый порядок:
    - `Archive` — текущий набор **без** `Username`/`Password`;
    - `Enrolled` — **без** `ExpelledAt`/`ExpelReason`, **с** `Username`/`Password`;
  - `required(ImportMode $mode)`:
    - `Archive` — как сейчас (LastName, FirstName, Group, ContractNo, ParentLastName, ParentFirstName);
    - `Enrolled` — тот же набор **+** `Username`, `Password`, `ParentEmail`;
  - `exampleRows(ImportMode $mode)` — строки-образцы по колонкам выбранного режима.
  - Обратная совместимость: существующие вызовы `headers()`/`required()` (шаблон, `StudentRowImporter`)
    перевести на явный `ImportMode::Archive`.
- `inc/DTO/Import/ImportContextDTO.php`: добавить `ImportMode $mode` и `bool $sendEmails` в конструктор
  и в `withRow()` (пробросить оба).

---

## Этап 3 — общий writer для обоих импортёров - готово

Чтобы не дублировать резолв/создание группы и persons между архивным и enrolled-импортёром.

- Новый `inc/DTO/Import/ImportedRecordDTO.php` (readonly): `{ int studentId, int parentId, int groupId }`.
- Новый `inc/Services/Import/StudentRecordWriter.php` (зависимости: `GroupsRepository`,
  `PersonImportResolver`, `PersonService`, `ClockInterface`):
  - `resolveOrCreateGroup(string $name, ImportContextDTO $ctx): int` — `findByNameSubjectPeriod` else `create`;
  - `resolveOrCreatePerson(PersonInputDTO $input): int` — `PersonImportResolver::resolve()` else
    `PersonService::createOrFindBy()`.
  - (создание самой `student_records` и дедуп по договору остаются в импортёрах — там различается
    статус/жизненный цикл.)
- Новый контракт `inc/Contracts/RowImporterInterface.php`:
  `requiredHeaders(): array`, `import(array $row, ImportContextDTO $ctx): ImportRowResultDTO`.
- `inc/Services/Import/StudentRowImporter.php` (рефактор): `implements RowImporterInterface`;
  резолв группы/persons — через `StudentRecordWriter`; `requiredHeaders()` → `ImportColumn::required(ImportMode::Archive)`;
  архивный `resolveLifecycle()` без изменений. Поведение неизменно (проверяется тестом).
- Адаптировать `tests/Unit/Services/Import/StudentRowImporterTest.php` под новую зависимость и
  сигнатуру `ImportContextDTO` (добавились `mode`/`sendEmails`).

---

## Этап 4 — enrolled-импортёр

- `inc/DTO/Import/ImportRowResultDTO.php`: добавить опциональные креды строки —
  `?RowCredentialsDTO $credentials = null` (новый readonly DTO
  `{ string studentName, string studentLogin, string studentPassword, ?string parentLogin, ?string parentPassword }`);
  `created()` — принимать необязательный `?RowCredentialsDTO`.
- Новый `inc/Services/Import/EnrolledStudentRowImporter.php` (`implements RowImporterInterface`;
  зависимости: `StudentRecordWriter`, `StudentRecordRepository`, `AccountProvisioningService`,
  `EmailService`, `ClockInterface`, `LogEventDispatcherInterface`):
  - `requiredHeaders()` → `ImportColumn::required(ImportMode::Enrolled)`;
  - `import()`:
    1. прочитать колонки (включая `Username`, `Password`); требовать непустые логин/пароль ученика,
       иначе `InvalidArgumentException` (→ ошибка строки);
    2. резолв группы/persons через writer;
    3. дедуп по договору (`existsByContract`) → `skipped` (учётки не трогаем);
    4. dry-run → `created('Будет зачислено (dry-run).')` без записи и без учёток;
    5. создать `student_records` со `status = active` (без полей отчисления);
    6. `AccountProvisioningService::provisionStudent(...)` + `provisionParent(...)`;
    7. если `ctx->sendEmails` и у родителя есть email → `sendWelcomeWithCredentials(parentUserId, parentPassword, {student_full_name, parent_first_name, parent_middle_name}, parentPersonId)`;
    8. лог `StudentEnrolled`; вернуть `created()` с `RowCredentialsDTO`.
  - Провизия учёток — **вне** транзакции записи (как в `enroll()`: запись создаётся в транзакции,
    учётки — после), чтобы падение `wp_insert_user` не откатывало корректную `student_records`.
- Тесты `tests/Unit/Services/Import/EnrolledStudentRowImporterTest.php`: active-запись + учётки
  ученика (креды из CSV) и родителя (сген. пароль); пустой логин/пароль → ошибка строки; дедуп →
  `skipped` без учёток; `sendEmails=false` → письмо не уходит; `sendEmails=true` + email → уходит;
  dry-run → без записи/учёток.

---

## Этап 5 — оркестратор, отчёт, колбэк

- `inc/Services/Import/ImportService.php`:
  - убрать `StudentRowImporter` из конструктора; `run()` принимает
    `RowImporterInterface $importer, ImportMode $mode, bool $sendEmails` (+ существующие
    `subjectKey, periodId, filePath, dryRun`);
  - `ImportContextDTO` собирать с `mode`/`sendEmails`;
  - собирать `RowCredentialsDTO` созданных строк в отчёт;
  - сводное лог-событие — текст по режиму («Импорт архивных записей…» / «Зачисление учеников…»).
  - Адаптировать `tests/Unit/Services/Import/ImportServiceTest.php` под новую сигнатуру `run()`.
- `inc/DTO/Import/ImportReportDTO.php`:
  - копить `credentials[]` из `ImportRowResultDTO::$credentials`;
  - `toArray()` — добавить ключ `credentials` (массив `{student_name, student_login, student_password, parent_login, parent_password}`).
- `inc/Callbacks/Import/ImportCallbacks.php`:
  - в конструктор — `StudentRowImporter`, `EnrolledStudentRowImporter`, `ImportService`;
  - в `ajaxImportStudentsCsv()`: прочитать `mode` (`ImportMode::fromRequest($this->sanitizeKey('mode'))`)
    и `send_emails` (`sanitizeBool`); выбрать импортёр по режиму; вызвать
    `run($importer, $mode, $sendEmails, $subjectKey, $periodId, $tmpPath, $dryRun)`;
  - авторизация/валидация файла — без изменений (`Nonce::Manager`, `Capability::Admin`).
  - Новый тест `tests/Unit/Callbacks/Import/ImportCallbacksTest.php` (по памятке «покрывать колбэки
    тестами»): выбор импортёра по `mode`, проброс `send_emails`, ошибка при отсутствии предмета/периода.
- `AjaxHook`/`Nonce`/`ImportController` — без изменений (хук переиспользуется).

---

## Этап 6 — UI и фронтенд

- `templates/admin/components/tabs/settings-tabs/settings-6-import.php`:
  - радио-переключатель «Режим импорта»: «Архивные записи (без учёток)» / «Полное зачисление (с учётками)»
    (name `mode`, значения `archive`/`enrolled`, дефолт — `archive`);
  - чекбокс «Отправить письма родителям с логином/паролем» (name `send_emails`, дефолт выкл;
    визуально относится к enrolled-режиму — гасить/показывать по выбору режима на JS);
  - кнопка шаблона: два набора в data-атрибутах —
    `data-headers-archive` / `data-examples-archive` (`ImportColumn::headers(ImportMode::Archive)`),
    `data-headers-enrolled` / `data-examples-enrolled` (`ImportColumn::headers(ImportMode::Enrolled)`);
  - обновить тексты: в шапке убрать «Учётные записи WP при импорте не создаются» → описать оба режима;
    заметку про колонку отчисления показывать только для архивного режима.
- `src/js/admin/services/import-csv.js`:
  - `submit()`: добавить в FormData `mode` (выбранное радио) и `send_emails`;
  - `downloadTemplate()`: брать `data-headers`/`data-examples` по выбранному режиму;
  - `renderReport()`: если `report.credentials?.length` — отрисовать таблицу
    (ученик · логин · пароль · родитель-логин · родитель-пароль) + кнопку
    «Скачать логины и пароли (CSV)» (сборка CSV на клиенте, BOM + `;`, как в `downloadTemplate`);
  - переключение режима: показывать/прятать чекбокс писем и заметку про отчисление.
- Сборка: `npx gulp scripts` (bundle `admin.min.js`).

---

## Этап 7 — документация и проверка

- Обновить заметку на табе и раздел импорта в `.docs/basic_doc.md`; при необходимости — упоминание
  в CLAUDE.md, что импорт умеет создавать учётки (enrolled-режим).
- Ручная проверка в Docker (`docker restart wp_app`):
  - enrolled dry-run: отчёт «будет зачислено», без записей и учёток в БД;
  - enrolled реальный: `student_records.status=active`, созданы WP-пользователи ученика (логин/пароль
    из CSV — вход работает) и родителя; в отчёте — таблица кредов + скачивание CSV;
  - чекбокс писем выкл → писем нет; вкл → родителю уходит `WelcomeWithCredentials`;
  - строка без логина/пароля → в ошибках отчёта, остальные проходят;
  - повторный импорт того же файла → строки `skipped` (дедуп по договору), учётки не задваиваются;
  - архивный режим — поведение неизменно (регресс не затронул).
- PHPUnit зелёный; `npm run lint:js`, `npm run lint:css` (если правились стили) чисто.

---

## Карта файлов

**Новые:**
`inc/Enums/Import/ImportMode.php`, `inc/Contracts/RowImporterInterface.php`,
`inc/Services/Enrollment/AccountProvisioningService.php`,
`inc/Services/Import/StudentRecordWriter.php`,
`inc/Services/Import/EnrolledStudentRowImporter.php`,
`inc/DTO/Import/AccountCredentialsDTO.php`, `inc/DTO/Import/RowCredentialsDTO.php`,
`inc/DTO/Import/ImportedRecordDTO.php`,
+ тесты (`AccountProvisioningServiceTest`, `StudentRecordWriterTest`,
`EnrolledStudentRowImporterTest`, `ImportCallbacksTest`).

**Изменяются:**
`inc/Enums/Import/ImportColumn.php`, `inc/DTO/Import/ImportContextDTO.php`,
`inc/DTO/Import/ImportRowResultDTO.php`, `inc/DTO/Import/ImportReportDTO.php`,
`inc/Services/Import/ImportService.php`, `inc/Services/Import/StudentRowImporter.php`,
`inc/Callbacks/Import/ImportCallbacks.php`,
`templates/admin/components/tabs/settings-tabs/settings-6-import.php`,
`src/js/admin/services/import-csv.js`,
+ адаптация `StudentRowImporterTest`, `ImportServiceTest`.

**DI:** новые сервисы автопровязываются контейнером; `ImportCallbacks` уже в `Init::getServices()`
через `ImportController` — новые импортёры инжектятся конструктором (autowiring), ручной регистрации
не требуется. Проверить, что `AccountProvisioningService`/`StudentRecordWriter` резолвятся (все
зависимости — с тайп-хинтами).

## Порядок и зависимости

```
Этап 1 (provisioner) ──┐
Этап 2 (enum-ы)      ──┼─→ Этап 4 (enrolled-импортёр) → Этап 5 (оркестратор+колбэк) → Этап 6 (UI/JS) → Этап 7
Этап 3 (writer)      ──┘
```

Этапы 1–3 независимы. Самый содержательный — Этап 4. Риск-точка — рефактор `StudentRowImporter`
на общий writer (Этап 3): страхуемся существующим `StudentRowImporterTest`.

## После плана (опционально, отдельный коммит)

- Переезд `EnrollmentService::enroll()` (строки 199–297) на `AccountProvisioningService` — устранит
  дублирование логики создания учёток. Делать отдельно и осторожно: поток под 561-тестовым сьютом,
  экзамен-критичный. Не входит в объём миграции.

---

# План: система in-app уведомлений кабинета (колокольчик /profile/)

> Согласовано: 2026-07-27, ветка `stage_11`.
> Цель: оживить колокольчик в топбаре кабинета (`templates/frontend/profile.php:66-68` — сейчас
> мёртвая заглушка без `id`, обработчика и badge) — выпадашка с плитками уведомлений в современном
> стиле (macOS-подобные плитки), badge непрочитанных, серверная генерация событий, выдача строго
> через шов `FS_LMS_API`.
> Порядок этапов = порядок исполнения; каждый этап — отдельный коммит.
> После каждого этапа: PHPUnit; на этапах с JS/SCSS — `npx gulp build`, `npm run lint:js`,
> `npm run lint:css`; после PHP-правок — `docker restart wp_app`.

## Зафиксированные решения (подтверждено с заказчиком)

**Каталог уведомлений V1** (enum `NotificationType`, backed string, значения snake_case):

| Тип | Заголовок плитки | Получатель | Триггер |
|---|---|---|---|
| `video_uploaded` | Появилась запись занятия | ученики группы занятия | новый хук `fs_lms_recording_attached` (все 3 пути привязки записи) |
| `deadline_soon` | Дедлайн через 24 часа | ученики, не сдавшие работу | cron, окно `(now, now+24h]` |
| `deadline_missed` | Дедлайн пропущен | ученик **+ родитель** | cron, окно `[now-24h, now)` |
| `lesson_soon` | Занятие через 30 минут | ученики группы / individual-ученик **и** эффективный учитель | cron, окно `(now, now+30m]`, `status='scheduled'` |
| `work_graded` | Работа проверена (+ балл) | ученик | шина `LogEvent::SubmissionGraded` |
| `work_returned` | Работа возвращена на доработку | ученик | шина `LogEvent::SubmissionReturned` |
| `attempt_graded` | Экзамен проверен | ученик **+ родитель** | шина `LogEvent::AttemptGraded` |
| `review_needed` | Сдана работа — нужна проверка | учитель занятия | шина `LogEvent::SubmissionMade`, **только при наличии ручной части** |
| `substitute_assigned` | Вам назначена замена | замещающий учитель | прямой вызов из `SubstitutionService::assign()` |
| `attendance_missed` | Пропущено занятие | **только родитель** | прямой вызов из `AttendanceService::mark()/markAll()` при `present=false` |

- **Родителю — только критичное**: `deadline_missed`, `attendance_missed`, `attempt_graded`.
  Никаких «видео появилось» / «работа проверена» родителю.
- **Пороги**: дедлайн — за 24 часа (одно напоминание), занятие — за 30 минут. Разрешение cron —
  15 минут, т.е. «за 30 минут» фактически приходит за 15–30 мин; для in-app достаточно.
- **НЕ делаем в V1**: перенос/отмена занятия (самой фичи нет — `setStatus()` вызывается лишь из
  `VideoRegistrationService`; уведомление появится вместе с фичей), «назначен экзамен» (сущности
  назначения не существует — экзамен доходит косвенно через открытие занятия с assessment-шагом),
  «выдан доступ к курсу», «опубликован урок», email/web-push-дублирование, пользовательские
  настройки типов уведомлений.
- **Автопроверенные работы учителя не беспокоят**: `review_needed` только если в сдаче есть задание
  без авто-чекера (единственный ручной шаблон — `file_answer_task`; источник истины —
  `TaskCheckerRegistry`, `inc/Services/Task/TaskCheckerRegistry.php:60`).
- **Двухступенчатое прочтение (как в macOS)**: `seen_at` ставится всем при открытии выпадашки
  (гасит badge на колокольчике), `read_at` — по клику на плитку или «Прочитать все» (гасит точку
  на плитке).
- **Идемпотентность**: `dedupe_key` + `UNIQUE(recipient_user_id, dedupe_key)`, вставка
  INSERT IGNORE — повторный cron-тик / перепривязка той же записи не плодят дубли. Ошибочная
  отметка «отсутствовал» отзывается (`retract`) при исправлении на «присутствовал».
- **Тексты рендерит сервер** при выдаче (единая точка русских строк; имена — PII-safe
  snapshot-поля `student_records`, как в Эпике 3). Клиент по `type` выбирает только иконку и цвет.
- **Транспорт** — новый блок `fsProfile.notifications` в `ProfileViewResolver::jsConfig()` +
  `createApi()` (`src/js/profile/api.js:59`); поллинг счётчика раз в 60 с. Будущий Telegram-модуль
  Notifier (`.docs/ModularArchitecture.md:61`) подпишется на `do_action('fs_lms_notification_created')`
  — core на модуль не ссылается.
- **Ретеншн**: cron удаляет прочитанные старше 30 дней и любые старше 90; выдача — последние 30 строк.
- **Адресация — WP `user_id`** (колокольчик живёт на логине): у родителя свои строки-зеркала.
  Ученики без учётки (`persons.wp_user_id IS NULL`, архивные) молча пропускаются.

## Контекст (что уже есть — переиспользуем, не переписываем)

| Механизм | Где | Используем |
|---|---|---|
| Колокольчик-заглушка в топбаре | `templates/frontend/profile.php:66-68` (`prof-icon-ghost` + `Icon::Bell`, без id) | оживляем: id + badge + поповер |
| Шина доменных событий (синхронная, не WP-хуки) | `LogEventDispatcher::subscribe/dispatch` (`inc/Services/Log/LogEventDispatcher.php:70,82`), изоляция ошибок слушателей | подписчик уведомлений |
| Образец подписчика | `inc/Controllers/Subscribers/LearningEventSubscriber.php` (ServiceInterface, ctor: dispatcher + writer, `register()` = серия `subscribe()`) | копируем паттерн |
| Хук появления видео (только REST-путь) | `do_action('fs_lms_video_registered', …)` — `VideoRegistrationService.php:64,84` | не трогаем; вводим единый `fs_lms_recording_attached` во всех 3 путях |
| Cron-каркас + интервал 15 мин | `CronController::register()` (`inc/Controllers/System/CronController.php:41-55`), `addCustomInterval('every_15_minutes', 900, …)` уже есть (:42), `CronManager::schedule()` идемпотентен (`inc/Managers/Wp/CronManager.php:66-70`), `unregisterAll()` снимает все кейсы `CronHook` при деактивации (:87-91) | новая задача `NotificationsTick` |
| Эталон no-capability AJAX кабинета | `LearnerCallbacks.php:72-90`: `Nonce::X->verify()` + `is_user_logged_in()` + данные только по `get_current_user_id()` | колбэки уведомлений |
| Шов API + конфиг экранов | `ProfileViewResolver::jsConfig()` (`inc/Services/Profile/ProfileViewResolver.php:97-122`), `createApi()` (`src/js/profile/api.js:59`) | новый блок `notifications` |
| Родитель → дети | `ProfileViewResolver::context()` → `StudentRecordRepository::findActiveByParent()` (:154) | как есть |
| Ребёнок → родитель (обратного метода НЕТ) | ручной паттерн `PersonViewCallbacks.php:102-105`: `findActiveByStudentFirst()` → `parentPersonId` → `PersonRepository::find()` → `wpUserId` | оборачиваем в хелпер `guardianUserIds()` |
| Ученики группы | `StudentRecordRepository::findActiveByGroupId()` (:133); person→user: `PersonRepository::find()/findByIds()` (:52/:69) → `PersonDTO->wpUserId` | fan-out |
| Эффективный учитель | `group_lessons.teacher_user_id` → `EffectiveTeacherResolver::forGroup(gid, date)` (`inc/Services/Course/EffectiveTeacherResolver.php:32`) → `groups.teacher_id` | адресат `lesson_soon`/`review_needed` |
| Дедлайны per-work | `GroupLessonDTO::deadlineForWork()` (`inc/DTO/Course/GroupLessonDTO.php:110`); фильтр «уже сдал» — паттерн `LearnerService.php:152-155`; переход к работе — `stepKeyForWork()` + `add_query_arg('step', …)` (`LearnerService.php:167-171`) | cron-продюсер |
| Deep-link плеера | `PageRoutes::GroupCockpit->lessonUrl($gid, $glid)` (`inc/Enums/Wp/PageRoutes.php:68-76`) — единственный владелец формата `/group/?gid=…&gl=…` | поле `url` уведомления |
| Deep-link кабинета | `/profile/?screen=<key>` — разбирается в `app.js:336-346` | `url` для summary/оценок/посещаемости |
| Поповер-паттерн | `openUserMenu()` (`app.js:233-248`) + `openCtxMenuRaw/closeCtxMenu` (`utils.js:160,177`); закрытие: backdrop + Escape (`app.js:278-282`) | по образу и подобию (свой контейнер) |
| Пустое состояние, тост | `emptyState()`, `toast()` (`src/js/profile/utils.js`) | как есть |
| UI-примитивы | миксин `cab-card` (`shared/cabinet/_ui.scss:131`), словарь статусов `--ok/--err/--wait` (`shared/cabinet/_theme.scss`), z-шкала профиля `$z-topbar:20 / $z-ctx:300 / $z-toast:400` (`profile/_variables.scss:23-30`) | стили поповера |
| Иконки | JS-фабрики `common/icons.js` (есть `icoClock`, `icoCamera`, `icoAlert`, `icoCheck`, `icoDocCheck`, `icoSwap`, `icoReplace`; **нет `icoBell`** — добавить зеркало `Icon::Bell` из `inc/Enums/Ui/Icon.php:114`) | иконки плиток + колокольчик |
| Тестовый харнесс | `fs_test_capture_json()` / `$GLOBALS['_fs_test_nonce_ok']` (`tests/bootstrap.php:382-431`), `FakeWpdb` (`tests/Support/FakeWpdb.php`) | тесты колбэков/репозитория |

---

## Этап 1 — схема и домен (таблица, enum, DTO, репозиторий, сервис)

- `inc/Enums/Settings/TableName.php`: новый кейс после `Rooms` (:48) со своим комментарием:
  `case Notifications = 'fs_lms_notifications';`
- `inc/Migrations/Migration_1_0_0.php`: блок `// ===== 25. fs_lms_notifications` после rooms (:599)
  + дроп в `down()`:

  ```sql
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  recipient_user_id bigint unsigned NOT NULL,
  type varchar(40) NOT NULL,
  group_id smallint unsigned DEFAULT NULL,
  entity_type varchar(30) DEFAULT NULL,
  entity_id bigint unsigned DEFAULT NULL,
  payload longtext DEFAULT NULL,          -- JSON: снапшот данных для текста (тема, имя, балл…)
  url varchar(500) DEFAULT NULL,
  dedupe_key varchar(120) NOT NULL,
  created_at datetime NOT NULL,
  seen_at datetime DEFAULT NULL,
  read_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY recipient_dedupe (recipient_user_id, dedupe_key),
  KEY recipient_created (recipient_user_id, created_at),
  KEY recipient_seen (recipient_user_id, seen_at)
  ```

  ⚠️ На dev **НЕ сбрасывать** `fs_lms_schema_version` (повторный `up()` дропнет живые
  groups/persons/student_records — проверено ранее): выполнить тот же `CREATE TABLE` напрямую
  через `docker exec wp_db mariadb …`.
- Новый `inc/Enums/Profile/NotificationType.php`: 10 кейсов из каталога + `title(): string`
  (заголовок плитки) + `tone(): string` (`ok|warn|err|info` — цвет кружка на клиенте).
- Новый `inc/DTO/Profile/NotificationDTO.php` (readonly, рядом с `ProfileContext`):
  `{ int id, int recipientUserId, NotificationType type, ?int groupId, ?string entityType, ?int entityId, array payload, string url, string createdAt, ?string seenAt, ?string readAt }` + `fromArray()`.
- Новый `inc/Repositories/WPDBRepositories/NotificationRepository.php` (паттерн
  `AttendanceRepository`: ctor `?\wpdb`, `TableName::Notifications->prefixed()`, `prepare` + `%i`):
  - `insertIgnore( int $recipientUserId, string $type, string $dedupeKey, array $payload, string $url, ?int $groupId, ?string $entityType, ?int $entityId ): bool` — `INSERT IGNORE`, `true` = реально вставлено;
  - `listRecent( int $userId, int $limit = 30 ): array` (DESC по `created_at`);
  - `unseenCount( int $userId ): int`;
  - `markAllSeen( int $userId ): void`; `markRead( int $userId, int $id ): void`;
    `markAllRead( int $userId ): void` — везде `WHERE recipient_user_id = %d` (чужое недостижимо);
  - `deleteByDedupe( array $userIds, string $dedupeKey ): void` — для retract;
  - `purge( int $readOlderDays = 30, int $allOlderDays = 90 ): void`.
- Новый `inc/Services/Profile/NotificationService.php` (зависимости: `NotificationRepository`,
  `StudentRecordRepository`, `PersonRepository`, `GroupsRepository`, `EffectiveTeacherResolver`):
  - `push( array $userIds, NotificationType $type, string $dedupeKey, array $payload = [], string $url = '', ?int $groupId = null, ?string $entityType = null, ?int $entityId = null ): void`
    — по каждому получателю `insertIgnore`; для реально вставленных —
    `do_action( 'fs_lms_notification_created', $recipientUserId, $type->value, $payload )`
    (шов будущего Telegram-модуля Notifier);
  - `pushFresh( … )` — `retract()` + `push()` (кейс переоценки работы: старая плитка заменяется свежей непрочитанной);
  - `retract( array $userIds, string $dedupeKey ): void`;
  - адресные хелперы: `studentUserId( int $personId ): ?int`;
    `guardianUserIds( int $studentPersonId ): array` (обратного метода в кодовой базе нет —
    инкапсулируем двухшаговый паттерн `PersonViewCallbacks.php:102-105`);
    `groupStudentUserIds( int $groupId ): array` (`findActiveByGroupId` → `findByIds` → `wpUserId`);
    `lessonStudentUserIds( GroupLessonDTO $l ): array` (individual → один ученик, group → группа);
    `lessonTeacherUserId( GroupLessonDTO $l ): ?int` (`teacher_user_id` строки → `EffectiveTeacherResolver::forGroup()` → `groups.teacher_id`);
  - `toClientArray( NotificationDTO $n ): array` — `{ id, type, tone, title, body, url, time, unread }`;
    русские строки `title`/`body` собираются здесь из `type` + `payload` (единственная точка текстов).
- Тесты: `tests/Integration/Repositories/NotificationRepositoryIntegrationTest.php` (FakeWpdb:
  `INSERT IGNORE` в SQL, scoping по recipient, purge-условия);
  `tests/Unit/Services/Profile/NotificationServiceTest.php` (fan-out адресатов, скип person без
  `wpUserId`, `pushFresh` = delete+insert, хук создания только для вставленных).

---

## Этап 2 — событийные продюсеры

- **Единый хук привязки записи** `do_action( 'fs_lms_recording_attached', int $groupLessonId )` —
  диспатчить при непустом URL во всех трёх путях (существующий `fs_lms_video_registered` не трогаем):
  1. `inc/Modules/VideoLibrary/Services/VideoRegistrationService.php` — в `bindToLesson()`
     (покрывает REST-автоматч `register()` и ручную привязку `attachManually()`);
  2. `inc/Callbacks/Course/ProgramCallbacks.php:588` `ajaxSetRecordingUrl()` (ручная ссылка из КТП, core);
  3. `inc/Modules/VideoLibrary/Callbacks/VideoLibraryCallbacks.php:116` `ajaxSetLessonRecordingUrl()`.
- Новый `inc/Controllers/Subscribers/NotificationSubscriber.php` (ServiceInterface, образец —
  `LearningEventSubscriber`; ctor: `LogEventDispatcherInterface`, `NotificationService`,
  `SubmissionRepository`, `AssessmentAttemptRepository`, `GroupLessonRepository`):
  - `LogEvent::SubmissionGraded` → загрузить сдачу по `entity_id` (добавить
    `SubmissionRepository::find(int $id)`, если отсутствует) → `pushFresh` `work_graded` ученику;
    payload: балл, тема занятия; url: `lessonUrl + &step=` через `stepKeyForWork()`
    (паттерн `LearnerService.php:167-171`); dedupe `graded:{submissionId}`;
  - `LogEvent::SubmissionReturned` → `pushFresh` `work_returned` ученику (та же адресация),
    dedupe `returned:{submissionId}`;
  - `LogEvent::AttemptGraded` → по `entity_id` попытки взять `student_person_id` →
    `attempt_graded` ученику **+ `guardianUserIds()`**; url: `/profile/?screen=learner-grades`;
    dedupe `attempt:{attemptId}`;
  - `LogEvent::SubmissionMade` → **фильтр ручной части**: статус `pending_review`
    (batch-путь, `SubmissionService.php:209`) ИЛИ single-путь (`status='submitted'` всегда,
    `SubmissionService.php:96,110`) с `task_id`, чей шаблон не имеет чекера
    (`TaskCheckerRegistry::has() === false`) → `review_needed` учителю
    (`lessonTeacherUserId()`); payload: snapshot-имя ученика, тема; url:
    `/profile/?screen=summary`; dedupe `review:{submissionId}`.
- `inc/Services/Course/SubstitutionService.php::assign()` (:33): + `NotificationService` в ctor
  (autowiring); после `create()` → `push` `substitute_assigned` на `substitute_teacher_id`
  (это уже WP user id); payload: имя группы, `valid_from–valid_to`, причина; url:
  `/profile/?screen=dashboard`; dedupe `sub:{substitutionId}`. (Сервис сейчас не диспатчит
  никаких событий — это первая точка.)
- `inc/Services/Course/AttendanceService.php` (:27): + `NotificationService` в ctor:
  - `mark(…, present: false)` → `push` `attendance_missed` на `guardianUserIds()`; payload:
    snapshot-имя ребёнка (у родителя может быть несколько детей!), дата/тема занятия; url:
    `/profile/?screen=learner-attendance`; dedupe `att:{groupLessonId}:{studentPersonId}`;
  - `mark(…, present: true)` → `retract` того же ключа (исправление ошибочной отметки);
  - `markAll()` — та же логика по каждому ученику. Будущие занятия сюда не доходят
    (`guardNotFuture`, `JournalCallbacks.php:73`), открытые группы — тоже (:103-110).
- Тесты: `tests/Unit/Controllers/Subscribers/NotificationSubscriberTest.php` (мок сервиса:
  graded → ученик; attempt → ученик+родители; made c авто-чекером → НЕ уведомляет; made с
  `file_answer_task` → учителю); дополнить `AttendanceServiceTest` (absent → push родителям,
  present → retract) и `SubstitutionServiceTest` (assign → push замещающему).

---

## Этап 3 — временны́е продюсеры (cron)

- `inc/Enums/Wp/CronHook.php`: `case NotificationsTick = 'fs_lms_notifications_tick';` (после :18).
- `inc/Controllers/System/CronController.php::register()`: `add_action` + планирование через
  существующий идемпотентный `CronManager::schedule( CronHook::NotificationsTick->value, 'every_15_minutes' )`
  (интервал уже регистрируется на :42, но сейчас никем не используется); хендлер
  `handleNotificationsTick()` → `NotificationCronService::tick()`. Деактивация — ничего не делать:
  `CronManager::unregisterAll()` итерирует `CronHook::cases()` и снимет новый хук сам.
- Новый `inc/Services/Profile/NotificationCronService.php` (зависимости: `GroupLessonRepository`,
  `SubmissionRepository`, `NotificationRepository`, `NotificationService`, `ClockInterface`):
  - `tick(): void` = `lessonSoon()` + `deadlines()` + `purge()`; все окна устойчивы к пропущенным
    тикам WP-Cron (низкий трафик): смотрим назад/вперёд с запасом, дубли гасит `dedupe_key`;
  - `lessonSoon()`: новый метод `GroupLessonRepository::listStartingBetween( string $from, string $to ): array`
    (`status='scheduled'`, `scheduled_at` ∈ `(now, now+30m]`; `visibility` НЕ фильтруем —
    расписание ученику видно всегда) → получатели `lessonStudentUserIds()` + `lessonTeacherUserId()`;
    payload: тема/label, время; url: `lessonUrl()` при наличии `lesson_id`, иначе `/profile/`;
    dedupe `lesson_soon:{groupLessonId}`;
  - `deadlines()`: новый метод `GroupLessonRepository::listWithDeadlines(): array`
    (`visibility='open'` AND (`homework_due_at` NOT NULL OR `work_deadlines` NOT NULL));
    per-work разбор — готовый `GroupLessonDTO::deadlineForWork()`; состав работ занятия — как в
    `LearnerService.php:149-181`; для каждой пары (занятие, работа):
    - дедлайн ∈ `(now, now+24h]` → `deadline_soon` ученикам занятия **без сдачи** (фильтр —
      `SubmissionRepository::listByStudentAndGroupLesson()`, паттерн `LearnerService.php:152-155`);
      url: `lessonUrl + &step=`; dedupe `dl_soon:{groupLessonId}:{workId}`;
    - дедлайн ∈ `[now-24h, now)` → `deadline_missed` не сдавшим **+ их `guardianUserIds()`**;
      dedupe `dl_miss:{groupLessonId}:{workId}`;
  - `purge()`: `NotificationRepository::purge( 30, 90 )`.
- Тесты `tests/Unit/Services/Profile/NotificationCronServiceTest.php` (фиксированный clock + моки):
  границы окон (29 мин — да, 31 — нет; и для дедлайнов), сдавший исключён, individual-занятие →
  один ученик, `deadline_missed` уходит и родителю, dedupe-ключи стабильны между тиками.

---

## Этап 4 — AJAX-выдача и конфиг шва

- `inc/Enums/Wp/AjaxHook.php`: новая группа `// ==== Уведомления кабинета ====` после `:305`:
  `GetNotifications = 'get_notifications'` (без params; список 30 + пометить seen),
  `GetNotificationsCount = 'get_notifications_count'` (badge-поллинг),
  `MarkNotificationRead = 'mark_notification_read'` (params: id),
  `MarkAllNotificationsRead = 'mark_all_notifications_read'`.
- `inc/Enums/Wp/Nonce.php`: `case Notifications = 'fs_lms_notifications';` — вставить в основной
  блок после `:102` (⚠️ не в хвост: файл не строго упорядочен, после `create()` (:109) лежат ещё
  кейсы — новые добавляем до него).
- Новый `inc/Callbacks/Profile/NotificationCallbacks.php` — no-capability эталон
  `LearnerCallbacks.php:72-90`: `Nonce::Notifications->verify()` + `is_user_logged_in()`;
  идентификатор получателя — **всегда** `get_current_user_id()`, клиентские id не принимаются:
  - `ajaxGetNotifications()`: `listRecent(30)` → `array_map` `toClientArray()` → `markAllSeen()` →
    `success( { items, unseen: 0 } )` (открытие выпадашки само гасит badge);
  - `ajaxGetNotificationsCount()`: `success( { unseen: unseenCount() } )`;
  - `ajaxMarkNotificationRead()`: `requireInt('id')` → `markRead( me, id )`;
  - `ajaxMarkAllNotificationsRead()`.
- Новый `inc/Controllers/Profile/NotificationController.php` — `extends AjaxController`,
  `ajaxActions()` с 4 хуками (только `wp_ajax_`, кабинет всегда залогинен — nopriv не нужен).
- `inc/Init.php`: `NotificationController::class` (рядом с профильными, :160-161),
  `NotificationSubscriber::class` (рядом с `LearningEventSubscriber`, :168).
- `inc/Services/Profile/ProfileViewResolver.php::jsConfig()`: блок для **всех ролей кабинета**:
  `$config['notifications'] = [ 'nonce' => Nonce::Notifications->create(), 'actions' => [ 'list' => …, 'count' => …, 'markRead' => …, 'markAllRead' => … ] ];`
- Тест `tests/Unit/Callbacks/Profile/NotificationCallbacksTest.php` (образец —
  `LearnerCallbacksTest`: `fs_test_reset_ajax()`, `_test_logged_in`, `fs_test_capture_json`):
  незалогинен → error; list помечает seen и отдаёт `unseen: 0`; markRead передаёт в репозиторий
  именно `get_current_user_id()`; чужой id в POST игнорируется.

---

## Этап 5 — UI: колокольчик, поповер, плитки

- `templates/frontend/profile.php:66-68`: кнопке — `id="profBell"`, `aria-haspopup="true"`,
  `title="Уведомления"` + внутрь `<span class="prof-bell-badge" id="profBellBadge" hidden></span>`;
  после блока оверлеев (:80-82) — статичный контейнер
  `<div class="prof-notif-pop" id="profNotifPop" hidden></div>` (наполняет JS).
- `src/js/common/icons.js`: + фабрика `icoBell` — JS-зеркало пути `Icon::Bell`
  (`inc/Enums/Ui/Icon.php:114`; правило зеркалирования — шапка icons.js:5-16). Иконки плиток —
  только существующие фабрики: `video_uploaded` → `icoCamera`, `deadline_soon`/`lesson_soon` →
  `icoClock`, `deadline_missed`/`attendance_missed` → `icoAlert`, `work_graded` → `icoCheck`,
  `work_returned` → `icoReplace`, `attempt_graded`/`review_needed` → `icoDocCheck`,
  `substitute_assigned` → `icoSwap`.
- Новый `src/js/profile/notifications.js` (экранный модуль, паттерн кабинета):
  - `initNotifications()`: гард `#profBell` + `window.fsProfile?.notifications`;
    `api = createApi( fsProfile.notifications )`;
  - поллинг: `count` сразу + `setInterval` 60 000 мс, пауза при `document.hidden`
    (visibilitychange); badge: число, `9+` при переполнении, `hidden` при нуле;
  - клик по колокольчику → toggle: `list` → рендер плиток → badge скрыт (сервер уже пометил seen);
    закрытие — клик вне поповера и `Escape` (паттерн `app.js:275-282`);
  - рендер: группировка «Сегодня / Вчера / Ранее»; плитка — `<a class="prof-notif-item" href=…
    data-tone=…>` (кружок-иконка по `type`, `title`, `body`, относительное время — локальный
    хелпер `timeAgo()`, точка непрочитанного при `unread`); клик по плитке → `markRead` → переход
    по `url`; кнопка «Прочитать все» в шапке поповера → `markAllRead`; пусто — `emptyState()`
    из `utils.js`;
  - обработка ошибок — `toast(msg, 'error')`.
- `src/js/profile/app.js`: импорт + вызов `initNotifications()` в `initProfile()` между `wire()`
  и `initCollapse()` (:333-334).
- Новый `src/scss/profile/components/_notifications.scss` + `@use` в `profile.scss` после
  `overlays` (:15). Только токены (stylelint: hex запрещён вне `_variables`/`_tokens`):
  - `.prof-bell-badge` — абсолютный кружок в правом-верхнем углу кнопки (родителю `#profBell`
    добавить `position: relative`), фон `var(--err)`, белый текст, `min-width: rem(14)`;
  - `.prof-notif-pop` — фиксирован под топбаром у правого края (`top: var(--top-h)`),
    `width: rem(380)`, `max-height: 70vh`, `overflow-y: auto`, миксин `cab-card`,
    `z-index: $z-ctx` (шкала бандла profile: топбар 20 < поповер 300 < тост 400);
    мобилка `@include below($bp-mobile)` — растяжка от края до края;
  - `.prof-notif-item` — grid «кружок / текст / время», hover `var(--surface-2)`, точка
    непрочитанного `var(--accent)`; цвет кружка по `data-tone` из словаря `--ok/--err/--wait`
    + акцент; секции-даты, шапка с «Прочитать все», появление — миксин `cab-enter`.
- Сборка и линт: `npx gulp build`, `npm run lint:js`, `npm run lint:css`.

---

## Этап 6 — документация и сквозная проверка

- `.docs/FS_LMS_API.md`: раздел «Уведомления» — блок `fsProfile.notifications`, 4 действия,
  формат плитки `toClientArray`, семантика seen/read (для будущего Telegram/mobile-порта).
- `.docs/ModularArchitecture.md`: у листа Notifier — уточнить контракт: подписка на
  `fs_lms_notification_created` + чтение `fs_lms_notifications`.
- Проверка в Docker (`docker restart wp_app`; таблица создана напрямую, версия схемы НЕ сброшена):
  - событийные: сдать работу с `file_answer_task` учеником (`demoteacher`-стенд, группа №1
    «Тест-группа 9А») → у препода `review_needed`; оценить → у ученика `work_graded`; вернуть →
    `work_returned`; привязать запись к занятию всеми тремя путями → `video_uploaded` только раз
    (dedupe); отметить absent → у родителя `attendance_missed`, исправить на present → плитка
    исчезла (retract); назначить замену → `substitute_assigned`;
  - cron: выставить `scheduled_at`/дедлайны в окна и `docker compose … run --rm wpcli wp cron event run fs_lms_notifications_tick`
    → `deadline_soon`/`deadline_missed` (+родителю)/`lesson_soon` (ученикам и учителю); повторный
    прогон — дублей нет;
  - UI: badge-счётчик, открытие гасит badge (seen), клик по плитке — переход по deep-link и
    гашение точки (read), «Прочитать все», пустое состояние, мобильная ширина;
  - PHPUnit весь сьют зелёный; линтеры чистые.

---

## Карта файлов (уведомления)

**Новые:**
`inc/Enums/Profile/NotificationType.php`, `inc/DTO/Profile/NotificationDTO.php`,
`inc/Repositories/WPDBRepositories/NotificationRepository.php`,
`inc/Services/Profile/NotificationService.php`, `inc/Services/Profile/NotificationCronService.php`,
`inc/Controllers/Subscribers/NotificationSubscriber.php`,
`inc/Callbacks/Profile/NotificationCallbacks.php`, `inc/Controllers/Profile/NotificationController.php`,
`src/js/profile/notifications.js`, `src/scss/profile/components/_notifications.scss`,
+ тесты (`NotificationRepositoryIntegrationTest`, `NotificationServiceTest`,
`NotificationCronServiceTest`, `NotificationSubscriberTest`, `NotificationCallbacksTest`).

**Изменяются:**
`inc/Enums/Settings/TableName.php`, `inc/Migrations/Migration_1_0_0.php`,
`inc/Enums/Wp/CronHook.php`, `inc/Controllers/System/CronController.php`,
`inc/Enums/Wp/AjaxHook.php`, `inc/Enums/Wp/Nonce.php`, `inc/Init.php`,
`inc/Services/Profile/ProfileViewResolver.php`,
`inc/Services/Course/SubstitutionService.php`, `inc/Services/Course/AttendanceService.php`,
`inc/Modules/VideoLibrary/Services/VideoRegistrationService.php`,
`inc/Modules/VideoLibrary/Callbacks/VideoLibraryCallbacks.php`,
`inc/Callbacks/Course/ProgramCallbacks.php`,
`inc/Repositories/WPDBRepositories/GroupLessonRepository.php` (+`listStartingBetween`,
`+listWithDeadlines`), `inc/Repositories/WPDBRepositories/SubmissionRepository.php` (+`find`,
если нет), `templates/frontend/profile.php`, `src/js/common/icons.js`, `src/js/profile/app.js`,
`src/scss/profile/profile.scss`,
+ дополнение `AttendanceServiceTest`, `SubstitutionServiceTest`.

**DI:** все новые классы автопровязываются контейнером (тайп-хинты в конструкторах);
ручная регистрация — только class-string'и в `Init::getServices()` (`NotificationController`,
`NotificationSubscriber`). `CronController` уже зарегистрирован (:134).

## Порядок и зависимости (уведомления)

```
Этап 1 (схема+домен) ──→ Этап 2 (события) ──┐
                     └─→ Этап 3 (cron)     ──┼─→ Этап 4 (AJAX+шов) → Этап 5 (UI) → Этап 6 (доки+e2e)
```

Этапы 2 и 3 независимы (оба зависят только от Этапа 1). Самые содержательные — Этапы 2 и 5.
Риск-точки: (а) фильтр «ручной части» в `review_needed` — single-сдача всегда пишет `'submitted'`
(`SubmissionService.php:96,110`), различать по шаблону задания, не по статусу; (б) существующая
несостыковка очереди проверки (`listQueueByGroup` по умолчанию берёт только `'submitted'` без
`'pending_review'`, `SubmissionRepository.php:100`) — уведомлений не касается, но всплывёт рядом:
не чинить молча, зафиксировать отдельно.

---
