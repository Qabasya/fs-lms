# План: урок группы как снапшот курса + шаг «Трансляция»

> Аудит: ветка `stage_11`, 2026-07-24.
> Цель: курс методиста — мастер; занятие группы получает свою редактируемую копию урока
> (copy-on-write при первой правке преподавателя); чекбокс «запись занятия» у видео-шага
> заменяется отдельным типом шага «Трансляция»; преподаватель видит/меняет запись прямо
> в шаге и открывает урок своей группы в плеере из ЛК.
>
> Порядок этапов = порядок исполнения; каждый этап — отдельный коммит/PR.
> После каждого этапа: `npx gulp build`, `npm run lint:js`, PHPUnit, проверка в Docker
> (`docker restart wp_app` после PHP-правок).
>
> **Зафиксированные решения:**
> - Снапшот — **copy-on-write**: форк создаётся сервером при первой правке шагов преподавателем,
>   а не при назначении урока в КТП. Пока преподаватель не трогал урок — группа видит мастер
>   (правки методиста доезжают).
> - Преподаватель **не имеет доступа к билдеру курса** (структура, модули, 72 урока — только
>   методист, `AuthorLmsCourses`). Преподавателю доступен только состав шагов урока своей
>   группы (`ManageLmsTeaching` + `GroupAccessGuard::canManage`).
> - Преподаватель задачи из банка **только выбирает**, не создаёт (read-only доступ к кандидатам).
> - `step_settings_overrides` остаётся как есть (настройки шага — отдельно от состава;
>   `EffectiveStepSettingsResolver` работает и для форка).
> - Шаг «Видео» остаётся для заранее записанных материалов; «Трансляция» — новый тип:
>   до загрузки записи — ссылка на трансляцию (заглушка), после — само видео
>   (python-сервис → REST `/videos` → `recording_url`, этот контур не меняется).
>
> **Статус выполнения:**
> - ✅ Этап 1 — шаг «Трансляция» + миграция `recording_slot` + выпил rec-pop из КТП (2026-07-24, коммит `1e93078`)
> - ✅ Этап 2 — плеер урока группы для преподавателя (просмотр «как презентация») (2026-07-24, коммит `68f7ef8`)
> - ✅ Этап 3 — COW-форк + teacher-endpoints (шаги, банк, записи) (2026-07-25, коммит `07b680a`)
> - ✅ Этап 4 — редактор шагов в плеере для преподавателя (2026-07-25, коммит `ebb87c0`)
> - ✅ Этап 5 — гигиена форков (бейдж «изменён», сброс к курсу, удаление с группой) (2026-07-25, коммит `018c8e2`)

---

## Контекст (что уже есть — не переписываем)

| Механизм | Где | Используем |
|---|---|---|
| Форк урока для группы | `inc/Services/Course/ContentCloneService.php:189` (`forkLessonForGroup`, идемпотентен, meta `ForkedFrom`/`ForkedForGroup`, перецепляет `group_lessons.lesson_id`) | как есть, вызов из нового teacher-endpoint |
| AJAX-обёртка форка | `inc/Callbacks/Course/CloneCallbacks.php:88` (`AuthorLmsCourses`, из JS никто не зовёт) | остаётся методисту; преподавателю — свой шов (Этап 3) |
| Плеер занятия группы | `inc/Controllers/Course/LessonPlayerController.php` (`?gid&gl`), `inc/Services/Course/LessonPlayerService.php:46` | добавляем teacher-ветку (Этап 2) |
| Подмена записи | `LessonPlayerService.php:86-89` — фильтр `fs_lms_recording_url`, модуль VideoLibrary подписывает `s3://` | как есть, переезжает на broadcast-шаг |
| Ручная привязка записей | `inc/Modules/VideoLibrary/Callbacks/VideoLibraryCallbacks.php` (lessons/attach/detach, `Capability::Admin`) | teacher-варианты (Этап 3) |
| Редактор шагов | `src/js/admin/services/step-editor.js` (`createStepEditor`, уже монтируется на 2 поверхностях) | третья поверхность — плеер (Этап 4) |
| Форки скрыты из банка | `inc/Managers/Course/LessonManager.php:74-84` | как есть |

