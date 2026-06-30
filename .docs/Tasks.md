# План внедрения RBAC (этапы)

> **Источник:** [[Roles.md]] §7 (миграция прав). **Статус:** не начато.
> **Дата:** 2026-06-30.

Пошаговый план перехода от текущих двух широких прав (`manage_lms_assignments`, `manage_options`) к дробной модели из `Roles.md`. Каждый этап самодостаточен и безопасен.

## Принципы безопасной миграции

1. **Administrator никогда не теряет доступ** — на каждом шаге сначала выдаём новое право, потом перевешиваем гейты, и только в конце снимаем старое.
2. **Не снимаем старый доступ, пока не работает замена.** `manage_lms_assignments` живёт до конца этапа 8.
3. **Не назначаем новые роли пользователям до конца этапа 3** — до этого гейты ещё на старом праве, роли инертны.
4. **Каждый этап → прогон PHPUnit** (`./vendor/bin/phpunit`) + ручная проверка по матрице ролей.

---

## Этап 1 — Реестр capabilities и ролей (фундамент)

Добавляем новые права и роли, раздаём их в `RoleManager`. Гейты пока не трогаем — поведение не меняется, новые роли инертны.

**Задача 1.1. Новые capabilities.**
`inc/Enums/Access/Capability.php` — добавить кейсы: `ManageLmsPlatform = 'manage_lms_platform'`, `AuthorLmsCourses = 'author_lms_courses'`, `ManageLmsArticles = 'manage_lms_articles'`, `ManageLmsTeaching = 'manage_lms_teaching'`, `ManageLmsRoles = 'manage_lms_roles'`. (`EditLmsCoursePresentation` — отложено, см. Roles.md §8.)

**Задача 1.2. Новые роли.**
`inc/Enums/Access/UserRole.php` — добавить кейсы `FSMethodist = 'lms_methodist'`, `FSMarket = 'lms_market'`; их `label()` (🎓 LMS: Методист / Маркетолог); расширить `capabilities()` (L98-113):
- `FSMethodist` → `author_lms_courses`
- `FSMarket` → `manage_lms_articles`, `view_lms_stats`
- `FSOffice` → дополнить до полного набора: `manage_lms_platform`, `view_lms_stats`, `export_pii`, `author_lms_courses`, `manage_lms_articles`, `manage_lms_teaching` (плюс уже имеющиеся applications/enroll/persons/view_pii)
- `FSTeacher` → **добавить** `manage_lms_teaching` (старое `manage_lms_assignments` пока оставить)

**Задача 1.3. Раздача прав и CPT-мета-каповов в RoleManager.**
`inc/Managers/Person/RoleManager.php`:
- В блоке грантов администратору (L75-87) добавить все новые `manage_lms_*` / `author_lms_courses`.
- `lessonCaps()` (L102-119, `capability_type = fs_lms_content`) — выдать эти 14 мета-каповов **методисту** (`lms_methodist`) и **FSOffice**, а не только `lms_teacher` (L89-94). Это нужно, чтобы методист мог редактировать курсы/уроки/работы/контрольные/задачи в wp-admin.
- Обновить doc-таблицу матрицы в шапке (L24-33).

**Задача 1.4. Переустановка ролей на апгрейде.**
`RoleManager::registerAll()` сейчас выполняется при активации. Добавить версионирование (`fs_lms_roles_version` в `wp_options`, по аналогии с `fs_lms_schema_version`) и пере-вызов `registerAll()` при росте версии — чтобы существующие установки получили новые роли/права без деактивации.

**Проверка:** новые роли видны в WP (Пользователи → роль), `wp_roles` содержит новые caps; старое поведение не изменилось.

---

## Этап 2 — Плагин-админ зоны: `manage_options` → `manage_lms_platform`

Перевешиваем внутренние админ-страницы с native-WP-права на плагинное, чтобы их получил FSOffice.

