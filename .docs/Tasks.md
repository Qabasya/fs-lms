# План: авторинг банка контента преподавателем на фронте

> 2026-07-25. Цель: преподаватель в плеере («Настроить урок») получает **тот же** инлайновый
> авторинг, что методист в админке — создание/редактирование **задач, работ, экзаменов** в
> общем банке предмета, с авторством (`post_author`). Структура курса (модули, дерево, 72 урока)
> остаётся методисту (`AuthorLmsCourses`). Экзамены преподавателю **разрешены** (потом при нужде
> отключим отдельной капой).
>
> **Ключевое решение (капабилити):** новая капа **`AuthorLmsBank`** = «авторинг контента банка»
> (задачи/работы/экзамены, subject-scoped, БЕЗ структуры курса). Выдаётся **и методисту, и
> преподавателю**. Bank-content-эндпоинты (`GetTaskEditorForm`/`SaveTaskContent`/create-draft
> работ/экзаменов) переводятся с `AuthorLmsCourses` → `AuthorLmsBank`; course-structure остаётся
> на `AuthorLmsCourses`. Так один и тот же код авторинга обслуживает обоих.
>
> **Что уже готово (переиспользуем):** админский авторинг **инлайновый AJAX**, не wp-admin:
> задача = `task-editor.js` + `GetTaskEditorForm` + `SaveTaskContent` (post_id=0 создаёт);
> черновики — `Create{Work,Assessment}Draft`; поля — PHP `Fields/*` (единый источник).
>
> **UX-фиксы тич-редактора (сделаны 2026-07-25, до фаз):** полноэкранная панель вместо 640px-drawer;
> `.fs-cb-ss-*` перенесены в `_step-editor.scss` (были в `_task-editor.scss`, не грузились в плеер);
> `fs-confirm-dialog` кнопки застилены (`shared/tokens`) + диалог подключён во frontend; «Редактировать ↗»
> скрыта в readOnlyBank; кнопка «← Вернуться» (текст) + перезагрузка плеера при возврате.

## Фаза 1 — задачи (tasks) ✅ ГОТОВО (2026-07-25, нужна браузер-проверка)

_Капа `AuthorLmsBank` + роли (методист/офис/преподаватель), эндпоинты `GetTaskEditorForm`/`SaveTaskContent`
переведены на неё + `post_author`=создатель; `task-editor.js`/`task-fields.js` + `fs_lms_task_editor_vars` +
`capabilities.authorBank` в teacher-бандле; `.fs-te-*` модалка подключена в плеер; step-editor: `canAuthor`+
`openTaskEditor` — «Добавить новую»/«Редактировать» задачу открывают инлайновую модалку (subject-задача,
`post_author`). +1 тест (post_author). teacher-бандл 38.7→51.2 КБ (task-editor вернулся). 890/890._

### Детали (для справки)

- **Капа `AuthorLmsBank`** — `inc/Enums/Access/Capability.php` (`case AuthorLmsBank = 'author_lms_bank';`).
  `RoleManager`: выдать ролям, у которых есть `AuthorLmsCourses` (методист), И `FSTeacher`. Bump `fs_lms_caps_version` (Init) — синхронизирует капы.
- **Перевести bank-content-эндпоинты** `AuthorLmsCourses → AuthorLmsBank`: `TaskContentCallbacks::ajaxGetTaskEditorForm`/`ajaxSaveTaskContent` (subject-scoped, не group). Проверить, что методист (получит `AuthorLmsBank`) не теряет доступ. `SaveTaskContent` ставит `post_author` = текущий юзер при создании (post_id=0).
- **Teacher-бандл**: вернуть `task-editor.js`+`task-fields.js` в бандл (Enqueue `enqueue_teacher_editor_assets` — уже грузит `wp_enqueue_editor()`); локализовать в `fs_lms_teacher_editor_vars` действия `getTaskEditorForm`/`saveTaskContent` + `nonces.taskContent`. Дать `createStepEditor` доступ к task-modal.
- **step-editor.js**: для преподавателя-автора (`canAuthor`, не `readOnlyBank`) — «Добавить новую»/«Редактировать» задачу открывают **инлайновую task-модалку** (`task-editor.js`), а НЕ wp-admin `post-new.php`/`post.php`. На сохранение (SaveTaskContent → post_id) — выставить `payload.ref` шага. Вернуть «Редактировать ↗» как инлайновую правку.
- **Флаг режима**: teacher vars — `capabilities.authorBank` (из PHP `current_user_can(AuthorLmsBank)`); `createStepEditor` получает `canAuthor` → снимает `readOnlyBank`.
- **Тесты**: `TaskContentCallbacksTest` — доступ по `AuthorLmsBank` (методист+преподаватель), запрет без капы; создание ставит author.
- Сборка teacher-бандла подрастёт (task-editor вернётся) — ок, это осознанно.

