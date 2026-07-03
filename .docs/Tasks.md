# FS LMS — Backlog замечаний (план реализации)

Источник — 20 замечаний по работе плагина (ниже сохранены дословно в разделе
«Исходные замечания»). Первопричины подтверждены анализом кода + живой БД
(2026-07-03). Эпики 1–14 — в git-истории (последний коммит закрытия Эпика 14
`e80c846`).

## Зафиксированные решения (2026-07-03)

- **D1 (#13) — авто-открытие уроков по дате.** Урок открывается ученику в момент
  занятия; все уроки с прошедшей `scheduled_at` доступны сразу. Реализация —
  подключить мёртвый `LessonVisibilityService::effectiveVisibility()` в
  `LessonAccessPolicy::resolve()`. «Скрыть насовсем» после даты = только статус
  `archived` (не `hidden`).
- **D2 (#4/#5) — каскадная очистка «вперёд».** Удаление предмета/периода/группы
  вычищает ВСЕ дочерние таблицы + мусор в `wp_posts` (как уже работает для групп и
  пользователей). Кабинет удаляется из БД физически (hard-delete вместо soft).
  Отдельный UI-инструмент очистки НЕ делаем; накопившийся dev-мусор вычищается
  разово SQL-ом.
- **D3 (#15) — вход в плеер и «Мои курсы».** «Перейти к курсу» → первый незакрытый
  урок. Клик по курсу в сайдбаре (учитель/админ) → курс в preview-плеере. Кнопка
  «Редактировать» на шаге — только в preview-режиме, для ролей с правом
  `AuthorLmsCourses`.
- **D4 (#15-D) — preview-плеер интерактивный, Stepik-подобно.** Шаги можно решать
  (виджеты активны), но без сохранения/проверки/прогресса/гейтов. Отдельный
  `CoursePreviewController`/`CoursePreviewService` от `course_id`, переиспользующий
  чистые рендер-помощники `LessonPlayerService` + шаблон плеера + `player.min.js`.
- **Роли (#19).** FSOffice — гибрид: фронт-кабинет `/profile/` (по умолчанию после
  логина) + доступ в wp-admin к офисным разделам. Заметка «у офисных ролей нет
  фронт-кабинета» устарела.

---

## Фаза 1 — быстрые баги (S, высокая отдача, низкий риск) ✅ Выполнена

### #19 — FSOffice не пускает в wp-admin
- **First cause:** `UserBehaviorManager::restrictAdminAccess()` (`inc/Managers/Person/UserBehaviorManager.php:51-64`, хук `admin_init`) пускает только по вайтлисту капы (`manage_options` ИЛИ `lms_teacher`). У FSOffice нет ни того, ни другого → редирект на `/profile/`. Логика инвертирована; даёт петлю редиректов для methodist/market.
- **Фикс:** заменить вайтлист капы на денилист ролей — блокировать в wp-admin только `lms_teacher / lms_student / lms_student_free / lms_parent`, администратора всегда пускать, остальных (office/methodist/market) пускать. Явная проверка по `$user->roles`, не по капе-роли.

### #2 — прятать пункт «Обучение» без предметов
- **First cause:** `LearningMenuController::registerLearningMenu()` (`:226-244`) регистрирует меню всегда, с лендинг-заглушкой.
- **Фикс:** ранний `return` при пустом `teacher_subjects->subjectsForUser()` — точный аналог `SubjectsMenuBuilder::buildPages():78`.

### #3 — notice «нет предметов» пропадает через 5с
- **First cause:** глобальный таймер `src/js/admin/admin.js:55-60` удаляет все `.notice-info` через 5с, включая структурные плашки `.fs-table__no-items`.
- **Фикс:** исключить `.fs-table__no-items` (и структурные плашки) из селектора таймера.

### #8 — табы предметов на «Обучение» выглядят как ссылки
- **First cause:** таб-бар обёрнут в прозрачный `.notice.fs-lms-learning-notice` (`_learning-bank.scss:6-12`), гасящий фон/рамку WP-класса `nav-tab`; у «FS LMS» тот же `nav-tab` в чистом `<h2 class="nav-tab-wrapper">`.
- **Фикс:** не оборачивать в `.notice` (рендерить как `nav-tab-wrapper` в `.wrap`) либо дать явные `.fs-lms-subject-tabs .nav-tab` стили под WP-дефолт. Шаблоны: `subject-bank-tabs.php:20-29`, `bank-landing.php:26-33`.

### #12 — в профиле слаг предмета вместо названия
- **First cause:** `LearnerService.php:57` кладёт сырой `$g->subject_key`.
- **Фикс:** инъекция `SubjectRepository` в `LearnerService`, `getByKey($key)?->name ?? $key` (паттерн уже в `AdminCallbacks:249`, `ParentsExportProvider:130`).

### #9 — FileAnswer: «Решение для проверяющего» необязательно
- **First cause:** `TaskPublishValidator::getSoftError()` (`:59-70`) требует все поля шаблона; `solution_text` (rich_text) и `task_code` (text) обязательны.
- **Фикс:** флаг `optional => true` в конфиге полей шаблона `FileAnswerTaskTemplate.php` + `continue` в цикле `getSoftError()`. Одно место — покрывает и метабокс, и банк `fs_lms_problems`.

### #10 — требовать название работ и контрольных
- **First cause:** у works/assessments проверки `post_title` нет (`WorkMetaBoxController:42-47`, `AssessmentMetaBoxController:44-48`).
- **Фикс:** переиспользовать `TaskPublishGuard::enforce()` (пустой title → откат в draft + notice) через `wp_insert_post_data` в обоих контроллерах. Новый сервис не нужен.

### #7 — иконки в формах заявки/join не по центру полей
- **First cause:** миксин `fs-field-icon` (`_mixins.scss:59`) центрирует `top:50%` относительно обёртки `.fs-form-group` (с label сверху), а не инпута.
- **Фикс:** позиционировать иконку относительно инпута (обёртка только вокруг control-строки, либо якорь на инпут).

### #16 — паддинги в prof-card на странице замен
- **Фикс:** добавить внутренние отступы карточкам `prof-card` на экране замен (`_substitutions.scss`).

### #20 — admin-bar съедает место под prof-side-user
- **Фикс:** учесть высоту WP admin-bar (`--wp-admin--admin-bar--height` / `body.admin-bar`) в высоте оболочки `.prof-app` / нижнего блока.

---

## Фаза 2 — профиль ученика и расписание ✅ Выполнена

### #13 — урок «Закрыт» при прошедшей дате (D1)
- **First cause:** `effectiveVisibility()` (`LessonVisibilityService.php:66-75`: hidden + дата ≤ now → open) — мёртвый код; `LessonAccessPolicy::resolve():30` читает сырой `visibility` и запирает до проверки даты.
- **Фикс:** в `LessonAccessPolicy::resolve()` считать `effectiveVisibility($lesson)` вместо `$lesson->visibility`. Тогда прошедшие уроки авто-открываются. Проверить, что `learner.js` рисует ссылку в плеер для `status='available'`.

### #14 — рассинхрон расписания + нет кабинета
- **First cause:** `group_lessons.scheduled_at` не пересчитывается при смене `meetings` (`StudentGroupCallbacks:245`) и назначении курса (`CourseAssignmentService:64-72`) — reflow там не вызывается; индивидуальные/пиннутые строки reflow не двигает. Кабинет в `LearnerService` не резолвится вовсе.
- **Фикс:** (а) триггерить `reflow` при сохранении meetings и `assign` курса (или явная кнопка «Распределить» — уточнить UX); (б) резолвить эффективный кабинет `gl.room_id ?? groups.room_id` через `RoomRepository::find()->name`, добавить `room` в item (`LearnerService:74-89`), вывести в `schedRow`/`lessonRow` (`learner.js:159-167,197-209`).

### #17 — prof-group-chip: 10px, ≤4 буквы, цвет по предмету
- **First cause:** `shortName` уже режет до 4 символов; `groupColor(gid)` красит по группе, а не предмету (`utils.js:72-80`); шрифт чипа 12px (`_layout.scss:65`).
- **Фикс:** шрифт → 10px; `groupColor` красить по `subject_key` (все группы предмета — один цвет). Прокинуть `subject`/`subject_key` в данные чипа.

### #18 — недельное расписание: все дни Пн–Вс
- **First cause:** `dashboard.js:112-120` итерирует только даты с занятиями (`byDate`).
- **Фикс:** генерировать 7 дат недели (Пн–Вс) от опорной даты, в пустые колонки — «Занятий нет».

### #6 — название направления в форме заявки
- **First cause:** `ValidateDirectionCode` (`ApplicationCallbacks:301-325`) отдаёт `form_html`, но не имя направления; под `fs-apply-card__title` его нет.
- **Фикс:** вернуть имя направления (предмета) из колбэка + вставить в JS под заголовком карточки (`apply-form.js`).

---

## Фаза 3 — валидация публикации курса   ✅ Выполнена

### #11 — нельзя опубликовать курс с пустым шагом
- **First cause:** `CourseBuilderService::updateCourseMeta` (`:262-272`) валидирует только допустимость статуса.
- **Фикс:** новый `CoursePublishValidator` — до `updateStatus` при `publish` обойти модули→уроки→шаги, правило пустоты (зеркало клиентского `stepHasContent`, `step-editor.js:807-812`: text без content, video без url, ref-шаг с ref≤0). Ошибка называет конкретный урок/шаг. Нюанс: свежесозданный урок = пустой text-шаг.

---

## Фаза 4 — каскадная очистка БД (D2) ✅ Выполнена

### #4 — осиротевшие записи при удалении предмета/периода/группы
- **First cause:** `GroupDeletionHandler::handle()` (`inc/Services/Deletion/GroupDeletionHandler.php:25-53`) — единый choke-point для subject/period/group — чистит только `student_records` + строку `groups`. Осиротевают: group_lessons, attendance, submissions, lesson_progress, learning_events, assessment_attempts, task_attempts, substitutions. Реальных FK в схеме нет.
- **Фикс:** расширить `GroupDeletionHandler` (уже транзакционный): собрать `group_lesson_id` группы → удалить дочерние по нему (attendance/submissions/lesson_progress/task_attempts) → затем по `group_id` (group_lessons/learning_events/assessment_attempts/substitutions). Дописать `deleteAllByGroup/deleteAllByGroupLesson` в 7 репозиториях (готов только `GroupLessonRepository::deleteAllByGroup`).

### #5 — wp_posts не чистится + неверное «Общее кол-во»
- **First cause:** (а) `PostManager::deleteAll()` (`:108`) через `get_posts('any')` не захватывает `trash`/`auto-draft`; (б) формула `array_sum − trash` включает `auto-draft/inherit` (`settings-1-subjects-manager.php:80`, `subject-1-stats.php:41`).
- **Фикс:** (а) явный список статусов в `deleteAll` (вкл. `trash`, `auto-draft`), либо прямое `wpdb`-удаление по `post_type`; (б) helper подсчёта только реальных статусов, заменить формулу в 2 шаблонах.

### Кабинеты — hard-delete + чистка ссылок
- **First cause:** `RoomCallbacks::ajaxDeleteRoom` → `RoomRepository::softDelete()` (только `deleted_at`); ссылки `group_lessons.room_id`/`groups.room_id` виснут; `rooms.allowed_subjects` не чистится при удалении предмета.
- **Фикс:** удалять строку кабинета физически (`hardDelete`); `RoomDeletionHandler`/событие — обнулять `room_id` в group_lessons/groups (`RoomAssignmentService` уже умеет); при удалении предмета — вычищать `subject_key` из `rooms.allowed_subjects`.

### Разовая очистка dev-БД
- Осиротевший мусор, накопившийся до фикса, вычистить одноразовым SQL/wp-cli (не постоянная фича).

---

## Фаза 5 — «Мои курсы» + preview-плеер (крупное, D3/D4) ✅ Выполнена

Центральный факт: плеер живёт на маршруте `/group/?gid&gl`, требует пару
(ученик, `GroupLessonDTO`); `LessonPlayerController:65` пускает только члена
группы. Редактор в ученический плеер не попадает — потому preview делается
отдельным путём от `course_id`.

### #15-A — ученик: «Перейти к курсу» в prof-card-head
- Кнопка после `<h3>` в `prof-card-head` (`learner.js:117`). Клиентский выбор из `d.lessons.filter(group_id & player_url)`: первый `status==='available'` (первый незакрытый) → его `player_url`. **S.**

### #15-B — учитель/админ: секция «Мои курсы» в сайдбаре
- `groups.course_id` уже в raw-строке; инъекция `CourseManager` в `ProfileViewResolver`, собрать `courses_taught[] = [{id,title,group_ids}]`. Секция в `app.js buildSidebar()` после блока групп, стиль как `.prof-group-item`. Клик → preview-плеер курса (первый урок). **M.**

### #15-C — сворачивание секций + переполнение
- Скролл уже есть (`.prof-side-scroll`); паттерн аккордеона — из `course-builder.js:283-298` (флаг `collapsed` + ре-рендер). Свернуть секции «Мои группы»/«Мои курсы»; при большом числе курсов — клиентский поиск-фильтр. **M.**

### #15-D — preview-плеер курса (интерактивный)
- Новый `CoursePreviewController` (маршрут/обёртка от `course_id`) + `CoursePreviewService`: обход `CourseDTO→ModuleDTO→LessonDTO→StepDTO`, контент через чистые помощники `LessonPlayerService` (`buildWidgetData/buildConditionHtml/renderVideoData/renderAssessmentData`) — вынести их в общий `StepContentRenderer` (сейчас `private`).
- View без статусов/гейтов/сдач: все шаги `available`, рейка-дерево из модулей без прогресса; виджеты активны, но submit/mark/«следующий урок» отключены.
- Переиспользуется шаблон `player.php` + partials + бандл `player.min.js`.
- Входы: кнопка «Просмотр» в конструкторе (`course-builder.js:158-161`, заменить нативный `?p=&preview=true`); клик по курсу в сайдбаре (#15-B). **L.**

### #15-E — кнопка «Редактировать» на шаге (только preview)
- Capability `AuthorLmsCourses`. Deep-link в конструктор: `admin.php?page=fs_lms_course_builder&course=..&lesson=..&step_ref=..` (`step_ref` есть для ссылочных шагов; для text/video — линк на урок либо расширить deep-link по `step.key`). Кнопка в preview-плеере на шаге, видна при праве редактирования. **M.**

---

## Фаза 6 — прочее ✅ Выполнена

### #1 — админка грузится внутри модалки wp-auth-check
- Плагин `wp-auth-check` не трогает. Возможный усугубитель — `resolveLoginRedirect()` (`UserBehaviorManager:99-111`) не учитывает `interim-login`, возвращает полный `admin_url()`.
- **Фикс/проверка:** возвращать `$redirect_to` как есть при `interim-login`; если не поможет — чистое ядро WP.
- **Сделано:** ранний `return $redirect_to` в `resolveLoginRedirect()` при `isset($_REQUEST['interim-login'])` — фильтр больше не навязывает `admin_url()` в контексте модалки. Ядро (`wp-login.php:1362`) на успешном interim-логине само рендерит экран успеха и `exit` (URL-редирект игнорируется), поэтому админка внутрь модалки не грузится. Остаточные проявления — уже чистое ядро WP.

---

## Новые баги — фазировка и оценки (Фазы 7–11)

Разбор 11 пунктов из раздела «Новые баги» (в конце файла). Нумерация **НБ-N**
соответствует списку «Новые баги». First-cause подтверждён чтением кода
(2026-07-03). Оценка: **S** — точечная правка (CSS/шаблон/малый JS); **M** —
межфайловая логика; **L** — новая фича/read-model/хук.

| НБ | Кратко | Фаза | Оценка | Задач |
|---|---|---|---|---|
| 1 | Табы «Обучение» дёргаются + аудит админ-страниц | 7 | M | 4 |
| 6 | `page-title-action` vs `button` — разные размеры | 7 | S | 2 |
| 2 | Лишний `titlediv` при deep-link в конструктор | 8 | S *(уже в дереве)* | 1 |
| 7 | Новый урок курса не попал в КТП | 8 | M | 4 |
| 8 | Выпадающие меню инд. занятия (`prof-grade-pop`) | 9 | S | 3 |
| 9 | UI присваивания урока инд. занятию | 9 | **L** | 7 |
| 3 | Журнал: в `hd-col` только дата | 10 | S | 1 |
| 4 | Тост «Занятие ещё не прошло»: крестик вместо галки | 10 | S | 3 |
| 10 | «Занятия сегодня»: кол-во занятий ≠ групп | 10 | M | 2 |
| 11 | Пагинация недели в расписании ЛК | 10 | M | 3 |
| 5 | Синхронизация `UI.md` с кодом | 11 | S | 4 |

**Рекомендуемый порядок:** сперва быстрые победы — НБ-2 (закоммитить готовое),
НБ-3, НБ-4 (S-полировка); затем НБ-6, НБ-8 (S); затем M-пул (НБ-1, НБ-7, НБ-10,
НБ-11, НБ-5); НБ-9 (L) — последней. **Взаимосвязь:** редизайн НБ-9 переносит
назначение урока в КТП и может вовсе убрать поповер из НБ-8 — если НБ-9 берётся
сразу, НБ-8 не делать (иначе — как временную заплатку).

### Статус реализации (2026-07-03)

| НБ | Статус | Что сделано |
|---|---|---|
| 1 | ✅ | Табы «Обучение» — описание+табы одним `.notice` (`renderBankChrome`, WP-JS переносит целиком, не «прыгает»); `bank-landing` на `.fs-page-header`; двойной `<h1>`→`<h2>` в 10 таб-партиалах. **Follow-up:** 3 `wp-heading-inline`-партиала + DRY-ролл `render_fs_page_header()`. |
| 2 | ✅ | Уже было в рабочем дереве (удаление `#titlediv`), подтверждено диффом. |
| 3 | ✅ | `hd-dow`/`hd-room` убраны из шапки журнала (JS + SCSS; кабинет остался в `title`). |
| 4 | ✅ | `toast(msg, type='ok')` — крестик + `--absent` для `error`; вызовы «Занятие ещё не прошло» помечены. |
| 5 | ✅ | `UI.md` синхронизирован: 3.1 → done, уточнены 1.3/2.1/3.2/4.1/4.2, note об удалённом `UI-Audit.md`. |
| 6 | ✅ | Шапочные кнопки → `.button`/`.button-primary` (= `--wp-admin-theme-color` = бренд); удалён легаси `.btn-filled`. |
| 7 | ✅ | `GroupsRepository::findByCourse` + `CourseAssignmentService::syncCourseLessons` + триггер в create/duplicate конструктора (best-effort, дедуп, пропуск заблокированных КТП). |
| 8 | ✅ | Clamp верх/низ позиционирования поповера + `.gp-field select` стилизован + `max-height`/overflow. |
| 9 | ✅ | Полный вариант: режим КТП «Индивидуальные занятия» (пункт пикера групп, sentinel `-1`), слоты с ФИО+фиол. полоской, пикер урока (курс-первыми + поиск), 3 AJAX-хука (`get_individual_slots`/`get_lesson_candidates`/`assign_individual_lesson`, `SaveSchedule`+`ManageLmsTeaching`), ФИО в расписании день/неделя. Backend: `ScheduleService::getIndividualProgram/lessonCandidatesForGroup/assignLessonToIndividual` (+ `CourseManager`). Структурно проверено (lint/build/boot); функциональный прогон — на данных в браузере. |
| 10 | ✅ | `groups`/`individual` считаются от сегодняшних занятий; трёхчастная подпись плитки. |
| 11 | ✅ | Пагинация недели (`weekOffset` + ‹ ›), окно недели на сервере расширено, клик делегирован. |

Проверки: `npx gulp build`, ESLint, `php -l` (все затронутые файлы) — чисто; сайт и wp-login грузятся без фатала.

---

## Фаза 7 — стандартизация админ-вёрстки (НБ-1, НБ-6)

### НБ-1 — табы «Обучение» дёргаются при загрузке + аудит всех админ-страниц
- **First cause:** `LearningMenuController::register()` (`inc/Controllers/Course/LearningMenuController.php:68-69`) вешает на `admin_notices` два колбэка: `renderBankDescription()` → `bank-notice.php:20` (`<div class="notice fs-lms-learning-notice">`) и `renderSubjectBankTabs()` → `subject-bank-tabs.php:20` (голый `<h2 class="nav-tab-wrapper">`, без обёртки). Ядро печатает `admin_notices` ДО `<div class="wrap">`; штатный `wp-admin/js/common.js` на `ready` переносит под `.wp-header-end` (между `<h1>` и `subsubsub`) только `div.notice/.updated/.error`. bank-notice (класс `.notice`) уезжает вниз, таб-бар (не `div`, без класса) остаётся над `<h1>` → «не там» + «дёргается» (до ready оба сверху, после — notice едет, табы нет). Регресс от фикса старого #8 (Фаза 1): таб-бар нарочно вынесли из `.notice`, потеряв участие в переносе.
- **Фикс:** вернуть табам участие в переносе без старого визуального бага — объединить описание + `nav-tab-wrapper` в один `admin_notices`-колбэк внутри одного `<div class="notice fs-lms-learning-notice">` (WP перенесёт связку одним куском в нужный слот). Плюс `templates/admin/learning/bank-landing.php:15-47` (лендинг без выбранного предмета) привести к `.fs-page-header`/`.tab-content`.
- **Аудит `templates/admin/`:** соответствуют стандарту — `userlist.php`, `logs.php`, `settings.php`, `subject.php`, `dashboard.php`, `groups.php` (фильтр-форма вместо табов — ок). Не соответствуют — `bank-landing.php`, пара `subject-bank-tabs.php`/`bank-notice.php`, `boilerplate-list/editor.php` (служебные, низкий приоритет), `course-builder.php` (осознанно вне охвата — Фаза 5 / `UI.md` §3). Внутристраничный дрейф: 5 вкладок `subject.php` + 8 вкладок `settings.php` дублируют вторичный заголовок тремя способами (нативный `wp-heading-inline`; ручной `<div class="fs-page-header"><h1>`), вместо готового `render_fs_page_header()` (`templates/admin/components/UI/ui_renderers.php:102-134`, 0 вызовов) → по 2+ `<h1>` на `subject.php`/`settings.php`. Это ровно незакрытая `UI.md` Фаза 3.1/3.2.
- **Разбивка:** 1) позиционирование/дёрганье табов «Обучение» — `LearningMenuController.php` + `subject-bank-tabs.php`/`bank-notice.php`; 2) `bank-landing.php` → стандартная разметка `.fs-page-header`/`.tab-content`; 3) докатка `render_fs_page_header()` на 11 таб-партиалов subject/settings (устраняет двойной `<h1>`; = `UI.md` 3.1); 4) *(опц., низкий)* `boilerplate-*.php` под стандарт.
- **Оценка:** M — задачи 1–2 сами по себе S (перестановка шаблонов/хуков), но полный аудит тянет задачу 3 (11 файлов + обязательная визуальная сверка каждой страницы).

### НБ-6 — `page-title-action` vs `button`/`button-primary`: разные размеры
- **First cause:** два несовместимых штатных размера WP: `.page-title-action` — 32px (`wp-admin/css/common.css:626-648`) vs `.button`/`.button-primary` без модификатора — 40px (`wp-includes/css/buttons.css:43-61`). Плагин мешает оба на одном экране: `templates/admin/groups.php:25-29` шапка на `page-title-action` (32px), `groups.php:89,92` фильтр-кнопки `.button` (40px), модалка `group-modal.php:116-117` — `.button`/`.button-primary` (40px) для того же «Добавить группу». Свой примитив `.fs-btn`/`--primary` (`_buttons.scss:9-103`, height = `$input-height` 40px) совпадает с `.button`, а не с шапкой, и на этих экранах не используется; вместо него легаси-хак `page-title-action btn-filled` (`subject-2-tasks.php:27-28`). Нюанс h1↔кнопка: `.fs-page-header__title` 24px/~29px (`_page-header.scss:23-29`) рядом с 40px-кнопкой визуально проседает.
- **Фикс:** шапочные экшены держать компактными 32px (это и снимает нюанс с h1), но дать «заливной» вариант — готовый ядровый класс `button button-primary button-compact` (`wp-includes/css/buttons.css:73-79`, ровно 32px) либо свой `page-title-action--primary` по образцу `.fs-btn--primary`. Применить на `groups.php:25` + заменить `btn-filled`-хак в `subject-2/3-*.php`. Всё ВНЕ шапки (фильтры/модалки/таблицы) — на существующий `.fs-btn`/`--primary`/`--secondary` (40px): первые цели — `groups.php:89,92`, `group-modal.php:116-117`, далее по `UI.md` Фаза 3.2/4.
- **Разбивка:** 1) компактный «primary»-вариант для `.fs-page-header__actions` + применить на `groups.php`, заменить `btn-filled`-хак; 2) унификация тела страниц (фильтры/модалки/таблицы) на `.fs-btn`, начиная с `groups.php`/`group-modal.php`.
- **Оценка:** S — только CSS/классы в разметке, без PHP/JS-логики.

