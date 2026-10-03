# Этап 0. Подготовка и исправления

Зависимости: нет. Результат: исправленный конструктор работ, контракт форматов, права, энумы,
поле вместимости кабинета, готовый к оплате dev. Видимой новой фичи нет.

Перед началом прочитать `README.md` этой папки. SPEC: §0, §2, §3 «Вместимость», §13.

**Порядок выполнения:** 0.0 → 0.3 → 0.1 → 0.2 → 0.5 → 0.4 → 0.6 → 0.9 → 0.8 → 0.7.
Пункт 0.1 использует реестр форматов из 0.3, поэтому 0.3 идёт раньше.

---

## 0.0 Ветка содержит master

**Зачем.** `public-exams` отстаёт от `master` на 4 коммита (версии 1.0.69–1.0.70, 132 файла).
В `master` уже есть то, на что ссылается план: `ApplyFormTrackingService`, `CaptchaService::check()`,
`Migration_1_0_70`, `TableName::WorkTaskChecks`. База dev уже на схеме 1.0.70.

**Проверить перед началом**
- `git log --oneline public-exams..master` — список коммитов, которых нет в ветке.
- `git status --short` — незакоммиченные правки владельца (`.docs/Tasks.md` и папка `tasks/`) не терять.

**Шаги**
- [ ] 0.0.1 Спросить владельца, как подтянуть `master` (merge или rebase). Без ответа ничего не сливать.
- [ ] 0.0.2 После слияния: `grep -n "FS_LMS_VERSION" fs-lms.php` → версия не ниже `1.0.70`.
- [ ] 0.0.3 `npm run ci` — зелёный до начала работ. Упавшие тесты записать и показать владельцу, не чинить молча.

**Готово, когда:** `git log --oneline public-exams..master | wc -l` → `0`, `npm run ci` проходит.

---

## 0.3 Контракт форматов экзамена в ядре

**Зачем.** Ядро должно знать число единиц, максимум, шкалу, длительность и направление формата
(КЕГЭ, ОГЭ), не импортируя классы модуля `EgeComputer` (SPEC §0). Сейчас эти числа ядро либо
считает по термам таксономии, либо хардкодит (`16` в `AssessmentMetaBoxController`).

**Проверить перед началом**
- `grep -n "add_filter" inc/Modules/EgeComputer/EgeComputerModule.php` — модуль уже публикует ядру
  фильтры (`STATION_SETTINGS_FILTER`, `EXTRA_POSITIONS_FILTER`); новый фильтр добавляется рядом тем же способом.
- `grep -rn "fs_lms_exam_formats\|ExamFormat" inc` → пусто (аналога нет).
- Источники чисел: `inc/Modules/EgeComputer/Config/StationExamConfig.php` (длительность 235 и 150 минут),
  `KegeScaleConfig.php` (27 типов, 29 первичных, шкала до 100, №26–27 по 2),
  `OgeScaleConfig.php` (`TASK_COUNT = 16`, `maxPrimary()` = 21, шкала в отметку 2–5).

**Шаги**
- [ ] 0.3.1 Создать `inc/Enums/Exam/ExamDirection.php` — backed enum `string`:
  `Ege = 'ege'`, `Oge = 'oge'`. Методы: `label(): string` («ЕГЭ» / «ОГЭ»), `grade(): int` (11 / 9).
- [ ] 0.3.2 Создать `inc/DTO/Exam/ExamFormatDTO.php` — `readonly class` с конструктором:
  ```php
  public function __construct(
      public AssessmentKind $kind,
      public ExamDirection  $direction,
      public int            $unitCount,        // единиц оценивания: 27 / 16
      public int            $primaryMax,       // 29 / 21
      public ?int           $secondaryMax,     // 100 / null (у ОГЭ вторичных нет)
      public int            $gradeMax,         // 0 / 5 (отметка ОГЭ)
      public int            $durationMinutes,  // 235 / 150
      public array          $scale,            // array<int,int> первичный → вторичный или отметка
      public array          $unitMaxScores,    // array<int,int> номер → максимум, только где не 1
  ) {}
  ```
  Методы: `unitMax( int $number ): int` → `$this->unitMaxScores[ $number ] ?? 1`;
  `translate( int $primary ): ?int` → `$this->scale[ $primary ] ?? null`.