## Фаза 2 — работы (works)

- `CreateWorkDraft` + редактор работы (набор задач + мета: тип homework/practice, дедлайны) на фронте — тот же паттерн. Эндпоинты сборки работы (add/remove задач) → `AuthorLmsBank`.
- В тич-редакторе шага `work`: «Добавить новую» открывает редактор работы инлайново; «Редактировать» — правка набора/меты.
- Тесты на teacher-доступ к work-авторингу.

## Фаза 3 — экзамены (assessments)

- `CreateAssessmentDraft` + редактор экзамена (набор задач + правила: scoring binary/ЕГЭ, intro) на фронте → `AuthorLmsBank` (по решению — экзамены разрешены).
- Учесть: секретный банк экзаменов станет виден/редактируем преподавателю (осознанно). Целостность (`AssessmentAccessPolicy`/`ExamLockService`) не меняется — экзамен препода идёт через ту же машинерию.
- Задел на будущее: отдельная капа `AuthorLmsExams` для точечного отключения — НЕ делаем сейчас, только оставить место в `Capability`/gate-хелпере.
- Тесты на teacher-доступ к assessment-авторингу.

## Порядок

Фаза 1 (задачи) — фундамент (капа + переключение эндпоинтов + бандл + task-модалка в step-editor).
Фаза 2 и 3 переиспользуют капу и паттерн бандла; отличаются редакторами работы/экзамена.
После каждой фазы: `npx gulp build`, `npm run lint:js/css`, PHPUnit, проверка в браузере (авторинг — визуальный).

---

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

## Этап 3 — общий writer для обоих импортёров

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

# План рефакторинга по итогам ревью этапов 1–5

> Ревью 2026-07-25, диапазон `b3cb68b..018c8e2`, 4 независимых прохода
> (правила PHP · правила JS/SCSS · SOLID/архитектура · мёртвый код).
> Каждая находка подтверждена по коду (файл:строка). Приоритет: Р0 — баги,
> чинить до релиза; Р1 — быстрые победы; Р2 — архитектурная дедупликация;
> Р3 — чистка и документация. Effort: S — до часа, M — полдня.

## Р0 — баги и безопасность (до релиза)

- ✅ **Р0.1 [M] Миграция `recording_slot` не выполнится на живых инсталляциях.**
  Два дефекта: (а) `MigrationRunner::run()` вызывается ТОЛЬКО из
  `register_activation_hook` (`Activate.php:60`), гейт `version_compare('1.0.0', $current, '>')` —
  на установке с `fs_lms_schema_version = 1.0.0` `up()` не запустится никогда
  (а это ровно те установки, где есть slot-данные); инструкция CLAUDE.md
  «перезагрузить страницу — миграции перезапустятся» коду не соответствует;
  (б) даже при активации `PostManager::getIds()` → `get_posts` по CPT, которые
  на хуке активации ещё не зарегистрированы → WP отсекает чужие черновики
  (синтетические капы), часть уроков будет молча пропущена.
  Решение: переписать `migrateRecordingSlotToBroadcast()` на прямой `$wpdb`
  (`SELECT post_id, meta_value FROM postmeta WHERE meta_key='fs_lms_meta' AND meta_value LIKE '%recording_slot%'`),
  гейтить собственной опцией-версией по паттерну `VideoSchema`/`AdSchema`
  (срабатывает на обычной загрузке, не только на активации); адаптировать
  `Migration_1_0_0Test` (сейчас дёргает приватный метод через Reflection, реальный
  путь `up()` не покрыт); синхронизировать раздел «Миграции» в CLAUDE.md.
