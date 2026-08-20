# Bug fix

- [x] Конструктор экзамена (ЕГЭ/ОГЭ): заготовленные пустые слоты заданий (номера без
  выбранной задачи) пропадали после сохранения при частичном заполнении — `persist()`
  не отправляет пустые слоты (`taskId=0`) в `item_ids`, а авто-паддинг слотов до
  `egeSlots(kind)` в `onReady()` срабатывал только при полном отсутствии слотов
  (`length === 0`). После сохранения 2 из 27/16 заданий сервер на перезагрузке отдаёт
  только реально заполненные (`AssessmentMetaBoxController::renderBuilderContent()`,
  `$task_ids = $assessment->taskIds`) — остальные позиции терялись насовсем.
  Исправлено: `src/js/admin/services/assessment-builder.js::onReady()` теперь
  довозит слоты до `egeSlots(kind)` при каждом открытии, а не только при первом.

# Задача: связка 19-21 — реэнтерабельный save_post ломает bundle (2026-08-20)

Контекст: тестировали публикацию задания-связки «№19-21» (`TaskTemplate::Triple`,
`ThreeInOneTemplate`) — сперва увидели ошибку «Невозможно опубликовать задание:
Заполните «Условие задания»», а при повторе в глобальном банке (`fs_lms_problems`)
поймали **активную бесконечную рекурсию**: `wp_posts` разрослась с ~100 до 2000+
строк за минуты, каждая новая запись — заголовок предыдущей + ещё один префикс
«№ 19. » (пост `18153` докатился до строки на ~9600 байт). Остановлено только
перезапуском `wp_app` (PHP не прерывается по обрыву соединения без
`ignore_user_abort`). **Мусорные записи из докер-БД удалены** (см. пункт A4);
на проде аналогичный мусор ещё не выявлен и не почищен — см. пункт A5.

**Корень обеих проблем один и тот же**: `TaskBundleService::upsertChild()`
(`inc/Services/Task/TaskBundleService.php:163-197`) создаёт/обновляет 3 children
через `PostManager::insert()`/`update()` (`inc/Managers/Wp/PostManager.php:189-194,
258-262`), которые вызывают полновесные `wp_insert_post()`/`wp_update_post()` — а
значит **повторно** запускают весь жизненный цикл сохранения поста
(`wp_insert_post_data`, `save_post_{cpt}`, `save_post`) **в рамках того же PHP-запроса**,
где `$_POST` всё ещё содержит форму **родительского** поста-связки. Хуки, которые
читают `$_POST` без проверки, что это форма именно текущего `$post_id`, принимают
детей за parent и либо ломают им валидацию (banка задания предмета), либо —
что хуже — заново метят их как `triple_task` и рекурсивно создают ещё детей
(глобальный банк).

---

## A. КРИТИЧНО: бесконечная рекурсия при публикации связки в банке (`fs_lms_problems`) ✅ 2026-08-20

- [x] `inc/Controllers/Problems/ProblemsController.php:72` — `add_action('save_post_' . $cpt,
  array($this, 'saveTemplateType'))` — хук специфичного типа поста, WP fires его
  **раньше** обычного `save_post` (`wp-includes/post.php`: сперва
  `save_post_{$post_type}`, потом `save_post`).
- [x] Там же, `saveTemplateType()` (строки 123-134) — читает `$template_id =
  $this->sanitizeKey(PostMetaName::TemplateType->value)` **напрямую из `$_POST`**,
  без проверки, что `$post_id` совпадает с постом текущей формы (`$_POST['post_ID']`),
  и безусловно пишет это значение в мету **любого** `$post_id`, для которого сейчас
  сработал `save_post_fs_lms_problems` — включая только что вставленных детей связки.
- [x] Цепочка: `MetaBoxController::handleMetaSave(18153)` (родитель, `template_id ===
  'triple_task'`) → `TaskBundleService::syncChildren(18153)` → `upsertChild()` →
  `PostManager::insert()` создаёт child C1 → `wp_insert_post()` синхронно fires
  `save_post_fs_lms_problems(C1)` **до** `save_post(C1)` → `ProblemsController::
  saveTemplateType(C1)` ставит C1.meta[`fs_lms_template_type`] = `'triple_task'`
  (из того же родительского `$_POST`, который для C1 не имеет смысла) → следом
  срабатывает `save_post(C1)` → `MetaBoxController::handleMetaSave(C1)` →
  `TemplateResolver::resolveId(C1)` (`inc/Services/Template/TemplateResolver.php:81-84`,
  приоритет 2 — метаполе поста) **теперь видит** `triple_task` (только что
  проставленный) → считает C1 связкой → вызывает `TaskBundleService::syncChildren(C1)`
  **снова** → создаёт C2 с заголовком `"№ 19. " . C1->post_title` → рекурсия без
  дна, ограниченная только `max_execution_time`/`memory_limit`/обрывом соединения.
- [x] **Фикс (сервер, приоритет)**: `ProblemsController::saveTemplateType()` и
  `saveSubjectFields()` (обе на `save_post_fs_lms_problems`) должны игнорировать
  вызов, если `$post_id !== (int) ($_POST['post_ID'] ?? 0)` — стандартная защита от
  реэнтерабельного `save_post` (WP всегда шлёт `post_ID` в форме редактора текущего
  поста; для программных вставок внутри того же запроса это значение не совпадёт
  с ID только что созданного ребёнка).