---

## Этап 1 — шаг «Трансляция» (`broadcast`) + миграция + выпил rec-pop

Семантика: инлайн-шаг урока. Payload: `{ title, stream_url }`. Рендер: если у занятия есть
`recording_url` (через фильтр `fs_lms_recording_url`) — видео-плеер (native/embed, как у видео);
если нет — плашка «Идёт/будет трансляция» со ссылкой `stream_url` (заглушка до интеграции
с плагином трансляций). Описание/главы/вложения не поддерживаются (как у нынешнего слота).

### PHP

- `inc/Enums/Course/StepType.php`
  - новый кейс `case Broadcast = 'broadcast';` + `label()` → «Трансляция»;
  - `isInline()` → true для `Broadcast`; `allowedTypesFor(LEVEL_LESSON)` покрывается `cases()` —
    убедиться, что `LEVEL_WORK/LEVEL_ASSESSMENT` не задеты (там только `Task`).
- `inc/Callbacks/Course/LessonCallbacks.php`
  - `sanitizeStep()` (строки 220-249): ветка `'broadcast' => array( 'title' => …, 'stream_url' => sanitizeTextValue(url) )`;
  - из ветки `'video'` удалить `recording_slot` (строки 229-231) — после миграции флага больше нет.
- `inc/Services/Course/StepContentRenderer.php`
  - новый `renderBroadcastData( StepDTO $step, ?string $recordingUrl ): array` —
    `{ url (подписанная запись или ''), mode (resolveVideoMode), stream_url }`;
    переиспользовать `resolveVideoMode()`;
  - `renderVideoData()` (строки 280-295): убрать `$isSlot`-логику и параметр `$recordingUrl`
    (видео больше не подменяется записью); `resolveVideoUrl()` (365-375) упростить до `payload['url']`.
- `inc/Services/Course/LessonPlayerService.php`
  - `renderData()` (строки 82-95): `'video'` больше не получает `recording_url`;
    новая ветка `'broadcast' => renderBroadcastData( $step, apply_filters( 'fs_lms_recording_url', … ) )`.
- `inc/Services/Course/CoursePreviewService.php` — broadcast в preview: `recordingUrl = null`,
  рендерится заглушка со `stream_url` (проверить, что ветка не падает).
- `templates/frontend/lesson-player/partials/`
  - вынести разметку нативного плеера (строки 44-84 `step-video.php`) в общий партиал
    `partials/video-chrome.php` (используют video и broadcast);
  - новый `partials/step-broadcast.php`: бейдж типа, заголовок; запись есть → `video-chrome.php`
    или oembed; записи нет → плашка + кнопка-ссылка на `stream_url` (если задан) или
    «Запись занятия ещё не доступна»;
  - `step-video.php`: удалить ветку `$video_is_slot` (строки 26, 94-95);
  - `player.php`: `case 'broadcast'` в switch (строки 199-215).
- Прохождение: broadcast без записи — инлайн-шаг, «Далее» отмечает пройденным (как text);
  с записью — работает существующий «досмотрел до конца» из `step-video.js` (тот же chrome).
  Проверить `LessonGateResolver`/`LessonProgressService` на неизвестный ранее тип — там
  завязка на `step.key`, типозависимой логики быть не должно.

### Миграция данных (`recording_slot` → `broadcast`)

- `inc/Migrations/Migration_1_0_0.php` → секция Cleanup, идемпотентный проход:
  для каждого предмета (`SubjectRepository`) по всем постам `{key}_lessons` (включая форки, любой статус)
  прочитать `PostMetaName::Meta`, шаги `type=video && payload.recording_slot` → `type='broadcast'`,
  `payload={ title, stream_url:'' }`. **`key` шага не менять** — на него завязан прогресс
  (`lesson_progress`) и `step_settings_overrides`.
- Dev-прогон: `UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';` + перезагрузка страницы.

### JS / SCSS

