# Этап 12. Источники школ и школьные отчёты
Перед началом сделай чтобы меню "Мои экзамены" у всех пользователей было свёрнуто по умолчанию
Зависимости: этап 8; для гостей — 11a и 11b (для учеников центра отчёт можно делать раньше). Результат: сотрудник собирает именованный отчёт из
выбранных участий и выдаёт школьному преподавателю ссылку; преподаватель видит таблицу результатов и разбор выбранного участника, только для чтения.

Перед началом прочитать `README.md`. SPEC: §8 целиком, §9 (согласия на передачу), §16 критерии 17, 18, 26.
Макет: `../report.png`, `../report-expanded.png`, `../mobile-report.png`, `../QA.md`.

**Порядок:** 12.1 → 12.2 → 12.3 → 12.4.

## Общие правила этапа

- Школьный преподаватель **не получает роль WordPress** и учётку. Доступ — только ссылка отчёта.
- Состав отчёта **фиксирован**: новый гость той же школы сам в отчёт не попадает.
- Ссылка приглашения (источник) доступа к результатам не даёт. Отчёт выдаётся отдельно и отдельным ключом (`ExamTokenPurpose::Report`).
- В отчёте нет контактов, личных ссылок, ключей, CSV и любых действий от имени участника.
- Для каждого запроса страницы отчёта сервер проверяет: ключ или сессия отчёта действительны **и** запрошенное участие входит в этот отчёт.
  Идентификатору попытки из адреса не доверять — его там и не должно быть.

---

## 12.1 Подсказки школ и связь источника с получателем отчёта

**Зачем.** При создании источника сотрудник выбирает школу из существующего справочника, а отчёт знает, какому источнику (школьному преподавателю) он адресован.

**Проверить перед началом**
- Справочник и подсказки: `src/js/frontend/data/schools.js`, `src/js/frontend/services/school-suggest.js`, тест `tests/js/school-suggest.test.mjs`.
  Посмотреть экспорт и что возвращает подсказка (название, ключ школы).
- Форма источника в кабинете — `src/js/profile/exams/exam-sources.js` (4.6.5); поле школы там — обычный ввод.
- `exam_sources.school_key`, `school_name`, `school_name_normalized`; `exam_reports.recipient_source_id`.

**Шаги**
- [ ] 12.1.1 Вынести подсказку школ в общий модуль: если `school-suggest.js` не зависит от DOM формы заявки — перенести ядро (поиск по справочнику)
  в `src/js/common/school-suggest.js` и оставить в `frontend/services/school-suggest.js` тонкую обёртку. Существующий JS-тест должен пройти без изменения ожиданий.
  Копию справочника в бандл кабинета не заводить.
- [ ] 12.1.2 `exam-sources.js`: поле школы с подсказками; при выборе из списка сохранять `school_key` и `school_name`, при свободном вводе — только
  `school_name` (`school_key = null`). Школы не объединяются по похожей строке; нового глобального реестра школ нет.
- [ ] 12.1.3 `ExamSourceService::save()` — принимать `school_key` (через `sanitizeKey`), проверять длину; `label` — внутреннее имя выборки (необязательное).
- [ ] 12.1.4 Источники и приглашения созданы в 4.6 и 11a. Здесь к ним добавляется только использование в отчёте: в форме отчёта (12.2) — выбор
  «Получатель: {школа, преподаватель}» из источников проведения, значение — `recipient_source_id` (необязательно: отчёт можно создать и без источника).

**Тесты**
- `tests/js/school-suggest.test.mjs` — зелёный после переноса; добавить `test` на импорт из `common`.
- `ExamSourceServiceTest.php`: `test_save_keeps_school_key_when_given`, `test_free_text_school_has_null_key`.

**Готово, когда:** тесты зелёные; в форме источника работает подсказка школ.

---

## 12.2 Отчёт: фиксированная выборка, срок, отзыв, перевыпуск