- [x] **Фикс (архитектурный, устраняет класс проблемы)**: `TaskBundleService::
  upsertChild()` не должен создавать/обновлять children через `PostManager::insert()/
  update()` (полный `wp_insert_post()`/`wp_update_post()`, с фильтрами и экшенами).
  Завести в `PostManager` низкоуровневые `insertBypassingHooks()`/
  `updateBypassingHooks()` — по аналогии с уже существующим `updatePostContent()`
  (`inc/Managers/Wp/PostManager.php:264-283`, docblock прямо описывает эту же
  проблему для другого случая) — прямая работа с `$wpdb->posts` + `clean_post_cache()`,
  без единого `do_action('save_post', ...)`. Переключить `upsertChild()` на них.
- [x] **Фикс (defense-in-depth)**: `TaskBundleService::syncChildren()` — guard-флаг
  (реализован как instance-свойство `private bool $syncing = false` — свежий экземпляр
  на singleton-сервис даёт тот же эффект, что static, но не течёт между тестами),
  возврат `array()` при повторном входе в рамках одного запроса.
- [x] **A4. Чистка докер-БД** (сделано в диагностической сессии 2026-08-20):
  `DELETE FROM wp_posts WHERE post_type = 'fs_lms_problems' AND post_title LIKE
  '№ 19. № 19.%'` (потомки поста 18153, кроме него самого) + восстановить
  заголовок/статус самого 18153 вручную, `wp_update_post` не использовать
  (снова спровоцирует хук) — прямой SQL + `clean_post_cache()`.
- [ ] **A5. Чистка прод-БД** — выполнить теми же SQL-запросами через прямой доступ
  к проду (не WP-CLI/`wp_update_post`, чтобы не спровоцировать хук снова):
  ```sql
  -- посчитать масштаб
  SELECT COUNT(*) FROM wp_posts WHERE post_type = 'fs_lms_problems'
    AND post_title LIKE '№ 19. № 19.%';
  -- удалить мусорных детей/внуков (не трогает исходный parent-пост)
  DELETE FROM wp_posts WHERE post_type = 'fs_lms_problems'
    AND post_title LIKE '№ 19. № 19.%';
  -- почистить осиротевшую постмету удалённых ID (если удаляли не через wp_delete_post)
  DELETE pm FROM wp_postmeta pm
    LEFT JOIN wp_posts p ON p.ID = pm.post_id
    WHERE p.ID IS NULL;
  ```
  Затем вручную поправить заголовок исходного parent-поста связки (у него тоже
  накопился хвост «№ 19. № 19. ...» — см. A6) через прямой `UPDATE wp_posts SET
  post_title = '<оригинальный заголовок>' WHERE ID = <parent_id>` — **не** через
  редактор/`wp_update_post`, пока фикс A выше не выкачен, иначе повторит рекурсию.
- [x] **A6. Разгадано** (докер-сессия 2026-08-20): пост `18153` — НЕ parent, а
  рядовой мусорный пост из середины рекурсивной цепочки (реальный parent — `17163`,
  «ЕГЭ-19», не тронут; первый child — `17164`, «№ 19. ЕГЭ-19»; garbage-диапазон
  `17164..19130`, 1966 постов). `18153` выглядел как «родитель» только потому, что
  `ProblemsController::saveTemplateType()` **и** `saveSubjectFields()` (обе на
  `save_post_fs_lms_problems`, п. A2) на каждом уровне рекурсии копируют на текущий
  `$post_id` ПОЛНУЮ мету из того же неизменного `$_POST` — включая
  `fs_lms_bank_task_subject`/`fs_lms_bank_task_number` и весь `fs_lms_meta` с
  тестовыми значениями `task_19_condition` и т.д. Явление объясняется целиком
  пунктами A1-A3, отдельного бага для заголовка parent'а нет.
- [x] Тест-регресс: `TaskBundleServiceTest::test_reentrant_sync_children_call_is_ignored` —
  реэнтерабельный вызов `syncChildren()` внутри `insertBypassingHooks()` возвращает
  пустой массив, ровно 3 вставки за весь цикл (плюс существующие тесты обновлены на
  `insertBypassingHooks()`/`updateBypassingHooks()`).

## B. Баг публикации «Условие задания» для связки в банке заданий предмета (`{key}_tasks`) ✅ 2026-08-20

- [x] Тип задания — `inc/Enums/Subject/TaskTemplate.php:45` (`case Triple =
  'triple_task'`) → `inc/MetaBoxes/Templates/ThreeInOneTemplate.php` (поля
  `task_19_condition/task_19_answer`, `task_20_...`, `task_21_...`,
  `ConditionField`, строки 23-59). У обычных шаблонов поле условия —
  `task_condition` с меткой «Условие задания» (`StandardTaskTemplate.php:30`
  и 10 других шаблонов).
- [ ] Причина — тот же реэнтерабельный `wp_insert_post()` из `TaskBundleService::
  upsertChild()`, но в этом CPT он бьёт по другому хуку:
  `SubjectValidationCallbacks::validateRequiredTaxonomies()`
  (`inc/Callbacks/Subject/SubjectValidationCallbacks.php:76-118`), навешанному
  на `wp_insert_post_data` — срабатывает при вставке каждого child в рамках того
  же запроса, где `$_POST` — форма **родителя**.