- `src/js/admin/services/step-editor.js`
  - `TYPE_UI` (31-37): `broadcast: { ui: 'broadcast', name: 'Трансляция', inline: true }`;
  - `ADD_TYPES` (40-46): `{ type: 'broadcast', desc: 'Ссылка на трансляцию, после занятия — запись' }`;
  - редактор тела: одно поле «Ссылка на трансляцию» (`stream_url`) + хинт «после занятия сюда
    автоматически привяжется запись»;
  - из video-редактора удалить чекбокс `data-recording-slot` и связанную логику (строки 493-542).
- `src/js/common/icons.js` — `STEP_GLYPHS.broadcast` (глиф «эфир»: камера/антенна с волнами, viewBox 24×24).
- `src/js/player/icons.js` — `TYPES.broadcast = { label: 'Трансляция', c: …, soft: … }`,
  `TYPE_UI.broadcast = 'broadcast'` (34).
- `src/scss/shared/_tokens.scss` — цвет в `$step-type-palette` (+`-soft`); JS-значения — зеркально
  (правило синхронизации из шапки `player/icons.js`).
- Стили `step-broadcast` (плашка-заглушка, кнопка трансляции) — `src/scss/player/components/`.

### Выпил rec-pop из КТП

- `src/js/profile/ktp.js` — удалить `openRecordingPopover` (716-745) и `attachRecordingClick` (709-714);
  камеру-индикатор (`recordingIconHtml`, 312-326) оставить, клик → переход в плеер занятия
  (`?gid&gl`, ссылка появится в Этапе 2).
- `inc/Callbacks/Course/ProgramCallbacks.php::ajaxSetRecordingUrl` (587-601) — **оставить**:
  переиспользуется тич-панелью broadcast-шага (Этап 4) как ручной фолбэк.

### Проверка

- Урок с бывшим slot-шагом: после миграции тип `broadcast`, прогресс учеников на месте.
- Занятие с привязанной записью → в шаге видео; без записи → заглушка/ссылка на трансляцию.
- VideoLibrary выключен (`video_enabled=false`) → `s3://`-указатель не рендерится (graceful absence, как раньше).
- Тесты: `tests/Unit/Services/Course/` — рендер broadcast (с записью/без/с выключенным модулем), санитайз шага.

---

## Этап 2 — плеер урока группы для преподавателя

Сейчас `LessonPlayerController::loadTemplate()` при `canManage` отдаёт кокпит (строки 66-73) —
преподаватель вообще не попадает в плеер. Делаем teacher-режим: просмотр урока **своей группы**
(именно версии группы — `group_lessons.lesson_id`, после форка это копия группы).

### PHP

- `inc/Controllers/Course/LessonPlayerController.php`
  - в ветке `canManage` (69-71): вместо `return $template` — рендер плеера в teacher-режиме
    (сохранить текущее поведение кокпита при отсутствии `gl` — не трогается);
  - teacher-view: `personId = 0`, прогресс не читается и не пишется, все гейты открыты,
    `$is_teacher = true` в шаблон.
- `inc/Services/Course/LessonPlayerService.php` — режим без ученика: `buildView()` с
  `studentPersonId = 0` — статусы `Available`, гейт `Open`, попытки/сдачи не грузить
  (или отдельный `buildTeacherView()`, если ветвление разрастается — решить по месту).
- `inc/Services/Course/CourseNavService.php` — `shell()`/`tree()` для `personId = 0` (без прогресса).
- `templates/frontend/lesson-player/player.php`
  - `$is_teacher`: бейдж «Режим преподавателя» вместо прогресс-бара (110-125), «Далее» не пишет
    `markStep` (в `core.js` — по `data-teacher="1"` на `#fsPlayerApp`);
  - существующий `$can_edit`-линк в админский билдер (172-184) в teacher-режиме не показывать
    (у преподавателя нет прав на билдер; для методиста в preview — как было).
- `src/js/player/core.js` — при `data-teacher="1"` не звать `markStep` (64-75).

### Ссылки из ЛК

- `src/js/profile/ktp.js` — клик по карточке занятия (`placed-theme`) и по камере-индикатору →
  `?gid={group_id}&gl={group_lesson_id}` (URL кокпита уже известен фронту);
  сервер: `ProgramCallbacks` (getCalendar) — добавить `player_url` в данные темы, по образцу
  `l.player_url` у ученика (`learner.js:631`).
