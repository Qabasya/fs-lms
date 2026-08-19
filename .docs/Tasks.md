# Bug fix

## КТП: переполнение периода, ручное распределение и урок вне расписания

### Что выяснено (проверено зондом на `FakeWpdb`, не рассуждением)

**Раскладка живёт в двух местах.** `SessionCalendarService::generate()` разворачивает
`group.meetings[] × [period.start..period.end] − holidays[]` в упорядоченный список слотов;
`GroupLessonRepository::applySlots()` идёт по строкам программы **в порядке `position`** и
выдаёт им слоты по курсору `$i`. Пиннутые и индивидуальные строки пропускаются, `held`
съедает слот, `cancelled`/`moved` освобождает.

**1. Уроков больше, чем слотов — наполовину работает.**
`applySlots()` на исчерпании слотов делает `break`. Строки без даты остаются с
`scheduled_at = NULL` → попадают в пул «Темы курса», счётчик в шапке показывает
`72 / 80 распределено`. **Это верное поведение.**

Но `break` не трогает строки, у которых дата **уже была**. Зонд:

```
4 урока, 2 слота, у уроков 3–4 даты с прошлой раскладки (период укоротили)
  UPDATE id=1 → '2026-09-01 10:00:00'
  UPDATE id=2 → '2026-09-03 10:00:00'
  всего UPDATE: 2 — строки 3 и 4 не тронуты, старые даты остались
```

То есть после сокращения периода (сдвинули `end_date`, убрали день из `meetings`, добавили
праздники) хвост висит на датах **вне нового периода**: в пул не вернулся, в календаре не
виден (месяц за границей), в счётчике числится распределённым.

Плюс предупреждение о переполнении уходит только в `PluginLogger::warning` →
`debug.log`. Преподаватель его не увидит никогда.

**2. Ручное распределение ломает порядок — подтверждено.**
`ScheduleReflowService::pinToDate()` пишет `scheduled_at` + `is_pinned`, но **не трогает
`position`**, а `applySlots()` ходит именно по `position`. Курсор-догон
(`while ($slots[$i] <= $row->scheduledAt) ++$i`) срабатывает только когда обход **дошёл**
до пиннутой строки — а к этому моменту курсор уже ушёл вперёд. Зонд:

```
урок #5 (position 4) перетащили на 2026-09-03 — дату 2-го слота
  урок #1 → 2026-09-01
  урок #2 → 2026-09-03   ← коллизия
  урок #3 → 2026-09-05
  урок #4 → 2026-09-08
  урок #5 → 2026-09-03   (закреплён)
```

Два урока на 03.09, а после закреплённого идут #3 и #4 вместо #6, #7. Ожидание
«поставил на место 9 → дальше 10, 11» не выполняется. Существующий тест
`test_apply_slots_distributes_after_pinned_first_lesson` этот случай не ловит: он пиннит
строку с `position 0`, где курсор ещё не успел уйти.

**Побочный баг там же:** `pinToDate()` зовёт
`updateSchedule($id, $scheduledAt, $row->teacherUserId)` — четвёртый аргумент `$endsAt`
по умолчанию `null`, то есть **затирает `ends_at`**. Восстановить некому: `applySlots()`
пиннутые строки пропускает. Дальше страдают проверка занятости кабинета
(`assertRoomFree()` падает на фолбэк «+60 минут»), время в ячейке календаря и подбор
записи занятия по временному окну в VideoLibrary.

**3. Урока вне расписания сейчас нет.** `attachDrop()` в `src/js/profile/ktp.js` вешается
только на `.kal-cell[data-lesson="1"]`, а время берёт из `lessonTimes[day]` и без него
отказывается закреплять. Механика для фичи, однако, уже есть целиком:
`LessonVisibilityService::effectiveVisibility()` открывает урок ученикам, как только
наступил `scheduled_at`. То есть «урок вне расписания» — это обычная строка с датой на дне,
которого нет в `meetings`, и `is_pinned = 1` (пиннутые слот не потребляют, последовательность
не сдвигают).

---

### Этап 1. Хвост за пределами периода возвращается в пул

- [x] `GroupLessonRepository::applySlots()` — заменить `break` на проход до конца: строкам,
      которым слот не достался, ставить `scheduled_at = NULL`, `ends_at = NULL`,
      `room_id = NULL`, `is_pinned = 0`. Не трогать `held` (исторический факт) и
      индивидуальные
- [x] Тест: «слотов меньше, чем строк — хвост обнуляется, а не сохраняет старую дату»
- [x] Тест: «`held` за пределами слотов сохраняет дату»

### Этап 2. Переполнение видно преподавателю