- [ ] `effectiveTemplateId()` (строки 261-277) — для нового child `$postId` внутри
  фильтра `wp_insert_post_data` ещё равен `0` (ID не назначен на этой стадии),
  поэтому ветка `$this->templateResolver->resolveId($post)` не срабатывает; при
  этом `sanitizeKey(TemplateType)` тоже пусто (форма — родителя, поля `fs_lms_
  template_type` в ней для этого CPT нет — шаблон предметного parent'а назначается
  термом таксономии, см. `TemplateResolver` приоритет 1) → возвращает `''`.
- [ ] `TemplateRegistry::get('')` (`inc/Services/Template/TemplateRegistry.php:79-89`)
  фолбэчит через `TaskTemplate::fromDatabase('')` (`TaskTemplate.php:100-115`) →
  `Standard`.
- [ ] `effectiveMeta(0)` (строки 238-248) видит `hasParam('fs_lms_meta') === true`
  (форма родителя) и отдаёт **родительский** массив меты (ключи `task_19_condition`
  и т.п.), где `task_condition` нет → `TaskPublishValidator::getSoftError()`
  (`inc/Services/Task/TaskPublishValidator.php:48-84`) возвращает «Заполните
  «Условие задания»».
- [ ] `TaskPublishGuard::enforce()` → `forceDraft()` (`inc/Services/Task/
  TaskPublishGuard.php:107-128`) переводит **child** в `draft` и пишет транзиент
  `fs_lms_publish_error_<uid>`, который `showEmptyRequiredTaxNotice()`
  (`SubjectValidationCallbacks.php:285-295`) выводит на экране parent'а как общую
  ошибку публикации — вводя автора в заблуждение: сам parent публикуется нормально,
  ошибка относится к побочно создаваемым children.
- [ ] Фикс — тот же, что в A: после перехода `TaskBundleService::upsertChild()` на
  bypass-вставку (A, пункт «архитектурный фикс») эта проблема исчезает сама, т.к.
  `wp_insert_post_data` для children вызываться не будет. Отдельного фикса в
  `SubjectValidationCallbacks` не требуется — не плодить два параллельных
  обходных пути.
- [ ] Тест-регресс: после фикса A публикация `triple_task` в `{key}_tasks`
  переводит все 3 children в `publish`, `TaskPublishGuard` на них не срабатывает.

## C. Автозаполнение слотов 19/20/21 в конструкторе «Компьютерный ЕГЭ»

Сейчас каждый из трёх слотов (19, 20, 21) в конструкторе EgeComputer заполняется
вручную поиском по названию — даже если все три условия хранятся в одной связке.

- [ ] `src/js/admin/services/assessment-builder.js` — слоты позиционные
  (`buildEgeSlots()`/`blankSlot()`, строки 42-43), AJAX-поиск шлёт `position:
  String(index + 1)` (строки 165-171) → сервер фильтрует кандидатов строго по
  номеру позиции (`LessonAuthoringService::getStepCandidates()`,
  `inc/Services/Course/LessonAuthoringService.php:117-145`, `taskNumberQuery()`/
  `bankNumberQuery()` строки 152-185) — т.е. в слоте 19 ищутся именно
  **дети-№19** (термированные отдельно, `TaskBundleService::upsertChild()`
  строки 189-195), а не parent-связка.
- [ ] Готовый, но не подключённый к EGE-конструктору паттерн: `WorkAuthoringService::
  withBundleChildren()` (`inc/Services/Course/WorkAuthoringService.php:86-93`) и
  `LessonAuthoringService::candidatesFrom()` (`$withBundles`, строки 196-217)
  добавляют кандидату поле `bundle_children` через `TaskBundleService::
  childrenSummary()` (строки 110-134), а `src/js/admin/services/slot-builder.js::
  assignPicked()` (строки 204-237) при наличии `bundle_children` заменяет 1 слот
  на 3 (`splice`). Не подходит впрямую: `splice()` рвёт фиксированную связку
  «индекс слота = номер позиции» для EGE-конструктора (слоты 20+ съедут).
- [ ] **План**:
  1. Сервер — в ветке `kind==='task' && position!==''` (`LessonAuthoringService::
     candidatesFrom()` или соседний метод) для child-поста с метой
     `PostMetaName::TaskBundleParentId` (`inc/Enums/Wp/PostMetaName.php:59`)
     резолвить parent → `TaskBundleService::childrenSummary($parentId)` и класть
     в item `bundle_siblings: {19:{id,title}, 20:{...}, 21:{...}}`. Понадобится
     обратный маппинг child→parent в `TaskBundleService`/`PostManager` (сейчас
     `childrenSummary()` работает только parent→children).
  2. JS — отдельно в `assessment-builder.js` (не трогать общий `slot-builder.js`,
     чтобы не задеть Work builder), только для `isEge(kind)`: после выбора
     кандидата с `bundle_siblings` — для номеров 20/21 находить слот по индексу
     `Number(number) - 1` и присваивать туда сиблинга напрямую (без `splice`),
     затем один `save()`. Понадобится либо публичный метод `assignTaskAt(index,
     id, title)` в `api` из `createSlotBuilder`, либо хук `onPickWithSiblings`
     через `config`.
  3. Тест-регресс: пикнули задачу №19 из связки в EGE-слот → слоты 20 и 21
     сами проставились соответствующими детьми той же связки; для не-связочных
     задач поведение не меняется.

