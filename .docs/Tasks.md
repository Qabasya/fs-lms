Bugfix
1. В ктп хочется drag and drop убрать урок из календаря обратно в темы. Например, когда решил только одну тему исключить, но чтобы остальные (следующие) сдвинулись на её место 
2. Нужно преподавателю в плеере курса добавить вывод правильного ответа и решения задачи в шагах "Задача" и "Работа". Возьми за пример поведение в публичном тренажёрё - кнопка "Показать решение" открывает и "Правильный ответ" и "Решение"
3. И напомни, мы же делали режим "проведения занятия". То есть не предпросмотр курса, а когда преподаватель открывает этот урок из курса у группы и работает по нему
4. Давай время жизни JOIN-ссылки для родителя сделаем 72 часа с момента как я её скопировал, повторное копирование сбрасывает счётчик (если еще не так)

**Уточнения после разбора (2026-09-16):** п. 3 проверен, режим проведения работает — задача снята;
п. 2 сводится к стилям аккордеона с решением (кнопка копирования без стилей, нет подсветки синтаксиса,
общий вид — как в тренажёре); в п. 4 срок заявки остаётся 14 дней, 72 часа — таймер от копирования.

---

# План решения

## 1. КТП: drag-and-drop темы из календаря обратно в «Темы курса» + сдвиг хвоста — СДЕЛАНО

Реализовано: `AjaxHook::UnpinLesson` → `LessonScheduleCallbacks::ajaxUnpinLesson()` →
`ScheduleReflowService::returnToPool()` (+ `GroupLessonRepository::moveToSlot()`), банк тем стал
drop-зоной (`attachBankDrop()` в `ktp.js`, подсветка `.prof-theme-bank.drop-ok`).
Правило сдвига: хвост едет на одно окно вперёд и останавливается на первом якоре — закреплённом
вручную, проведённом или отменённом/перенесённом занятии; индивидуальные не участвуют; продолжение
темы (T12.6) уходит в пул вместе с оригиналом и тоже освобождает своё окно. Покрыто юнит-тестами
`ScheduleReflowServiceTest` (7 новых).


**Диагноз**
- Drop-зоны есть только у ячеек дней: `attachDrop()` вешается на `.kal-cell[data-day]:not(.holiday)`
  (`src/js/profile/ktp.js:415`, вызов в `renderCalendar()`). Банк тем (`#ktpBank`) drop не принимает —
  вернуть одну тему в пул нечем.
- Точечного «снять дату» в AJAX нет: `PinLesson` только ставит дату, а `UnscheduleGroup`
  (`LessonScheduleCallbacks::ajaxUnscheduleGroup` → `GroupLessonRepository::unscheduleAll()`)
  снимает даты у ВСЕЙ группы. `ScheduleReflowService::schedule($id, null, …)` умеет снять дату
  у одной строки, но наружу не выведён и хвост не подтягивает.
- Каскадного сдвига нет намеренно: `pinToDate()` (`ScheduleReflowService:135`) когда-то звал
  `calendar->reflow()` — это и был баг «перетащил один урок, съехало всё».
- **`reflow()` для этой задачи не подходит**: `applySlots()` (`GroupLessonRepository:150`) раскладывает
  ВСЕ непиннутые строки по `position` с начала периода, поэтому строка, только что возвращённая
  в пул, мгновенно получит дату обратно. Нужен адресный сдвиг, а не переразливка.

**Шаги**
1. `Inc\Enums\Wp\AjaxHook`: `case UnpinLesson = 'unpin_lesson';` (params: `group_lesson_id`) —
   в блок «КТП / расписание».
2. `LessonScheduleCallbacks::ajaxUnpinLesson()`: `authorize(Nonce::SaveSchedule, Capability::ManageLmsTeaching)`
   → `requireProgramRow()` → `denyIfProgramLocked()` → сервис → `success(['shifted' => N])`.
3. `ScheduleReflowService::returnToPool( int $groupLessonId, int $actorUserId ): int`:
   - строка без даты — no-op; `status = held` — отказ (исторический факт не снимаем);
   - `clearSchedule()` самой строки и её продолжений (`continuedFromId`) — как в `pinToDate()` (T12.6);
   - сдвиг: строки группы (`kind != individual`) с датой > снятой, по возрастанию `scheduled_at`;
     очередь освободившихся слотов (`scheduled_at`/`ends_at`/`room_id`) стартует снятым слотом;
     `held` и `is_pinned` — якоря: дату не меняют и слот из очереди не берут; остальные забирают
     самый ранний слот из очереди и кладут в неё свой бывший;
   - `events->groupChanged()`, вернуть число сдвинутых строк.