- [x] `SessionCalendarService::reflow()` — возвращать не только `int $conflicts`, а структуру
      `{conflicts:int, slots:int, consuming:int, unplaced:int}`. Сейчас сигнатура `int`,
      её читает `ScheduleReflowService::reflow()` → `ajaxReflowSchedule()` → `doReflow()`
      (реализовано как `ScheduleReflowResultDTO` + read-only `completeness()` для баннера)
- [x] `GroupCalendarService::getCalendar()` — добавить в payload `slots_total` и `unplaced`
      (считается там же, где `periodMeta`), чтобы баннер жил и после перезагрузки страницы,
      а не только в тосте после нажатия «Распределить»
- [x] `src/js/profile/ktp.js` — баннер над календарём: «В периоде 72 занятия, в курсе 80 тем.
      8 тем не помещаются — их можно открыть только вне расписания». Формулировка именно
      такая: перетаскивание на день со слотом лишь меняет, какая тема размещена (этап 3),
      общее число не растёт. Тост `doReflow()` дополнить тем же числом
- [x] Стиль баннера — в `src/scss/profile/components/`, токенами, без инлайна

### Этап 3. Перетаскивание строго закрепляет тему, вытесняя занятую дату

**Решение (принято):** drop = «эта тема строго на эту дату». Тема, которая стояла на дате,
возвращается в пул «Темы курса» (без даты, без закрепления). Все остальные размещённые темы
дат **не меняют** — никакого каскадного сдвига. Ожидание «после 9-го идут 10, 11» выполняется
именно так: уроки 10 и 11 остаются на своих датах, а вытесненный 9-й уходит в пул.

`position` при этом не трогаем: последовательность курса — это порядок тем, а не порядок дат.

**Одна тема на день — сквозное правило.** Замена работает на ЛЮБОМ дне: и на дне со слотом,
и на дне вне расписания (этап 4). Двух тем в один день быть не должно нигде — стека нет.
Из этого следует, что перетаскивание на день со слотом никогда не увеличивает число
размещённых тем, а только меняет, какая именно тема размещена. Поэтому **«лишние» темы при
переполнении периода (этап 1) размещаются исключительно вне расписания.**

> Последствие: `T12.5` («на один день может быть две и более тем одной группы») этим правилом
> отменяется. Рендер `byDate` в `renderCalendar()` стек всё ещё умеет — решить, оставить его
> защитным (данные с прошлых версий) или убрать вместе с правилом.

- [x] **Убрать `$this->calendar->reflow()` из `ScheduleReflowService::pinToDate()`.** Это
      корень проблемы: reflow перекладывает ВСЕ непиннутые строки от начала периода, из-за
      чего дата закреплённой темы дублируется у той, что стоит на этом слоте по порядку.
      После правки drop меняет ровно две строки — перетаскиваемую и вытесненную
- [x] `pinToDate()` — найти строки группы с тем же днём (`DATE(scheduled_at)`, исключая саму
      перетаскиваемую и индивидуальные) и снять с них дату: `scheduled_at = NULL`,
      `ends_at = NULL`, `is_pinned = 0`. Нужен метод репозитория — `listByGroupAndDay()`
      уже есть, дополнить обнулением. Правило действует на любом дне, слот там есть или нет
      (реализовано новым `GroupLessonRepository::clearSchedule()`, переиспользован и в Этапе 1)
- [x] **Не вытеснять `held`**: проведённое занятие — исторический факт. Если на дате стоит
      `held`, drop отклоняется с внятным сообщением, а не молча стирает факт
- [x] **Починить `ends_at`**: `pinToDate()` обязан передавать четвёртый аргумент
      `updateSchedule()` — конец слота из `SessionCalendarService::generate()`, а для дня без
      слота (этап 4) — начало плюс длительность из `meetings` (при разнобое — длительность
      ближайшей встречи). Сейчас аргумент не передаётся и `ends_at` затирается в NULL
      (реализовано как приватный `resolveEndsAt()`)
- [x] Продолжения темы (`continuedFromId`, T12.6) — вытеснение исходной части не должно
      осиротить вторую: решить, вытесняются обе или drop на дату части запрещён
      (решение: вытесняются обе вместе)
- [x] `src/js/profile/ktp.js` — тост после drop должен называть вытесненную тему:
      «Тема N закреплена на 1 ноября · тема M возвращена в пул»
- [x] Тест: «drop на занятую дату — вытесненная строка обнулена, остальные даты не тронуты»
- [x] Тест: «drop на дату с `held` отклоняется»
- [x] Тест: «`ends_at` после `pinToDate()` не пустой»
- [x] Регресс-тест на зонд из разбора: перетаскивание урока #5 на дату 2-го слота больше не
      даёт двух уроков на одну дату

### Этап 4. Урок вне расписания

Единственный способ разместить темы, не поместившиеся в период: на дне со слотом замена
(этап 3) общее число размещённых тем не меняет.

- [x] `src/js/profile/ktp.js` — `attachDrop()` вешать на **все** ячейки периода, кроме
      выходных (`holiday`), а не только на `[data-lesson="1"]`