---

# Задачи: замены преподавателя — доступ и уведомления; поле контрольной (2026-08-20)

Контекст: два независимых запроса пользователя по системе замен (Эпик 5), плюс отдельная
правка конструктора контрольной, плюс переработка проверки работ (блок D).

**A.** Сейчас доступ замещающего к группе (чтение КТП, плеер урока, журнал) открывается/
закрывается по календарной проверке `valid_from <= CURDATE() <= valid_to`
(`SubstitutionRepository::hasActiveGrant()`), а не в момент утверждения замены. Если офис
назначает замену заранее («с понедельника»), препод не видит группу до наступления даты,
хотя замена уже утверждена. Нужно: чтение (КТП, предстоящие задания) открывать сразу при
утверждении; запись в журнал — по-прежнему только в активном окне `[valid_from, valid_to]`.

**B.** При утверждении замены уведомление получает только замещающий преподаватель
(`NotificationType::SubstituteAssigned`). Ученики группы не оповещаются ни о замене
преподавателя, ни о смене кабинета (у `RoomAssignmentService` уведомлений нет вообще).

**C.** У обычной контрольной (`AssessmentKind::Control`) в конструкторе виден блок
«Таблица перевода баллов» (`score_map`) — поле унаследовано от общего шаблона метабокса и
для Control нигде не используется в расчёте оценки (везде гейт
`AssessmentKind::needsSecondaryScore()`, который для Control — `false`). Сейчас логика
скрытия обратная: поле прячется только для ЕГЭ/ОГЭ-станций
(`STATION_ONLY_HIDDEN_FIELD_IDS` в `assessment-builder.js`), а для Control остаётся
видимым и сохраняемым, хотя мёртвое. Нужно убрать поле именно у Control, сохранив его для
ЕГЭ/ОГЭ, где оно реально участвует в переводе первичного балла во вторичный
(`SecondaryScoreService`).

---

## A. Доступ замещающего — сразу при утверждении

- [x] `inc/Repositories/WPDBRepositories/SubstitutionRepository.php` — добавить
  `hasUpcomingOrActiveGrant(int $userId, int $groupId): bool` (та же проверка, что
  `hasActiveGrant()`, но без условия на `valid_from` — только `valid_to >= CURDATE()`).
- [x] Там же — `findUpcomingOrActiveBySubstitute(int $userId, string $today): array`
  (симметрично `findActiveBySubstitute()`, без нижней границы по `valid_from`).
- [x] `inc/Services/Course/GroupAccessGuard.php::canManage()` — заменить
  `hasActiveGrant()` → `hasUpcomingOrActiveGrant()`. Одна точка входа закрывает разом
  КТП (`ProgramAccess::requireGroupAccess/requireProgramRow`), teacher-режим плеера
  урока (`LessonPlayerController`), превью.
- [x] `canWriteJournal()` — **не менять**: запись посещаемости/оценок остаётся на
  `hasActiveGrant()` (дата-в-дату), иначе замещающий сможет проставлять оценки на
  месяц вперёд.
- [x] `inc/Services/Profile/DashboardService.php::collectGroups()` — использовать
  `findUpcomingOrActiveBySubstitute()` вместо `findActiveBySubstitute()`, чтобы группа
  попадала в «Мои группы»/дашборд сразу после назначения замены.
- [x] Там же, блок `covering` (строки ~156-163) — добавить `valid_from` в возвращаемый
  массив, чтобы отличать «замена уже идёт» от «начнётся с {valid_from}».
- [x] `src/js/profile/dashboard.js` — для будущих замен показывать бейдж «с {valid_from}»
  вместо текущего «до {valid_to}».
- [x] Тесты: `GroupAccessGuardTest` (canManage — доступ при будущем `valid_from`,
  canWriteJournal — по-прежнему запрет), `DashboardServiceTest` (группа в списке при
  будущей замене), плюс юниты на новые методы репозитория.

## B. Уведомления ученикам о замене преподавателя и смене кабинета

- [x] `inc/Enums/Profile/NotificationType.php` — новые кейсы `SubstituteAssignedStudent`
  и `RoomChanged`, `title()`/`tone()` (`info`).
- [x] `inc/Services/Profile/NotificationService.php::renderBody()` — ветки рендера тела
  для обоих новых типов (группа/препод/даты; старый→новый кабинет).
- [x] `inc/Services/Course/SubstitutionService.php::assign()` — после существующего
  `push()` замещающему добавлена рассылка `SubstituteAssignedStudent` через
  `groupStudentUserIds($groupId)` (родителям не дублируется — решено раньше).
- [x] `SubstitutionService::revoke()` — теперь отзывает уведомления через `retract()` по
  dedupe-ключам `sub:{id}` и `sub-student:{id}` (сначала `find()`, потом `delete()`).
- [x] `inc/Services/Course/RoomAssignmentService.php` — `NotificationService` внедрён в
  конструктор.
- [x] Там же, `assignToLesson()` и `overrideForRange()` — после проверки, что кабинет
  реально меняется, рассылают `RoomChanged` ученикам (`lessonStudentUserIds`) и
  эффективному преподавателю занятия (`lessonTeacherUserId()`), со старым/новым
  кабинетом в payload (`pushFresh`, dedupe `room-lesson:{id}` — переживает повторные
  правки одного занятия). `assignToGroup()` (дефолт на год) уведомлений не шлёт —
  решено раньше.