---

## Фаза 8 — конструктор курса ↔ КТП (НБ-2, НБ-7)

### НБ-2 — лишний `titlediv` в конструкторе при deep-link
- **First cause:** `templates/admin/course-builder.php:34-46` под `if ( $post )` рендерит собственный `<div id="titlediv">` с `<input id="fs-course-title">` — осиротевший дубль: SPA правит заголовок через `.cs-title` (`course-builder.js:245`, `startTitleEdit()`), `#fs-course-title` не читается ни одним JS. Виден не из-за `step_ref`, а из-за поверхности: deep-link «Редактировать» ведёт на admin-страницу (`page=fs_lms_course_builder`, `player.php:230-234`), где нативный `#titlediv` прятать нечем; при обычной правке курс на `post.php`, где `#titlediv` скрыт CSS (`CourseMetaBoxController::hideTitleOnCourseScreen():57-70`).
- **Фикс:** удалить блок `<?php if ( $post ) : ?> … #titlediv … <?php endif; ?>` (`course-builder.php:34-46`) — заголовок уже редактируется в курс-стрипе SPA. **NB: уже применено в рабочем дереве (незакоммичено, файл `M`) — осталось закоммитить.**
- **Разбивка:** единая задача.
- **Оценка:** S.

### НБ-7 — новый урок курса не появился в КТП
- **First cause:** снапшот-модель без ре-синка. `group_lessons` наполняется уроками курса ТОЛЬКО при назначении — `CourseAssignmentService::assign()` (`CourseAssignmentService.php:47-90`: обход `$course->lessonIds()` → `groupLessons->add()`), вызывается лишь из `ProgramCallbacks::ajaxAssignCourse` (`:60`). Добавление урока идёт `CourseBuilderService::createLessonInModule()` (`:142-183`) — пишет только структуру поста курса (`courses->update:179`), не трогает `group_lessons` и не шлёт `do_action`. КТП рендерится из снапшота (`ScheduleService::getProgram():485-486` → `groupLessons->listByGroup()`). Итог: урок, добавленный после назначения, в КТП назначенных групп не попадает — ни авто-триггера, ни ре-синка.
- **Фикс:** добавить ре-синк «новые уроки курса → назначенные группы»: (а) `GroupsRepository::findByCourse( int $courseId )` (колонка `groups.course_id` уже пишется, `assign():75`, но финдера нет); (б) `CourseAssignmentService::syncNewLessons( $groupId, $courseId )` — дозапись только отсутствующих `lesson_id` (dedup через `listByGroup`), append в конец, без `scheduled_at` (чтобы «Распределить»/`reflow` расставил); (в) триггер из `createLessonInModule` (`CourseBuilderCallbacks.php:95`) для незалоченных КТП (`ScheduleService::isProgramLocked`), ИЛИ явная кнопка «Обновить из курса» в КТП (`ktp.js` + AJAX).
- **Разбивка:** 1) `GroupsRepository::findByCourse()`; 2) `CourseAssignmentService::syncNewLessons()` (dedup + append); 3) точка триггера (авто в `createLessonInModule` либо AJAX-хук + кнопка); 4) учёт `isProgramLocked` и позиции для `reflow`.
- **Оценка:** M — межфайловая логика (финдер репозитория + метод сервиса + точка вызова + учёт lock/reflow), без новой таблицы или слоя.