- [x] День со слотом: время берётся из слота (`lessonTimes`), модалки нет. Если тема на дне
      уже стоит — замена по правилу этапа 3
- [x] День без слота: модалка-предупреждение «В этот день занятия нет. Урок просто откроется
      ученикам — выберите время» + выбор времени (дефолт — время ближайшей встречи из
      `meetings`) и длительности. Компонент — рядом с `ktp-individual.js`, паттерн
      `indi-modal.js`. Если тема на этом дне уже стоит — та же замена, а не вторая тема
      (реализовано как `ktp-offschedule-modal.js`)
- [x] Бэкенд: `ajaxPinLesson()` принимает необязательные `ends_at`/`duration_min`;
      `pinToDate()` пишет `is_pinned = 1` — такая строка слот не потребляет и
      последовательность не двигает (duration_min не понадобился — модалка сама считает
      `ends_at` из даты+времени, как в `indi-modal.js`)
- [x] Валидация на сервере: дата внутри периода, день не выходной, кабинет свободен
      (`assertRoomFree()` уже есть), время не пересекается с другим занятием группы в этот день
      (реализовано `assertWithinPeriod()` + overlap-check с индивидуальными занятиями дня —
      групповые того же дня всё равно вытесняются правилом этапа 3)
- [x] Отдавать признак «вне расписания» в `getCalendar()` (`off_schedule: scheduled_at есть,
      дня нет в lessonDays`) и помечать карточку в календаре — иначе такой урок неотличим
      от планового
- [x] Проверить `LessonVisibilityService::effectiveVisibility()`: урок вне расписания должен
      открыться ученикам ровно в назначенное время, отдельной ветки не требуется — убедиться
      тестом, а не предположением (подтверждено — метод решает только по `scheduledAt`,
      о расписании группы вообще не знает)
- [x] Журнал и посещаемость: убедиться, что занятие вне расписания попадает в
      `JournalService` и `AttendanceService` наравне с плановым (подтверждено — обе строятся
      по `group_lesson_id`/`scheduledAt`, без фильтра по дню недели)

### Этап 5. Уведомление «Открыт урок»

Сейчас в `NotificationType` десять типов, и ни один не сообщает об открытии урока. Для урока
вне расписания (этап 4) это критично: занятия в этот день нет, ученик о новом уроке не узнает
никак. Момент открытия уже определён — `LessonVisibilityService::effectiveVisibility()`
переводит `hidden` → `open`, как только наступил `scheduled_at`.

- [x] `Inc\Enums\Profile\NotificationType` — кейс `LessonOpened = 'lesson_opened'`,
      `title()` → «Открыт новый урок», `tone()` → `info`
- [x] Текст плитки — в `NotificationService::toClientArray()`, рядом с остальными
      (тема + название группы, как у `VideoUploaded`)
- [x] Иконка — `src/js/common/icons.js` (`icoEye`, зеркало в `notifications.js:TYPE_ICON`).
      `Inc\Enums\Ui\Icon` не задействован — по прецеденту у остальных 10 типов уведомлений
      (icон выбирается только на клиенте по `type`, сервер PHP-иконку не рендерит)
- [x] **Продюсер — по крону, а не по событию.** `effectiveVisibility()` — ленивый расчёт при
      чтении, момента перехода как события не существует: он «наступает» у каждого читателя
      свой. Ставить уведомление туда нельзя — плитка родится при первом заходе кого угодно
      в кабинет, а у кого не зашёл — не родится вовсе. Правильное место —
      `NotificationCronService::tick()`, рядом с `LessonSoon`: выбрать строки, у которых
      `scheduled_at` уже прошёл, `visibility = hidden` (то есть открылись лениво), и
      разослать ученикам занятия. Дедуп-ключ `opened:{group_lesson_id}` делает повтор
      невозможным (реализовано новым `GroupLessonRepository::listRecentlyOpened()`,
      окно поиска — 24 часа назад, устойчиво к пропущенным тикам)
- [x] Получатели — `NotificationService::lessonStudentUserIds()` (уже есть, учитывает
      индивидуальные занятия)
- [x] Ссылка плитки — `PageRoutes::LessonPlayer->lessonUrl()`, как у `VideoUploaded`
- [x] **Не дублировать `LessonSoon`**: если занятие плановое, ученик за 30 минут уже получил
      «Занятие через 30 минут». Слать `LessonOpened` только для уроков вне расписания
      (признак `off_schedule` из этапа 4) либо развести по времени — решить при реализации
      (решение: `NotificationCronService::isOffSchedule()` пересчитывает флаг тем же способом,
      что и `GroupCalendarService`, через `SessionCalendarService::periodMeta()->lessonDays`)