- [x] `src/js/profile/notifications.js` — `TYPE_ICON` дополнен (`substitute_assigned_student`
  → `icoSwap`, `room_changed` → `icoMapPin`).
- [x] Тесты: `SubstitutionServiceTest` (рассылка ученикам, retract при revoke),
  `RoomAssignmentServiceTest` (уведомление/её отсутствие при `assignToLesson`),
  `NotificationServiceTest` (рендер тела обоих новых типов). Полный набор — 1336
  тестов зелёные.

## C. Убрать «Таблицу перевода баллов» у обычной контрольной

- [x] `src/js/admin/services/assessment-builder.js` — в `toggleKindFields()` разведены
  `score_map` и остальные три поля `STATION_ONLY_HIDDEN_FIELD_IDS`
  (`time_limit_minutes`, `max_attempts`, `pass_score`): те по-прежнему прячутся для
  станций (Ege/Oge), а `score_map` — наоборот, показывается **только** когда
  `isEge(kind)` (Ege/Oge, тот же набор, что и `needsSecondaryScore()`), для
  `Control` скрыт.
- [x] `inc/Controllers/Assessment/AssessmentMetaBoxController.php::handleAssessmentSave()`
  — условие удаления `score_map` из `$_POST` инвертировано: раньше стрипался для
  станций (`isStation()`) вместе с остальными station-only полями, теперь стрипается
  отдельным условием `! needsSecondaryScore()` (то есть для `Control`), а для ЕГЭ/ОГЭ
  сохраняется как обычное поле формы (раньше не сохранялся вообще нигде через форму —
  только через `ScoreMapCallbacks::ajaxCopyScoreMap()`, который пишет в мету напрямую).
- [x] Рендер метабокса — поле прячется тем же JS-тумблером, что и остальные
  station-only поля (инвертированное условие `hidden`), без изменений на уровне
  `AssessmentTemplate`/`ScoreMapField` — DOM-элемент остаётся, скрытие только
  визуальное + серверный strip.
- [x] Не тронуты: `ScoreMapField.php`, `ScoreMapCallbacks.php` (копирование между
  экзаменами), `SecondaryScoreService.php`.
- [x] У существующих `Control`-контрольных, где уже сохранён `score_map` в мете —
  оставлено как есть (поле и так нигде не читается), миграция-очистка не заводилась.
- [x] Тесты: `AssessmentStationFieldsGateTest` обновлён под инверсию (`score_map`
  сохраняется для `ege_computer`/`oge_computer`, стрипается для `control`; остальные
  station-only поля — без изменений). Полный набор — 1336 тестов зелёные.

---

## D. Проверка работ: страница вместо модалки + вкладка «Работы» + фикс условия

Контекст: сейчас деталь работы/экзамена — модалка `sum-modal` (`src/js/profile/summary.js`),
открыть её можно только пройдя Сводка → группа → ученик → занятие → клик по бейджу работы —
неочевидно и требует знать, у какого ученика что на проверке. Плюс в модалке у каждого
задания написано «условие недоступно» — реальный баг, не дизайн. Ориентир вида —
`.docs/05-review.html` (список работ на проверку + отдельная страница-карточка с панелью
заданий, критериями и вердиктом): адаптировать под наши таблицы/токены/`prof-` BEM, не копировать
инлайновые стили макета.

### D1. Багфикс: «Условие недоступно» ✅ 2026-08-20

- [x] `inc/Services/Course/WorkDetailService.php::condition()` — читал
  `$post->post_content` (для заданий всегда пуст), заменено на
  `TaskMetaService::getCombinedCondition( $this->posts->taskMeta( $taskId ) )` (тот же
  способ, что `ArticleContentService`) — `TaskMetaService` внедрён в конструктор,
  `PostManager::taskMeta()` — единая точка чтения `fs_lms_meta` целиком.
- [x] Тест: `WorkDetailServiceTest` — два новых теста (`fromSubmission`/`fromAttempt`),
  `condition` берётся из меты, `PostManager::get()` (post_content) не вызывается.
  Полный набор — 1338 тестов зелёные.

### D2. Деталь работы — экран SPA вместо модалки

- [ ] Убрать `sum-modal` (`renderDetailModal`/`closeDetailModal`/`onDetailEsc` и все
  `wire*`-обработчики оценивания) из `src/js/profile/summary.js`, вынести их в новый
  модуль `src/js/profile/work-review.js` (object-pattern, admin-конвенции JS не
  действуют — это `profile/`, здесь чистые функции) как полноценный экран, а не
  контент модалки: `renderWorkReview(root, { sourceType, sourceId, onBack })`.
  Логика оценивания (`wireGrading`, `wireAttemptGrading`, criteria/oge-rubric блоки,
  `wireApprove`, `wireReset`) переносится как есть — меняется только контейнер
  (страница вместо `<div class="sum-modal">`) и способ закрытия (кнопка «‹ Назад»
  вместо крестика/Escape/backdrop).