---

## Фаза 9 — индивидуальные занятия (НБ-8, НБ-9)

### НБ-8 — выпадающие меню инд. занятия (`prof-grade-pop`)
- **First cause:** три дефекта одного поповера `#profGradePop` (`templates/frontend/profile.php:74`), который наполняет `openIndiForm()` (`src/js/profile/groups.js:102-158`) и позиционирует `openGradePopPositioned()` (`src/js/profile/utils.js:192-204`): (1) вертикальный флип `utils.js:199` без верх/низ-отсечки и без кэпа высоты → высокий поповер (≈230-260px) у нижних кнопок ростера уходит выше вьюпорта; (2) поле «Кабинет» = `<select id="giRoom">` (`groups.js:112`), а `_overlays.scss:56-57` стилизует только `.gp-field input` — `select` рендерится нативным; (3) сама форма-поповер (`groups.js:108-117`) — разовый ad-hoc паттерн.
- **Фикс:** (1) `utils.js:199` — `top = Math.max(10, Math.min(top, window.innerHeight - ph - 10))` + `max-height`/`overflow-y:auto` на `.prof-grade-pop` (`_overlays.scss:47-50`); (2) `_overlays.scss:56` — расширить селектор до `.gp-field input, .gp-field select` (+ `:focus`) и добавить `appearance`/каретку. **NB: редизайн НБ-9 переносит назначение урока в КТП и может убрать этот поповер — НБ-8 имеет смысл как заплатка, если НБ-9 не делается сразу.**
- **Разбивка:** 1) `utils.js:199` — верх/низ-clamp + кэп высоты; 2) `_overlays.scss:56-57` — стиль `.gp-field select` + каретка; 3) *(опц.)* `max-height`/overflow на `.prof-grade-pop`.
- **Оценка:** S — одна правка JS-позиционирования + один SCSS-селектор.