- [ ] 0.3.3 Создать `inc/Services/Exam/ExamFormatRegistry.php`:
  - `public const FILTER = 'fs_lms_exam_formats';`
  - `all(): array` — `apply_filters( self::FILTER, array() )`, оставить только элементы
    `instanceof ExamFormatDTO`, вернуть массив с ключом `$dto->kind->value`;
  - `for( AssessmentKind $kind ): ?ExamFormatDTO`;
  - `unitCount( AssessmentKind $kind ): int` — `0`, если формата нет;
  - `forDirection( ExamDirection $direction ): array` — список `ExamFormatDTO`.
  Класс без состояния и без конструктора; внедряется через DI.
- [ ] 0.3.4 В `inc/Modules/EgeComputer/Config/KegeScaleConfig.php` добавить
  `public static function taskTypes(): int { return self::TASK_TYPES; }` (константа приватная).
- [ ] 0.3.5 В `inc/Modules/EgeComputer/EgeComputerModule.php`:
  - в `register()` после остальных `add_filter` добавить
    `add_filter( ExamFormatRegistry::FILTER, [ $this, 'provideExamFormats' ] );`
  - метод `provideExamFormats( array $formats ): array` дописывает два `ExamFormatDTO`:
    - КЕГЭ: `kind = EgeComputer`, `direction = Ege`, `unitCount = KegeScaleConfig::taskTypes()`,
      `primaryMax = KegeScaleConfig::primaryMax()`, `secondaryMax = KegeScaleConfig::secondaryMax()`,
      `gradeMax = 0`, `durationMinutes = StationExamConfig::for( … )['timeLimit']`,
      `scale = KegeScaleConfig::scale()`, `unitMaxScores` — цикл по номерам `1..taskTypes()`,
      в массив попадают номера, где `KegeScaleConfig::answerSlots( $n ) > 1` (получится `[26 => 2, 27 => 2]`);
    - ОГЭ: `kind = OgeComputer`, `direction = Oge`, `unitCount = OgeScaleConfig::TASK_COUNT`,
      `primaryMax = OgeScaleConfig::maxPrimary()`, `secondaryMax = null`,
      `gradeMax = OgeScaleConfig::secondaryMax()`, `durationMinutes` из `StationExamConfig`,
      `scale = OgeScaleConfig::scale()`, `unitMaxScores` — цикл `1..16`, значение
      `OgeScaleConfig::pointsForPosition( (string) $n )`, в массив — где больше 1.
  Модулю импортировать классы ядра можно; обратного импорта быть не должно.

**Тесты**
- `tests/Unit/Services/Exam/ExamFormatRegistryTest.php`:
  - `test_all_returns_formats_keyed_by_kind` — подложить
    `$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats']` с двумя DTO;
  - `test_all_ignores_non_dto_values` — в фильтре мусор (`'x'`, `array()`), результат пуст;
  - `test_unit_count_is_zero_when_module_disabled` — фильтр не задан → `0`;
  - `test_for_direction_filters_by_direction`.
- `tests/Unit/Modules/EgeComputer/ExamFormatsProviderTest.php` (посмотреть, как в `tests/Unit/Modules/`
  создаётся модуль; если конструктор тяжёлый — вынести сборку DTO в статический метод
  `EgeComputerModule::buildExamFormats(): array` и тестировать его):
  - КЕГЭ: `unitCount = 27`, `primaryMax = 29`, `secondaryMax = 100`, `unitMax(26) = 2`, `unitMax(1) = 1`,
    `translate(18) = 72`, `translate(22) = 83`, `durationMinutes = 235`;
  - ОГЭ: `unitCount = 16`, `primaryMax = 21`, `secondaryMax = null`, `gradeMax = 5`, `durationMinutes = 150`.

**Готово, когда:** `vendor/bin/phpunit --filter ExamFormat` зелёный;
`grep -rn "Inc\\\\Modules" inc/Services/Exam inc/DTO/Exam inc/Enums/Exam` → пусто.

---

## 0.1 Число позиций КЕГЭ берётся из формата

**Зачем.** Конструктор работы считает число слотов КЕГЭ через `wp_count_terms` таксономии
`{предмет}_task_number`: если в таксономии больше 27 термов, слотов тоже больше. Проверка полноты
`EgeCompletenessChecker` читает те же термы. Источник должен быть один — формат (SPEC §13).