**Зачем.** Именованный отчёт — список выбранных участий с отдельным ключом доступа и сроком. Ученики центра включаются без гостевого согласия;
гости — только согласившиеся на передачу результата школе (SPEC §8, §9, критерии 17, 26).

**Проверить перед началом**
- Таблицы `exam_reports`, `exam_report_members` (2.1); `exam_participations.transfer_allowed`, `consent_refs`.
- Согласие на передачу — тип `ConsentType::PdTransfer`; гость отмечает его в форме (11a.3.2), ID согласия лежит в `consent_refs` заявки и участия.
  При подтверждении оплаты (`materializeParticipant()`, 11a.1.4) выставить `transfer_allowed = 1`, если согласие `pd_transfer` дано, — **проверить, что это сделано**;
  если нет — дописать там.
- Право: `ShareExamResults` + `ExamAccessGuard::canManageEvent()`.
- Срок хранения гостевых данных: `PluginConfig::examGuestRetentionDays()`.

**Шаги**
- [ ] 12.2.1 `inc/DTO/Exam/ExamReportDTO.php`; `inc/Repositories/WPDBRepositories/Exam/ExamReportRepository.php`: `create`, `find`, `update( …, int $expectedVersion )`,
  `listByEvent( int $eventId )`, `addMember( int $reportId, int $participationId, ?int $consentRef ): bool`, `removeMember()`, `listMemberIds( int $reportId ): array`,
  `isMember( int $reportId, int $participationId ): bool`.
- [ ] 12.2.2 `inc/Services/Exam/ExamReportService.php`, `use TransactionRunner;`. Зависимости: репозитории отчётов, участий, проведений, источников;
  `ExamAccessGuard`, `ExamAccessTokenService`, `LogEventDispatcherInterface`, `PluginConfig`, `ExamTime`.
- [ ] 12.2.3 `create( int $actorUserId, int $eventId, string $title, array $participationIds, ?int $recipientSourceId, ?int $days ): ExamReportDTO`:
  - название обязательно; `days` — от 1 до 90, по умолчанию 90; `expires_at = min( сейчас + days, конец хранения данных проведения )`;
  - каждое участие проходит `canInclude()` (12.2.4); непрошедшие — отказ всей операции с перечнем причин (чтобы сотрудник не думал, что они включены);
  - `recipient_source_id`, если задан, принадлежит этому проведению.
- [ ] 12.2.4 `canInclude( ExamParticipationDTO $p, int $eventId ): ?string` — `null` или причина:
  | Условие | Причина |
  |---|---|
  | участие другого проведения | «Участник не из этого проведения.» |
  | попытка не сдана | «Работа не сдана.» |
  | аудитория `student`, попытка не утверждена | «Результат ученика не утверждён.» |
  | аудитория `guest`, `transfer_allowed = 0` | «Нет согласия на передачу результата школе.» |
  | аудитория `guest`, согласие отозвано (`ConsentRepository`: `withdrawn_at` у `consent_ref`) | «Согласие отозвано.» |
  Ученику центра отдельное согласие на передачу не требуется (решение пользователя). Наличие оплаты или приглашения согласием **не считается**.
- [ ] 12.2.5 Изменение состава — отдельные методы `addMember()` / `removeMember()` с проверкой `canInclude()`, версией отчёта и записью в журнал
  (кто, когда, кого добавил или убрал). Автоматического пополнения по школе нет.
- [ ] 12.2.6 Ключ отчёта: `issueLink( int $actorUserId, int $reportId ): string` — `issue( Report, report_id, actor, expires_at отчёта )`,
  адрес `home_url( '/exam-report/' ) . '?k=' . $plain`; `revoke( … )` — `revoked_at` у отчёта и отзыв ключа; перевыпуск — повторный `issueLink()`
  (старый ключ отзывается). Журнал — на каждое действие.
- [ ] 12.2.7 `inc/Callbacks/Exam/ExamReportCallbacks.php`, экшены (nonce `ExamManage`, право `ShareExamResults`): `GetExamReports` (`event_id`),
  `SaveExamReport`, `AddExamReportMember`, `RemoveExamReportMember`, `IssueExamReportLink`, `RevokeExamReportLink`.
  Выдача и копирование ссылки **не требуют** `ExportPII` и `ManageLmsPlatform`.