- ✅ **Р0.2 [S] XSS-зазор в тич-панели записей.** `teacher-editor.js:144-160`
  (`renderRecordingsPanel`) вставляет `r.s3_key`/`r.recorded_at`/`r.id` в `innerHTML`
  и `data-*` без экранирования (`s3_key` — внешний ввод из push-регистрации).
  Обернуть в `esc()` (как в `step-editor.js`), `r.id` — через `parseInt`.
- ✅ **Р0.3 [S] Тич-панель broadcast-шага мертва до открытия редактора.**
  `initBroadcastPanels()` вызывается только из `bootstrap()` (первое открытие
  drawer) — до этого `.broadcast-teacher-panel` вечно «Загрузка записей…».
  Вызывать на `DOMContentLoaded` независимо от монтирования редактора.
- ✅ **Р0.4 [S] «Редактировать ↗» из плеера ведёт на 404.** `step-editor.js:667,773` —
  относительный `href="post.php?post=…"`; в админке ок, на маршруте плеера
  (`/group/…`) → `/group/post.php`. Добавить `opts.adminBase`
  (дефолт `fs_lms_vars.ajaxurl.replace('admin-ajax.php','')`), в teacher-бандле
  локализовать `admin_url()` через `Enqueue`.
- ✅ **Р0.5 [S] Невидимая кнопка перехватывает клики по карточке КТП.**
  `_ktp.scss:170-172` — `.pt-deadlines` с `opacity:0` без `pointer-events:none`
  лежит поверх начала `.pt-title`: клик по первым символам темы открывает
  поповер дедлайнов вместо перехода в плеер. Фикс: `pointer-events:none` +
  `:hover → auto`. Тот же дефект у `.pt-more` (преэкзистентный) — заодно.
- ✅ **Р0.6 [S] `TaskPreviewService` читает неверные/несуществующие ключи меты.**
  `task_text` — это поле «Решение» (`TaskTextSolution`), а сервис показывает его
  как условие; ключи `problem_text|question_text|content|answer|answer_text|correct_answer|task_solution|solution|hint`
  не существуют ни в одном шаблоне (мёртвые ветки); в блок «Решение» уезжает
  `task_hint` (подсказка). Условие → `['common_condition','task_condition']`,
  решение → `['task_text']`, подсказку — отдельным `hint_html` (+секция в JS).
- ✅ **Р0.7 [S] (hardening) `SaveGroupLessonSteps` не валидирует `payload.ref`.**
  Преподаватель (и методист — зазор преэкзистентный) может прицепить к шагу
  ref-ид чужого предмета. Проверять принадлежность ref предмету урока в
  `LessonAuthoringService::buildSteps()` или на сейве.

## Р1 — быстрые победы

- ✅ **Р1.1 [S] Разжать `teacher-editor.min.js` вдвое (59.8 КБ → ~30).** _(58.6→38.7 КБ)_
  Удалить мёртвый `import { TaskEditor }` из `step-editor.js:4` (не используется,
  но тащит task-editor+task-fields+confirm-modal+modal-base в оба бандла);
  добавить `"sideEffects": ["*.scss"]` в `package.json`.
- ✅ **Р1.2 [S] Судьба `SetRecordingUrl` — единственный невыполненный пункт плана.** _(вариант б: цепочка выпилена, метод репозитория оставлен для VideoLibrary)_
  Этап 4 обещал ручной ввод URL записи как фолбэк в тич-панели — не сделано;
  endpoint жив, но фронт-потребителей 0 (`ProgramCallbacks:587`, кейс AjaxHook,
  регистрация в `ScheduleController:42`, ключ в `ProfileViewResolver:194`).
  Решить: (а) доделать фолбэк в тич-панели broadcast (рекомендуется — это
  замысел плана) ИЛИ (б) выпилить всю цепочку. В обоих случаях: убрать
  `data-url` и «изменить/добавить ссылку вручную»-тайтлы с `.pt-recording`
  в `ktp.js:328,331` (кнопка теперь просто ведёт в плеер).