- [x] Тест: «урок вне расписания даёт ровно одну плитку `lesson_opened` при повторных тиках»

---

# Подготовка к релизу 1.0.0

## Контекст

Плагин достиг финального состояния по фичам. Дальше **не добавляются**: витрины курсов,
мобильное приложение, Telegram-интеграция, внешние (свободные) преподаватели и ученики.
Предметы — только информатика: ЕГЭ, ОГЭ, Программирование на Python, Робототехника Ардуино.
От унификации под произвольные школьные предметы отказываемся, но **сама механика
произвольного предмета остаётся** — на ней стоят все четыре (свой ключ, свои таксономии,
`hasBank = false` для Python/Ардуино без банка заданий).

Нужно две вещи. Первая — вычистить код от того, что дальше не дописывается: догоняющие
data-миграции, одноразовые бэкфиллы, мёртвые ключи и швы под несуществующие фичи. Вторая —
завести раннер, который по тегу собирает устанавливаемый ZIP без исходников, тестов и
документации, прогнав перед этим линтеры и тесты.

Итог: тег `v1.0.0` → в GitHub Release лежит `fs-lms-1.0.0.zip`, который ставится на чистый
WordPress и работает.

---

## Зафиксированные решения

| Вопрос | Решение |
|---|---|
| Боевая установка | Нет, ставим с нуля → цепочку версий схемы можно схлопнуть |
| Одноразовые WP-CLI | Удалить (`task-bundle migrate`, `article reslug`) |
| `OptionName::Periods`, `AuthGroups` | Удалить (мёртвые; живой справочник — `AcademicPeriods`) |
| Неотправляемые типы писем | Удалить 4 из 7 |
| Модуль SocialAuth | Снести целиком, страницу входа перенести в ядро |
| Пакет переноса предмета (Bundle) | Оставить |
| Типы задач и шагов | Не трогать |
| Сборка релиза | Тег `v*` → ZIP ассетом GitHub Release |
| `/assets/`, `/vendor/` | Остаются в `.gitignore`, собираются в раннере |

Почему сборка в раннере, а не коммит `/assets/`: коммиченный билд протухает молча — этот
случай уже записан в `gulpfile.js:108` («так `frontend.min.css` неделями собирался из старого
кода при сломанном SCSS»). Плюс минифицированный JS в одну строку на 240 КБ даёт конфликт
слияния на каждой ветке. Дрейф версий закрывается тем, что уже есть: `npm ci` +
`package-lock.json`, `composer install` + `composer.lock`, пин `node-version: '20'`.
Плата: плагин больше нельзя ставить `git clone`'ом в `wp-content/plugins` — только из ZIP.

---

## Этап 0. Эталон схемы (делается ПЕРВЫМ, до любых правок)

Текущая dev-БД — единственный носитель полной схемы: она получила `Migration_1_0_0` + блок
`Cleanup` (~35 `ALTER`) + `Migration_1_1_0` + `AssessmentAnswerUniqueMigration`. Схлопывание
миграций — это перенос результата всех этих `ALTER` внутрь `CREATE TABLE`, и сверять его надо
с фактом, а не с чтением кода.

- [ ] Снять `SHOW CREATE TABLE` со всех `wp_fs_lms_*` в `schema-before.sql`

```bash
docker exec wp_db mariadb -u root -proot wordpress -e "SHOW TABLES LIKE 'wp_fs_lms%'"
# для каждой: SHOW CREATE TABLE
```

Эталон — 25 core-таблиц. `wp_fs_lms_ad_outbox` и `wp_fs_lms_video_recordings` в него не входят:
их заводят сами модули (`AdSync/Schema/AdSchema`, `VideoLibrary/Schema/VideoSchema`).

---

## Этап 1. Схема: одна миграция вместо цепочки

**`inc/Migrations/Migration_1_0_0.php`**

- [ ] Убрать блок «Сброс старой схемы» (строки 48–62): дропает `fs_lms_expelled_archive`,
      `fs_lms_relationships`, `fs_lms_enrollments`, `fs_lms_archive` и чистит
      `fs_lms_student_group_matrix` — на чистой установке этого нет никогда
- [ ] Влить блок «Cleanup — добавление колонок для уже существующих установок»
      (строки 617–681) внутрь соответствующих `CREATE TABLE`. Затрагивает:
      `student_records` (6 snapshot-колонок + `enrolled_by_user_id`),
      `groups` (`course_id`, `meetings`, `room_id`, `program_locked_at`, `access_mode`,
      без `group_id`),
      `group_lessons` (`ends_at`, `is_pinned`, `label`, `step_settings_overrides`, `kind`,
      `status`, `student_person_id`, `room_id`, `work_deadlines`, `continued_from_id`,
      `lesson_id` nullable),
      `assessment_attempts` (`group_lesson_id`),
      `assessment_answers` (`grader_note`, `criteria_scores`),
      `lesson_progress` / `submissions` (полный набор значений `enum`),
      `pii_access_log`, `export_log`, `email_log`, `applications`, `consent_change_log`,
      `entity_audit_log`, `persons` (`expelled_at` + индекс).
      **Определения брать из `schema-before.sql`, а не выводить вручную** — там уже финал
