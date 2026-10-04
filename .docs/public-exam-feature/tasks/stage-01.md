# Этап 1. Аудитория записи и права на предмет

Зависимости: этап 0 (права 0.4, реестр форматов 0.3). Результат: три сервиса без UI — «кому доступен экзамен»,
«кто управляет предметом», «подходит ли вариант предмету» — и WP-CLI-команда, которая выводит аудиторию.
Новых таблиц и миграций нет: направление ЕГЭ/ОГЭ уже заложено в предметах (`inf_ege`, `inf_oge`).

Перед началом прочитать `README.md`. SPEC: §2 «Область и права».

**Порядок:** 1.1 → 1.2 → 1.3 → 1.4.


## Статус (проверено 2026-10-04)

**Сделано и проверено:** 1.1–1.4. Тесты — `ExamAudienceResolverTest`, `ExamAccessGuardTest`, `ExamVariantPolicyTest` зелёные в контейнере.
`wp fs-lms exam audience inf_oge --format=count` → `3`, прямой запрос по `student_records`/`groups` (активные, не пробные, предмет `inf_oge`) → `3`.
В сервисах этапа нет `$wpdb`, `get_posts`, хуков и импортов из `Inc\Modules` (единственное упоминание `$wpdb` — комментарий в `ExamAudienceResolver`).
`ExamAccessGuard::canManageEvent()` и `canManageEventGuests()` добавлены на этапах 2.4 и 4.6, как и предписывает 1.2.7.

---

## 1.1 `ExamAudienceResolver`

**Зачем.** Записаться на проведение могут все активные ученики, зачисленные в группы предмета проведения
(`groups.subject_key = event.subject_key`), независимо от преподавателя. Родитель видит проведения своих детей.

**Проверить перед началом**
- `sed -n 60,80p inc/Repositories/WPDBRepositories/GroupsRepository.php` — `findBySubjectKey()`. Посмотреть, отсекает ли метод
  удалённые группы (`deleted_at IS NULL`). Если нет — отсекать в резолвере (`null === $group->deleted_at`), сам метод не менять.
- `sed -n 126,150p inc/Repositories/WPDBRepositories/StudentRecordRepository.php` — `findActiveByGroupId()` возвращает
  `StudentRecordDTO[]` со статусом `Active`; у DTO есть `studentPersonId`, `parentPersonId`, `groupId`, `isTrial`.
- `findActiveByStudent( int $studentPersonId )` — активные записи ученика (нужен для обратного вопроса «какие предметы у ученика»).
- Данные dev: группы `КЕГЭ-1`, `КЕГЭ-2` (`inf_ege`), `КОГЭ-1` (`inf_oge`), `питонтест` (`python`).
- `grep -rn "ExamAudience" inc` — класса `ExamAudienceResolver` ещё нет (энум `ExamAudience` из 0.5 — другое).

**Шаги**
- [x] 1.1.1 Создать `inc/Services/Exam/ExamAudienceResolver.php`. Зависимости конструктора: `GroupsRepository`, `StudentRecordRepository`.
- [x] 1.1.2 Метод `studentPersonIds( string $subjectKey ): array` — уникальные `int` ID учеников:
  группы предмета (без удалённых) → по каждой `findActiveByGroupId()` → собрать `studentPersonId`.
  **Пробные записи (`isTrial === true`) не включать**: пробный доступ — не зачисление.
- [x] 1.1.3 Метод `isEligible( int $personId, string $subjectKey ): bool` — есть ли у ученика активная не пробная запись
  в группе этого предмета. Реализация через `findActiveByStudent( $personId )` + `GroupsRepository::findById()`
  (не перебирать всех учеников предмета).
- [x] 1.1.4 Метод `subjectKeysForStudent( int $personId ): array` — уникальные ключи предметов активных не пробных записей ученика.
  Нужен «Моим экзаменам» (этап 5): ученик видит проведения только этих предметов.