- [ ] `src/js/profile/app.js` — новый экран в `SCREENS`, не входящий в `cfg.screens` по
  умолчанию (открывается только программно, как временный «под-экран», по аналогии
  с тем, как `dashboard`→`openReview` делает `go('summary')`): нужен статful переход
  `go('work-review', {sourceType, sourceId})` — либо простая мутация module-level
  `state.current` в `work-review.js` перед `go()`, либо расширить `go()` параметром
  контекста. Кнопка «‹ Назад» возвращает на экран, с которого открыли (Сводка или
  «Работы», см. D3) — хранить `returnTo` в состоянии перехода.
- [ ] `src/js/profile/summary.js` — клик по бейджу работы (`openWorkDetail`) теперь не
  грузит деталь и не рисует модалку сам, а вызывает переход на `work-review` с
  `returnTo: 'summary'`; данные (`reviewApi.getDetail`) грузит сам `work-review.js`.
- [ ] SCSS: `sum-modal*` классы (`src/scss/profile/_summary.scss` или где они объявлены)
  либо переиспользовать под страничную обёртку (без `position:fixed`/backdrop/
  `box-shadow` поверх контента — обычный `prof-screen`), либо завести
  `src/scss/profile/_work-review.scss` с `.wr-*` классами на токенах
  `_variables.scss` — визуально то же (шапка/панель заданий/подтабы ответ-эталон/
  вердикт), но как контент экрана, не оверлей.
- [ ] Deep-link со страницы работы/журнала (если такой есть, проверить) и hash-навигация
  уровня прежней очереди `05-review.html#sub=N` — не переносить: текущий вход уже
  идёт из Сводки/«Работы» кликом, отдельный URL-параметр не нужен.

### D3. Вкладка «Работы» — список работ на проверку, без обхода по ученикам

Решено (см. «Решения (D)» ниже): три вкладки как в референсе (На проверке / Ждут
подтверждения / Проверенные) + фильтры группа/тип работы/сортировка; ЕГЭ-компьютерный
(и любой другой kind, закрытый через «Утвердить работу», D18) попадает в «Ждут
подтверждения» — именно отсюда, а не из Сводки, теперь и утверждается.

- [ ] `inc/Services/Profile/TeacherProfileView.php` — новый пункт `nav`/`screens`:
  `array( 'key' => 'works', 'label' => 'Работы' )` (препод + офис, сразу после
  «Журнал» — до «Сводки», это теперь основной вход в проверку). Новый блок конфига
  `works` с нонсом `Nonce::GradeWork` и экшенами `getPendingWorks`/`getWorkSubmissions`
  (плюс переиспользуемые `reviewApi`/`attemptGrade` блоки для перехода в D2, включая
  `approveAttempt` — «Утвердить работу» теперь доступна и с этого экрана).
- [ ] Новый сервис `inc/Services/Course/ReviewQueueService.php` (или расширить существующий
  домен, если найдётся более подходящий) — `pendingWorks(int $userId, bool $allGroups, string $tab): array`:
  агрегирует **и** обычные работы (`submissions`, сгруппированные по `work_id`), **и**
  экзамены (`assessment_attempts`, сгруппированные по `assessment_id`) по группам
  пользователя (переиспользовать `GroupAccessGuard`/набор групп, как в
  `DashboardService::collectGroups()`). Три корзины по `$tab`:
  - `pending` — есть хотя бы одна сдача/попытка со статусом, требующим ручной оценки
  (`submitted`, либо экзамен с неоценённым `file_answer_task`-заданием);
  - `confirm` — попытки, для которых `AttemptRevealPolicy`/`needsSecondaryScore` уже
  выставили итог автоматически, но `approved_at` ещё `null` (ЕГЭ-компьютерный и
  подобные kind — сюда, не в «pending»);
  - `done` — `graded`/утверждённые.
  Поля элемента: `source_type`, `source_id`, `title`, `label` (тип работы/«Экзамен»),
  `count` (сколько сдач в корзине), `group_ids`. Пустой список для активной вкладки →
  фронт покажет «Работ на проверку нет».
- [ ] Там же — `submissionsFor(string $sourceType, int $sourceId, int $userId, bool $allGroups, string $tab): array`:
  список сдач по конкретной работе/экзамену в разрезе той же вкладки — ученик, группа,
  дата сдачи, `source_type`/`source_id` **сдачи** (для перехода на D2: у работы с
  несколькими учениками у каждого свой `submission_id`/`attempt_id`).
- [ ] `inc/Repositories/WPDBRepositories/SubmissionRepository.php` — новый метод группировки
  по `work_id` через все группы пользователя (сейчас `listQueueByGroup()` — только одна
  группа; нужен вариант с `group_id IN (...)` и `GROUP BY work_id`).
- [ ] `inc/Repositories/WPDBRepositories/AssessmentAttemptRepository.php` — симметричный
  метод группировки по `assessment_id` через список групп (сейчас есть только
  `listByGroupForGradebook()` для одной группы); плюс выборка «есть попытки с
  `approved_at IS NULL`, но статус уже финальный» для корзины `confirm`.
- [ ] Новые AJAX-хуки в `Inc\Enums\Wp\AjaxHook`: `GetPendingWorks`, `GetWorkSubmissions`;
  обработчики — в `GradingCallbacks` (или новый `ReviewQueueCallbacks`, если
  `GradingCallbacks` разрастётся) через `$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching )`.