- `src/js/profile/dashboard.js` — «следующее занятие» → тот же `player_url`.

### Проверка

- Преподаватель группы открывает занятие из КТП → плеер с шагами именно этой группы; посторонний → 404.
- Прогресс ученика от захода преподавателя не меняется (`lesson_progress` без новых строк).
- Ученик — без изменений поведения.

---

## Этап 3 — COW-форк + teacher-endpoints

Ключевой принцип: клиент передаёт **`group_lesson_id`, а не `lesson_id`** — сервер сам резолвит
урок, форкает при необходимости и никогда не даст преподавателю задеть мастер или чужую группу.

### Enums

- `inc/Enums/Wp/Nonce.php` — `case TeachLesson = 'fs_lms_teach_lesson';`
- `inc/Enums/Wp/AjaxHook.php` — новые кейсы:
  - `GetGroupLessonSteps` — шаги урока занятия (для монтирования редактора);
  - `SaveGroupLessonSteps` — сохранение шагов с COW-форком;
  - `TeacherStepCandidates`, `TeacherTaskPreview`, `TeacherRefPreview` — read-only банк;
  - `TeacherListRecordings`, `TeacherAttachRecording`, `TeacherDetachRecording` — записи (модуль);
  - `ResetLessonFork` — Этап 5.

### PHP

- Вынести `sanitizeStep()`/`sanitizeChapters()` из `LessonCallbacks` (211-290) в сервис
  `inc/Services/Course/LessonAuthoringService` (там уже живёт `buildSteps()`), чтобы методист и
  преподаватель шли через один санитайз. `LessonCallbacks` делегирует.
- Новый `inc/Callbacks/Course/TeacherLessonCallbacks.php` (все методы: `Nonce::TeachLesson->verify()`
  → `GroupAccessGuard::canManage( $row->groupId, get_current_user_id() )`, `authorize()` с
  `ManageLmsTeaching` + ручная проверка группы):
  - `ajaxGetGroupLessonSteps( group_lesson_id )` → `{ lesson_id, subject_key, is_forked, steps[] }`;
  - `ajaxSaveGroupLessonSteps( group_lesson_id, steps[] )`:
    1. найти строку, проверить `canManage`;
    2. **COW**: если meta `ForkedForGroup` урока ≠ `groupId` → `ContentCloneService::forkLessonForGroup()`;
    3. санитайз + `buildSteps` + лимит `MAX_STEPS_PER_LESSON`;
    4. `LessonManager::update( forkId, … )` + `visibilityService->syncExtraWorksForOpenOccurrences( forkId )`;
  - `ajaxTeacherStepCandidates` / `ajaxTeacherTaskPreview` / `ajaxTeacherRefPreview` — тонкие
    обёртки над теми же сервисами, что у методиста (`getStepCandidates` и превью), read-only.
    Экшены создания (задач/работ/контрольных/черновиков) преподавателю НЕ дублируются.
- Новый `inc/Controllers/Course/TeacherLessonController.php` (`ServiceInterface`, регистрация
  `wp_ajax_*` хуков) + добавить в `Init::getServices()`.
- `inc/Modules/VideoLibrary/Callbacks/VideoLibraryCallbacks.php` — teacher-методы:
  - `ajaxTeacherListRecordings( group_lesson_id )` — записи группы занятия
    (`VideoRecordingRepository`: добавить `listByGroup( groupId )`, если нет) —
    привязанная к этому занятию + unmatched этой группы;
  - `ajaxTeacherAttach` / `ajaxTeacherDetach` — те же `VideoRegistrationService::attachManually/detachManually`,
    но с `Nonce::TeachLesson` + `canManage` по группе занятия (и записи — проверить, что
    `recording.group_id` совпадает с группой занятия). Админские V9-экшены не трогаем.
  - Регистрация — `inc/Modules/VideoLibrary/Controllers/VideoLibraryController.php`.

### Тесты

- `tests/Unit/Callbacks/Course/TeacherLessonCallbacksTest.php`: COW (первое сохранение форкает,
  второе — нет), запрет чужой группы, запрет по мастеру без форка, лимит шагов.
- Расширить `tests/Unit/Services/Course/ContentCloneServiceTest.php`, если COW-обвязка ляжет в сервис.