- [ ] Влить `UNIQUE KEY attempt_task (attempt_id, task_id)` прямо в `CREATE TABLE`
      `assessment_answers` (секция 19)
- [ ] Влить `CREATE TABLE notifications` из `Migration_1_1_0` как секцию 25
- [ ] Удалить приватные хелперы `addColumn()` / `dropColumn()` / `dropIndex()` / `addIndex()` /
      `hasColumn()` — после вливания они не вызываются
- [ ] Проверить, что список в `down()` полон (25 таблиц, `Notifications` уже есть)
- [ ] Обновить докблок класса: он до сих пор описывает снятые `enrollments` / `archive` /
      `deletion_log`
- [ ] Удалить `inc/Migrations/Migration_1_1_0.php`

`MigrationRunner` и `MigrationInterface` **оставить**: это штатный способ довезти DDL до
установок в будущих патч-релизах (см. скилл `db-migrations`).

---

## Этап 2. Догоняющие data-миграции → в основной код

Все четыре существуют, чтобы починить уже задеплоенные dev-установки. На установке с нуля их
гейт-опция просто ставится, а работы нет.

| Класс | Что с ним | Куда переезжает результат |
|---|---|---|
| `BroadcastStepMigration` | Удалить | Ничего не нужно: `StepType::Broadcast` уже в энуме, `recording_slot`-данных на чистой БД нет |
| `ArticlesSectionMigration` | Удалить | Ничего: `SubjectPageType::Articles` уже `'articles'` |
| `AssessmentAnswerUniqueMigration` | Удалить | Ключ уехал в DDL (этап 1) |
| `RoutingPagesMigration` | Удалить класс | `Activate::generatePages()` — заменить `createPageIfNeeded()` на `ensurePublished()` (восстанавливает удалённую/черновиковую страницу, а не только создаёт отсутствующую) и добавить туда `/sign-in/` |

- [ ] Удалить четыре класса миграций
- [ ] `Activate::generatePages()` — перевести на `ensurePublished()`, добавить `/sign-in/`
- [ ] `inc/Init.php` — вырезать блок строк 252–288 (`BroadcastStepMigration::ensure()`,
      регистрация и `run()` `MigrationRunner`, `ArticlesSectionMigration`,
      `AssessmentAnswerUniqueMigration`, `add_action('init', RoutingPagesMigration)`)
      и соответствующие `use`. Побочный эффект — минус 4 `get_option()` на каждом запросе;
      миграции остаются только в `Activate::activate()`
- [ ] `SubjectLandingController::redirectLegacySection()` (редирект `/textbook/` →
      `/articles/`) — проверить и удалить, если он существует только ради переехавших установок

---

## Этап 3. Снос SocialAuth, страница входа — в ядро

Модуль по умолчанию **выключен** (`ModuleConfig::isEnabled()` → `?? false`), а страница
`/sign-in/` живёт внутри него. То есть на чистой установке `Activate` создаёт страницу с
шорткодом `fs_lms_login_form`, но регистрировать шорткод некому — страница пустая, редиректа
с `wp-login.php` нет. Перенос в ядро чинит это, а не украшает.

- [x] Создан `inc/Controllers/Person/AuthPageController.php` (ядро, в `Init::getServices()`) —
      `add_shortcode(ShortCode::LoginForm)`, `redirectToCustomLogin()` (перехват `wp-login.php`),
      `redirectFailedLogin()` на `wp_login_failed` с приоритетом 20 (после `AuthLogController`),
      `forceCleanAuthLayout()` через `template_include`
- [x] `templates/frontend/auth-page.php` — блок провайдеров и закомментированная регистрация
      преподавателя убраны; вместо `<a href="#">` футер ведёт на страницу согласия
      (`ConsentDefinitionsRepository`, ключ `pd_processing`) и целиком скрывается, если
      согласие не заведено
- [x] `src/scss/frontend/components/_auth-page.scss` — сняты `&__divider`, `&__socials`,
      `&__btn-social`, `.fs-social-icon`
- [x] Удалён `inc/Modules/SocialAuth/` целиком (18 файлов)
- [x] Удалён `SocialAuthModule` из `Init::getServices()` + `use`
- [x] Удалён `OptionName::AuthSettings`; заодно снят осиротевший
      `UserRepository::getBySocialId()`
- [x] `hybridauth/hybridauth` убран из `composer.json`, `composer.lock` пересобран
- [x] Удалены `provider-logo.php` и секции google/vk/github из `help-modal.php` (модалка
      осталась — её используют DaData и SmartCaptcha); удалён
      `src/js/admin/services/settings/auth-settings.js` и его вызов в `admin.js`