- [ ] 12.2.8 Интерфейс — на экране «Результаты» (`exam-results.js`): режим выбора строк (как при утверждении) → «Создать отчёт» → форма в поповере
  (название, получатель, срок в днях) → список отчётов проведения (название, число участников, «действует до {дата}», кнопки «Скопировать ссылку»,
  «Отозвать», «Изменить состав»). Строки, которые нельзя включить, в режиме выбора неактивны с причиной из 12.2.4.
  Отзыв — через `confirmDialog()`.

**Тесты** — `tests/Unit/Services/Exam/ExamReportServiceTest.php`:
- `test_create_requires_share_cap_and_event_scope`;
- по тесту на каждую строку таблицы 12.2.4 (`test_cannot_include_…`);
- `test_center_student_included_without_guest_consent`;
- `test_guest_without_transfer_consent_is_rejected`;
- `test_payment_or_invitation_is_not_treated_as_consent`;
- `test_new_guest_of_same_school_is_not_added_automatically`;
- `test_default_term_is_ninety_days_and_capped_by_retention`;
- `test_member_change_is_logged_and_versioned`;
- `test_revoke_blocks_key_exchange`, `test_reissue_revokes_previous_key`.
- `tests/Unit/Callbacks/Exam/ExamReportCallbacksTest.php`: `test_link_issue_does_not_require_export_pii`,
  `test_denied_without_share_cap`, `test_denied_for_foreign_event`.

**Готово, когда:** тесты зелёные; на dev создан отчёт из ученика центра и согласившегося гостя; гость без согласия в режиме выбора неактивен.

---

## 12.3 Страница отчёта на сайте

**Зачем.** Школьный преподаватель по ссылке видит таблицу результатов и раскрывает под ней разбор выбранного участника (SPEC §8).

**Проверить перед началом**
- Страницы гостя (11a.2, 11b.1, 11b.3): порядок «ключ → кука → редирект без ключа», заголовки, 404.
- Серверный рендер разбора из 11b.3 (карточки заданий, квадратики навигации) — переиспользовать тот же партиал.
- `ExamReviewProjection::forViewer( …, 'read_only' )`, `ExamScoreService::summarize()` / `caption()`.

**Шаги**
- [ ] 12.3.1 Страница: `PageRoutes::ExamReport = 'exam-report'`, шорткод, шаблон `templates/frontend/exam-report.php` (шапка и подвал темы).
  Обработка ключа — как у результата: `exchange( Report, k )` → `GuestSessionService::openReport( report_id, generation, expires_at )`
  (`scope = report`) → редирект без ключа. Для ID отчёта в `exam_guest_sessions` нужна своя колонка `report_id int unsigned DEFAULT NULL`:
  добавить её в DDL (`Migration_1_0_0` через `examDdl()`) и идемпотентным `ALTER TABLE … ADD COLUMN` в версионной миграции этапа (README §4.2).
  Чужие колонки (`source_id`) под ID отчёта не использовать.
- [ ] 12.3.2 Проверка на **каждом** запросе страницы: сессия отчёта действительна; отчёт не отозван и не истёк; поколение ключа актуально.
  Иначе — 404.
- [ ] 12.3.3 Таблица результатов (новый элемент №6 `../QA.md`): строка на участие отчёта — ФИО, статус работы, итог (`caption()`), кнопка
  «Результат и работа». Три показателя над таблицей (число участников, средний итог по окончательным результатам, число ожидающих проверки) —
  карточки одной высоты. У учеников центра показываются только утверждённые результаты (это условие включения, 12.2.4); если после включения результат
  исправлен — отчёт показывает актуальный.