### Проверка

- Через `admin-ajax` преподавателем: сохранение шагов создаёт форк, мастер не изменён,
  `group_lessons.lesson_id` перецеплен; повторное сохранение пишет в тот же форк.
- Методист правит мастер → нетронутые группы видят правку, форкнутая — нет.

---

## Этап 4 — редактор шагов в плеере (преподаватель)

### Бандл

- Новая точка входа `src/js/teacher-editor/teacher-editor.js`: импортирует `createStepEditor`
  из `admin/services/step-editor.js`; gulpfile — новый бандл `assets/js/teacher-editor.min.js`
  (зависимость jQuery — редактор jQuery-based, плеер — нет; бандлы не смешивать).
- `inc/Core/Enqueue.php::enqueue_player_assets()` (327-…): если текущий пользователь
  `canManage` группы из `?gl` — дополнительно `wp_enqueue_script('fs-lms-teacher-editor', …, ['jquery'], …)`,
  `wp_enqueue_editor()` (TinyMCE для text-шагов), `wp_enqueue_media()` (вложения видео);
  локализовать `fs_lms_vars`-совместимый объект: `ajaxurl`, `ajax_actions` (teacher-экшены),
  `nonces.teachLesson`, `subject_key`, `group_lesson_id`. Все `wp_localize_script` — только здесь.

### step-editor.js — параметризация шва

- `createStepEditor(opts)` — новые opts: `actions` (карта экшенов), `nonce`, `saveParams`
  (доп. параметры сейва — `{ group_lesson_id }` вместо `{ lesson_id, subject_key }`);
  `acts()` (53), `nonceFor()` (65-72), `ajax()` (74), `saveSteps()` (961-970) — читать из opts
  с фолбэком на текущее поведение (админские поверхности не трогаются);
- режим `readOnlyBank: true` — скрыть в пикере кнопки «создать» (черновики работ/контрольных),
  оставить только выбор из кандидатов.

### UI в плеере

- `player.php`: для `$is_teacher` — кнопка «Настроить урок» в топбаре; панель-контейнер
  `#fsTeacherEditor` (скрытая), в неё `teacher-editor.js` монтирует `createStepEditor`
  (шаги — через `GetGroupLessonSteps`); после сохранения — перезагрузка страницы плеера
  (шаги рендерятся сервером — честнее, чем частичный ре-рендер).
- Первая правка → тост «Урок скопирован для вашей группы — изменения не затронут курс» (флаг
  `forked: true` в ответе сейва).
- Тич-панель broadcast-шага (в `step-broadcast.php`, только `$is_teacher`):
  текущая запись (есть/нет), список кандидатов (`TeacherListRecordings`) с «Привязать»/«Отвязать»,
  ручной ввод URL — фолбэк через существующий `setRecordingUrl`. JS — в `teacher-editor.js`.
- SCSS: панель редактора поверх плеера — `src/scss/player/` (или отдельный лист бандла
  teacher-editor), токены — из `shared/_tokens.scss`.

### Проверка

- Преподаватель добавляет шаг → COW-форк, ученик группы видит новый шаг, другие группы — нет.
- Пикер задач: банк виден, кнопок создания нет; превью задач работает.
- Привязка записи из панели шага → у ученика в broadcast-шаге появляется видео.
- Ученик/аноним teacher-бандл не получает (проверить исходники страницы).

---

## Этап 5 — гигиена форков

- **Бейдж «изменён для группы»** в КТП: `ProgramCallbacks` (getCalendar) — флаг `is_forked`
  по meta `ForkedForGroup` уроков тем (батчем, без N+1 — `update_postmeta_cache` по списку
  lesson_id); `src/js/profile/ktp.js` — метка на карточке (`placedThemeHtml`, 328-343) и в банке тем.
- **Сброс к версии курса**: `ajaxResetLessonFork( group_lesson_id )` в `TeacherLessonCallbacks` —
  `canManage` → `group_lessons.lesson_id` вернуть на meta `ForkedFrom`, форк-пост удалить
  (метод в `ContentCloneService`, рядом с форком + юнит-тест). UI — кнопка в панели редактора
  с `ConfirmModal` («правки группы будут удалены»). Прогресс по шагам с теми же `key` сохранится,
  по добавленным преподавателем шагам — потеряется (озвучить в конфирме).
