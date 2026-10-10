# Этап 11b. Гость: вход, сдача, результат (веха M3b)

Зависимости: этапы 6, 7, 8, 11a. Результат: гость по личной ссылке от сотрудника входит на станцию, сдаёт экзамен, сразу видит разбор
на странице сайта, завершает сеанс на общем компьютере; сотрудник выдаёт личную ссылку результата.

Перед началом прочитать `README.md`. SPEC: §6 «Персональная ссылка на вход», «Немедленный результат», §14, §16 критерии 12–14, 28, 30.
Макет: `../guest-entry.png`, `../mobile-guest-entry.png`, `../result.png`, `../mobile-result.png`, `../QA.md`.

**Порядок:** 11b.1 → 11b.2 → 11b.3 → 11b.4 → 11b.5.

**Статус (2026-10-09): код и юнит-тесты готовы, `npm run ci` зелёный; на dev проверено curl-ом:** ссылка входа → кука без срока → адрес без ключа → станция по `?exam_reg=` →
старт/сдача гостя без `Person` (`student_person_id = NULL`) → страница результата → ссылка результата на втором устройстве → отзыв (404) → «Завершить сеанс» (кука удалена, сессия отозвана, старая кука даёт 404).
**Не проверено:** Chrome/Safari руками («Назад», восстановление вкладок), ширина 390 px в настоящем окне (headless Chrome даёт минимум 500 px), прохождение ОГЭ с файлом ответа,
сайдбар результата (11b.3.1 — сделан), `ExamStatsService` и «Результаты» с гостевыми попытками на реальных данных.
Допуск гостя (8.8.2) реализован здесь же (`ExamConductService::admit()`, `AdmitExamGuest`), иначе ссылку входа выдать нельзя; остальное 8.8 (четыре пилюли, очередь оплат) не сделано.

## Общие правила этапа

- Гость **не становится пользователем WordPress**: `wp_set_current_user()`, `wp_set_auth_cookie()` и создание учётки запрещены.
  Личность гостя — только гостевая сессия (`exam_guest_sessions`).
- Ключ в адресе живёт один запрос: обмен на куку `HttpOnly` и редирект на адрес без ключа.
- Недействительный, истёкший, отозванный ключ и ключ другого назначения → обычная страница 404 темы, без ФИО, баллов и намёков на существование записи.
  Отдельного экрана «Ссылка недействительна» нет.
- Все страницы гостя: `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`.
- OTP, почты, кода входа, восстановления по ФИО или телефону нет. Потерял ссылку — сотрудник перевыпускает.
- Гость не отменяет и не меняет сеанс: таких действий нет ни в интерфейсе, ни на сервере.
- Nonce — защита от подделки запроса, а не идентификатор гостя.

---

## 11b.1 Ссылка на вход

**Зачем.** Сотрудник нажимает «Выдать ссылку», адрес копируется, сотрудник передаёт его гостю на площадке. Страница ссылки показывает имя, дату,
окно старта и одну кнопку «Приступить» (SPEC §6).

**Проверить перед началом**
- `ExamAccessTokenService` (2.5), `GuestSessionService` (11a.2.2), таблица `exam_guest_sessions` (поля `scope`, `participation_id`, `registration_id`, `generation`).
- Допуск гостя: `exam_participations.admitted_at` (8.8.2); подтверждённая запись — действующая строка `exam_registrations`.
- Экран сеанса и меню действий строки (8.1, 8.2).
- `src/js/common/components/copy-button.js`.

**Шаги**
- [x] 11b.1.1 Страница входа: `PageRoutes::ExamEntry = 'exam-entry'`, шорткод `ShortCode::ExamEntry`, создание страницы — как в 11a.2.1.
- [x] 11b.1.2 `ExamConductService::issueEntryLink( int $actorUserId, int $participationId ): string` (право `ManageExamGuests` + `canManageEvent()`):
  участие гостевое; есть действующая запись; `admitted_at` задан (иначе «Сначала отметьте допуск участника.»); сеанс не закончился.
  `ExamAccessTokenService::issue( Entry, participation_id, actor, planned_end_at сеанса )` — прежний ключ входа отзывается, поколение растёт;
  **действующие гостевые сессии этого участия с прежним поколением перестают работать для нового старта**, но уже начатая попытка не теряется
  (ответы на сервере; гость входит по новой ссылке и продолжает). Возвращает `home_url( '/exam-entry/' ) . '?k=' . $plain`.