**Задача 2.1. Меню админки.**
- `inc/Controllers/System/AdminController.php` (L138,177,187,198,209,219,229) — `Capability::Admin` → `Capability::ManageLmsPlatform` для: Settings, UserList (Пользователи), Logs, Groups, BoilerplateManager, Main-дашборд. **Исключение:** страницу/дашборд **Статистики** гейтить `Capability::ViewLmsStats` (чтобы её видел и маркетолог).
- `inc/Controllers/Builders/SubjectsMenuBuilder.php` (L87, L117) — `Capability::Admin` → `Capability::ManageLmsPlatform` (Предметы).
- `inc/Controllers/Log/LogsController.php` (L33, экспорт логов) — `Capability::Admin` → `Capability::ManageLmsPlatform`.

**Задача 2.2. Админ-байпас на фронте групп.**
- `inc/Services/Course/GroupAccessGuard.php` (`canManage()`, L19-25) — байпасить не только `Capability::Admin`, но и `Capability::ManageLmsPlatform` (FSOffice курирует любую группу).
- `inc/Controllers/Group/GroupCockpitController.php` (L80, `$isAdmin`) — то же.

**Проверка:** FSOffice заходит в Предметы/Настройки/Пользователи/Логи; маркетолог видит Статистику; администратор не потерял ничего.

---

## Этап 3 — Раскол `manage_lms_assignments` в гейтах (авторинг / проведение / статьи)

Перевешиваем все `authorize(..., ManageLMSAssignments)` и cap'ы меню. Роли уже несут новые права (этап 1), поэтому переключение безопасно.

**Задача 3.1. Авторинг → `AuthorLmsCourses`.**
Заменить `Capability::ManageLMSAssignments` на `Capability::AuthorLmsCourses` в:
- `inc/Callbacks/Course/CourseBuilderCallbacks.php` (37,54,71,87,106,125,142)
- `inc/Callbacks/Course/CourseCallbacks.php` (37)
- `inc/Callbacks/Course/LessonCallbacks.php` (41,62,89,107,121,136,163) — **кроме** `ajaxCreateArticleDraft` (см. 3.3)
- `inc/Callbacks/Course/WorkCallbacks.php` (40,57,79,100,118,148)
- `inc/Callbacks/Course/CloneCallbacks.php` (89)
- `inc/Callbacks/Assessment/AssessmentAuthorCallbacks.php` (43,70,90,220)
- `inc/Callbacks/Assessment/ScoreMapCallbacks.php` (47,65)
- `inc/Callbacks/Task/TaskContentCallbacks.php` (40,77)
- `inc/Callbacks/Task/TaskCreationCallbacks.php` (60,96,109)
- `inc/Controllers/Problems/ProblemsController.php` (179)
- `inc/Controllers/Subject/ContentDeletionGuard.php` (253)
- `inc/Controllers/Course/CourseBuilderController.php` (42, cap страницы билдера)
- `inc/Controllers/Course/LearningMenuController.php` (227, `$cap` для банков Курсы/Уроки/Работы/Контрольные/Банк задач/Задания) — **кроме** банка Статьи (3.3)

**Задача 3.2. Проведение → `ManageLmsTeaching`.**
- `inc/Callbacks/Course/GradingCallbacks.php` (37,65,87,109)
- `inc/Callbacks/Course/BatchSubmissionCallbacks.php` (88)
- `inc/Callbacks/Assessment/GradeAttemptCallbacks.php` (35)
- `inc/Callbacks/Task/TaskAttemptCallbacks.php` (32)
- `inc/Callbacks/Course/ProgramCallbacks.php` (45,60,75,89,99,113,126,136,150,167,212,239) — программа/расписание/видимость группы.
  **Решить:** «назначить курс группе» (`AssignCourse`, L45) — это проведение (преподаватель) или операции (FSOffice)? По умолчанию — `ManageLmsTeaching` (FSOffice его тоже имеет). Если хотим, чтобы курсы группе цеплял только офис — вынести `AssignCourse` в `ManageLmsPlatform`.