- **Удаление группы**: в флоу удаления (`GroupsRepository` / соответствующий сервис — найти по
  месту) добавить удаление уроков с meta `ForkedForGroup = {groupId}` (через `ContentCloneService`,
  не прямым `WP_Query` — по правилам слоёв).
- `.docs/Courses.md` / `.docs/basic_doc.md` — дописать раздел про COW-форк и шаг «Трансляция».

### Проверка

- Форк → бейдж в КТП; сброс → бейдж исчез, ученики видят мастер-версию.
- Удаление группы не оставляет постов-форков (`wp post list --post_type={key}_lessons`).

---

## Порядок и зависимости

```
Этап 1 (broadcast)  ──┐
                      ├─→ Этап 4 (редактор в плеере: нужен teacher-режим плеера и endpoints)
Этап 2 (teacher-плеер)┤
Этап 3 (COW+endpoints)┘
Этап 5 (гигиена) — после 3-4
```

Этапы 1 и 2 независимы, можно параллельно. Самый объёмный — Этап 4 (новый бандл + параметризация
step-editor). Самый рискованный — миграция в Этапе 1 (прогнать на копии прод-БД до релиза).

---

# План рефакторинга по итогам ревью этапов 1–5

> Ревью 2026-07-25, диапазон `b3cb68b..018c8e2`, 4 независимых прохода
> (правила PHP · правила JS/SCSS · SOLID/архитектура · мёртвый код).
> Каждая находка подтверждена по коду (файл:строка). Приоритет: Р0 — баги,
> чинить до релиза; Р1 — быстрые победы; Р2 — архитектурная дедупликация;
> Р3 — чистка и документация. Effort: S — до часа, M — полдня.

## Р0 — баги и безопасность (до релиза)

- ⬜ **Р0.1 [M] Миграция `recording_slot` не выполнится на живых инсталляциях.**
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
- ⬜ **Р0.2 [S] XSS-зазор в тич-панели записей.** `teacher-editor.js:144-160`
  (`renderRecordingsPanel`) вставляет `r.s3_key`/`r.recorded_at`/`r.id` в `innerHTML`
  и `data-*` без экранирования (`s3_key` — внешний ввод из push-регистрации).
  Обернуть в `esc()` (как в `step-editor.js`), `r.id` — через `parseInt`.
- ⬜ **Р0.3 [S] Тич-панель broadcast-шага мертва до открытия редактора.**
  `initBroadcastPanels()` вызывается только из `bootstrap()` (первое открытие
  drawer) — до этого `.broadcast-teacher-panel` вечно «Загрузка записей…».
  Вызывать на `DOMContentLoaded` независимо от монтирования редактора.
- ⬜ **Р0.4 [S] «Редактировать ↗» из плеера ведёт на 404.** `step-editor.js:667,773` —
  относительный `href="post.php?post=…"`; в админке ок, на маршруте плеера
  (`/group/…`) → `/group/post.php`. Добавить `opts.adminBase`
  (дефолт `fs_lms_vars.ajaxurl.replace('admin-ajax.php','')`), в teacher-бандле
  локализовать `admin_url()` через `Enqueue`.
- ⬜ **Р0.5 [S] Невидимая кнопка перехватывает клики по карточке КТП.**
  `_ktp.scss:170-172` — `.pt-deadlines` с `opacity:0` без `pointer-events:none`
  лежит поверх начала `.pt-title`: клик по первым символам темы открывает
  поповер дедлайнов вместо перехода в плеер. Фикс: `pointer-events:none` +
  `:hover → auto`. Тот же дефект у `.pt-more` (преэкзистентный) — заодно.
- ⬜ **Р0.6 [S] `TaskPreviewService` читает неверные/несуществующие ключи меты.**
  `task_text` — это поле «Решение» (`TaskTextSolution`), а сервис показывает его
  как условие; ключи `problem_text|question_text|content|answer|answer_text|correct_answer|task_solution|solution|hint`
  не существуют ни в одном шаблоне (мёртвые ветки); в блок «Решение» уезжает
  `task_hint` (подсказка). Условие → `['common_condition','task_condition']`,
  решение → `['task_text']`, подсказку — отдельным `hint_html` (+секция в JS).