- [x] 1.1.5 Метод `guardianPersonIds( int $studentPersonId, string $subjectKey ): array` — `parentPersonId` из активных записей
  ученика в группах предмета (без нулей и повторов). Родитель получает просмотр и уведомления.
- [x] 1.1.6 Никакого кеша и никакой сохранённой «аудитории»: состав вычисляется при каждом вызове. Поэтому зачисление,
  выбытие и смена группы учитываются сразу. Требование SPEC «новые ученики получают приглашение один раз» закрывается
  на этапе 9 (уведомление с ключом дедупликации), здесь ничего не хранится.
- [x] 1.1.7 Докблок класса: аудитория записи (все ученики групп предмета) и право управления (свои предметы, 1.2) — разные понятия.

**Тесты** — `tests/Unit/Services/Exam/ExamAudienceResolverTest.php` (репозитории — моки):
- `test_student_in_two_groups_of_subject_listed_once`;
- `test_expelled_student_is_not_in_audience` — репозиторий не возвращает запись со статусом не `Active`;
- `test_group_of_other_subject_is_ignored`;
- `test_ege_and_oge_subjects_do_not_mix` — ученик группы `inf_oge` не попадает в аудиторию `inf_ege`;
- `test_trial_record_is_not_eligible`;
- `test_deleted_group_is_ignored`;
- `test_subject_keys_for_student_are_unique`;
- `test_guardians_are_unique_and_non_zero`.

**Готово, когда:** `vendor/bin/phpunit --filter ExamAudienceResolver` зелёный.

---

## 1.2 `ExamAccessGuard` — право на предмет

**Зачем.** Преподаватель управляет проведениями только своих предметов; методист и администратор — любых.
`TeacherSubjectsService::subjectsForUser()` для проверки прав **не годится**: если у преподавателя нет групп,
он возвращает все предметы (это запасной вариант для меню, см. докблок метода).

**Проверить перед началом**
- `sed -n 22,60p inc/Services/Course/TeacherSubjectsService.php` — убедиться в описанном запасном варианте.
- `sed -n 160,185p inc/Repositories/WPDBRepositories/GroupsRepository.php` — `findByTeacherId( int $teacherId )`.
- README §6 — матрица прав.
- `grep -n "ManageSubjects" inc/Enums/Access/UserRole.php` — право раздела «Предметы» есть у методиста и офиса,
  у преподавателя его нет. Используем его как признак глобального охвата по предметам.

**Шаги**
- [x] 1.2.1 Создать `inc/Services/Exam/ExamAccessGuard.php`. Зависимости: `GroupsRepository`.
- [x] 1.2.2 Метод `isGlobal( int $userId ): bool`:
  `user_can( $userId, Capability::ManageExams->value )` **и** (`user_can( …, Capability::Admin->value )` **или** `user_can( …, Capability::ManageSubjects->value )`).
  Офис сюда не попадает: у него нет `ManageExams`.
- [x] 1.2.3 Метод `subjectKeysFor( int $userId ): array` — уникальные `subject_key` групп, где `teacher_id === $userId`
  (без удалённых групп). **Пустой результат остаётся пустым**, без запасного варианта «все предметы».
- [x] 1.2.4 Метод `canManageSubject( int $userId, string $subjectKey ): bool`:
  нет `ManageExams` → `false`; `isGlobal()` → `true`; иначе `in_array( $subjectKey, $this->subjectKeysFor( $userId ), true )`.
- [x] 1.2.5 Метод `manageableSubjectKeys( int $userId, array $allSubjectKeys ): array` — для селектора предмета:
  глобальному пользователю — все переданные ключи, остальным — пересечение с `subjectKeysFor()`.
- [x] 1.2.6 Активная замена преподавателя прав на экзамены **не даёт** (SPEC §3: другой преподаватель — только через
  передачу владения администратором). Записать это в докблоке; `SubstitutionRepository` не подключать.