### НБ-9 — присваивание урока индивидуальному занятию (UI-фича)
- **First cause (данные ГОТОВЫ, UI НЕТ):** модель `group_lessons.kind='individual'` + `student_person_id` + `lesson_id` + `room_id` (`inc/DTO/Course/GroupLessonDTO.php:49-56`, `Migration_1_0_0.php:396`); `GroupLessonRepository::setLessonId()` (`:206-215`), `ScheduleService::createIndividualLesson()` (`:65-121`) и `ProgramCallbacks::ajaxCreateIndividualLesson()` (`:313-348`) уже принимают `lesson_id`. НО: `openIndiForm()` (`groups.js:102-158`) собирает только Дата/Время/Кабинет/Тема (свободный текст `label:113`), `lesson_id` не шлёт (`:145-151`) → у инд. занятия лишь текстовый ярлык. Инд. занятий нет в КТП — `getProgram()` пропускает `kind='individual'` (`:490`). Расписание кладёт имя ГРУППЫ, не ученика (`DashboardService::lessonItem():199`). Пикер группы (`picker.js:29`, `ktp.js:298`) без пункта «Индивидуальные». Поиск уроков предмета уже есть, но под чужой капой: `GetCourseLessonCandidates` (`AjaxHook.php:151` → `CourseCallbacks.php:36-53`, `Capability::AuthorLmsCourses`); все уроки — `LessonManager::getBankBySubject()` (`:73`), уроки курса — `CourseManager->get()->lessonIds()` (`ProfileViewResolver.php:280`).
- **Фикс (послойно):** (a) пункт «Индивидуальные занятия» в `openGroupPicker` (`picker.js:29`/`ktp.js:298`), sentinel-id; инд.-ветка рендера `ktp.js:83`. (b) read-model инд.-строк с ФИО ученика — расширить `getCalendar()`/`getProgram()` (`ScheduleService.php:396,485`) флагом либо отдельный `getIndividualCalendar()`; карточка «Инд. · {ФИО}», фиол. полоска, пустой слот (`lesson_id=null`) → «Назначьте урок». (c) сайдбар: заголовок «Темы курса»→«Уроки курса» (`ktp.js:120`), панель `.prof-theme-bank` (`_ktp.scss:48`) → пикер: уроки курса → разделитель → все уроки предмета + строка поиска; логика из `getLessonCandidates()`, но через НОВЫЙ профильный хук под `Capability::ManageLmsTeaching` (не переиспользовать `AuthorLmsCourses`). (d) назначение: новый хук `AssignLessonToIndividual` + `ScheduleService::assignLesson()` → `setLessonId()` (есть); протянуть `lesson_id` в create (`groups.js:145`). (e) расписание: ФИО в `DashboardService::lessonItem():195` + рендер «Время · ФИО · Тема» в `dashboard.js schedRow:169`/week-card:135 при `kind==='individual'` (полоска `--t-zachet` уже есть, `:180`).
- **Разбивка:** 1) read-model инд.-строк + ФИО ученика; 2) пункт «Индивидуальные» в пикере + инд.-ветка `ktp.js`; 3) карточка-слот (ФИО, полоска, «назначить урок»); 4) пикер «Уроки курса» (курс-первым → разделитель → все предметные + поиск; новый AJAX под `ManageLmsTeaching`); 5) AJAX назначения + `assignLesson`/`setLessonId` + `lesson_id` в `groups.js`; 6) день/неделя препода (ФИО + Тема для инд.); 7) SCSS слот/пикер/поиск (`_ktp.scss`, токен `--t-zachet`).
- **Оценка:** L — новый режим КТП + read-model для индивидуальных + хук назначения + поисковый пикер; слой данных частично готов (`setLessonId`, `getBankBySubject`, `lesson_id` в create, токен), UI и read-model — с нуля.