- [x] Упоминания SocialAuth / `AuthStrategyInterface` вычищены из `CLAUDE.md`
- [x] `RoutingPagesMigration` — добавлен `/sign-in/` (страницей больше не владеет модуль),
      `VERSION` поднята до `'2'`. Класс всё равно уходит на этапе 2 — правка на время
      до него

---

## Этап 4. Свободные роли Student / Teacher

`UserRole::Teacher` (`lms_teacher_free`) уже мёртв. `UserRole::Student` (`lms_student_free`)
держится на трёх точках, все три уходят вместе с SocialAuth или переписываются.

- [ ] `inc/Enums/Access/UserRole.php` — снять кейсы `Student` и `Teacher`, за ними строки в
      `label()`, `baseCapabilities()`, `capabilities()`
- [ ] Разобрать фолбэки: `primary()` возвращает `self::Student`, когда ни один слаг не совпал →
      сменить на `?self` с `null` (и поправить вызывающих) либо на `FSStudent`. Выбрать по
      вызывающим: `primaryForCabinet()`, `primarySlug()`, `UserDTO::fromArray()` (строка 77 —
      `?? UserRole::Student`)
- [ ] `frontCabinetRoles()` — убрать `Student`; порядок приоритета в `primary()` — убрать оба
      хвостовых кейса
- [ ] `inc/Services/Profile/ProfileViewResolver.php` — строки 84 и 111, `UserRole::Student` из
      обоих `in_array`
- [ ] `inc/Managers/Person/RoleManager.php` — в `registerAll()` добавить `remove_role()` для
      `lms_student_free` и `lms_teacher_free`: на dev-БД роли уже созданы и сами не исчезнут
- [ ] Поднять `$capsVersion` в `Init.php:245` с `'5.3'` до `'5.4'`, иначе пересинхронизация не
      запустится
- [ ] `src/js/profile/app.js` — строка 60 (`lms_student_free: 'Ученик'`) и строка 207
      (`hideRole`-список)
- [ ] Тесты: `tests/Unit/Enums/UserRoleTest.php` (строки 29, 33),
      `tests/Unit/Services/Profile/ProfileViewResolverTest.php` (строка 51)

---

## Этап 5. Мёртвый код и ключи

**Блокер, чинить обязательно:** `uninstall.php:47` обращается к
`Capability::ManageLMSAssignments` — такого кейса в энуме нет (там 14 других). Удаление
плагина из админки падает фаталом.

- [x] `uninstall.php` переписан. Список прав больше не хардкодится: `RoleManager::purgeAdminCaps()`
      собирает его из `Capability::cases()` + производных прав CPT — из тех же источников, что и
      выдача, поэтому разойтись снова не может. `manage_options` и `manage_categories` исключены
      явно (штатные права WP, плагин их не выдавал). Таблицы сносятся по префиксу
      `{prefix}fs_lms_%`, а не перечислением: старый список из `Migration_1_0_0::down()`
      оставлял в базе `fs_lms_ad_outbox` и `fs_lms_video_recordings` (таблицы модулей)

**Энумы:**

- [ ] `OptionName::Periods` (`fs_lms_periods_list`) — дубль-пустышка; живой ключ —
      `AcademicPeriods` (`fs_lms_academic_periods`), его читает `AcademicPeriodRepository`
- [ ] `OptionName::AuthGroups` (`fs_lms_auth_group`)
- [ ] `EmailTemplateType::PasswordSetup`, `ApplicationConfirmation`, `ApplicationReady`,
      `Rejection` — редактируются во вкладке «Шаблоны писем», но ни одно письмо этих типов не
      отправляется (`EmailService` шлёт только `WelcomeWithCredentials`, `CourseGranted`,
      `OtpCode`). Снять кейсы и строки в `label()`; проверить вкладку настроек и
      `EmailTemplateSettingsCallbacks`
- [ ] `ShortCode::RegisterForm` (`fs_lms_register_form`)
- [ ] `ShortCode::GroupLessons` (`fs_lms_group_lessons`, остаток снятого кокпита группы)
- [ ] `PageRoutes::ConsentPage` (`'consent'`) — проверить `templates/frontend/consent-page.php`:
      если страница рендерится по другому маршруту, кейс мёртв
- [x] `Inc\Enums\Course\LessonKind` — протащен в `GroupLessonDTO` и `GroupLessonInputDTO`
      (было: `public string $kind = 'group'`). 23 файла: сравнения `'individual' === $row->kind`
      заменены на `$row->kind->isIndividual()`, два SQL-литерала в `GroupLessonRepository`
      (`unscheduleAll`, `listIndividualByTeacherAndDay`) — на `%s` + `LessonKind::Individual->value`,
      выходные массивы отдают `->value`. JSON-контракт для JS не изменился