- ⬜ **Р0.7 [S] (hardening) `SaveGroupLessonSteps` не валидирует `payload.ref`.**
  Преподаватель (и методист — зазор преэкзистентный) может прицепить к шагу
  ref-ид чужого предмета. Проверять принадлежность ref предмету урока в
  `LessonAuthoringService::buildSteps()` или на сейве.

## Р1 — быстрые победы

- ⬜ **Р1.1 [S] Разжать `teacher-editor.min.js` вдвое (59.8 КБ → ~30).**
  Удалить мёртвый `import { TaskEditor }` из `step-editor.js:4` (не используется,
  но тащит task-editor+task-fields+confirm-modal+modal-base в оба бандла);
  добавить `"sideEffects": ["*.scss"]` в `package.json`.
- ⬜ **Р1.2 [S] Судьба `SetRecordingUrl` — единственный невыполненный пункт плана.**
  Этап 4 обещал ручной ввод URL записи как фолбэк в тич-панели — не сделано;
  endpoint жив, но фронт-потребителей 0 (`ProgramCallbacks:587`, кейс AjaxHook,
  регистрация в `ScheduleController:42`, ключ в `ProfileViewResolver:194`).
  Решить: (а) доделать фолбэк в тич-панели broadcast (рекомендуется — это
  замысел плана) ИЛИ (б) выпилить всю цепочку. В обоих случаях: убрать
  `data-url` и «изменить/добавить ссылку вручную»-тайтлы с `.pt-recording`
  в `ktp.js:328,331` (кнопка теперь просто ведёт в плеер).
- ⬜ **Р1.3 [S] Стили wp-admin `.button` в плеере.** В тич-редакторе без стилей:
  «Выбрать существующую», «+ Глава», «+ Файл…», «Привязать» (`.button-primary` —
  0 правил в player.min.css), «Отвязать» (`.fs-sb-btn-danger` не подключён).
  ~8 строк в `_teacher-editor.scss` со скоупом `#fsTeacherEditor, .broadcast-teacher-panel`
  через существующие миксины `admin/_mixins.scss` (`cb-ghost-button`/`cb-chip-solid`).
  Заодно: стили для `.tep-loading`, решить судьбу пустых `.tep-reset`/`.teacher-editor-mount`.
- ⬜ **Р1.4 [S] Admin-палитра утекла в плеер.** `@use admin/_variables` из
  `_teacher-editor.scss` приносит в `player.min.css` второй `:root` с
  `--color-primary: var(--wp-admin-theme-color,…)` — фокус инпутов `.fs-se`
  красится admin-синим вместо `var(--accent)`. Вынести `:root`-мост из
  `admin/_variables.scss` в `admin/_wp-bridge.scss`, подключаемый только из `admin.scss`.
- ⬜ **Р1.5 [S] Мёртвый CSS в player.min.css.** `@use admin/components/course-builder`
  затянул 89 вхождений `.fs-lms-cb-wrap` (дерево модулей, футер билдера), из
  которых плееру нужны только `.fs-cb-popover/.fs-cb-picker/.fs-cb-pick-*`.
  Вынести попап-пикер в `admin/components/_picker-popover.scss`, в player
  подключать только его.

## Р2 — архитектурная дедупликация

- ⬜ **Р2.1 [S] `GroupAccessGuard::findManageableLesson()`.** Блок
  «`groupLessons->find` → `canManage` → `error('Занятие не найдено.')`»
  повторён 7 раз (`TeacherLessonCallbacks` ×4, `VideoLibraryCallbacks` ×3),
  с уже начавшимися расхождениями. Guard получает `GroupLessonRepository`,
  метод возвращает `?GroupLessonDTO`; `error()` остаётся в Callback-слое.
  (Родственный `canWriteJournal`-вариант ×4 в Journal/GradingCallbacks — опционально.)