**Проверить перед началом**
- `sed -n 236,262p inc/Controllers/Assessment/AssessmentMetaBoxController.php` — массив `$ege_slots_by_kind`:
  ЕГЭ через `wp_count_terms`, ОГЭ — число `16`.
- `grep -n "get_terms" inc/Services/Assessment/EgeCompletenessChecker.php` — два места: `validate()` и `getMissingTaskNumbers()`.
- Имена термов на dev (должны быть числами):
  `docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp term list inf_ege_task_number --fields=name,slug`.
  Если есть нечисловые имена — остановиться и показать список владельцу.
- `grep -rn "new EgeCompletenessChecker" inc tests` — все места создания (после шага 0.1.3 им нужен аргумент).

**Шаги**
- [ ] 0.1.1 `AssessmentMetaBoxController`: добавить в конструктор `private readonly ExamFormatRegistry $formats`.
- [ ] 0.1.2 Там же заменить массив `$ege_slots_by_kind`:
  - для каждого `AssessmentKind`, у которого `isStation()`, значение = `$this->formats->unitCount( $kind )`;
  - если формат не найден (модуль выключен, `0`) — прежнее поведение: для `EgeComputer` `wp_count_terms(...)`, для `OgeComputer` `16`.
  Комментарий над блоком переписать: источник — формат модуля, термы — только запасной путь.
- [ ] 0.1.3 `EgeCompletenessChecker`: добавить конструктор `public function __construct( private readonly ExamFormatRegistry $formats ) {}`.
- [ ] 0.1.4 Там же приватный метод `expectedNames( array $termNames, AssessmentKind $kind ): array`:
  если `$n = $this->formats->unitCount( $kind )` больше нуля — оставить только элементы `slug => name`,
  где `ctype_digit( $name ) && (int) $name >= 1 && (int) $name <= $n`; иначе вернуть вход без изменений.
- [ ] 0.1.5 В `validate()` применить `expectedNames()` к `$termNames` **после** добавления позиций из
  `EXTRA_POSITIONS_FILTER` и перестроить `$nameToSlug` по отфильтрованному набору. Задание с номером
  вне формата после этого попадает в `orphans` — оно не теряется молча, автор видит его в сообщении проверки.
- [ ] 0.1.6 В `getMissingTaskNumbers()` тот же фильтр: терм вне `1..N` не считается пропуском.
- [ ] 0.1.7 Исправить все `new EgeCompletenessChecker()` из проверки выше — передать `new ExamFormatRegistry()`.
- [ ] 0.1.8 Старые работы с лишними заданиями не обрезать: `layoutByPosition()` уже дописывает «не поместившееся»
  в конец раскладки (`$layout[] = $id`). Убедиться чтением кода, что при `total = 27` и 30 заданиях раскладка
  содержит 30 элементов; ничего не менять, добавить тест (ниже).

**Тесты**
- `tests/Unit/Services/Assessment/EgeCompletenessCheckerTest.php` — дописать, существующие не трогать:
  - `test_expected_count_comes_from_format_not_terms` — 36 термов, формат `unitCount = 27` → `expectedCount = 27`, пропусков нет при 27 заданиях;
  - `test_task_with_number_outside_format_is_orphan` — задание с термом `30` попадает в `orphans`;
  - `test_falls_back_to_terms_when_format_missing` — фильтр форматов не задан → поведение прежнее;
  - `test_oge_extra_positions_still_counted` — формат ОГЭ на 16, термы `1..12`, фильтр доп. позиций `13..16`.
  Формат в тест подаётся через `$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats']`.
- `tests/Unit/Controllers/…/AssessmentMetaBoxControllerTest.php` — если тест контроллера уже есть
  (`ls tests/Unit/Controllers`), добавить `test_ege_slots_equal_format_unit_count` и
  `test_extra_tasks_of_old_work_are_kept`. Если теста нет — вынести расчёт слотов в приватный метод
  `slotsByKind( string $subject ): array` и покрыть его через рефлексию не надо: достаточно тестов чекера и ручной проверки.

**Готово, когда**
- `vendor/bin/phpunit --filter EgeCompletenessChecker` зелёный, старые тесты не изменены.
- Ручная проверка: в админке открыть работу вида «Компьютерный ЕГЭ» предмета `inf_ege` — в конструкторе ровно 27 позиций;
  временно добавить лишний терм `28` в таксономию номеров — позиций по-прежнему 27; терм удалить.