**Классы:**

- [ ] `inc/Cli/TaskBundleMigrationCommand.php` + `inc/Services/Task/TaskBundleMigrationPlanner.php`
      + `inc/DTO/Task/TaskBundleReferenceChangeDTO.php` (проверить, что DTO больше нигде не нужен)
- [ ] `inc/Cli/ArticleSlugCommand.php`
- [ ] Обе команды — из `Init::getServices()` (`Init.php:177` и рядом)
- [ ] `BoilerplatePageController` — снять `implements ServiceInterface` и пустой `register()`
      (класс резолвится контейнером из `AdminCallbacks`, сервисом не является)
- [ ] `BoilerplateController` — снять неиспользуемый `use TemplateRenderer`

**Файлы:**

- [ ] `assets/css/{all,fontawesome,solid}.min.css`, `assets/webfonts/` — FontAwesome грузится
      с CDN (`BundleLoader.php:75`), локальные копии не используются и не в git
- [ ] `.docs/db-backups/legacy-tables-pre-migration-reset.sql`

---

## Этап 6. Хвосты снятых с плана фич

- [ ] `src/js/profile/api.js` (шапка файла) — обещание «перенести кабинет в Telegram Web App
      или мобильное приложение» и мост `window.FS_LMS_API.request`. **Код оставить** —
      косвенность транспорта ничего не стоит и это хорошая изоляция; переписать комментарий
      так, чтобы он описывал текущий шов, а не будущую интеграцию
- [ ] `inc/Services/Profile/NotificationService.php:50` — «шов будущего Telegram-модуля
      Notifier», комментарий
- [ ] `inc/DTO/Person/UserDTO.php` — поле `telegramId` и чтение меты `fs_telegram_id`: нигде
      не заполняется и не читается, снять
- [ ] `inc/Callbacks/System/AdminCallbacks.php:85` — Dashboard помечен «временная заглушка»,
      но фактически рендерит `admin/dashboard` с фильтром `fs_lms_dashboard_modules`. Снять
      формулировку
- [ ] `inc/Services/Course/ContentUsageService.php:452` — `TODO Этап 2` про кросс-предметный
      поиск. Решить: доделать или зафиксировать как сознательное ограничение в докблоке
- [ ] `AdSync`: `AdSyncStatusCallbacks.php:41` и `AdSyncController.php:75` — `TODO(текст)`,
      недописанные пользовательские сообщения. Дописать

---

## Этап 7. Раннер релиза

- [ ] **`.distignore`** (новый файл в корне) — что не едет на сервер:

```
/src/
/tests/
/.docs/
/.github/
/.claude/
/.idea/
/node_modules/
/.phpunit.cache/
/.scss-check/
/assets/css/maps/
*.map
*.LICENSE.txt
/composer.json
/composer.lock
/package.json
/package-lock.json
/gulpfile.js
/eslint.config.cjs
/.stylelintrc.json
/phpcs.xml
/phpunit.xml
/.gitignore
/.gitattributes
/.distignore
/CLAUDE.md
/README.md
/Tasks.md
inc/Services/Subject/Bundle/CLAUDE.md
```

Едут: `fs-lms.php`, `uninstall.php`, `inc/` (включая `inc/Modules/*/assets/` — рукописные,
ничем не собираются), `templates/`, `assets/js/*.min.js`, `assets/css/*.min.css`, `vendor/`.

- [ ] **`.github/workflows/release.yml`** (новый), триггер `push: tags: ['v*']`:

1. `actions/checkout@v4`
2. `actions/setup-node@v4` (node 20, `cache: npm`) → `npm ci`
3. `shivammathur/setup-php@v2` (php 8.3) → `composer install --no-interaction --prefer-dist`
4. **Гейт качества** (любой красный шаг рушит релиз): `npm run lint:js`, `npm run lint:css`,
   `npm run build:check`, `vendor/bin/phpunit`
5. **Гейт версии**: тег без `v` должен совпасть с `Version:` в шапке `fs-lms.php` и с
   `FS_LMS_VERSION` — иначе `exit 1`. Без этого в манифест пакета переноса предмета уедет
   неверная версия сборки
6. `npx gulp build`
7. `rm -rf vendor && composer install --no-dev --optimize-autoloader --classmap-authoritative`
8. `rsync -a --exclude-from=.distignore ./ build/fs-lms/`
9. `cd build && zip -r ../fs-lms-${VERSION}.zip fs-lms` — папка `fs-lms/` **внутри** ZIP
   обязательна, иначе WP распакует файлы в корень `wp-content/plugins/`
10. `softprops/action-gh-release@v2` с ZIP в `files`