---

## Фаза 10 — расписание и полировка ЛК (НБ-3, НБ-4, НБ-10, НБ-11)

### НБ-3 — журнал: в `hd-col` только дата
- **First cause:** `src/js/profile/journal.js:197-210` (`lessonHead()`) рендерит в `<th class="hd-col">` три блока: `.hd-date` (204-205), `.hd-dow` (206, день недели), `.hd-room` (208, кабинет). Стили — `_journal.scss:46-49` и `:118-128`.
- **Фикс:** удалить `<div class="hd-dow">` (`journal.js:206`) и блок `.hd-room` (`:208`), убрать неиспользуемую `dow`; `.hd-cont` (207) и `roomTip` в `title` (204) не трогать. В `_journal.scss` удалить осиротевшие `.hd-dow` (49) и `.hd-room` (118-128).
- **Разбивка:** единая задача.
- **Оценка:** S.

### НБ-4 — тост «Занятие ещё не прошло»: крестик вместо галки
- **First cause:** `templates/frontend/profile.php:75-78` — `#profToast` содержит статичный SVG-чекмарк (`M4 10.5 8 14l8-8.5`) для ВСЕХ тостов; `toast(msg)` (`src/js/profile/utils.js:7-14`) меняет только текст `<span>`; `_overlays.scss:14` жёстко красит иконку в `--toast-ok`. «Типа тоста» не существует — `journal.js:255,261` зовут `toast('Занятие ещё не прошло')` через тот же успех-путь.
- **Фикс:** `toast(msg, type = 'ok')` в `utils.js` — модификатор-класс на `#profToast` + `d`-путь SVG на крестик (`M6 6l8 8M14 6l-8 8`, уже есть как иконка «отсутствовал» в `journal.js:300`); `_overlays.scss` — `.prof-toast.error svg { stroke: var(--absent) }` (токен `--absent`, `_variables.scss:66`, рядом с `--toast-ok:76` — новый не заводить); `journal.js:255,261` — `toast(…, 'error')`.
- **Разбивка:** 1) расширить `toast()` (класс + путь иконки); 2) правило `.error` в `_overlays.scss`; 3) 2 вызова в `journal.js`.
- **Оценка:** S — 3 файла, каждая правка тривиальна.