- ⬜ **Р2.2 [S] Один владелец deep-link плеера и критерия форка.**
  `playerUrl()` скопирован в `LearnerService:456`, `DashboardService:244`,
  `ScheduleService:489` (+инлайн в `AssessmentPageController:238`) →
  `PageRoutes::GroupCockpit->lessonUrl(int $groupId, int $glId)`.
  Критерий «урок — форк группы» в 5 местах (`TeacherLessonCallbacks:77`,
  `ScheduleService:458`, `ContentCloneService:197,245,280`) →
  `ContentCloneService::isForkedForGroup()` + батч `forkedLessonIds(int $groupId, array $ids)`
  (внутри `primeMetaCache`). Бонус: из `ScheduleService` уходят `PostManager`/`PostMetaName`,
  из `deleteForksForGroup()` — N+1 по мете.
- ⬜ **Р2.3 [S] Схлопнуть дубли плеера.** `buildView`/`buildTeacherView`
  (22 строки, различие в 2) → приватный `assembleSteps(..., ?array $statuses)`;
  инлайн-ветки `text|video|broadcast` из `LessonPlayerService::renderData()` и
  `CoursePreviewService::renderData()` → `StepContentRenderer::renderInlineData()`
  (новый инлайн-тип = правка одного файла). Заодно `_title`-петля с прямым
  `get_the_title` из `TeacherLessonCallbacks:80-87` → в сервис
  (есть `StepContentRenderer::resolveTitle`).
- ⬜ **Р2.4 [M] `TaskPreviewService` поверх существующих сервисов.**
  После Р0.6: сейчас это 4-е PHP-место (+5-е в JS `buildAnswerSection`) со
  знанием схемы `fs_lms_meta` задачи. Строить превью через
  `StepContentRenderer::taskBundle()` (шаблон через `TemplateResolver`, не
  «угадай-ключ») + `CorrectAnswerResolver`; отдавать нормализованный
  `widget_data.type`, `buildAnswerSection()` в step-editor.js переключить на него.
  Меняет контракт `GetTaskPreview`/`TeacherTaskPreview`/Ref-вариантов — делать
  отдельным коммитом с синхронной правкой JS. Заодно:
  `StepContentRenderer::buildFiles` → `TaskMetaService::getTaskFiles` (дубль).
- ⬜ **Р2.5 [M] `step-editor.js`: транспорт вместо пакета флагов.**
  `opts.actions/nonce/ajaxurl/extraAjaxParams/persist` связаны скрытыми
  инвариантами (nonce игнорируется без actions; extraAjaxParams только для
  кандидатов) → один `opts.transport = { actions, nonce, ajaxurl, params, persist }`
  с единственной точкой `request()`. Удалить мёртвые `showStepSettings`/`renderStepSettings`
  (30 строк, 0 вызовов) и `export` у `nonceFor`. Точек вызова две — правка локальная.
- ⬜ **Р2.6 [S] `Enqueue::enqueueBundle()`.** Пять почти одинаковых блоков
  «style+script+filemtime+localize» (profile/player/teacher/assessment/kege) →
  приватный хелпер. Заодно `(int) $_GET['gl']` (`Enqueue:422`) → `sanitizeGetInt('gl')`.
- ⬜ **Р2.7 [M] Разгрузить `LessonPlayerController::loadTemplate()`.**
  Ветвление режимов, сборка view/shell/tree, расчёт lock-таймера, сырые `$_GET` —
  вынести в `LessonPlayerService::buildRouteView(int $userId, GroupLessonDTO $row)`,
  в контроллере оставить маршрут + include. `$_GET` → Sanitizer-методы.

## Р3 — чистка и документация

- ⬜ **Р3.1 [S] Мёртвый код PHP:** `Sanitizer::sanitizeBoolValue()` (0 вызовов
  после выпила recording_slot); `sanitizeChapters()` → `private`; тернарник
  `$lessonGate` в `LessonPlayerController:81` (teacher-ветка не читается);
  `declare(strict_types=1)` в `AjaxHook.php`/`Nonce.php`/`Init.php`;
  стиль `error()`+`return` выровнять внутри `VideoLibraryCallbacks`.
- ⬜ **Р3.2 [S] Мёртвый код JS/SCSS:** `.rec-pop/.rec-input/.rec-actions` из
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