---

## 0.2 Отступы `fs-field__label` в админке

**Зачем.** В метабоксах работы и экзамена подписи полей стоят с неверным отступом (SPEC §13).
Исправление не должно затронуть другие формы, где используется `.fs-field`.

**Проверить перед началом**
- `sed -n 11,25p src/scss/admin/components/_field.scss` — базовое правило `.fs-field__label` (`margin-bottom: $spacing-sm`).
- `grep -rn "fs-field--checkbox" src/scss` → пусто: модификатор используется в `inc/MetaBoxes/Fields/CheckboxField.php`,
  но правила для него нет. Подпись-чекбокс получает стили обычной подписи.
- Где ещё используется `.fs-field`: `grep -rln "fs-field__label" templates inc` (настройки, печать, поля метабоксов, секции модулей).
- Открыть на dev работу (`{предмет}_assessments`) и сделать снимок метабокса «до». **Показать снимок владельцу
  и получить подтверждение, какой именно отступ считается неверным**, если это не очевидно из снимка.

**Шаги**
- [ ] 0.2.1 В `_field.scss` добавить модификатор внутри блока `.fs-field`:
  ```scss
  // Подпись-чекбокс: label оборачивает input, нижний отступ подписи не нужен.
  &--checkbox {
    .fs-field__label {
      margin-bottom: 0;
      font-weight: $font-regular; // взять существующий токен; посмотреть имена в src/scss/admin/_variables.scss
    }
  }
  ```
  Использовать только существующие токены; если нужного нет — сначала завести его в `_variables.scss`.
- [ ] 0.2.2 Если по снимку неверен отступ у обычных полей метабокса — править **не** базовое правило,
  а область метабокса: найти класс-обёртку шаблона (`grep -n "class=" inc/MetaBoxes/Templates/BaseTemplate.php`)
  и добавить правило вида `.ОБЁРТКА .fs-field { … }` в файл стилей метабоксов (`ls src/scss/admin/components | grep -i meta`).
- [ ] 0.2.3 `npx gulp styles:admin`, снимок «после», сравнить со снимком «до».
- [ ] 0.2.4 Открыть «Настройки → Конфигурация», «Настройки → Импорт», «Центр печати» — вид полей не изменился.

**Тесты.** Автотестов нет. `npm run lint:css` обязан пройти.

**Готово, когда:** снимки «до/после» метабокса показаны владельцу; три экрана из 0.2.4 без изменений; `npm run lint:css` зелёный.

---

## 0.5 Энумы и словари

**Зачем.** Все статусы, таблицы, коды ошибок, nonce и cron-хуки фичи заводятся один раз, до сервисов,
чтобы дальше в коде не появлялось сырых строк.

**Проверить перед началом**
- `ls inc/Enums/Exam 2>/dev/null` — после 0.3 там только `ExamDirection.php`.
- `README.md` §7.1–7.3, §7.5 — точные имена и значения. Отступать от них нельзя.
- Образец энума с `label()` — `inc/Enums/Assessment/AssessmentKind.php`.

**Шаги**
- [ ] 0.5.1 `inc/Enums/Settings/TableName.php` — 15 кейсов из README §7.1 под комментарием `// ==== Экзамены (проведения, запись, гости) ====`.
- [ ] 0.5.2 `inc/Enums/Exam/` — файлы энумов из README §7.2 (кроме уже созданного `ExamDirection`):
  `ExamEventStatus`, `ExamSessionStatus`, `ExamRegistrationStatus`, `ExamAudience`, `ExamProgress`,
  `GuestApplicationState`, `ExamPaymentState`, `ExamTokenPurpose`, `ManualResolutionKind`, `ExamOutboxEvent`.
  В каждом — `label(): string`. Подписи без рода и без чисел, образцы:
  | Кейс | Подпись |
  |---|---|
  | `ExamRegistrationStatus::Confirmed` | «Запись подтверждена» |
  | `ExamRegistrationStatus::Cancelled` | «Запись отменена» |
  | `ExamRegistrationStatus::Transferred` | «Запись перенесена» |
  | `ExamRegistrationStatus::Missed` | «Экзамен пропущен» |
  | `ExamProgress::NotStarted / InProgress / Submitted / Missed` | «Не начат» / «В процессе» / «Работа сдана» / «Неявка» |
  | `GuestApplicationState::Hold`, `AwaitingPayment` | «Место удерживается» |
  | `GuestApplicationState::PaymentPending` | «Ожидается подтверждение оплаты» |
  | `GuestApplicationState::Confirmed` | «Оплата получена, запись подтверждена» |
  | `GuestApplicationState::ExpiredUnpaid` | «Время брони истекло» |
  | `GuestApplicationState::Failed` | «Оплата не подтверждена» |
  | `GuestApplicationState::PaidNeedsResolution` | «Оплачено, требуется помощь» |