- [x] 11b.1.3 Экшены `IssueExamEntryLink` (`participation_id`) в `ExamConductCallbacks`; ответ — `url` (один раз). Для выдачи и копирования
  **не нужны** `ExportPII` и `ManageLmsPlatform`. В строке гостя: кнопка «Выдать ссылку на вход» → адрес копируется в буфер (`copy-button`),
  подсказка «Ссылка скопирована. Передайте её участнику.»; повторное нажатие — «Перевыпустить ссылку» с `confirmDialog( 'Прежняя ссылка перестанет работать.' )`.
  Отметка «Ссылка передана» — отдельным действием (8.8.6).
- [x] 11b.1.4 `GuestSessionService` — вход:
  - `openEntry( ExamAccessTokenDTO $token, int $registrationId, string $expiresAtUtc ): string` — строка `exam_guest_sessions` (`scope = entry`,
    `participation_id`, `registration_id`, `generation` токена); кука `fs_exam_guest` (`HttpOnly`, `SameSite=Lax`, `Secure` при https, **без срока** — сессионная);
  - `current(): ?AttemptContext` — по куке: сессия не отозвана, не истекла, поколение равно `currentGeneration( Entry, participation_id )`
    **или** у участия уже есть начатая попытка этой сессии (перевыпуск не обрывает идущий экзамен на том же устройстве — решение: при перевыпуске
    старая сессия остаётся действительной только для продолжения своей попытки и просмотра результата);
  - `revoke( string $cookie ): void`; `revokeByParticipation( int $participationId ): void`.
  Срок сессии: до `planned_end_at`, пока попытка не начата; после старта продлевается до личного дедлайна попытки + 30 минут на просмотр результата
  (метод `extendForAttempt()` вызывается из старта и продления).
- [x] 11b.1.5 Обработка страницы `ExamEntry` (в `ExamGuestPageController`, логика — `inc/Callbacks/Exam/GuestEntryCallbacks.php`):
  1. заголовки; лимит неудачных проверок ключа (общий с 11a.2.5);
  2. параметр `k` → `exchange( Entry, k )` → проверить участие и действующую запись → `openEntry()` → редирект на адрес без ключа;
  3. без `k` → `current()`; нет контекста → 404.
  **Повторный переход по уже обменянной ссылке с другого устройства**: создаётся новая сессия того же участия — это не «чужая параллельная сессия»,
  попытка одна (уникальный индекс), ответы общие. Чтобы два устройства не писали одновременно: при `openEntry()` отзывать предыдущие сессии
  `scope = entry` этого участия (последний вход побеждает).
- [x] 11b.1.6 Шаблон `templates/frontend/exam-entry.php` (карточка `fs-join-card`, шапка и подвал темы через `ThemeCompatService`):
  имя участника (как в заявке), название проведения, дата, «Начать можно с {время} до {плановый конец}», одна кнопка:
  | Момент | Кнопка и текст |
  |---|---|
  | до начала | неактивная «Приступить», «Вход откроется в {время}» |
  | окно старта | «Приступить» → станция |
  | попытка идёт | «Продолжить», «Завершение в {личный дедлайн}» |
  | попытка сдана | «Посмотреть результат» → страница результата (11b.3) |
  | после планового конца без старта | «Время начала истекло. Обратитесь к сотруднику.» + контакт центра |
  **Чекбокса подтверждения данных нет**; кнопки восстановления доступа нет. На ширине 390 px — предупреждение «Экзамен рассчитан на компьютер.», если
  станция не проходится с телефона (6.5.6). Открытие страницы таймер не запускает.

**Тесты**
- `tests/Unit/Services/Exam/ExamConductServiceTest.php`: `test_entry_link_requires_admission_and_confirmed_registration`,
  `test_entry_link_denied_for_student_participation`, `test_entry_link_does_not_require_export_pii`,
  `test_reissue_revokes_previous_key`.
- `tests/Unit/Services/Exam/GuestSessionServiceTest.php`: `test_entry_session_cookie_is_hashed_and_session_only`,
  `test_new_entry_revokes_previous_entry_sessions`, `test_session_extends_to_personal_deadline_after_start`,
  `test_session_with_old_generation_cannot_start_new_attempt`, `test_session_with_own_started_attempt_survives_reissue`.