**Задача 3.3. Статьи → `ManageLmsArticles`.**
- `inc/Callbacks/Course/LessonCallbacks.php` — `ajaxCreateArticleDraft` (~L149): `ManageLMSAssignments` → `ManageLmsArticles`.
- `inc/Controllers/Course/LearningMenuController.php` (L267) — банк **Статьи**: отдельный cap `ManageLmsArticles` вместо общего `$cap`.
- Аудит read-эндпоинтов статей (`ajaxGetLessonArticles` L89, `getStepCandidates` для kind=article) — оставить на `AuthorLmsCourses` (методисту нужно *ссылаться* на статьи в уроках, не создавая их).

**Задача 3.4. Прочие точки.**
- `inc/Modules/SocialAuth/Controllers/SocialAuthController.php` (143) — `ManageLMSAssignments` здесь выглядит чужеродно (контекст настроек модуля); проверить и, скорее всего, перевести на `ManageLmsPlatform`.

**Проверка:** методист (только `author_lms_courses`) авторит, но не оценивает и не видит статистику; преподаватель (только `manage_lms_teaching`) оценивает, но не видит конструктор; администратор/офис — всё.

---

## Этап 4 — Раздельный `capability_type` для статей (CPT-уровень)

Сейчас все контент-CPT (`{key}_tasks/articles/lessons/works/assessments/courses`, `problems`) используют общий `capability_type = fs_lms_content` (SubjectController:358-376, ProblemsController:100). На уровне WP это делает «редактировать статью» неотличимым от «редактировать курс». Чтобы методист реально не мог редактировать статьи в wp-admin, статьям нужен свой тип.

**Задача 4.1. Отдельный capability_type статей.**
- `inc/Controllers/Subject/SubjectController.php` (~L375-376, аргументы CPT статей; либо фильтр `fs_lms_cpt_args`) — для `{key}_articles` задать `capability_type => 'fs_lms_article'`, `map_meta_cap => true`.

**Задача 4.2. Гранты новых мета-каповов.**
- `inc/Managers/Person/RoleManager.php` — добавить метод `articleCaps()` (аналог `lessonCaps()`, 14 каповов на `fs_lms_article`) и выдать их **FSMarket**, **FSOffice**, **administrator** — но **не** методисту.

**Задача 4.3. UX-блок статей у методиста.** НЕ СДЕЛАНО
- В конструкторе/банках — кнопку «создать статью» для методиста скрыть или показывать с предупреждением (как просил заказчик). Серверный cap-чек уже стоит (3.3) — UX это не замена, а дополнение.

**Проверка:** методист не открывает редактор статьи (403/нет пункта); маркетолог создаёт/правит/публикует статьи; курсы/уроки методисту по-прежнему доступны.

---

## Этап 5 — Ужесточение FSTeacher (только фронт)

**Задача 5.1.** `inc/Enums/Access/UserRole.php` (`capabilities()`) — у `FSTeacher` убрать `manage_lms_assignments`, оставить только `manage_lms_teaching`.
**Задача 5.2.** `inc/Managers/Person/RoleManager.php` — снять у `lms_teacher` 14 `fs_lms_content`-каповов (L89-94), т.к. преподаватель ничего не авторит. Предусмотреть миграцию `remove_cap` для уже существующих установок (через версию ролей из 1.4).

**Проверка:** преподаватель не видит меню «Обучение», не открывает CPT-редакторы; кокпит своей группы и оценивание работают.

---

## Этап 6 — Вкладка «Роли» в Настройках

**Задача 6.1. Право и доступ.**
`ManageLmsRoles` (этап 1) — выдать **только** administrator (в `RoleManager`, не в одной из плагинных ролей).

**Задача 6.2. UI вкладки.**
- Новый партиал `templates/admin/components/tabs/settings-tabs/settings-8-roles.php` (список персонала + чекбоксы ролей; строка администратора — отмечена и заблокирована).
- Зарегистрировать вкладку в рендере страницы настроек: `inc/Callbacks/System/AdminCallbacks.php` (`settingsPage()`, L112) + навигация вкладок (там же, где подключаются `settings-1..7`).