4. `GroupLessonRepository`: одним апдейтом переносить `scheduled_at`/`ends_at`/`room_id`
   (расширить `updateSchedule()` параметром `roomId` либо добавить `moveToSlot()`).
5. `src/js/profile/ktp.js`:
   - в `attachDrag()` помечать источник (`state.dragFromCalendar = el.classList.contains('placed-theme')`);
   - `attachBankDrop()` на `#ktpBank`/`.prof-theme-bank`, принимать drop только при `dragFromCalendar`
     и при `!isLocked()`; вызов `api('unpin', { group_lesson_id })`, тост
     «Тема N возвращена в пул · следующие сдвинулись на одну дату», затем `loadCalendar()`.
6. `TeacherProfileView::config()` → `schedule.actions`: `'unpin' => AjaxHook::UnpinLesson->jsAction()`.
7. `src/scss/profile/components/_ktp.scss`: `.prof-theme-bank.drop-ok` по образцу `.kal-cell.drop-ok:125`,
   только токенами.
8. Сборка (`npx gulp scripts styles:frontend`), проверка: «Распределить» → вытащить тему в банк →
   следующие поднялись на одну дату, закреплённые и проведённые остались на месте.

**Решение (уточни, если не так):** якорями считаем `held` **и** `is_pinned`. После «Распределить»
строки непиннуты — сценарий из задачи работает; вручную закреплённую drag-ом тему сдвиг не трогает,
иначе «закреплено» перестаёт что-либо значить.

## 2. Аккордеон «Показать решение» в плеере: стили как в тренажёре — СДЕЛАНО (пп. 1–5)

Сделано: токены блока кода и подсветки перенесены в `shared/_tokens.scss` (секция «Блок кода»,
+ `$font-code`, `$font-medium`, `$code-line-height`); партиал переехал во `shared/_code-block.scss`
и подключён в `frontend.scss` и `player.scss` — поверхности он берёт из темы бандла
(`var(--surface, …)`), подсветка общая. Это же чинит «1234567891011» перед листингом: цифры —
это гаттер с номерами строк, который без стилей печатался сплошной строкой. Аккордеон приведён к
тренажёру: `summary` — кнопка кабинета (`cab-btn` + `cab-btn-sm`), заливная при `[open]`, строки —
плашка в духе `.fs-answer` (uppercase-лейбл + моноширинный ответ), у строки с кодом своя плашка
снята (`.fs-solution__row--code`). Пункты 6–8 ниже — не делались.


Логика уже есть и работает: `LessonPlayerService::solutionFor()` (`:193`) отдаёт `{answer, html, code}`
в `render.solution` (task-шаг, `:383`) и `tasks[].solution` (work-шаг, `:247`) только при `$isTeacher`;
рендерит `templates/frontend/lesson-player/partials/teacher-solution.php`. Правим оформление.

**Диагноз**
- Кнопка копирования и подсветка: `teacher-solution.php` печатает `<pre><code class="js-code">`,
  а `player.js:33` зовёт тот же `initCodeBlocks()`, что и фронт — DOM `.fs-code-editor`
  (шапка с бейджем языка и кнопкой копирования) и токены `.fs-hl-*` собираются. **Но CSS для них
  (`src/scss/frontend/components/_code-block.scss`) подключён только в `frontend.scss`;
  в `player.scss` его нет** — отсюда голая кнопка и чёрно-белый листинг.
- Общий вид: `.fs-solution` (`src/scss/player/components/_step-task.scss:359`) — своя акцентная плашка,
  тогда как в тренажёре это ghost-кнопка `.fs-answer-toggle` (`@include fs-btn-toggle`,
  `frontend/components/_answer.scss`) + панель `.fs-answer` (uppercase-лейбл слева, моноширинное
  значение справа), а решение/код — вкладки `.fs-tab-panel` (`_tabs.scss`).
- Бандлы держат токены по-разному: фронт — SCSS-переменные (`frontend/_variables.scss`, hex подсветки
  захардкожены на `:104-109`), плеер — CSS-переменные темы кабинета (`shared/cabinet/_theme.scss`).
  Поэтому партиал кода нельзя просто подключить в оба бандла — нужен общий слой токенов.

**Шаги**
1. Токены кода — в общий слой: перенести `$hl-keyword/$hl-builtin/$hl-function/$hl-string/$hl-number/
   $hl-comment` и `$code-header-bg/$code-body-bg/$code-gutter-text/$code-selection-bg`
   из `frontend/_variables.scss` в `shared/_tokens.scss` (попутно снимаем сырые hex — правило «no raw values»).