### НБ-10 — «Занятия сегодня»: кол-во занятий ≠ групп
- **First cause:** `inc/Services/Profile/DashboardService.php:123` — `'groups' => count( $groups )`, где `$groups` (`collectGroups():149-167`) = ВСЕ группы преподавателя (свои + замены), без привязки к сегодняшним занятиям; `'lessons_today'` (`++$lessonsTd:77`) считает строки `group_lessons` на сегодня по всем `kind` (фильтр `individual` на `:84` — только для `to_fill`). Два числа из непересекающихся множеств → «1 занятие, 2 группы».
- **Фикс:** в `build():60-94` при формировании `$todayItems` собирать distinct `group_id` для `LessonKind::Group` (`inc/Enums/Course/LessonKind.php:20`) и отдельно считать `individual`; заменить `'groups' => count($groups)` на count уникальных сегодняшних groupId + ключ `'individual'`. `dashboard.js:55` (`statTile('Занятий сегодня', …)`) — трёхчастная подпись «N групп · M инд.» (инд. только если >0).
- **Разбивка:** 1) `DashboardService` — счёт групп/инд. от `$todayItems`, а не от общего списка; 2) `dashboard.js:55` — трёхчастная подпись плитки.
- **Оценка:** M — меняется агрегирующая логика сервиса (новый ключ статистики) + рендер плитки.