- [ ] 0.5.3 В `GuestApplicationState` добавить `holdsSeat(): bool` — `true` для `Hold`, `AwaitingPayment`, `PaymentPending`;
  `isTerminal(): bool` — `true` для `Confirmed`, `ExpiredUnpaid`, `Failed`, `Cancelled`, `Missed`.
- [ ] 0.5.4 В `ExamEventStatus` добавить `isEditable(): bool` (`Draft`, `Published`) и
  `acceptsRegistration(): bool` (только `Published`).
- [ ] 0.5.5 `inc/Enums/Log/ErrorCode.php` — 12 кейсов из README §7.3 под комментарием `// Экзамены`.
  Посмотреть, есть ли в энуме метод с подписями/описаниями кейсов (`grep -n "function" inc/Enums/Log/ErrorCode.php`);
  если есть `match` по всем кейсам — дополнить его, иначе PHP упадёт на непокрытом кейсе.
- [ ] 0.5.6 `inc/Enums/Wp/Nonce.php` — 4 кейса из README §7.5.
- [ ] 0.5.7 `inc/Enums/Wp/CronHook.php` — 3 кейса из README §7.5 с докблоками.
- [ ] 0.5.8 Новые `OptionName` и `TransientKey` **не заводить**: настройки экзаменов хранятся в существующей
  опции `PluginConfig` (этап 11a.6), счётчики лимитов — внутри `RateLimitService` со своим префиксом.

**Тесты**
- `tests/Unit/Enums/Exam/ExamEnumsTest.php`:
  - `test_every_case_has_non_empty_label` — цикл по всем энумам этапа;
  - `test_labels_have_no_gendered_forms` — ни одна подпись не содержит слов «записан », «сдал », «пришёл», «не явился»;
  - `test_hold_states_hold_seat`, `test_terminal_states`;
  - `test_table_names_have_plugin_prefix` — все 15 значений начинаются с `fs_lms_exam_`.
- Существующий `tests/Unit/Enums/NonceTest.php` — проверить, не перечисляет ли он кейсы списком; если да — дополнить.

**Готово, когда:** `vendor/bin/phpunit --filter "ExamEnums|NonceTest"` зелёный; `grep -rn "fs_lms_exam_" inc --include="*.php" | grep -v "Enums/"` → пусто.

---

## 0.4 Права `ManageExams`, `ManageExamGuests`, `ShareExamResults`

**Зачем.** Отдельные права на управление проведениями, гостями и выдачу результатов (SPEC §2).
Преподаватель — в рамках своих предметов (scope проверяется на этапе 1.2), методист и администратор — глобально, офис — нет.

**Проверить перед началом**
- README §4.3 (рецепт) и §6 (матрица).
- `grep -n "capsVersion" inc/Init.php` — текущее значение `'5.6'`.
- `sed -n 84,115p inc/Managers/Person/RoleManager.php` — блок `$admin->add_cap( … )`.

**Шаги**
- [ ] 0.4.1 `Capability.php` — раздел `// ===== Экзамены =====`, три кейса со значениями из README §6 и докблоками
  (что открывает право и почему оно не равно `ManageLmsTeaching`).
- [ ] 0.4.2 `UserRole::capabilities()` — добавить три права в массивы `FSTeacher` и `FSMethodist`. В `FSOffice` **не добавлять**.
- [ ] 0.4.3 `RoleManager::syncCapabilities()` — три `$admin->add_cap( … )`.
- [ ] 0.4.4 `inc/Init.php` — `$capsVersion = '5.7'`, комментарий `// 5.7: + права экзаменов`.

**Тесты** — `tests/Unit/Enums/UserRoleTest.php`:
- `test_teacher_and_methodist_have_exam_caps`;
- `test_office_has_no_exam_management_caps` — у `FSOffice` нет ни одного из трёх;
- `test_student_and_parent_have_no_exam_caps`.