- [x] 1.2.7 Метод `canManageEvent()` появится на этапе 2.4, когда будет `ExamEventDTO`. Здесь его не добавлять.

**Тесты** — `tests/Unit/Services/Exam/ExamAccessGuardTest.php` (права — `$GLOBALS['_test_user_can'][ $userId ][ $cap ] = true`, заглушка `user_can()` в `tests/bootstrap.php`):
- `test_teacher_manages_only_own_subjects`;
- `test_teacher_without_groups_manages_nothing` — главный тест: нет запасного «все предметы»;
- `test_methodist_is_global`;
- `test_admin_is_global`;
- `test_office_cannot_manage_any_subject` — есть `ManageSubjects`, нет `ManageExams` → `false`;
- `test_user_without_manage_exams_is_denied_even_for_own_group`;
- `test_manageable_subject_keys_intersects_for_teacher`.

**Готово, когда:** `vendor/bin/phpunit --filter ExamAccessGuard` зелёный.

---

## 1.3 `ExamVariantPolicy` — вариант принадлежит предмету

**Зачем.** Вариант проведения (существующая работа-станция) обязан принадлежать предмету проведения и иметь
формат, известный ядру (SPEC §2, §3).

**Проверить перед началом**
- `sed -n 33,62p inc/Managers/Assessment/AssessmentManager.php` — `get( int $assessmentId ): ?AssessmentDTO`;
  у `AssessmentDTO` есть `subjectKey`, `kind`, `status`, `taskIds`.
- `sed -n 140,170p inc/Managers/Assessment/AssessmentManager.php` — `getBankBySubject( string $subjectKey, array $args )`:
  посмотреть формат возвращаемых элементов.
- `inc/Services/Assessment/EgeCompletenessChecker.php` — `validate()` возвращает `EgeCompletenessResult`;
  «работа полна» — `EgeCompletenessResult::isStrictlyComplete()`, текст для автора — `summary()`.
- `AssessmentKind::isStation()` — признак станции.

**Шаги**
- [x] 1.3.1 Создать `inc/Services/Exam/ExamVariantPolicy.php`. Зависимости: `AssessmentManager`, `ExamFormatRegistry`, `EgeCompletenessChecker`.
- [x] 1.3.2 Метод `check( int $assessmentId, string $subjectKey ): ?string` — `null`, если вариант годится, иначе текст причины:
  | Условие | Текст |
  |---|---|
  | работа не найдена | «Вариант не найден.» |
  | `subjectKey` работы ≠ предмету | «Вариант относится к другому предмету.» |
  | `! kind->isStation()` | «Для проведения подходит только работа формата экзамена.» |
  | `formats->for( kind ) === null` | «Формат экзамена недоступен: модуль экзаменов выключен.» |
  | работа не опубликована (`status !== 'publish'`) | «Вариант не опубликован.» |
  | `! validate( … )->isStrictlyComplete()` | «Вариант не укомплектован: » + `summary()` |
- [x] 1.3.3 Метод `assert( int $assessmentId, string $subjectKey ): void` — при непустой причине
  `throw new CodedException( ErrorCode::ExamConflict, $reason )`.
- [x] 1.3.4 Метод `listForSubject( string $subjectKey ): array` — список `array{id:int, title:string, kind:string, direction:string}`
  опубликованных станций предмета, прошедших `check()`. Нужен банку вариантов в календаре (этап 4.2).
- [x] 1.3.5 Добавить класс в README §7.4 (строка «Сервисы»), если его там нет.

**Тесты** — `tests/Unit/Services/Exam/ExamVariantPolicyTest.php`:
- `test_variant_of_same_subject_and_station_kind_passes`;
- `test_variant_of_other_subject_is_rejected`;
- `test_control_kind_is_rejected`;
- `test_rejected_when_format_registry_empty` — фильтр форматов не задан;
- `test_draft_variant_is_rejected`;
- `test_incomplete_variant_is_rejected`;
- `test_assert_throws_coded_exception_with_exam_conflict`.