### НБ-11 — пагинация недели в расписании ЛК
- **First cause:** пагинации нет — `#profSchedToggle` (`dashboard.js:63-66`) переключает только «Сегодня/Неделя»; `renderSched('week'):112-145` всегда одна неделя, а `d.week` обрезан на сервере (`DashboardService.php:46` `weekEnd = today+6д`, фильтр `:79`). При этом `GroupLessonRepository::listByGroup():23-33` тянет ВСЕ занятия группы — они просто отбрасываются в памяти (`:79`), т.е. более широкий набор уже доступен бесплатно. Прецедент готов: журнал листает месяцы клиентом (`journal.js:57-94 computeMonths/changeMonth`), UI `.jm-arrow` (`_journal.scss:12-19`); в `_dashboard.scss` пейджера нет.
- **Фикс:** по образцу журнала — (а) `DashboardService.build()` не обрезать `week` до +6д, отдавать более широкий диапазон (с датами), раз данные уже в памяти; (б) `dashboard.js` — состояние `weekOffset`, prev/next (переиспользовать `.jm-arrow`), опорный понедельник = `today + weekOffset*7` (вместо вывода из `Object.keys(byDate).sort()[0]`, :120), рендер клиентский без нового AJAX; (в) `_dashboard.scss` — стили пейджера по аналогии с `.jm-arrow`/`.jm-label`.
- **Разбивка:** 1) `DashboardService.php` — расширить окно `week` вместо обрезки; 2) `dashboard.js` — `weekOffset` + prev/next-контролы + пересчёт понедельника; 3) `_dashboard.scss` — стили пейджера.
- **Оценка:** M — межфайловая правка (PHP-агрегация + состояние/контролы JS + CSS) по готовому прецеденту, без нового AJAX-контракта.

---

## Фаза 11 — синхронизация UI.md (НБ-5)

### НБ-5 — актуальность задач в UI.md
- **First cause:** `.docs/UI.md` (17 чекбоксов, Фазы 0–5) рассинхронизирован в обе стороны. Источник `.docs/UI-Audit.md` (ссылки 0.1–0.4) удалён коммитом `89775e9` — числа инвентаризации не проверяемы. 1.3 и 2.1 частично ложны (container-query не реализован — 0 совпадений `container-type` в `src/scss`; таб авторизации `inc/Modules/SocialAuth/templates/settings-tab.php:13-14` не на `fs-page-header`). Фазы 3–5 промаркированы `[ ]`, но **3.1 фактически готова** (все табы настроек на примитивах: `settings-1/3/5/6/8/9-*.php`), **3.2 и 4.1/4.2 частично** (page-header раскатан; `ui-helpers.js` покрывает только badge/empty; filter-bar/generic accordion/`fs-btn`-rollout/модалки — нет), реально не тронута только Фаза 5. ≥4 из 17 пунктов промаркированы неточно.
- **Фикс:** обновить `.docs/UI.md`: 3.1 → `[x]`; 1.3 — снять утверждение про `container-type`; 2.1 — пометить авторизацию как не мигрированную; 3.2 расщепить на готовое (page-header) и остаток (filter-bar/accordion/`fs-btn`); 4.1/4.2 → «в процессе»; 5.1/5.2 — корректно `[ ]` (цвета на токенах, но сырые отступы `12px/6px` в `_course-builder.scss:57,172`); решить судьбу ссылки на удалённый `UI-Audit.md`.
- **Разбивка:** 1) проставить/переформулировать 3.1/1.3/2.1; 2) расщепить 3.2 (готово vs остаток); 3) 4.1/4.2 → «в процессе»; 4) ссылка на `UI-Audit.md`. **Двойной `fs-page-header` на табах настроек** (`settings.php:50-59` + свой в каждом табе → вложенные `.fs-page-header`) — самостоятельный баг, ведётся в **Фазе 7 (задача 3)**, здесь не дублируется.
- **Оценка:** S — правки только в `.docs/UI.md`, кода не касаются.

---

## Исходные замечания (дословно)