**Готово, когда:** тесты зелёные; на dev после открытия любой страницы:
`docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp cap list lms_teacher | grep exam` показывает три права,
`… wp cap list lms_office | grep manage_lms_exams` — пусто.

---

## 0.6 Право `ResolveExamPayments` для офиса

**Зачем.** Администраторы платформы (`lms_office`) разбирают проблемные оплаты гостей, но проведениями не управляют (решение 29).

**Проверить перед началом:** 0.4 выполнен.

**Шаги**
- [ ] 0.6.1 `Capability.php` — кейс `ResolveExamPayments = 'resolve_lms_exam_payments'` с докблоком: очередь
  «Оплачено, требуется помощь», перенос оплаченного гостя, отметка урегулирования; **без** создания и публикации проведений.
- [ ] 0.6.2 `UserRole::capabilities()` — добавить право только в `FSOffice`.
- [ ] 0.6.3 `RoleManager::syncCapabilities()` — `$admin->add_cap( Capability::ResolveExamPayments->value );`.
- [ ] 0.6.4 `Init.php` — если 0.4 и 0.6 идут одной серией, оставить `'5.7'` и дополнить комментарий; если 0.4 уже
  выкатывался отдельно — поднять до `'5.8'`.

**Тесты** — `UserRoleTest.php`:
- `test_office_has_only_payment_resolution_among_exam_caps` — есть `ResolveExamPayments`, нет `ManageExams`;
- `test_teacher_has_no_payment_resolution`.

**Готово, когда:** тесты зелёные; `wp cap list lms_office | grep resolve_lms_exam_payments` показывает право.

---

## 0.9 Поле «Вместимость» в «Настройки → Кабинеты»

**Зачем.** Вместимость сеанса экзамена = `seats` кабинета (SPEC §3). Колонка `fs_lms_rooms.seats`,
`RoomDTO::$seats` и `RoomCallbacks::ajaxSaveRoom()` уже принимают значение, но интерфейс его не шлёт.
**Сейчас каждое сохранение кабинета из модалки обнуляет `seats`**: JS не передаёт поле, сервер читает
`max( 0, sanitizeInt( 'seats' ) )` и записывает `0`. На dev у обоих кабинетов (`315`, `317`) `seats = 0`.

**Проверить перед началом**
- `templates/admin/components/modals/enrollment/room-modal.php` — поля «название» и «предметы», поля мест нет.
- `src/js/admin/modals/enrollment/room-modal.js` — `open()`, `_collectFormData()`, `_resetForm()`.
- `src/js/admin/managers/enrollment/room-modal-manager.js` — `_handleEdit()`, `_handleSave()`, `_renderRows()`.
- `templates/admin/components/tabs/settings-tabs/settings-9-rooms.php` — таблица из трёх колонок, `data-id`, `data-name`, `data-subjects`.
- `sed -n 76,95p inc/Callbacks/Course/RoomCallbacks.php` — сервер уже читает `seats`.
- `tests/Unit/Callbacks/Course/RoomCallbacksTest.php` — существующие тесты.

**Шаги**
- [ ] 0.9.1 `room-modal.php` — после поля названия добавить блок:
  ```php
  <div class="fs-form-group">
      <label for="room_seats">Вместимость (мест)</label>
      <input type="number" id="room_seats" min="0" max="500" step="1" value="0">
      <p class="description">Используется как число мест в сеансе экзамена. 0 — вместимость не задана, кабинет нельзя выбрать для экзамена.</p>
  </div>
  ```
- [ ] 0.9.2 `room-modal.js`: поле `$seatsInput = $('#room_seats')` в `init()`; в `open()` при правке — `this.$seatsInput.val( data.seats ?? 0 )`;
  в `_resetForm()` — сброс в `0`; в `_collectFormData()` — `seats: parseInt( this.$seatsInput.val(), 10 ) || 0`.
  Обновить JSDoc `@param` у `open()`.
- [ ] 0.9.3 `room-modal-manager.js`:
  - `_handleEdit()` — передавать `seats: $link.data('seats')`;
  - `_handleSave()` — добавить в запрос `seats: formData.seats`;
  - `_renderRows()` — `data-seats="${Number(room.seats) || 0}"` на обеих ссылках `js-edit-room`, новая ячейка с числом мест
    (`room.seats > 0 ? room.seats : '—'`), `colspan="3"` у пустого состояния → `4`.
