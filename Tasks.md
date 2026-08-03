# Задачи: доводка после удаления кокпита группы (2026-08-01)

Контекст: фронт-страница `/group/` (кокпит) удалена, плеер урока переехал на собственный
маршрут `/lesson/?gid=N&gl=M`. Осиротевшие AJAX-хуки разобраны: дубликаты и ненужное удалены,
две функции перенесены в кабинет. Открытых вопросов не осталось.

---

## Сделано

### Привязка попытки контрольной к занятию ✅

`assessment_attempts.group_lesson_id` заполнялся только при заходе из плеера (`from_gl` в URL);
по прямой ссылке — закладка, возврат к активной попытке из кабинета (`LearnerService::examLock`)
— попытка оставалась без привязки и выпадала из отчёта занятия.

Оказалось, привязку не нужно ниоткуда доставать: `AssessmentAccessPolicy::canAccess()` и так
перебирала занятия, чтобы решить, есть ли доступ, — просто отдавала наружу `bool`. Теперь есть
`resolveAccessibleLesson(): ?GroupLessonDTO`, а `canAccess()` — тонкая обёртка над ним;
`AttemptService::start()` берёт занятие и группу оттуда, когда параметров нет. Контекст из
плеера по-прежнему в приоритете. Если контрольная стоит в нескольких занятиях, выбирается
самое позднее по расписанию (занятия без даты уступают датированным).

Старые попытки (7 записей на dev) так и остались с `NULL` — их привязка неизвестна; чинить
разовой миграцией нечего.

### История пересдач работ и контрольных ✅

`submitBatch()` перезаписывает строку `submissions`, поэтому каждая сдача дополнительно
пишется в `task_attempts` с синтетическим ключом `work:{id}` (формат — в enum
`Inc\Enums\Course\AttemptSource`). Нумерация идёт **по задаче**, а не по работе целиком:
в одной сдаче задач несколько, и сквозной счётчик давал бы второй задаче номера 2, 4, 6.
Сброс работы (`WorkResetService`) удаляет историю вместе со сдачами — иначе после «сброса»
преподаватель видел бы старые попытки.

Контрольные истории не требовали: `assessment_attempts` изначально пишет каждую попытку
отдельной строкой со своим `attempt_number` — их достаточно было прочитать
(`listByGroupLesson()`).

Отчёт `TaskAttemptReportService` отдаёт три блока — `steps` (задания урока), `works` (задачи
работ, с названием работы в заголовке) и `exams` (контрольные: лучший балл, число пересдач,
чипы попыток со статусом вместо вердикта).

### Экран «Активность» в кабинете ✅

Новый экран (`activity`) для преподавателя и офиса, две вкладки на одном экране:

- **Лента событий** — `GetGroupActivity`: журнал обучения группы (`fs_lms_learning_events`)
  постранично по 20, с кнопкой «Показать ещё». Метки событий человекочитаемые
  (`Начата попытка`, `Оценена работа`, `Изменено расписание`, …), рядом — актор и время.
- **Решения задач** — `GetTaskAttempts`: селект занятия (по умолчанию последнее прошедшее),
  под ним разбивка по заданиям-шагам: «решили N из M», построчно ученики с числом попыток
  и чипами `#1 1/1`, `#2 0/1` — зелёный/красный по вердикту.

Что изменилось в контракте `GetTaskAttempts`: раньше он принимал `group_lesson_id` + `step_key`
(список шагов давала снятая панель настроек кокпита) и брал имя ученика через `get_the_title()`
по `person_id` — то есть всегда мимо. Теперь принимает только `group_lesson_id`, отдаёт все
шаги занятия, имена берёт из снимка `student_records` (как остальные экраны преподавателя),
доступ проверяет `requireProgramRow()` из трейта `ProgramAccess`.

Новое: `TaskAttemptReportService` (группировка шаг → ученик → попытки),
`TaskAttemptRepository::listByGroupLesson()`, нонсы `TaskAttempts` и `GroupActivity`
(лента читает журнал, а не правит расписание — свой нонс вместо `SaveSchedule`),
`src/js/profile/activity.js`, `src/scss/profile/components/_activity.scss`.
Тесты: `TaskAttemptReportServiceTest` (4), `TaskAttemptCallbacksTest` (4).

### Удалённые хуки ✅

Как дубликаты актуального пути:

| Хук | Чем заменён |
|---|---|
| `GetGroupSubmissions` | кабинет считает очередь `SubmissionRepository::listQueueByGroup()`; `ReviewQueueService` удалён целиком |
| `GetGradebook` | `GradebookService` остаётся (журнал, сводка, кабинет ученика), убран только эндпоинт |
| `GetMySubmissions` | не вызывался ниоткуда |
| `SubmitWork` | работы сдаются в плеере (`SubmitBatchWork`), файловые ответы — `UploadAnswerFile` + `SaveAttemptAnswer` |
| `SaveLessonSchedule` | дата занятия ставится через `PinLesson` |

Как ненужные (решение 2026-08-01): `AddLessonToProgram`, `RemoveLessonFromProgram`,
`SetLessonVisibility`, `GetStepSettings`, `SaveStepSettings`, `ReorderProgram`,
`DuplicateProgramLesson`.

Вместе с ними убраны: `SubmissionService::submit()`, `LessonVisibilityService::setVisibility()`,
`ProgramCompositionService::addLesson()/removeLesson()/reorder()/duplicateLesson()`, кейсы
`AjaxHook`/`Nonce`, регистрации в `ScheduleController`/`SubmissionController` и тесты.

Правила дедлайнов (D13: `allow_late`, per-work deadline), которые проверялись через удалённый
`submit()`, перенесены на `submitBatch()` — 5 новых тестов в `SubmissionServiceTest`.
