# Этап 0. Швы ядра

Зависимости: нет (ветка уже на `master` 1.1.85).
Результат: семь универсальных швов Ш1–Ш7, компонент `.s-banner`, мелкие правки ядра и тесты ядра «без подписчиков поведение
прежнее». Модуля ещё нет; в ядре нет слова «мастер-класс».

Перед началом прочитать `README.md`, `../SPEC.md` §10–§11, `../CONTRACTS.md` §2.

**Порядок:** 0.1 → 0.2 → 0.3 → 0.4 → 0.5 → 0.6 → 0.7 → 0.8 → 0.9. Ш1 первой: от неё зависит вся заморозка и самый широкий тест
(доступ ко всем занятиям всех учеников).

## Статус

Не начат.

---

## 0.1 Исходная точка

- [ ] `grep -rni masterclass inc` — пусто (фиксируем «до»); `git status` чист; `php vendor/bin/phpunit` зелёный до правок (запомнить число тестов).
- [ ] Номер версии релиза — вопрос владельцу (`README.md`); пока работаем без бампа версии.

**Готово, когда:** базовый прогон PHPUnit зелёный, число тестов записано здесь.

## 0.2 Ш1: `fs_lms_lesson_access`

**Зачем.** Заморозка мастер-класса — понижение уровня доступа, поэтому она проходит через единственное место решения о доступе.

- [ ] `Enums\Access\AccessLevel::rank(): int` (`None` 0 < `Read` 1 < `ReadSubmit` 2).
- [ ] `LessonAccessPolicy::resolve()` → `resolveBase()` + `apply_filters( 'fs_lms_lesson_access', $base, $record, $lesson )`;
  результат принимается, только если это `AccessLevel` и **не выше** базового. Докблок с контрактом шва.
- [ ] Тесты: без подписчика — прежняя матрица `LessonAccessPolicyTest`; подписчик понижает; попытка повысить игнорируется;
  фильтр вернул не `AccessLevel` — игнорируется.
- [ ] **Закрепить предусловие `SPEC.md` §11 п.2:** с подписчиком, понижающим до `None`, `SubmitTaskAnswerCallbacks` и
  `LessonPlayerCallbacks::ajaxMarkStepProgress()` отвечают отказом (`canRead`/`canSubmit`/`visibleLessonsForStudent` стоят на `resolve()`).
  Если какой-то путь записи ученика в обход — доработать гейт и записать сюда.

**Готово, когда:** тесты Ш1 зелёные, весь `LessonAccessPolicyTest` и тесты гейтов без изменений.

## 0.3 Ш2: плеер + `.s-banner` + иконки

- [ ] `LessonPlayerController` отдаёт шаблону `$userId` и `$row`; в `templates/frontend/lesson-player/player.php`, **только в виде ученика**
  (`! $isTeacher && ! $isParent`, в предпросмотре не вызываются): `fs_lms_player_back_url` (пустая строка — `.s-back` не выводится),
  `fs_lms_player_lock_html` (непустая заменяет стандартные ветки `.lock-modal`, иконка остаётся), экшены `fs_lms_player_header_actions`,
  `fs_lms_player_before_content`, `fs_lms_player_footer` — места по `CONTRACTS.md` §2.
- [ ] `.s-banner` (`.s-banner__text`, `.s-banner__actions`) в `src/scss/player/components/_shell.scss` — только токены.
- [ ] `Enums\Ui\Icon`: `Phone`, `Message`, `Logout` (+ SVG, цвет `currentColor`).
- [ ] Тесты: без подписчиков разметка плеера для ученика не меняется; у учителя и родителя хуки не вызываются.

**Готово, когда:** тесты зелёные, `npx stylelint` и `npx gulp build` чисты, `git diff` разметки плеера без подписчиков пуст.

## 0.4 Ш3: `fs_lms_group_deleting`

- [ ] `GroupDeletionHandler::handle()` — первой строкой, до транзакции; исключение подписчика ловится (`try/catch` + `PluginLogger::exception`)
  и удаление группы не останавливает.