- ✅ **Р1.3 [S] Стили wp-admin `.button` в плеере.** В тич-редакторе без стилей:
  «Выбрать существующую», «+ Глава», «+ Файл…», «Привязать» (`.button-primary` —
  0 правил в player.min.css), «Отвязать» (`.fs-sb-btn-danger` не подключён).
  ~8 строк в `_teacher-editor.scss` со скоупом `#fsTeacherEditor, .broadcast-teacher-panel`
  через существующие миксины `admin/_mixins.scss` (`cb-ghost-button`/`cb-chip-solid`).
  Заодно: стили для `.tep-loading`, решить судьбу пустых `.tep-reset`/`.teacher-editor-mount`.
- ✅ **Р1.4 [S] Admin-палитра утекла в плеер.** `@use admin/_variables` из
  `_teacher-editor.scss` приносит в `player.min.css` второй `:root` с
  `--color-primary: var(--wp-admin-theme-color,…)` — фокус инпутов `.fs-se`
  красится admin-синим вместо `var(--accent)`. Вынести `:root`-мост из
  `admin/_variables.scss` в `admin/_wp-bridge.scss`, подключаемый только из `admin.scss`.
- ✅ **Р1.5 [S] Мёртвый CSS в player.min.css.** _(−9.7 КБ)_ `@use admin/components/course-builder`
  затянул 89 вхождений `.fs-lms-cb-wrap` (дерево модулей, футер билдера), из
  которых плееру нужны только `.fs-cb-popover/.fs-cb-picker/.fs-cb-pick-*`.
  Вынести попап-пикер в `admin/components/_picker-popover.scss`, в player
  подключать только его.

## Р2 — архитектурная дедупликация

- ✅ **Р2.1 [S] `GroupAccessGuard::findManageableLesson()`.** _(7 блоков → 1; groupLessons убран из TeacherLessonCallbacks; +3 теста)_ Блок
  «`groupLessons->find` → `canManage` → `error('Занятие не найдено.')`»
  повторён 7 раз (`TeacherLessonCallbacks` ×4, `VideoLibraryCallbacks` ×3),
  с уже начавшимися расхождениями. Guard получает `GroupLessonRepository`,
  метод возвращает `?GroupLessonDTO`; `error()` остаётся в Callback-слое.
  (Родственный `canWriteJournal`-вариант ×4 в Journal/GradingCallbacks — опционально.)
- ✅ **Р2.2 [S] Один владелец deep-link плеера и критерия форка.** _(PageRoutes::lessonUrl ×4; ContentCloneService::isForkedForGroup/forkedLessonIds ×5; ScheduleService: PostManager→ContentCloneService)_
  `playerUrl()` скопирован в `LearnerService:456`, `DashboardService:244`,
  `ScheduleService:489` (+инлайн в `AssessmentPageController:238`) →
  `PageRoutes::GroupCockpit->lessonUrl(int $groupId, int $glId)`.
  Критерий «урок — форк группы» в 5 местах (`TeacherLessonCallbacks:77`,
  `ScheduleService:458`, `ContentCloneService:197,245,280`) →
  `ContentCloneService::isForkedForGroup()` + батч `forkedLessonIds(int $groupId, array $ids)`
  (внутри `primeMetaCache`). Бонус: из `ScheduleService` уходят `PostManager`/`PostMetaName`,
  из `deleteForksForGroup()` — N+1 по мете.
- ✅ **Р2.3 [S] Схлопнуть дубли плеера.** _(assembleView + StepContentRenderer::renderInlineData; _title-петля отложена — array vs StepDTO)_ `buildView`/`buildTeacherView`
  (22 строки, различие в 2) → приватный `assembleSteps(..., ?array $statuses)`;
  инлайн-ветки `text|video|broadcast` из `LessonPlayerService::renderData()` и
  `CoursePreviewService::renderData()` → `StepContentRenderer::renderInlineData()`
  (новый инлайн-тип = правка одного файла). Заодно `_title`-петля с прямым
  `get_the_title` из `TeacherLessonCallbacks:80-87` → в сервис
  (есть `StepContentRenderer::resolveTitle`).