2. Объявить их CSS-переменными в обоих бандлах: `shared/cabinet/_theme.scss` (кабинет+плеер) и `:root`
   фронта; имена общие — `--code-header-bg`, `--code-body-bg`, `--hl-keyword`, … .
3. Перенести `frontend/components/_code-block.scss` → `src/scss/shared/_code-block.scss`, переписав
   значения на `var(--…)` (поверхности — `--surface`/`--surface-2`/`--line`/`--muted`/`--ink`/`--mono`;
   у фронта объявить те же имена в его `:root`). Подключить `@use` в `frontend.scss` и `player.scss`.
   Проверить, что страница задания в тренажёре не поехала — разметка и классы прежние.
4. `.fs-solution` привести к тренажёру (`player/components/_step-task.scss`):
   - `summary.fs-solution__toggle` — ghost-кнопка кабинета (`cab-btn` + `cab-btn-ghost` + `cab-btn-sm`,
     как `.b.b-gh.b-sm` в `_shell.scss:277`), `::-webkit-details-marker { display: none }`,
     шеврон — `Icon::ChevronDown` в разметке (инлайновые SVG в шаблонах запрещены);
   - `.fs-solution__row` — плашка в духе `.fs-answer`: фон `--surface-2`, 1px `--line`,
     радиус `--radius-sm`, лейбл uppercase `--muted-2`, значение ответа шрифтом `--mono`;
   - строка «Код» отдельного оформления не требует — её берёт на себя `.fs-code-editor` из п. 3.
   Остаёмся на `<details>`: `js-answer-toggle`-механика тренажёра тянет за собой JS, а `<details>`
   даёт то же поведение без него (и не нарушает «JS не задаёт стили»).
5. Сборка `npx gulp styles:frontend styles:player`, `npm run lint:css`; проверка на шаге «Задача»
   и внутри «Работы»: кнопка копирования оформлена, листинг подсвечен, аккордеон выглядит как ответ
   в тренажёре.

**Остальное по этой задаче — СДЕЛАНО**
6. Эталон вынесен в `Inc\Services\Task\TaskSolutionService`, зовут его `LessonPlayerService`
   (teacher-режим) и `CoursePreviewService` (предпросмотр — туда пускает только
   `CoursePreviewAccessGuard`, ученика на маршруте нет). Партиалы включают блок по наличию
   `render.solution` / `tasks[].solution`, а не по флагу режима.
   Попутно: решение ручных шаблонов лежит в `solution_text` («Решение для проверяющего») и до
   плеера не доезжало — добавлен фолбэк; листинг кода теперь отдаётся по наличию `task_code`,
   а не по `TaskTemplate::hasCodeField()` (тот про поле «Код» в ответе УЧЕНИКА и прятал
   авторский код у «19-21» и ручных шаблонов).
7. Поле «Решение» (`task_text`, `ConditionField`, `optional`) добавлено в 11 шаблонов заданий.
   Флаг `optional` обязателен: `TaskPublishValidator` требует заполнения всех полей без него —
   иначе публикация всех существующих заданий сломалась бы.
8. Teacher-режим больше не пишет данные: `step-task.js`/`step-work.js` уходят в dry-run
   (`PreviewCheckTask`/`PreviewCheckWork`) и при `isTeacherMode()`, шаблоны отдают им
   `data-preview-ref` и примечание «ответ не сохраняется»; на сервере `SubmitTaskAnswerCallbacks`
   и `BatchSubmissionCallbacks` теперь требуют членства в группе (`isMemberEver`).

**Было в плане (выполнено выше)**
6. Предпросмотр курса эталона не отдаёт вовсе (`CoursePreviewService::renderTaskData/renderWorkData`,
   `:158/:195`). Вынести `solutionFor()` в `Inc\Services\Task\TaskSolutionService` (рядом с
   `CorrectAnswerResolver`), звать из обоих сервисов; в preview показывать при праве `AuthorLmsCourses`
   (флаг `can_edit` в `player.php` уже есть) — условие в партиалах `! empty( $is_teacher ) || ! empty( $can_edit )`.
7. Поле «Решение» (`task_text`) объявлено только в шаблоне `TaskTextSolution` (`text_task`) —
   у Standard/Choice/Matching/Ordering/Fill/Audio/Common автор его ввести не может, и блок «Решение»
   будет пустым. Добавить `task_text` (`ConditionField`) в остальные `inc/MetaBoxes/Templates/*`
   (источник истины — PHP-поля, inline-модалка подхватит сама).