- [ ] `src/js/profile/works.js` (новый, чистые функции, как остальные экраны `profile/`) —
  `renderWorks(root)`: вкладки «На проверке / Ждут подтверждения / Проверенные»
  (счётчик на каждой, как `tabs .b` в референсе) + фильтры группа/тип работы/сортировка
  над списком (переиспользовать `groupPickerBtnHtml`/`openGroupPicker` из `picker.js`
  для фильтра группы). Шаг 1 — пикер работы в активной вкладке, либо пустое состояние
  «Работ на проверку нет» (`emptyState()` из `utils.js`); шаг 2 — после выбора работы,
  таблица учеников: **группа**, **дата сдачи**, кнопка/строка-клик «Открыть» →
  `go('work-review', { sourceType, sourceId, returnTo: 'works' })` (см. D2). На вкладке
  «Ждут подтверждения» строка ведёт на тот же `work-review` — кнопка «Утвердить работу»
  там уже есть (переносится из модалки как есть, D2).
- [ ] SCSS: вкладки/фильтры/таблица списка учеников — переиспользовать существующий
  табличный паттерн профиля (`journal`/`groups` roster-таблицы) и паттерн вкладок
  (`.tabs`/`.b` из референса адаптировать в `prof-` BEM на токенах `_variables.scss`,
  как уже сделано в других экранах); без raw-значений.
