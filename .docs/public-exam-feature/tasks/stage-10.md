# Этап 10. Статистика

Зависимости: этап 8. Результат: внутренний экран «Статистика» для сотрудника: показатели проведения и разбор по заданиям.
Это не школьный отчёт (он — этап 12).

Перед началом прочитать `README.md`. SPEC: §7 «Статистика». Макет: `../stats.png`, `../QA.md` («Результаты, Статистика»: `pr-row`, `prof-work-item`,
`prof-seg`, `prof-stat-tiles`, `sc-row`).

**Порядок:** 10.1 → 10.2.

## Общие правила этапа

- Все числа считает сервер. Клиент ничего не усредняет и не складывает.
- Шкалы разных форматов не смешиваются: одно среднее — один формат (ЕГЭ отдельно, ОГЭ отдельно).
- Статистика недоступна ученикам, родителям и гостям.
- Рядом с каждым средним показывается размер выборки.

---

## 10.1 Фильтры и показатели

**Зачем.** Сколько записано, начали, сдали, не явились, ждут проверки; средние баллы по завершённым оценённым работам (SPEC §7).

**Проверить перед началом**
- Заглушка `src/js/profile/exams/exam-stats.js` (4.1.4).
- `ExamConductService::results()` (8.7.1) — выборка участий с попытками и статусом результата; `ExamScoreService::summarize()` (7.3).
- Репозитории: `ExamParticipationRepository::listByEvent()`, `ExamRegistrationRepository::listBySession()`, `AssessmentAttemptRepository::listByParticipations()`.
- Формат проведения — по основному варианту; если в проведении сеансы с вариантами **разных форматов** (не должно быть: варианты одного предмета
  одного направления), статистика считается отдельно по каждому формату.

**Шаги**
- [ ] 10.1.1 `inc/Services/Exam/ExamStatsService.php`. Зависимости: репозитории проведений, сеансов, участий, записей, попыток, ответов;
  `ExamScoreService`, `ExamAccessGuard`, `ScoringUnits`, `AssessmentManager`.
  `overview( int $actorUserId, array $filters ): array`. Фильтры: `subject_key`, `event_id`, `session_id?`, `audience` (`student` / `guest` / `all`).
  Право: `canManageEvent()`.
- [ ] 10.1.2 Показатели (`kpi`):
  | Ключ | Как считается |
  |---|---|
  | `registered` | участия, у которых есть действующая запись **или** попытка (отменённые без попытки не считаются) |
  | `started` | участия с попыткой |
  | `submitted` | попытка не `in_progress` |
  | `missed` | участия, у которых последняя запись `missed` и нет попытки |
  | `pending_review` | сдана, есть задания на ручной проверке |
  | `avg_primary` | среднее первичного по работам, где проверка завершена (`final = true`), округление до одного знака |
  | `avg_secondary` | ЕГЭ: среднее вторичного по тем же работам; ОГЭ: `null` |
  | `avg_grade` | ОГЭ: средняя отметка; ЕГЭ: `null` |
  | `sample` | число работ, вошедших в средние |
  При `sample = 0` средние — `null`, клиент показывает «—».
- [ ] 10.1.3 Что не искажает статистику:
  - отменённые и перенесённые брони — считается участие, а не строки записей (перенос не даёт «+1 записано»);
  - попытки не этого проведения — берутся только попытки с `exam_participation_id` участий проведения;
  - фильтр по сеансу — участия, чья попытка или действующая запись относится к этому сеансу; для `missed` — сеанс пропущенной записи.
- [ ] 10.1.4 Блок `format` в ответе: `direction`, `primary_max`, `secondary_max`, `grade_max`, `unit_count` — из снимка проведения.
- [ ] 10.1.5 `inc/Callbacks/Exam/ExamStatsCallbacks.php`, экшен `GetExamStats` (`subject_key`, `event_id`, `session_id?`, `audience?`);
  `authorize( Nonce::ExamManage, Capability::ManageExams )`. Зарегистрировать в `ExamController`, действие — в `exams.actions`.
  Ответ: `filters` (проведения предмета, сеансы), `format`, `kpi`, `tasks` (10.2).
- [ ] 10.1.6 `exam-stats.js`: селекторы предмета (скрыт при одном), проведения, сеанса («Все сеансы»), сегмент «ученики / гости / все» (`prof-seg`);
  плитки `prof-stat-tiles`. Все плитки одной высоты, отступ сетки общий (SPEC «Уточнения по мокапам»). Подпись под средним — «по {N} работам»
  (склонение — `plural()`).
- [ ] 10.1.7 Пустые состояния: нет проведений; в проведении нет записей; нет завершённых работ — показатели записи есть, средние «—».