8. Teacher-режим не должен писать данные: `step-task.js`/`step-work.js` уходят в dry-run только по
   `isPreview()`, а `SubmitTaskAnswerCallbacks` членство в группе не проверяет — «Ответить» пишет
   попытку на `person_id` преподавателя. Переводить teacher-режим на `PreviewCheckTask`/`PreviewCheckWork`
   (нонс `PreviewSolve`) либо прятать кнопки + серверная проверка членства (`GroupAccessGuard::isMemberEver`).

## 3. Режим «проведения занятия» — проверено, работает (задача закрыта)

Для протокола, чтобы не искать заново: это teacher-режим плеера, а не предпросмотр.
Вход — клик по карточке занятия в КТП (`attachPlacedThemeClick` → `player_url` из
`GroupCalendarService::getCalendar()`), адрес `/lesson/?gid&gl`; `LessonPlayerController` пускает
преподавателя группы (`GroupAccessGuard::canManage`), постороннему — 404;
`LessonPlayerService::buildTeacherView()` идёт с `personId = 0` — прогресс не читается, гейты открыты,
в топбаре бейдж «Режим преподавателя», «Далее» прогресс не пишет (`core.js:71`), эталоны видны.

Чего в нём нет (если когда-нибудь понадобится «проведение» в полном смысле): отметки посещаемости
прямо из плеера (только экран «Журнал»), кнопки «Занятие проведено» (`LessonStatus::Held` ставит
лишь авто-привязка записи, `VideoRegistrationService:137`), ручного «открыть урок классу сейчас»
(видимость открывается лениво по `scheduled_at`) и живой картины класса. В текущий багфикс не входит.

## 4. JOIN-ссылка родителя: 72 часа от копирования

**Диагноз**
- Сроки сейчас: 14 дней при подаче заявки (`ApplicationService:116`) — **остаётся как есть**;
  48 часов при восстановлении из архива (`EnrollmentService:229`), причём там `strtotime('+48 hours')` —
  локальная зона сервера, а сравнение в `findExpiredPending()` идёт с `gmdate()` (UTC): расхождение на смещение зоны.
- Копирование чисто клиентское: `applications-table.js::copyJoinLink()` кладёт `data-url` в буфер —
  сервер о копировании не знает, сбрасывать нечего.
- Смена/снятие родителя генерирует НОВЫЙ код (`EnrollmentService:338`, `:371`), но `join_code_expires_at`
  не обновляет — свежая ссылка живёт по сроку старой.
- Срок проверяет только cron `ExpireApplications` (переводит заявку в `Expired`); сами
  `prepareJoinPage()` и `submitParentData()` дату не смотрят — просроченная ссылка работает до тика.

**Правило (согласовано)**
Заявка живёт 14 дней без действий. Копирование ссылки в таблице заявок ставит срок ровно
`сейчас + 72 ч` — в том числе если это укорачивает 14-дневный срок: таймер отсчитывается от момента,
когда ссылку отдали родителю. Повторное копирование сбрасывает таймер заново.

**Шаги**
1. `JoinCodeService`: `public const TTL_HOURS = 72;` + `expiresAt(): string` (`gmdate`, UTC).
   Использовать в восстановлении из архива вместо `strtotime('+48 hours')`; создание заявки
   (`ApplicationService:116`, 14 дней) не трогаем.
2. Обновлять `join_code_expires_at` везде, где генерируется новый код —
   `selectExistingParent()` и `removeParentAssignment()` (иначе новый код наследует старый срок).
3. Новая ручка: `AjaxHook::TouchJoinLink = 'touch_join_link'` + `ParentLinkCallbacks::ajaxTouchJoinLink()`
   (`authorize( Nonce::Manager, Capability::ManageApplications )` — нонс уже localize-ится как
   `appVars.nonces.manager`) → `ApplicationService::refreshJoinExpiry( int $appId ): string`:
   разрешать только для `PendingParent`/`ReadyForReview`, писать `сейчас + TTL_HOURS`, вернуть срок.
4. `applications-table.js::copyJoinLink()`: после успешного копирования — POST `touchJoinLink`
   с `app_id` из `closest('tr').dataset.appId`; в нотис добавить «Ссылка скопирована · действует до DD.MM HH:MM».
   `archive-view-modal-manager.js` не трогаем: там код только что создан.
5. Проверять срок в момент использования, не дожидаясь cron: в `ApplicationCallbacks::prepareJoinPage()`
   и `ApplicationService::submitParentData()` сверять `join_code_expires_at` с `gmdate()` → 404 / ошибка.
6. Проверка: скопировать ссылку → в БД `join_code_expires_at` = +72 ч (UTC); скопировать повторно —
   срок сдвинулся; отодвинуть дату в прошлое руками → `/lms/join/{code}` отдаёт 404.