- `tests/Unit/Callbacks/Exam/GuestEntryCallbacksTest.php`: `test_valid_key_sets_cookie_and_redirects_without_key`,
  `test_invalid_expired_revoked_and_wrong_purpose_keys_are_404`, `test_page_without_cookie_is_404`,
  `test_no_wp_login_is_created` — `get_current_user_id()` остаётся `0`, `wp_set_auth_cookie` не вызывается.

**Готово, когда:** тесты зелёные; на dev: выдать ссылку, открыть в приватном окне — адрес без ключа, страница с именем и кнопкой;
открыть по ключу результата (`purpose = result`) страницу входа — 404.

---

## 11b.2 `AttemptContext` гостя и сдача

**Зачем.** Гость проходит ту же станцию тем же серверным кодом, что и ученик; допуск — после подтверждённой записи и допуска сотрудника (SPEC §11).

**Проверить перед началом**
- `ExamAttemptService` (6.1–6.2) работает через `AttemptContext`; `AttemptService::saveAnswerFor()` / `submitFor()` не требуют `Person`.
- `inc/Controllers/Assessment/AssessmentController.php` — экшены попыток зарегистрированы только для вошедших (`ajaxActions()`).
- `AssessmentPageController::loadTemplate()` — гостя без публичного доступа отправляет на логин.
- Загрузка файлов ответа ОГЭ: `AjaxHook::UploadAnswerFile` — кто и как авторизуется (`grep -rn "UploadAnswerFile" inc`).
- Потребители, которым нужен `Person`: `grep -rn "studentPersonId" inc/Services/Assessment inc/Modules/EgeComputer` — список мест с защитой «гость» из 2.2.9.

**Шаги**
- [x] 11b.2.1 `ExamAttemptService::contextForGuest(): ?AttemptContext` — из `GuestSessionService::current()`; `personId = null`, `wpUserId = null`,
  `audience = Guest`.
- [x] 11b.2.2 `start()` для гостя — дополнительные условия: `admitted_at` задан; запись действующая; шаг «у ученика нет другой активной попытки» пропускается
  (нет `Person`); `student_person_id = null`, `attempt_number = 1`. Блокировка учебного контента (`ExamLockService`) гостя не касается.
- [x] 11b.2.3 Транспорт: экшены `StartAttempt`, `SaveAttemptAnswer`, `SubmitAttempt`, `GetAttemptResult` и загрузку файла ответа зарегистрировать
  **также** в `publicAjaxActions()`. В `AttemptCallbacks` для невошедшего пользователя единственный допустимый путь — гостевой контекст:
  нет гостевой сессии → `fail( ErrorCode::ExamAccess, … )`. Проверить каждый метод: ветка «Профиль не найден» не должна срабатывать раньше гостевой.
- [x] 11b.2.4 Страница станции: `AssessmentPageController::loadTemplate()` — невошедший пользователь с гостевой сессией и параметром `exam_reg`
  получает `AttemptPageService::buildForExam()` (6.1.8); без сессии — прежнее поведение (логин или публичный режим). Вариант записи обязан совпасть
  с открытой работой, иначе 404.
- [x] 11b.2.5 Пройти список мест, требующих `Person` (проверка выше), и для гостевой попытки обеспечить работу: сохранение ответа, автопроверка,
  лист результатов станции, файлы ответов ОГЭ (владелец файла — попытка, а не пользователь), истечение по дедлайну (6.2), экран проверки сотрудника
  (`WorkDetailService::fromAttempt()` — имя из `ExamConductService::participantName()`). Значение `person_id = 0` для гостя не использовать.
- [x] 11b.2.6 Гость не попадает в списки учеников: проверить, что ни одна выборка «учеников» не читает `exam_participants`
  и что у гостя нет строк в `persons`, `student_records`, `wp_users`.
- [x] 11b.2.7 Ученик центра не может воспользоваться гостевым путём для своего участия: `issueEntryLink()` отклоняет участие с аудиторией `student`
  (11b.1.2), а гостевая заявка вошедшего ученика отклоняется (11a.1.7). Если сотрудник связал гостя с `Person` до старта — участие становится `student`
  и требует утверждения (правило SPEC §1; сама операция связывания — вне этого этапа, записать в «Открыто», если владелец её запросит).

**Тесты**
- `tests/Unit/Services/Exam/ExamAttemptServiceTest.php`: `test_guest_start_requires_admission`,
  `test_guest_attempt_has_null_person_and_number_one`, `test_guest_cannot_use_foreign_registration`,
  `test_guest_save_and_submit_by_session_participation`.