- [ ] 0.9.4 `settings-9-rooms.php`: колонка `<th>Мест</th>` между «Название» и «Группы»; ячейка
  `<?php echo $room->seats > 0 ? (int) $room->seats : '—'; ?>`; `data-seats="<?php echo (int) $room->seats; ?>"` на обеих ссылках правки;
  `colspan="3"` в `tfoot` → `4`.
- [ ] 0.9.5 `RoomCallbacks::ajaxSaveRoom()` — защита от обнуления старым клиентом: при правке (`$roomId > 0`) и
  отсутствии параметра (`! $this->hasParam( 'seats' )`) ключ `seats` в `$data` не класть. Проверить, что
  `RoomRepository::update()` обновляет только переданные ключи (`sed -n 65,80p inc/Repositories/WPDBRepositories/RoomRepository.php`);
  если он требует все ключи — доработать его, а не обходить.
- [ ] 0.9.6 `inc/DTO/Course/RoomDTO.php` — метод `hasCapacity(): bool { return $this->seats > 0; }`.
  Проверку «кабинет без вместимости нельзя выбрать для сеанса» сам сеанс получит на этапе 2.4; здесь только метод и тест.
- [ ] 0.9.7 `npx gulp scripts`, открыть «Настройки → Кабинеты»: задать 20 мест кабинету 315, сохранить, обновить страницу — значение на месте;
  изменить только название — места не обнулились.

**Тесты** — `tests/Unit/Callbacks/Course/RoomCallbacksTest.php`:
- `test_save_room_passes_seats_to_repository` — `seats = '20'` → в `create()` уходит `seats => 20`;
- `test_save_room_keeps_seats_when_param_absent_on_update` — `room_id = 3`, без `seats` → в `update()` нет ключа `seats`;
- `test_save_room_clamps_negative_seats_to_zero`.
- `tests/Unit/DTO/…RoomDTOTest.php` (создать, если нет): `test_has_capacity_false_for_zero_seats`.

**Готово, когда:** тесты зелёные, `npm run lint:js` зелёный, ручная проверка 0.9.7 пройдена,
`docker exec wp_db mariadb -u root -proot wordpress -e "SELECT id,name,seats FROM wp_fs_lms_rooms"` показывает сохранённые места.

---

## 0.8 Совместимость с HPOS и условный запуск Woo-адаптера

**Зачем.** На проде WooCommerce хранит заказы в HPOS без режима совместимости. Плагин обязан объявить
совместимость, иначе WooCommerce покажет предупреждение о несовместимом плагине. Весь код оплаты должен
молчать, если WooCommerce выключен (SPEC §6 «Товар и WooCommerce»).

**Проверить перед началом**
- `grep -rn "declare_compatibility\|before_woocommerce_init" inc` → пусто.
- README §7.4: класс `Inc\Controllers\Exam\WooExamController`.

**Шаги**
- [ ] 0.8.1 Создать `inc/Controllers/Exam/WooExamController.php` — `extends BaseController implements ServiceInterface`.
  В `register()`:
  ```php
  add_action( 'before_woocommerce_init', array( $this, 'declareHposCompatibility' ) );
  ```
- [ ] 0.8.2 Метод `declareHposCompatibility(): void`:
  ```php
  if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FS_LMS_PLUGIN_FILE, true );
  }
  ```
  Имя константы с путём главного файла плагина уточнить: `grep -n "define(" fs-lms.php`. Если константы файла нет —
  добавить её в `fs-lms.php` рядом с `FS_LMS_VERSION` (`define( 'FS_LMS_PLUGIN_FILE', __FILE__ );`).
- [ ] 0.8.3 Метод `isWooActive(): bool { return class_exists( 'WooCommerce' ); }`. Все хуки оплаты (этап 11a.5)
  будут регистрироваться в этом же `register()` **только** при `isWooActive()`. Сейчас — только объявление совместимости.
- [ ] 0.8.4 Добавить `WooExamController::class` в `Init::getServices()` в блок ядра (не в блок модулей) с комментарием.