- ❌ **Р2.4 [M] `TaskPreviewService` поверх существующих сервисов. WON'T DO (premise не держится).**
  _При реализации выяснилось: `StepContentRenderer::taskBundle` **намеренно answer-less** (`buildWidgetData`
  не кладёт `correct`-флаги вариантов и ответы в fill-сегменты — рендер ученику). А превью задачи
  методисту/преподавателю ОБЯЗАНО показывать правильные ответы. Значит «taskBundle как единый источник»
  противоречит назначению превью: choice/fill из taskBundle воссоздать answer-ful нельзя (только из меты) →
  дедупа не выходит; либо это ре-дизайн подачи ответов в превью (меняет рендер, только браузер подтвердит,
  выгода спорная). Текущий код превью корректен после Р0.6. Под-пункт buildFiles→getTaskFiles тоже
  небезопасен (методы разошлись: esc_url_raw+без лимита vs без экранирования+лимит 2). **Решение: не трогать
  рабочую фичу.** Если понадобится единый рендер виджетов — делать как отдельную фичу «превью с ответами»
  поверх taskBundle+CorrectAnswerResolver в браузер-сессии._
  После Р0.6: сейчас это 4-е PHP-место (+5-е в JS `buildAnswerSection`) со
  знанием схемы `fs_lms_meta` задачи. Строить превью через
  `StepContentRenderer::taskBundle()` (шаблон через `TemplateResolver`, не
  «угадай-ключ») + `CorrectAnswerResolver`; отдавать нормализованный
  `widget_data.type`, `buildAnswerSection()` в step-editor.js переключить на него.
  Меняет контракт `GetTaskPreview`/`TeacherTaskPreview`/Ref-вариантов — делать
  отдельным коммитом с синхронной правкой JS. Заодно:
  `StepContentRenderer::buildFiles` → `TaskMetaService::getTaskFiles` (дубль).
- ✅ **Р2.5 [M] `step-editor.js`: транспорт вместо пакета флагов.** _мёртвые showStepSettings/renderStepSettings + export nonceFor убраны; opts.transport-консолидация (поведение идентично — admin-call-site глобалы не трогались, teacher-editor обновлён). Единая request()-точка не делалась (module-level хелперы берут cfg параметром — приемлемо)._
  `opts.actions/nonce/ajaxurl/extraAjaxParams/persist` связаны скрытыми
  инвариантами (nonce игнорируется без actions; extraAjaxParams только для
  кандидатов) → один `opts.transport = { actions, nonce, ajaxurl, params, persist }`
  с единственной точкой `request()`. Удалить мёртвые `showStepSettings`/`renderStepSettings`
  (30 строк, 0 вызовов) и `export` у `nonceFor`. Точек вызова две — правка локальная.
- ✅ **Р2.6 [S] `Enqueue::enqueueBundle()`.** _(+enqueueMathJax(); gl→sanitizeGetInt)_ Пять почти одинаковых блоков
  «style+script+filemtime+localize» (profile/player/teacher/assessment/kege) →
  приватный хелпер. Заодно `(int) $_GET['gl']` (`Enqueue:422`) → `sanitizeGetInt('gl')`.
- ✅ **Р2.7 [M] Разгрузить `LessonPlayerController::loadTemplate()`.** _LessonPlayerService::buildRouteView (view+shell+tree+lock); контроллер — auth+include, локали player.php идентичны; $_GET→Sanitizer; gate/nav убраны из контроллера; +2 теста. ⚠️ рекомендуется браузер-проверка входа плеера (ученик+преподаватель)._
  Ветвление режимов, сборка view/shell/tree, расчёт lock-таймера, сырые `$_GET` —
  вынести в `LessonPlayerService::buildRouteView(int $userId, GroupLessonDTO $row)`,
  в контроллере оставить маршрут + include. `$_GET` → Sanitizer-методы.

## Р3 — чистка и документация