- [ ] 12.3.4 Раскрытие разбора: адрес страницы с параметром `p=<номер строки отчёта>` (порядковый номер в отчёте, **не** ID участия и не ID попытки).
  Сервер по номеру строки берёт участие из состава отчёта, строит разбор и выводит блок под таблицей: имя и баллы, квадратики навигации, задания.
  Выбор другого участника заменяет блок. Клиент после загрузки прокручивает к блоку (`src/js/frontend/services/exam-guest.js`).
  Номер вне диапазона → страница без блока разбора (не ошибка с подробностями).
- [ ] 12.3.5 Участник, удалённый или обезличенный по сроку хранения (13.3): строка остаётся с текстом «Данные участника недоступны», кнопки раскрытия нет;
  остальные строки работают.
- [ ] 12.3.6 Ручная часть ОГЭ у гостя: статус «Проверяется», итог предварительный — как в 11b.3.4.

**Тесты**
- `tests/Unit/Callbacks/Exam/ExamReportPageTest.php` (или в `GuestEntryCallbacksTest`): `test_report_key_opens_report_and_redirects_without_key`,
  `test_revoked_expired_or_wrong_purpose_key_is_404`, `test_review_block_is_built_only_for_report_member`,
  `test_row_number_out_of_range_shows_no_review`, `test_page_never_accepts_attempt_or_participation_id`,
  `test_anonymized_member_row_is_placeholder`.
- `ExamReportServiceTest.php`: `test_report_shows_current_result_after_correction`.

**Готово, когда:** тесты пройдены; на dev по ссылке отчёта открывается таблица, клик «Результат и работа» раскрывает разбор под таблицей.

---

## 12.4 Ограничения отчёта

**Зачем.** Внешний отчёт — только чтение ограниченной выборки (SPEC §8, критерии 17, 18).

**Шаги**
- [ ] 12.4.1 В ответе страницы и в её HTML нет: телефона, мессенджера, школы плательщика, ключей и ссылок входа/результата, ID попыток и участий,
  кнопок оценивания, CSV и печати с ПД. Проверка поиском по HTML страницы (см. тесты).
- [ ] 12.4.2 На странице отчёта нет ни одного экшена записи: сессия `scope = report` не принимается ни одним изменяющим коллбеком
  (`contextForGuest()` — только `entry`; экшены сотрудника требуют входа и прав).
- [ ] 12.4.3 Заголовки: `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`; страница исключена из карты сайта и поиска.
- [ ] 12.4.4 Подмена: ключ отчёта A не открывает отчёт B; ключ назначения `result` или `entry` на странице отчёта → 404; номер строки чужого отчёта
  подставить нельзя (номер относится только к текущему отчёту).
- [ ] 12.4.5 Отзыв согласия гостя на передачу (`withdrawn_at`) или удаление по сроку хранения: строка участника в отчёте заменяется заглушкой (12.3.5)
  **без** пересоздания отчёта — проверять согласие при каждом построении страницы. Действующий `Person` ученика центра при этом не трогается.

**Тесты**
- `ExamReportPageTest.php`: `test_html_has_no_contacts_tokens_or_internal_ids`, `test_headers_noindex_no_store_no_referrer`,
  `test_report_session_cannot_call_guest_or_staff_mutations`, `test_key_of_report_a_does_not_open_report_b`,
  `test_withdrawn_transfer_consent_hides_member`.
- Ручная проверка: в адресе страницы отчёта подставить `attempt=`, `participation=`, `p=999` — данных чужих участников нет.

**Готово, когда:** тесты и ручная проверка пройдены.

---

## Проверка этапа (SPEC §16: 17, 18, 26)

- [ ] Подмена идентификаторов и ключа чужого назначения → отказ без утечки.
- [ ] Новый гость той же школы в отчёт не попадает.
- [ ] Ученик центра включается без гостевого согласия; гость без согласия на передачу — нет.
- [ ] Внешний отчёт не позволяет менять оценки и выгружать ПД; внутренний CSV и печать требуют оба экспортных права (8.9).
- [ ] Отзыв и истечение срока прекращают доступ.
- [ ] `npm run ci`, `npx gulp build` — зелёные. Новые элементы — в `../QA.md`.

**Конец M3.**