- `tests/Unit/Callbacks/Assessment/AttemptCallbacksTest.php`: `test_anonymous_without_guest_session_is_denied`,
  `test_anonymous_with_guest_session_starts_exam_attempt`, `test_guest_cannot_touch_course_attempt_by_id`.
- `tests/Unit/Callbacks/Exam/LearnerExamCallbacksTest.php`: `test_guest_session_cannot_register_change_or_cancel` — экшены записи ученика для гостя закрыты
  (прямой POST отклоняется).
- e2e (приватное окно, без входа): ссылка входа → «Приступить» → ответить на 3 задания → закрыть вкладку → открыть ссылку входа снова → «Продолжить»,
  ответы на месте → сдать.

**Готово, когда:** тесты и e2e пройдены для КЕГЭ и ОГЭ; счётчики `persons`, `student_records`, `wp_users` до и после сдачи гостя равны.

---

## 11b.3 Результат сразу после сдачи

**Зачем.** Гость видит итог и полный разбор на странице сайта сразу после сдачи; ручная часть ОГЭ помечена как проверяемая, итог — предварительным (SPEC §6).

**Проверить перед началом**
- `AttemptRevealPolicy::isExamRevealed()` — гостю раскрыто после сдачи (7.5.1).
- `ExamReviewProjection::forViewer( …, 'read_only' )` (7.1.4), `ExamScoreService::summarize()` (7.3) — `pending`, `final`.
- Публичная оболочка: `ThemeCompatService`, `templates/frontend/subject/page.php`, `templates/frontend/partials/sidebar-*.php`, `PublicCourseService`.
- Существующие элементы результата (`../QA.md`): карточки заданий Тренажёра (`task-card-row`, `tcr-*`, `fs-answer`), `side-card`, `fs-breadcrumbs`.
  Renderer задач кабинета (`task-render.js`) живёт в бандле `profile`; на публичной странице разбор рендерится **на сервере** теми же данными проекции.

**Шаги**
- [x] 11b.3.1 Страница: `PageRoutes::ExamResult = 'exam-result'`, шорткод, шаблон `templates/frontend/exam-result.php`: шапка и подвал темы, хлебные крошки,
  контент + сайдбар (существующие партиалы сайдбара с курсами и CTA; своих CTA не придумывать). Обёртку страницы добавить в раскладку «сайдбар + контент».
  **Решение владельца (2026-10-09), реализовано 2026-10-10 (`GuestResultSidebarService`, набор «случайных» статей детерминирован номером участия):**
  - Сайдбар «Курсы» (`partials/sidebar-courses.php`, `PublicCourseService::getSidebarCourses()`): блок по направлению проведения — ЕГЭ → блок ЕГЭ, ОГЭ → блок ОГЭ, ровно как в учебнике. Это заглушки программы подготовки (ссылка на страницу предмета), а не реальные курсы: настоящий курс не ищется и не подбирается.
  - Вторым — сайдбар статей (`partials/sidebar-articles.php`): 4 статьи по заданиям, в которых у гостя ошибки. «Ошибка» — вердикт `incorrect`, `unanswered` или `partial`
    (`pending` — ручная часть ОГЭ — не считается). Статьи — опубликованные статьи предмета с термом `{key}_task_number` нужного номера
    (данные как у `ArticlesDataBuilder`, кеш `TransientKey::ArticleCatalog`). Разложить по ошибочным номерам по кругу (по одной с номера, начиная с наименьшего балла),
    недостающие до четырёх — случайные статьи предмета; ошибок нет — четыре случайные. Случайный набор закрепить на гостевую сессию, чтобы не менялся при обновлении страницы.
  - **ОГЭ:** статей по ОГЭ пока нет — сайдбар для ОГЭ не показывать. Оставить заготовку: сайдбар статей строится так же и появляется сам, когда у предмета ОГЭ есть
    опубликованные статьи; блок без статей скрыт (`hidden` в партиале). Сайдбар «Курсы» для ОГЭ — как в учебнике (то, что вернёт `getSidebarCourses()`).
  - Раскладка: сайдбар справа на широком экране, под результатом на узком (как на странице задания). Новый класс — `GuestResultSidebarService`, тесты на подбор статей
    (ошибки, круговой порядок, добор случайными, пустой ОГЭ).
- [x] 11b.3.2 Доступ: гостевая сессия входа (`scope = entry`) своего участия **или** сессия результата (11b.5). Нет доступа → 404.
  Данные: `ExamReviewProjection::forViewer( current_attempt_id, 'read_only' )` + `summarize()`. `attempt_id` из адреса не читается.