**Тесты** — `tests/Unit/Controllers/Exam/WooExamControllerTest.php`:
- `test_register_does_not_fail_without_woocommerce` — `register()` отрабатывает, когда класса `WooCommerce` нет;
- `test_is_woo_active_false_without_class`.

**Готово, когда:** тесты зелёные; на dev «WooCommerce → Состояние» не показывает `fs-lms` в списке несовместимых с HPOS плагинов;
при выключенном WooCommerce сайт открывается без ошибок (`wp plugin deactivate woocommerce`, открыть главную, включить обратно).

---

## 0.7 Dev готов к проверке оплаты

**Зачем.** На проде WooCommerce 11.1.2, классический checkout, HPOS без синхронизации, шлюз ЮKassa.
На dev — 10.2.1 и шлюза нет. Оплату на dev имитируем заказом с нулевой суммой по купону.

Это настройка окружения, не код плагина. Каждую команду выполнять и записывать результат.
Префикс команд: `docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli`.

**Проверить перед началом**
- `… wp plugin list --name=woocommerce --fields=name,status,version` → `active`, `10.2.1`.
- `… wp help wc hpos` — список подкоманд HPOS в установленной версии (флаги между версиями отличаются).
- Сделать дамп базы перед обновлением:
  `docker exec wp_db mariadb-dump -u root -proot wordpress > .docs/db-backups/before-woo-11.sql`.

**Шаги**
- [ ] 0.7.1 Обновить WooCommerce до версии прода: `… wp plugin update woocommerce --version=11.1.2`
  (если точной версии нет в каталоге — ближайшая `11.1.x`; записать фактическую).
- [ ] 0.7.2 Прогнать обновление базы магазина: `… wp wc update`.
- [ ] 0.7.3 Включить HPOS **без** режима совместимости: в админке «WooCommerce → Настройки → Дополнительно → Возможности»
  выбрать «Высокопроизводительное хранилище заказов» и снять «Включить режим совместимости». Проверка:
  `… wp wc hpos status` — HPOS включён, синхронизация выключена.
- [ ] 0.7.4 Оформление: страница «Оформление заказа» содержит шорткод `[woocommerce_checkout]`, а не блок
  (`… wp post list --post_type=page --fields=ID,post_title,post_name`, затем `… wp post get <ID> --field=post_content`).
  Блок заменить шорткодом.
- [ ] 0.7.5 Гостевое оформление включено, регистрация при оформлении выключена:
  `… wp option get woocommerce_enable_guest_checkout` → `yes`,
  `… wp option get woocommerce_enable_signup_and_login_from_checkout` → `no`.
- [ ] 0.7.6 Товар-фикстура: `… wp wc product create --name="Пробный экзамен" --type=simple --virtual=true --regular_price=1500 --user=1`.
  Записать ID товара в `.docs/public-exam-feature/NOTES.md` (раздел «Dev-фикстуры»).
- [ ] 0.7.7 Купон 100%: `… wp wc shop_coupon create --code=exam-free-dev --discount_type=percent --amount=100 --product_ids=<ID> --user=1`.
- [ ] 0.7.8 Ручной прогон: добавить товар в корзину, применить купон, оформить заказ гостем. Заказ с нулевой суммой
  должен получить статус «Обработка» или «Выполнен». Проверка через CRUD:
  `… wp eval 'var_dump( wc_get_order( <ID> )->is_paid() );'` → `bool(true)`.
- [ ] 0.7.9 Записать в `NOTES.md`: фактическую версию WooCommerce, ID товара, код купона, статус нулевого заказа.

**Тесты.** Автотестов нет — это окружение.

**Готово, когда:** `wp wc hpos status` показывает HPOS без синхронизации, нулевой заказ по купону даёт `is_paid() === true`,
данные записаны в `NOTES.md`, сайт и кабинет открываются без ошибок в `debug.log` (последние 15 строк).

---

## Проверка этапа

- [ ] `npm run ci` зелёный.
- [ ] Тесты 0.1, 0.3, 0.4, 0.5, 0.6, 0.8, 0.9 зелёные; существующие тесты `EgeCompletenessChecker` и конструктора не изменены и зелёные.
- [ ] Конструктор работы КЕГЭ предлагает ровно 27 позиций при любом числе термов.
- [ ] `lms_office` имеет `ResolveExamPayments` и не имеет `ManageExams`.
- [ ] Кабинет хранит вместимость, правка названия её не обнуляет.