**Тесты** — `tests/Unit/Services/Exam/ExamStatsServiceTest.php` (фикстуры — массивы DTO, репозитории — моки):
- `test_kpi_counts_on_fixture` — фикстура: 10 участий: 6 сдали (из них 1 ждёт ручной проверки), 1 в процессе, 2 неявки, 1 записан без попытки →
  `registered = 10`, `started = 7`, `submitted = 6`, `missed = 2`, `pending_review = 1`, `sample = 5`;
- `test_transferred_registration_counts_participation_once`;
- `test_cancelled_registration_without_attempt_is_not_registered`;
- `test_averages_use_only_final_results`;
- `test_ege_has_secondary_average_and_no_grade`, `test_oge_has_grade_average_and_no_secondary`;
- `test_zero_sample_gives_null_averages`;
- `test_session_filter`, `test_audience_filter_separates_guests`;
- `test_denied_for_foreign_event`.
- `tests/Unit/Callbacks/Exam/ExamStatsCallbacksTest.php`: `test_requires_manage_exams`, `test_student_role_denied`.

**Готово, когда:** тесты зелёные; числа плиток на dev совпадают с ручным подсчётом по доске сеанса (8.1).

---

## 10.2 Разбор по заданиям

**Зачем.** По каждому заданию — доли полного балла, частичного, ошибок, пропусков и ожидающих проверки; №26–27 учитываются по 2 балла (SPEC §7).

**Проверить перед началом**
- `ExamScoreService::units()` (7.3.3) — статус и балл единицы оценивания по `unit_key`.
- Статусы задания (7.2.1): `correct`, `partial`, `incorrect`, `unanswered`, `pending`.
- Строка задания в макете — `sc-row` с полосой долей.

**Шаги**
- [ ] 10.2.1 `ExamStatsService::byTask( array $attempts, ExamEventDTO $event ): array` — по каждой единице оценивания формата (`1..unit_count`):
  `number`, `max` (`unitMax( number )`), `total` (число сданных работ), `full`, `partial`, `incorrect`, `unanswered`, `pending` (числа работ),
  `avg_score` (средний балл единицы по работам без `pending`), `full_share` (доля полного балла в процентах, целое).
  Статус единицы в работе — по правилу 7.3.3; несколько заданий одного номера дают **одну** единицу.
- [ ] 10.2.2 Попытки `in_progress` в разбор не входят. Работы, где единица на ручной проверке, попадают в `pending` и не входят в `avg_score`.
- [ ] 10.2.3 Сумма `full + partial + incorrect + unanswered + pending` по каждой строке равна `total` — проверять тестом.
- [ ] 10.2.4 `exam-stats.js`: таблица заданий — строки `sc-row`: номер, максимум, полоса из пяти сегментов (ширины — доли; цвета — те же токены,
  что у вердиктов 7.2.4), проценты текстом. Ширину сегментов задавать не `style`, а через существующий механизм прогресса
  (`applyProgress()` и атрибут `data-progress` из `src/js/common/utils.js`) — по одному элементу на сегмент.
- [ ] 10.2.5 Сортировка: по номеру (по умолчанию) и «сначала самые трудные» (по возрастанию `full_share`) — переключатель `prof-seg`.
- [ ] 10.2.6 Экспорта статистики в файл нет (в плане не заявлен).

**Тесты** — `ExamStatsServiceTest.php`:
- `test_by_task_shares_sum_to_total_for_every_unit`;
- `test_unit_26_has_max_two_and_partial_bucket` — работа с 1 баллом из 2 попадает в `partial`;
- `test_repeated_numbers_form_one_unit`;
- `test_pending_units_are_excluded_from_average`;
- `test_in_progress_attempts_are_ignored`;
- `test_oge_has_sixteen_units_with_manual_max`;
- `test_fixture_matches_manual_count` — фикстура из трёх работ с выписанными вручную ожидаемыми числами по трём заданиям.
- Сверка с ручным подсчётом: на dev по сеансу с 3–5 сданными работами выписать на бумаге/в `NOTES.md` статусы заданий №1, №14, №26 и сравнить с экраном.

**Готово, когда:** тесты зелёные; сверка с ручным подсчётом записана в `NOTES.md`.

---

## Проверка этапа

- [ ] PHPUnit на агрегаты зелёный.
- [ ] Числа экрана совпадают с ручным подсчётом.
- [ ] Статистика ЕГЭ и ОГЭ не смешивает шкалы; у каждого среднего указан размер выборки.
- [ ] Ученик и родитель не могут получить `get_exam_stats` (прямой запрос — отказ).
- [ ] `npm run ci`, `npx gulp build` — зелёные.

**Конец M2: полный цикл для учеников центра** (запись → сдача → проверка → утверждение → результат → уведомления → статистика).