- [x] 11b.3.3 Блоки страницы (новые элементы №6 в `../QA.md`): шапка результата — балл и шкала (`resultCaption`-логика на PHP: вынести в
  `ExamScoreService::caption( array $summary ): string`, чтобы подпись в кабинете и на сайте совпадала); квадратики навигации по заданиям с цветом статуса
  (якоря из 7.2.2); три показателя (верно / частично / неверно и не решено); задания — карточки с «Ваш ответ», «Правильный ответ» (при ошибке),
  решение раскрывается кнопкой.
- [x] 11b.3.4 Ручная часть ОГЭ: задания со статусом `pending` показаны с пометкой «Проверяется преподавателем», их балл не выводится; итог —
  с подписью «Предварительный результат: проверены не все задания», отметка не показывается. После ручной проверки та же страница показывает
  окончательный итог; уведомления гостю нет.
- [x] 11b.3.5 После сдачи станция ведёт гостя на эту страницу: в `finish.php` для гостевой экзаменной попытки — кнопка «Посмотреть результат»
  (или автоматический переход, если так устроен экран завершения — проверить по шаблону).
- [x] 11b.3.6 Кнопок «Перерешать», «Решить самостоятельно» и режима тренировки нет. Гость видит только свою работу.
- [x] 11b.3.7 Ожидания утверждения и конца остальных сеансов нет: разбор доступен сразу после фиксации сдачи (следствие продуктового решения;
  задержку не вводить).

**Тесты**
- `tests/Unit/Callbacks/Exam/GuestEntryCallbacksTest.php`: `test_result_page_requires_guest_session_of_same_participation`,
  `test_result_page_ignores_attempt_id_param`, `test_result_page_before_submit_is_404`.
- `tests/Unit/Services/Exam/ExamScoreServiceTest.php`: `test_caption_for_ege_and_oge`, `test_caption_marks_preliminary_when_pending`.
- `tests/Unit/Templates/…ExamResultTemplateTest.php`: `test_pending_task_has_no_score_and_has_review_note`,
  `test_no_retry_or_practice_controls`, `test_correct_answer_shown_only_for_wrong_tasks`.
- Визуальная проверка на 1440 px и 390 px рядом со страницей задания Тренажёра: карточки заданий выглядят как на сайте.

**Готово, когда:** тесты пройдены; гость после сдачи ОГЭ видит разбор автопроверенных заданий и пометку «Проверяется» у ручных.

---

## 11b.4 «Завершить сеанс» на общем компьютере

**Зачем.** После гостя за компьютер сядет следующий человек: результат не должен остаться доступным (SPEC §6, критерий 30).

**Проверить перед началом**
- Заголовок `Cache-Control: no-store` на страницах гостя уже отправляется (11b.1.5, 11b.3).
- Возврат страницы из кеша переходов браузера (bfcache): событие `pageshow` с `event.persisted === true`.

**Шаги**
- [x] 11b.4.1 Кнопка «Завершить сеанс» на странице результата и на странице входа после сдачи. Публичный экшен `EndExamGuestSession` (nonce `ExamGuest`):
  `GuestSessionService::revoke( кука )`, удалить куку (`setcookie` с прошедшей датой), ответ — адрес нейтральной страницы (главная сайта).
- [x] 11b.4.2 Клиент (`src/js/frontend/services/exam-guest.js`, `initExamGuest()` в `frontend.js`): после ответа очистить содержимое блока результата
  (`innerHTML = ''`) и выполнить `window.location.replace( url )` — страница результата не остаётся в истории.
- [x] 11b.4.3 Возврат кнопкой «Назад»: на странице результата обработчик `pageshow` — при `persisted` выполнить `window.location.reload()`;
  перезагруженная страница без действующей сессии отдаёт 404. Дополнительно к `no-store` отправлять `Pragma: no-cache` и `Expires: 0`.
- [x] 11b.4.4 Сервер проверяет действительность сессии **при каждом запросе** страницы и каждого экшена гостя: отозванная сессия не работает, даже если
  браузер восстановил вкладки и сессионную куку.
- [x] 11b.4.5 Тайм-аут бездействия для просмотра результата: сессия после сдачи живёт 30 минут (11b.1.4); по истечении — 404, результат дальше доступен
  только по личной ссылке (11b.5).