1. Проверить, что при перезаходе в админку (сессия истекла, появилась модалка со входом) модалка скрывается. Сейчас внутри модального окна прогружается админка (поверх обычной).
2. Прятать вкладку Обучение без существования предметов (по аналогии со вкладкой "Предметы")
3. Тайминг для notice задели и классы с "notice notice-info inline fs-table__no-items". Нужно исправить, чтобы напоминания в стиле "Вы еще не создали ни одного предмета." не пропадали через 5 секунд. Возможно убрать им notice-info.
4. Проверить что записи удаляются из БД. Я удалил предмет, кабинеты и периоды обучения. Таблицы в БД должны быть чистыми, но остались записи в: wp_fs_lms_attendance, wp_fs_lms_group_lessons, wp_fs_lms_learning_events, wp_fs_lms_lesson_progress, wp_fs_lms_rooms, wp_fs_lms_submissions. Это был hard-delete, я ожидал подчистки всех таблиц. Заодно предусмотреть механизм очистки таблиц БД помимо hard-delete предмета
5. Создал предмет со слагом inf_ege. Но в таблице "Общее кол-во" задач и статей выводит 2. Хотя статей и задач нет. -> Проблема в том, что wp_posts не очищается при удалении предметов. Теперь там много мусора в виде старых уроков, статей и задач. Реши аналогично с пунктом 4.
6. Пусть при активном режиме заявок с кодом направление в форме подачи заявки под fs-apply-card__title показывается название направление (например, Информатика ЕГЭ)
7. Выровняй иконки в формах подачи заявки и join по центру полей ввода по вертикали
8. Поправь внешний вид табов предметов на страницах пункта "Обучение" (убрать подчеркивание и выдаление как у ссылок, сделать по аналогии с табами в пунтке FS LMS)
9. В типе заданий "Развернутый ответ" не требовать для публикации Решение для проверяющего (ни текст ни код) - заполняются по желанию
10. Требовать заполнения названий работ и контрольных перед публикацией
11. Добавь проверку - нельзя опубликовать курс с пустым шагом (без контента)
12. В профиле ученика не подтянулось название предмета (вместо него слаг)
13. Не могу перейти в плеер курса с профиля ученика. Я поставил урок на прошедшее число, а у ученика висит статус "Закрыт"
14. Проблема с расписанием. У группы расписание такое Понедельник 17:00 - 18:30 · Каб 317, Четверг 17:00 - 18:30 · Каб 317. А у ученика стоит занятие 6.07 в 09:00. Добавь еще рядом с названием номер кабинета.
15. Вход в плеер курса должен быть у ученика в Мои курсы -> клик по уроку переносит в соответствующий урок курса. Добавить кнопку "Перейти к курсу" в prof-card-head (в конец). У преподавателя и администратора под пунктом Мои группы должен быть аналогичный "Мои курсы" (в том же стиле что и группы) с перечнем его курсов (уже прикреплённых к группам). Добавить возможность сворачивать группы и курсы. Предусмотреть вариант, что кол-во курсов перевалит за высоту экрана - найти решение (скролл + поиск?). + вход в плеер курса через админку "Просмотреть". У ролей, которым доступно редактирование курса должна быть еще кнопка "Редактировать" на каждом шаге, которая переносит в конструктор курса на этом же шаге.
16. Добавить паддинги в карточки prof-card на странице замен
17. Уменьшить шрифт в prof-group-chip, чтобы вмешалось 4 буквы (10 пикселей). Больше 4 букв не выводить в чип. Все чипы одного предмета имеют один цвет.
18. В расписании на неделю выводить все дни недели (с понедельника по воскресенье), а не только те, в которые есть занятия. В пустых писать "Занятий нет".
19. У роли FSOfice недоступна админка. Проверить это запреты плагина или мои настройки (должна быть доступна админка у всех кроме преподавателя (и студента с родителем))
20. При наличии сверху админ бара - съедается снизу пространство под prof-side-user


Новые баги:
1. По пункту 8: теперь расположение табов не там, должно быть между notice fs-lms-learning-notice и списком subsubsub. При перезагрузке дёргается вёрстка. Есть варианты более нативно все разместить, чтобы текст не прыгал по экрану? Как привести к стандартному виду (как в меню FS LMS):
```php
	<div class="wrap">
                <div class="fs-page-header">
            <div class="fs-page-header__content">
                <h1 class="fs-page-header__title">Работа с пользователями</h1>

            </div>

            <p class="fs-page-header__desc">
                Здесь обрабатываются заявки и происходит зачисление и отчисление учеников
            </p>
            
        </div>
        
        <!--  Навигация по табам -->
        
        <h2 class="nav-tab-wrapper">
            <?php foreach ( $tabs as $tab_id => $tab ) : ?>
                <a href="?page=<?php echo esc_attr( $_GET['page'] ?? '' ); ?>&tab=<?php echo esc_attr( $tab_id ); ?>"
                   class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $tab['title'] ); ?>
                </a>
            <?php endforeach; ?>
        </h2>

		<!-- Содержимое активной вкладки -->
		
		<div class="tab-content">
			
		</div>
		
	</div>
```
Проверь чтобы все страницы в админке были в этом же виде (заголовок, описание, табы (где нужны), кнопки при необходимости).

2. При ссылке на конкретный урок и шаг (например r&course=16668&lesson=16679&step_ref=16675) у курса появляется инпут titlediv, которого быть не должно и нет в стандартном конструкторе курса
3. В журнале в hd-col должна быть только дата - убрать  hd-dow hd-room
4. Toast "Занятие еще не прошло" должно иметь слева от текста не галку, а крестик
5. Проверь актуальность задач в UI.md: они не выполнены, или выполнены, но не отмечены.
6. У page-title-action и button разные размеры. Я не могу просто применить заливку через button-primary. Тогда нужно либо сделать аналогичный -primary класс, либо привести все к одному (лучше стандартному button). Это особенно видно по странице groups.php - кнопки и page-title-action сильно отличаются по размерам. Но второй нюанс - тогда текст в h1 fs-page-header__title будет сильно отличаться от высоты Button. -> Найти решение
7. Добавил в курс (16682) новый урок - он так и не появился у преподавателя в КТП.
8. Выпадающее меню в ЛК преподавателя для добавления индивидуального занятия (prof-grade-pop open) вылезает за экран. Также не соответствуют общему стилю. Выпадающее меню "Кабинет" вообще не стилизовано 
9. Как происходит присваивание индивидуальному занятию урока? Нужно сделать UI в котором преподаватель может выбрать урок из существующих по предмету и присвоить индивидуальному занятию. Моё предложение - в КТП появляется новый слот с пометкой, что это Индивидуальное занятие с "Имя ученика" к этому слоту можно присвоить урок из Банка. Лучше в выборе групп добавить пункт Индивидуальные занятия. Тогда и UI будет лучше подстроен. И в сайдбаре будет нет Темы курса, а Уроки курса. Со всеми доступными уроками (сначала из курса, затем после разделителя все остальные), сверху - строка поиска урока по названию. В расписании преподавателя на день или неделю отображение аналогичное: Время, ФИО ученика, Тема занятия. (слева полоска фиолетовая как у всех индивидуальных занятий)
10. Почему в блоке "Занятия сегодня" (prof-stat-tile) написано 1 занятие и 2 группы. По факту сегодня было 1 занятие с 1 группой. Поправь отображение - кол-во занятий и кол-во групп должно совпадать с расписанием (например 3 занятия, 2 группы, 1 инд.)
11. Добавить пагинацию в расписании в ЛК для отображения "Неделя"