- [ ] Тесты: `ReviewQueueServiceTest` (агрегация по группам пользователя и по вкладке,
  пустой список, попадание ЕГЭ-компьютерного attempt'а именно в `confirm`), юниты на
  новые методы `SubmissionRepository`/`AssessmentAttemptRepository`, AJAX-тесты на
  новые колбэки (авторизация, доступ только к своим/office-всем группам).

### D4. Автопроверяемые vs ручные задания в детали работы

Решено (см. «Решения (D)» ниже): submission-работы оцениваются поштучно, как экзамены.

- [ ] Новый AJAX `GradeSubmissionTask` (аналог `GradeAttempt`, но пишет в per-task строку
  `submissions` вместо `assessment_attempts`/`assessment_answers`) — обработчик рядом с
  `GradingCallbacks::ajaxSaveGrade()`/`ajaxReturnSubmission()`, тот же нонс `GradeWork`,
  та же проверка `GroupAccessGuard::canWriteJournal()`. Итоговый балл работы — сумма по
  заданиям (аналог `tSum()` в референсе), как сейчас пишется в `submissions.score` при
  «Сохранить оценку».
- [ ] `WorkDetailService::fromSubmission()` — `tasks[]` уже даёт `verdict`/`score` по
  заданию (см. текущий код); дополнительно нужно поле, различающее авто/ручное задание
  для фронта (`gradable: bool` на уровне задачи), источник истины —
  `TaskTemplate::isFileAnswerShape()` через `TaskCheckerRegistry` (единственный ручной
  шаблон сейчас — `file_answer_task`).
- [ ] `src/js/profile/work-review.js` (после переноса из `summary.js`, см. D2) —
  `taskBlock()`: контролы оценивания рисуются только для `t.gradable === true`
  (`file_answer_task`), для остальных — только `condition`/`answer`/`correct`, без
  input'ов; общая логика (`criteriaGradeBlock`, простой балл) переиспользуется у
  `kind: 'work'` так же, как сейчас у `kind: 'exam'` (`wireAttemptGrading` обобщается
  на оба источника, дергая либо `GradeAttempt`, либо новый `GradeSubmissionTask` по
  `d.kind`).
- [ ] Тесты: `GradingCallbacksTest`/аналог на `GradeSubmissionTask` (сумма по заданиям,
  доступ через `canWriteJournal`), `WorkDetailServiceTest` — `tasks[].gradable`
  корректно для `file_answer_task` vs остальных шаблонов.

---

## Решения (D)

- Гранулярность оценивания submission-работ: **поштучно**, как у экзамена — не единый балл
  на всю сдачу. Каждая авто-проверяемая задача просто показывает ответ+эталон, ручная
  (`file_answer_task`) — с контролом оценки; итог работы — сумма по заданиям.
- Экран «Работы» — по образцу `.docs/05-review.html`: три вкладки (На проверке / Ждут
  подтверждения / Проверенные) + фильтры (группа, тип работы, сортировка).
- Экзамены без поштучной ручной проверки (ЕГЭ-компьютерный и любой другой kind, закрытый
  через явное «Утвердить работу», D18) **входят** в список «Работы» — во вкладку «Ждут
  подтверждения». Кнопка «Утвердить работу» переносится на страницу `work-review` (D2) и
  становится доступна и с этого входа, не только из Сводки — снимает исходную жалобу
  «в Сводку неудобно лезть».

---

## Открытые вопросы (уточнить перед реализацией B)

- Дублировать ли уведомление о замене родителям (`guardianUserIds()`), или только ученику? - нет, только ученику
- Нужен ли `RoomChanged` при смене дефолтного кабинета группы на год (`assignToGroup()`),
  или только при точечном override (`assignToLesson`/`overrideForRange`) — на год вперёд
  спамить всех уведомлением может быть избыточно - уведомление только при разовой замене

---

# Задача: тип экзамена — отдельный метабокс (2026-08-20)

Контекст: сейчас метабокс «Настройки контрольной» (`AssessmentMetaBoxController::renderSettingsContent()`,
`AssessmentTemplate`) — один контейнер на все поля сразу: `kind` (тип экзамена), `time_limit_minutes`,
`max_attempts`, `pass_score`, `score_map`, `intro_html`. Видимость части полей уже переключается JS'ом
по выбранному `kind` (`assessment-builder.js::toggleKindFields()`, Block C) — но это скрытие отдельных
строк ВНУТРИ одного контейнера, а не отдельные контейнеры. Нужно: вынести выбор типа экзамена в свой
метабокс, а «Настройки контрольной» показывать целиком только когда выбран тип «Контрольная»
(`AssessmentKind::Control`); для станций (ЕГЭ/ОГЭ, `isStation()`) эти четыре поля всё равно не
редактируются (приходят из `StationExamConfig`, см. `handleAssessmentSave()`) — прятать весь контейнер,
а не только поля по одной. `score_map` (Block C: видим ТОЛЬКО для `isStation()`, обратное условие) при
этом конфликтует с «показывать контейнер только для Control» — его нужно вынести в третий, отдельный
метабокс с противоположной видимостью, иначе для ЕГЭ/ОГЭ таблица перевода останется без контейнера.

- [ ] `inc/MetaBoxes/Templates/AssessmentTemplate.php` — источник истины состава полей не менять
  (`kind`, `time_limit_minutes`, `max_attempts`, `pass_score`, `score_map`, `intro_html` остаются
  здесь одним списком — на нём держится `handleAssessmentSave()::saveFieldsMerge()` и JS-схема
  редактора); рендер по трём разным метабоксам — на уровне контроллера, не шаблона.
- [ ] `inc/Controllers/Assessment/AssessmentMetaBoxController.php::handleAddMetaBoxes()` — вместо
  одного `fs_lms_assessment_settings` зарегистрировать три:
  1. `fs_lms_assessment_kind` («Тип экзамена») — только поле `kind`, всегда видим, приоритет `high`,
     рендерится ДО двух остальных (они зависят от его значения).
  2. `fs_lms_assessment_settings` («Настройки контрольной») — `time_limit_minutes`, `max_attempts`,
     `pass_score`, `intro_html`; видим только для `AssessmentKind::Control` (`! kind->isStation()`).
  3. `fs_lms_assessment_score_map` («Таблица перевода баллов») — только `score_map`; видим только для
     станций (`kind->isStation()` / `needsSecondaryScore()` — тот же predicate, что уже в Block C).
- [ ] Три новых/переименованных render-метода контроллера рендерят каждый свой подмножество полей
  (`AssessmentTemplate::get_fields()['kind']`/… по отдельности через `fields-wrapper` — обёртка уже
  принимает произвольный `template`/`values`, дублировать `BaseTemplate::render()` не нужно: либо
  завести тонкий рендер-хелпер над подмножеством полей, либо три тонких шаблон-класса-обёртки над
  тем же `$fields`, отдающих нужное подмножество из `get_fields()` — без копипасты сигнатур полей).
- [ ] `src/js/admin/services/assessment-builder.js::toggleKindFields()` — переписать под три
  контейнера вместо трёх спрятанных строк:
  - постбокс `#fs_lms_assessment_settings` целиком скрывается/показывается по `! isEge(kind)`
    (`isEge` здесь = `isStation()`, как и раньше — переименование не требуется);
  - постбокс `#fs_lms_assessment_score_map` — по `isEge(kind)` (то же условие, что раньше было у
    `score_map`-строки, Block C);
  - `STATION_ONLY_HIDDEN_FIELD_IDS`/поштучный обход `.fs-field, .fs-lms-field-group` для этих полей
    убрать — они больше не соседи `kind` в одном контейнере, скрывается сразу весь `<div class="postbox">`
    (WP-обёртка метабокса, id = `id` метабокса) через `hidden`, не `style`;
  - постбокс `#fs_lms_assessment_kind` (с `kindSelect`) остаётся видимым всегда — не трогать.
- [ ] `handleAssessmentSave()` — сохранение не меняется (уже стрипает станционные поля/`score_map`
  по `AssessmentKind`, независимо от того, в каком контейнере они отрисованы).
- [ ] Порядок постбоксов на экране: «Тип экзамена» — первым (`priority: 'high'`), «Настройки
  контрольной» и «Таблица перевода баллов» — следующими; «Конструктор контрольной»
  (`fs_lms_assessment_builder`) — как сейчас, ниже.
- [ ] Тесты: `AssessmentMetaBoxControllerTest`/`AssessmentStationFieldsGateTest` — проверить, что
  `renderSettingsContent`-эквивалент для новых трёх колбэков рендерит правильное подмножество полей
  (`kind` только в своём метабоксе, `score_map` только в своём, остальные три — в «Настройках»);
  сохранение (`handleAssessmentSave`) — без изменений, старые тесты не должны сломаться.

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

- [x] Убрать блок «Сброс старой схемы» (строки 48–62): дропает `fs_lms_expelled_archive`,
      `fs_lms_relationships`, `fs_lms_enrollments`, `fs_lms_archive` и чистит
      `fs_lms_student_group_matrix` — на чистой установке этого нет никогда
      (снят 2026-08-19 внепланово: блок дропал ЕЩЁ и `fs_lms_persons`/`fs_lms_groups`/
      `fs_lms_student_records` при каждом полном прогоне `up()` — реальный сброс
      схемы версии на dev стёр эти три таблицы с боевыми на тот момент данными
      импорта; см. `migration-reset-drops-core-tables.md` в памяти)
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