- ◑ **Р3.1 [S] Мёртвый код PHP:** _(сделано: `sanitizeBoolValue` удалён, `sanitizeChapters`→private, `declare(strict_types=1)` в AjaxHook/Nonce/Init. Осталось: `$lessonGate`-тернарник — в отложенном Р2.7; выравнивание error()+return в VideoLibraryCallbacks)_ `Sanitizer::sanitizeBoolValue()` (0 вызовов
  после выпила recording_slot); `sanitizeChapters()` → `private`; тернарник
  `$lessonGate` в `LessonPlayerController:81` (teacher-ветка не читается);
  `declare(strict_types=1)` в `AjaxHook.php`/`Nonce.php`/`Init.php`;
  стиль `error()`+`return` выровнять внутри `VideoLibraryCallbacks`.
- ◑ **Р3.2 [S] Мёртвый код JS/SCSS:** _(сделано: `.rec-pop/.rec-input/.rec-actions` удалены из `_overlays.scss`. Осталось: дубль тостов на маршруте плеера; jQuery-ready→DOMContentLoaded в teacher-editor.js)_ `.rec-pop/.rec-input/.rec-actions` из
  `_overlays.scss:46-49` (rec-pop выпилен); дубль тостов на маршруте плеера
  (common `.fs-toast` + собственный `#fsToast` из `shell.js` — оставить один);
  обёртка `teacher-editor.js` — jQuery-ready без единого использования `$` →
  `DOMContentLoaded` (или зафиксировать выбор в шапке файла).
- ⬜ **Р3.3 [S] Консистентность:** `match` по сырым строкам типов шага в новых
  точках (`LessonAuthoringService:290`, `LessonPlayerService:119`,
  `CoursePreviewService:144`) → кейсы `StepType`; сырой `$_POST['steps']`
  (LessonCallbacks + TeacherLessonCallbacks) → хелпер в `Sanitizer`;
  `VideoLibraryController` — 3 teacher-хука ручным `add_action` вместо паттерна
  `AjaxController` (опционально); embed-ветка `step-broadcast.php` дублирует
  `step-video.php` → партиал `video-embed.php`.
- ⬜ **Р3.4 [S] Документация:** `FS_LMS_API.md:327` — абзац про `recording_slot`
  → `broadcast`/`renderBroadcastData`; `fs_lms_teacher_editor_vars` — typedef в
  `_types.js` + строка в таблицах глобалов CLAUDE.md и `basic_doc.md:1041`;
  «189 кейсов AjaxHook» в `basic_doc.md:455,1045` → факт 206; комментарий в
  `step-editor.js:50` про `LessonCallbacks::MAX_STEPS_PER_LESSON` → `LessonAuthoringService`;
  раздел «Миграции» CLAUDE.md — вместе с Р0.1. Преэкзистентное: 4 инлайновых
  `<svg>` в `step-assessment.php` → `Icon`-enum (три глифа уже есть).

## Признано здоровым (перепроверять не нужно)

AJAX-авторизация всех 9 новых обработчиков (двухслойная, без сырых WP-вызовов);
`AjaxResponse`/`AjaxHook`-паттерн 1:1; `wp_localize_script` только в Enqueue;
слои в новых сервисах (все через Managers/Repositories, `primeMetaCache` —
корректная обёртка); `video-chrome.php` — образцовая дедупликация; вынос
`sanitizeStep`/preview-логики из Callbacks — чистое улучшение; teacher-режим
через `studentPersonId=0` проведён сквозняком (сервер+клиент); палитра
JS↔SCSS `broadcast` синхронна посимвольно; SCSS-токены/`@use`/вложенность/
`!important` — без нарушений; `ContentCloneService` делить не нужно;
`GroupDeletionHandler` (14 зависимостей) — линейный transaction script,
рефакторить только при 15-й зависимости; новые интерфейсы не нужны нигде.

## Порядок

```
Р0 (все, до релиза) → Р1.1-Р1.5 → Р2.1-Р2.3, Р2.6 (S, механика)
                                → Р2.4 (после Р0.6), Р2.5, Р2.7 (M, отдельными коммитами)
                                → Р3 (фоном, можно вперемешку)
```