- [ ] Тесты: подписчик вызван с `groupId`/`actorId`; его исключение не мешает удалению; без подписчика поведение прежнее.

**Готово, когда:** тесты зелёные.

## 0.5 Ш4: служебные группы

- [ ] `Services\Group\ServiceGroupResolver` (`ids()` с кешем на запрос, `isService( int )`), фильтр `fs_lms_service_group_ids`.
- [ ] Потребители: `EnrollmentLifecycleCallbacks::ajaxGetStudentGroups()` (исключить); **`OpenGroupEnrollmentService::enrollableGroup()`**
  (общая проверка `enrollMany()` и `planFromSubject()`) → `\InvalidArgumentException( 'В служебную группу учеников не добавляют.' )`;
  `StudentsExportProvider::rows()` (ученик, у которого все записи в служебных группах, пропускается).
- [ ] **`grep` остальных выборов группы для зачисления/добавления** (`findByPeriodAndSubject`, `findByFilters`, список открытых групп в
  «Добавить в открытую группу», пикер групп кабинета) и решение по каждому — **записать сюда таблицей**: «место → служебные скрываем / не нужно, почему».
- [ ] Тесты: без подписчиков списки прежние; со служебной группой — исключена и из списка, и из `enrollMany()`/`planFromSubject()`, и из выгрузки.

**Готово, когда:** таблица решений заполнена, тесты зелёные.

## 0.6 Ш5, Ш6

- [ ] Ш5: `ApplyPageController::renderApplyForm()` — `$prefill = (array) apply_filters( 'fs_lms_apply_prefill', $prefill ?? array() )` после
  разбора ссылки гостя экзамена; ключи — те, что читает `templates/frontend/apply-fields.php`. Тест: без подписчиков прежнее.
- [ ] Ш6: `CaptchaService::check()` — перед `allowCaptchaFallback()`: при `$failure?->allowsFallback()` и `true === apply_filters(
  'fs_lms_captcha_fallback_unlimited', false, $scope, (string) $identifier, $remoteIp )` лимит по IP не применяется; запись
  `AuthAction::CaptchaFallback` остаётся (`note` — «без лимита по правилу модуля»). Тесты: без подписчика лимит прежний; с подписчиком
  лимит снят **только** для `Login`-скоупа и разрешающего сбоя.

**Готово, когда:** тесты зелёные.

## 0.7 Ш7: `findActiveTrials()`

- [ ] `AND EXISTS ( SELECT 1 FROM {applications} a WHERE a.student_person_id = r.student_person_id )` (`TableName::Applications`).
- [ ] **Тест на MariaDB:** временная запись без заявки плитку «ждём анкету родителя» не получает; с заявкой — получает.

**Готово, когда:** тест на `wp_db` зелёный, `NotificationCronService::trialAccess()` без других изменений.

## 0.8 Мелкие правки ядра

- [ ] `OpenCourseValidator::lessonProblems( LessonDTO ): array` (тело цикла `problems()`), `problems()` зовёт его. Поведение прежнее.
- [ ] `PasswordGeneratorService::generateReadable( int $length = 8 ): string` — алфавит без `0 O 1 l I`, `random_int`. Тест: длина, алфавит,
  разные значения за серию.

**Готово, когда:** тесты `OpenCourseValidatorTest` без правок зелёные + новые.

## 0.9 Граница модуля

- [ ] Тест-сканер: в `inc/` (кроме `inc/Modules/Masterclass/`) нет `Masterclass` / `masterclass` (образец — `tests/js/profile-no-native-select.test.mjs`,
  но на PHP/PHPUnit).

**Готово, когда:** сканер зелёный, и красный, если подложить слово в файл ядра (проверить вручную один раз).

---

## Проверки этапа

PHPUnit полностью; DI-загрузка; PHPStan уровня 5 по правленым файлам; `npx eslint .`; `npx stylelint "src/scss/**/*.scss"`; `npx gulp build`;
п. 0.7 — на `wp_db`; `grep -rni masterclass inc` пуст. Затем **переписать этап 1** по факту.