**Готово, когда:** `vendor/bin/phpunit --filter ExamVariantPolicy` зелёный.

---

## 1.4 WP-CLI: вывести аудиторию предмета

**Зачем.** Проверить резолвер на настоящих данных без интерфейса записи. Эта же команда `ExamCommand`
дальше получит стенд гонок (этап 3.5) и самопроверку репозиториев (этап 2.7).

**Проверить перед началом**
- `inc/Cli/TaskFileSchemeCommand.php` — образец: `implements ServiceInterface`, в `register()` выход, если `WP_CLI` не определён.
- `grep -n "Command::class" inc/Init.php` — где регистрируются CLI-команды.
- Имена в выводе — **только снимок ФИО из `student_records`** (`snapshotLastName`, `snapshotFirstName`), не расшифрованные документы.

**Шаги**
- [x] 1.4.1 Создать `inc/Cli/ExamCommand.php`. Зависимости: `ExamAudienceResolver`, `StudentRecordRepository`, `GroupsRepository`.
  В `register()`: `WP_CLI::add_command( 'fs-lms exam audience', array( $this, 'audience' ) );`
- [x] 1.4.2 Метод `audience( array $args, array $assoc ): void` — позиционный аргумент `<subject_key>`, флаг `[--format=<table|count>]`.
  Вывод таблицей: `person_id`, `ФИО (снимок)`, `группы предмета`. В конце строка «Всего: N».
  Неизвестный предмет (нет групп) — `WP_CLI::warning( 'Групп предмета нет.' )` и «Всего: 0».
- [x] 1.4.3 Докблок метода в формате WP-CLI (`## OPTIONS`, `## EXAMPLES`) — по образцу `TaskFileSchemeCommand`.
- [x] 1.4.4 Добавить `ExamCommand::class` в `Init::getServices()` рядом с другими командами.

**Тесты.** Юнит-теста на команду не нужно (логика в резолвере). Ручная проверка:

```bash
docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp fs-lms exam audience inf_ege
docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp fs-lms exam audience inf_oge --format=count
```

Сверить число с запросом:

```bash
docker exec wp_db mariadb -u root -proot wordpress -e "
SELECT COUNT(DISTINCT sr.student_person_id)
FROM wp_fs_lms_student_records sr JOIN wp_fs_lms_groups g ON g.id = sr.group_id
WHERE g.subject_key = 'inf_ege' AND sr.status = 'active' AND g.deleted_at IS NULL;"
```

Имя колонки пробной записи и значение активного статуса уточнить: `SHOW COLUMNS FROM wp_fs_lms_student_records`,
`inc/Enums/Enrollment/EnrollmentStatus.php`. Если пробные записи есть — добавить в запрос условие по этой колонке.

**Готово, когда:** числа команды и запроса совпадают для `inf_ege` и `inf_oge`; ученик `inf_oge` отсутствует в выводе `inf_ege`.

---

## Проверка этапа

- [x] `vendor/bin/phpunit --filter "ExamAudienceResolver|ExamAccessGuard|ExamVariantPolicy"` зелёный. — зелёный (полный прогон в контейнере)
- [x] `npm run ci` зелёный. — по частям (2026-10-04): `eslint .` и `stylelint` без ошибок, `gulp styles:check` и `gulp build` успешны, PHPUnit в контейнере 2727 тестов без падений, `npm run test:js` 79 тестов; целиком `npm run ci` на Windows-хосте не идёт: `npm test` вызывает `vendor/bin/phpunit`, который хост не запускает
- [x] WP-CLI выводит аудиторию предмета, число совпадает с прямым запросом. — `inf_oge`: 3 = прямой запрос
- [x] В новых сервисах нет `$wpdb`, `get_posts`, хуков и импортов из `Inc\Modules`.