- [x] 11b.4.6 Во время идущей попытки кнопки «Завершить сеанс» нет: выйти из экзамена можно, только сдав работу (как у станции сейчас).

**Тесты**
- `tests/Unit/Callbacks/Exam/GuestEntryCallbacksTest.php`: `test_end_session_revokes_and_clears_cookie`,
  `test_result_page_after_end_session_is_404`, `test_actions_with_revoked_session_are_denied`,
  `test_result_headers_have_no_store`.
- `GuestSessionServiceTest.php`: `test_result_view_expires_thirty_minutes_after_submit`.
- Ручная проверка в Chrome и Safari: сдать → «Завершить сеанс» → «Назад» → разбор не показан; закрыть и восстановить вкладки браузера → разбор не показан.

**Готово, когда:** тесты и ручная проверка в двух браузерах пройдены.

---

## 11b.5 Личная ссылка результата

**Зачем.** Позже сотрудник вручную отправляет гостю личную ссылку на результат; ссылку можно выдать, отозвать и перевыпустить (SPEC §6, §8, критерий 28).

**Проверить перед началом**
- `ExamTokenPurpose::Result`; право `ShareExamResults`; срок хранения гостевых данных — `PluginConfig::examGuestRetentionDays()`.
- Страница результата (11b.3) и её проверка доступа.

**Шаги**
- [x] 11b.5.1 `ExamConductService::issueResultLink( int $actorUserId, int $participationId ): string` — право `ShareExamResults` + `canManageEvent()`;
  участие гостевое; попытка сдана. `issue( Result, participation_id, actor, <дата завершения проведения + срок хранения> )`.
  Адрес: `home_url( '/exam-result/' ) . '?k=' . $plain`. `revokeResultLink( … )` — отзыв без нового ключа.
- [x] 11b.5.2 Экшены `IssueExamResultLink`, `RevokeExamResultLink` в `ExamConductCallbacks`. В строке гостя (экран сеанса и «Результаты»):
  «Скопировать ссылку результата» (выдаёт новый ключ и копирует; предупреждение о том, что прежняя ссылка перестанет работать — только если она уже была выдана),
  «Отозвать ссылку». Открытый ключ не хранится: «скопировать ещё раз» = перевыпуск. Запись в журнал: кто, когда, какое участие.
- [x] 11b.5.3 Обработка `?k=` на странице результата: `exchange( Result, k )` → сессия `scope = result` (`GuestSessionService::openResult()`, кука `fs_exam_result`,
  сессионная) → редирект без ключа. Сессия результата **не даёт** начать, продолжить или пересдать попытку: `contextForGuest()` принимает только `scope = entry`.
- [x] 11b.5.4 Отзыв ссылки результата не влияет на идущий экзамен и на сессию входа (разные назначения). Отзыв входа не отзывает результат.
- [x] 11b.5.5 Ключ результата на странице входа и ключ входа на странице результата → 404.

**Тесты**
- `ExamConductServiceTest.php`: `test_result_link_requires_share_cap_and_submitted_attempt`, `test_result_link_denied_for_student_participation`,
  `test_revoke_result_link_keeps_entry_session`.
- `GuestEntryCallbacksTest.php`: `test_result_key_opens_result_and_redirects_without_key`, `test_result_session_cannot_start_attempt`,
  `test_revoked_result_key_is_404`, `test_entry_key_on_result_page_is_404`.
- Проверка хранения: `SELECT token_hash FROM wp_fs_lms_exam_access_tokens` — 64 hex-символа; открытого ключа нет ни в таблицах, ни в `debug.log`.

**Готово, когда:** тесты пройдены; ссылку результата можно выдать, открыть на другом устройстве, отозвать (после отзыва — 404) и перевыпустить.

---

## Проверка этапа (SPEC §16: 12–14, 28, 30)

- [ ] Гость без `Person` сдаёт, автосохраняет, возвращается по ссылке и получает разбор; в списках учеников не появляется.
- [ ] Ученик не обходит утверждение через гостевую форму или гостевой адрес.
- [ ] По ФИО и телефону без сотрудника чужую запись или ссылку получить нельзя.
- [ ] «Назад» после «Завершить сеанс» не показывает разбор; кука не постоянная; восстановление вкладок не обходит отзыв.
- [ ] Гость не отменяет и не меняет сеанс прямым запросом.
- [ ] Ссылки входа и результата выдаются, отзываются, перевыпускаются; открытый ключ не хранится.
- [ ] `npm run ci`, `npx gulp build` — зелёные.