- [x] **`.github/workflows/ci.yml`** — `npm run lint:css` добавлен в job `assets` (раньше
      stylelint на PR не проверялся). Прогон: 0 ошибок, 123 предупреждения `!important`,
      exit 0 — гейт не красный
- [ ] **Версия** — поднять `0.0.1` → `1.0.0` в трёх местах: шапка `fs-lms.php`, константа
      `FS_LMS_VERSION`, `package.json`. Заодно `@since 0.0.1` в `uninstall.php`

---

## Проверка

**1. Схема идентична эталону** (после этапов 1–2)

Деактивировать плагин → `DROP TABLE` все `wp_fs_lms_*` → удалить опции `fs_lms_%` →
активировать → снять `SHOW CREATE TABLE` заново и сравнить с `schema-before.sql`.
Сравнивать **состав колонок, типы, ключи и индексы**, не текст целиком: порядок колонок после
`ALTER` и после чистого `CREATE` закономерно расходится — это не расхождение схемы.

**2. `npm run ci` зелёный** (lint:js + lint:css + build:check + phpunit)

**3. Четыре предмета работают**

Завести на чистой БД: ЕГЭ информатика и ОГЭ информатика (с банком, `hasBank = true`),
Программирование на Python и Робототехника Ардуино (без банка, `hasBank = false`).
По каждому:

- лендинг `/{key}/` и разделы `/{key}/trainer/`, `/{key}/articles/`, `/{key}/courses/`
  (у безбанковых — только описание и курсы)
- создать задание, проверить адрес `/{key}/trainer/{номер}/`
- создать статью, опубликовать, проверить слаг `article-task-{N}-{i}` и `ArticlePublishValidator`
- собрать курс с уроком, назначить группе, открыть КТП, опубликовать программу
- открыть плеер `/lesson/?gid=&gl=`, пройти шаги, сдать работу
- контрольная: попытка, автосохранение ответа, оценивание
- модуль EgeComputer на ЕГЭ и ОГЭ (компьютерный формат)

**4. Вход без модуля**

`/sign-in/` рендерит форму; `wp-login.php` редиректит на неё; неверный пароль возвращает на
страницу с ошибкой и подставленным логином; `logout` не зацикливается; ролей
`lms_student_free` / `lms_teacher_free` в БД нет.

**5. Удаление плагина не падает** ✅ прогнано 2026-08-19 на dev (снимок БД → прогон → восстановление)

| Этап | Таблицы `wp_fs_lms_*` | Опции `fs_lms_%` | LMS-роли | `manage_lms_platform` у admin | Крон |
|---|---|---|---|---|---|
| исходно | 27 | 17 | 8 | да | стоит |
| после `deactivate` | **27** | **17** | 0 | да | снят |
| после `activate` | 27 | 17 | 8 | да | стоит |
| после `uninstall` | **0** | **0** | 0 | нет | снят |

Ключевое: деактивация не трогает ни одной строки данных — данные уничтожает только удаление.
После uninstall остались 13 штатных таблиц WP; `manage_options` и `manage_categories` у
администратора на месте. Фатала (старый `Capability::ManageLMSAssignments`) больше нет.

**6. Релизный ZIP**

Тег `v1.0.0-rc1` в тестовой ветке → раннер собрал ZIP → распаковать в чистый WP (можно поднять
второй контейнер), активировать, повторить пункты 3–5 на нём. Проверить, что внутри ZIP нет
`src/`, `tests/`, `.docs/`, `*.map`, а `vendor/autoload.php` есть.

---

## Порядок работ

```
Этап 0  → снять эталон схемы
Этап 1  → схлопнуть миграции          ─┐
Этап 2  → убрать догоняющие миграции   ├─ проверка 1
Этап 3  → SocialAuth → ядро           ─┐
Этап 4  → свободные роли               ├─ проверка 4
Этап 5  → мёртвый код + фикс uninstall ├─ проверка 5
Этап 6  → хвосты                      ─┘
Этап 7  → раннер                      ─── проверки 2, 3, 6
```

Этапы 3 и 4 связаны (роль `Student` создаётся только соцвходом) — делать подряд.
Этапы 5 и 6 независимы от остальных, можно в любом порядке.

---

## Что НЕ трогаем

- Механику произвольного предмета: `SubjectRepository`, создание предмета, пользовательские
  таксономии, `hasBank`, `SubjectPageType` — на ней стоят все четыре предмета
- Типы заданий (`TaskTemplate`, `MetaBoxes/Templates/*`) и типы шагов (`StepType`)
- Пакет переноса предмета `inc/Services/Subject/Bundle/` + `wp fs-lms subject export|import`
- Модули `AdSync`, `DaData`, `EgeComputer`, `SmartCaptcha`, `VideoLibrary`
- `MigrationRunner` / `MigrationInterface` — нужны для DDL будущих патч-релизов
- `.docs/` в репозитории (исключается только из релизного ZIP)