**Задача 6.3. Сохранение ролей (AJAX).**
- Новый класс `inc/Callbacks/Settings/RolesSettingsCallbacks.php` (по образцу прочих `Settings/*Callbacks`): хэндлер назначения ролей через существующие `UserManager::addRole()` / `removeRole()` (`inc/Managers/Person/UserManager.php` L141-154).
- Новый `Nonce` кейс (`inc/Enums/Wp/Nonce.php`) и `AjaxHook` (`inc/Enums/Wp/AjaxHook.php`); зарегистрировать в подходящем контроллере (`inc/Controllers/System/…`).
- Авторизация: `$this->authorize(Nonce::SaveRoles, Capability::ManageLmsRoles)`.

**Задача 6.4. Защита.**
- Запрет самоэскалации (нельзя выдать право, которого нет у действующего), запрет снятия последнего administrator и самоблокировки.
- Аудит изменения ролей в лог (через существующие log-writer'ы).
- JS — только разметка/чекбоксы в `src/js/admin/...` (по конвенциям проекта), сборка gulp.

**Проверка:** только администратор видит вкладку; назначение двух ролей одному пользователю даёт объединённые права; нельзя разлогинить/разжаловать себя.

---

## Этап 7 — Косметика мультироли

При легальной мультироли «первая роль» начинает врать в отображении и логах.

**Задача 7.1.** Ввести понятие «основная роль» (например, user-meta `fs_lms_primary_role` или приоритет по enum) и использовать его в `inc/DTO/Person/UserDTO.php` (`fromWPUser()`, L83-93 — сейчас берёт первую совпавшую).
**Задача 7.2.** Поправить log-writer'ы, берущие `roles[0]` / `reset($user->roles)`:
- `inc/Services/Log/LearningEventWriter.php` (43), `EntityAuditLogWriter.php` (107), `ExportLogWriter.php` (98), `EmailLogWriter.php` (98), `DataChangeLogWriter.php` (103), `PiiAccessLogWriter.php` (97), `EnrollmentAuditLogWriter.php` (127), `ConsentChangeLogWriter.php` (100).

**Проверка:** совместитель (методист+маркетолог) отображается корректно; в логах — осмысленная роль актора.

---

## Этап 8 — Ретайр `manage_lms_assignments` и финальная чистка

**Задача 8.1.** Убедиться, что не осталось ссылок на `Capability::ManageLMSAssignments` (grep). Снять его со всех ролей; удалить кейс из `Capability.php` или пометить deprecated.
**Задача 8.2.** Обновить doc-таблицу в `RoleManager.php` и при необходимости `.docs/basic_doc.md` (матрица ролей).
**Задача 8.3.** Финальный прогон тестов + ручная QA по матрице.

---

## Тесты

- На каждом этапе обновлять/добавлять PHPUnit для затронутых `*Callbacks` (память проекта: «покрывать коллбеки тестами»). Моки уже умеют подменять `current_user_can` через `$GLOBALS['_fs_test_can']` — добавить проверки, что требуется именно нужный cap, а не «любой залогиненный».
- Отдельный тест на `RoleManager`: матрица «роль → ожидаемые caps».
- Тест на защиту вкладки «Роли»: самоэскалация/самоблокировка отклоняются.

## Матрица приёмки (ручная QA)

Прогнать по каждой роли: что **видит** (меню), что **может** (создать/опубликовать/оценить), что **запрещено** (403/нет пункта). Эталон — §4 и §5 в `Roles.md`.

| Роль | Должен мочь | Должно быть запрещено |
|---|---|---|
| FSOffice | Предметы, Настройки, Пользователи, Логи, Статистика, заявки/зачисление/ПДн, (как override) авторинг/статьи/проведение | site-admin вне плагина; вкладка «Роли» |
| FSMethodist | «Обучение»: курсы/уроки/работы/контрольные/задачи + публикация | статьи (создание/правка), Предметы/Настройки/Статистика, фронт-кокпит |
| FSMarket | статьи (CRUD+публикация), Статистика | авторинг курсов, структура курса, Предметы/Настройки/ПДн |
| FSTeacher | свои группы на фронте: журнал/посещаемость/оценивание/расписание, статистика по своим группам | админ-конструктор, создание любого контента, чужие группы, глобальная статистика |
| Administrator | всё, включая вкладку «Роли» | — |
