# План рефакторинга по итогам аудита (2026-07-31)

Аудит: архитектура PHP, SOLID/дизайн, JS-конвенции, SCSS/шаблоны, мёртвый код.
Приоритеты: **P1** — высокая выгода / источник багов, **P2** — системный долг, **P3** — точечные правки, **P4** — решения/политика (обсудить, зафиксировать в CLAUDE.md).

---

## P1. Первая волна — высокая выгода, ограниченный риск

### 1.1 Log-инфраструктура: убрать ~400 строк копипасты ✅
- [x] Ввести `AbstractLogRepository` (общие `list()`, `countFiltered()`, `listAll()`, скелет `buildConditions()`; абстрактные `hydrate()`, `filterMap()`), отнаследовать 8 репозиториев `inc/Repositories/WPDBRepositories/Log/` — фильтры описываются парой `[колонка, LogFilterType]`, даты общие; 1687 → 1289 строк вместе с базовым классом
- [x] Вынести дублированный `resolveRole()` из 7 Log-writer'ов (`inc/Services/Log/*Writer.php`) в `ActorRoleResolver` (или трейт) — 8 райтеров, `UserManager` из них ушёл
- [x] `AdminCallbacks::logsPage()` (`inc/Callbacks/System/AdminCallbacks.php:301`, 185 строк, 8 elseif по `LogChannel`) → реестр `LogPageProvider` по каналу; минус 8 из 19 зависимостей конструктора — `inc/Services/Log/Pages/` (8 провайдеров + `LogPageRegistry` + `LogOptionsResolver`), метод 185 → 38 строк, 19 → 10 зависимостей; попутно удалена недостижимая else-ветка (шаблон рендерит только известные вкладки)

### 1.2 Разбить главные god-классы ✅
- [x] `inc/Callbacks/Course/ProgramCallbacks.php` (708 строк, 31 ajax-метод, 10 зависимостей) → 4–5 классов: программа / расписание-reflow / индивидуальные занятия / ростер / дедлайны работ — `ProgramCallbacks` (состав+публикация+шаги), `LessonScheduleCallbacks`, `IndividualLessonCallbacks`, `GroupRosterCallbacks`, `LessonDeliveryCallbacks`; общие гарды — трейт `Shared/Traits/ProgramAccess`
- [x] `inc/Services/Group/ScheduleService.php` (744 строки, 23 метода, 11 зависимостей) → `ProgramCompositionService` + `ScheduleReflowService` + `IndividualLessonService` + `GroupCalendarService` (синхронно с разбиением ProgramCallbacks) — плюс `ScheduleEventPublisher` (события КТП в одном месте); старый класс удалён, тесты разложены на 4 файла
- [x] `inc/Controllers/Subject/SubjectController.php` — `getDefaultCptArgs()` → `Controllers/Builders/SubjectCptArgsBuilder`, `registerForSubject()`/`registerCptsAndTaxonomies()` → `Registrars/SubjectContentRegistrar`; 529 → 280 строк, минус 2 зависимости, хуки `add_meta_boxes`/`wp_insert_post_data` подняты в контроллер (раньше вешались в цикле по предметам)
  - ⚠️ **не сделано:** «диспетчеризация ajax через реестр вместо инъекции всех коллбеков» — требует ленивого резолва через контейнер (правка `AjaxController` + `Container`, эффект на все ~20 контроллеров). Вынести в отдельный пункт и обсудить: без ленивости реестр не даёт выигрыша, а с ней появляется service-locator

### 1.3 Слои, которые «врут» о себе ✅
- [x] `inc/DTO/Task/PostsListTableDTO.php` — не DTO: держит `WP_Posts_List_Table`, рендерит HTML через `ob_start()`, мутирует `$_SERVER` → перенести в презентационный слой (`Controllers/Builders/` или `View/`) как `PostsListTablePresenter` — заодно `PostManager::buildListTable()` переехал в него статической фабрикой `::for()` (см. «PostManager» в разделе «не трогать»), добавлен `searchBox()`
- [x] `inc/DTO/Subject/ImportedEntitiesDTO.php` — мутабельный аккумулятор с 8 мутаторами → переименовать в `ImportedEntitiesCollector`, переместить в `Services/Subject/` (положен рядом с потребителем — `Services/Subject/Import/`)

### 1.4 Токены SCSS: одинаковые имена — разные значения (источник багов) ✅
- [x] Устранить конфликт имён между `shared/_tokens.scss` и `frontend/_variables.scss` — **сведено к одной шкале** (кегли/отступы/радиусы/веса: имя ступени = одно значение во всех бандлах), `frontend/_variables` форвардит общие ступени вместо переопределения. Одноимённые, но разные по смыслу разведены: `$font-code` vs `$font-mono`, `$shadow-surface` vs `$shadow-card`, `$line-height-relaxed` (1.6) vs `$line-height-base` (1.5). Попутно найден и закрыт незамеченный аудитом конфликт **`$font-bold`: 600 в ядре vs 700 во frontend** (теперь `$font-semibold` 600 / `$font-bold` 700)
- [x] Согласовать две лестницы радиусов — единая `sm 4 / md 6 / lg 8 / xl 12 / 2xl 16 / pill 999px`; ступень 3px удалена (схлопнута в 4px — единственное визуальное изменение, только admin)
- [x] Убрать точные дубли hex под разными именами — введён слой сырых оттенков `$hue-violet` / `$hue-violet-dk` / `$hue-red` / `$hue-red-soft` / `$hue-amber-dk`; на них ссылаются палитра типов шагов, чип-палитра, cabinet-тема, WP-фиолетовый и подсветка кода; `--wait-ink` → `var(--wait)`; `$color-danger` во frontend — форвард из ядра
- [x] Почистить почти-дубли — схлопнуты: `$cb-chip-bg`/`$cb-badge-bg` → `$wp-admin-gray-2`, `$table-header-bg` → `$wp-admin-gray-bg-light`, `--good-bg`/`--good-bg-soft` → `--ok-soft`/`--ok-soft-2`. **Оставлены намеренно** (иерархия поверхностей, а не дрейф): `--line`/`--line-2` (внешняя граница vs внутренняя сетка журнала), `--accent-soft`/`--accent-soft-3` (заливка vs подсветка липкой ячейки), `--surface-2`, `$cb-bg-tree`
- Проверка: CSS всех 7 бандлов сверялся с baseline после каждого шага; чисто-переименовательные шаги дали **побайтово идентичный** результат, визуальные схлопывания — ровно ожидаемый набор изменившихся литералов (`#f0f1f3`/`#eceef0`/`#f8f9fa`/`#e7f5ec`/`#f3faf5` ушли, новых цветов не появилось)

### 1.5 Мёртвая фича «Все задания» (JS) ✅
- [x] Решение (2026-07-31): **удалить все 5 файлов, страницу оставить** — `frontend/services/all-tasks-page.js`, `all-tasks-api.js`, `components/filter-section.js`, `task-card.js`, `modules/card-tabs.js` удалены (каталог `frontend/modules/` исчез вместе с ними)
- [x] Страница остаётся серверным рендером (`AllTasksPageController` + `templates/frontend/all-tasks.php`): первая порция заданий и фильтры печатаются из PHP
- ⚠️ Разметка страницы сохранила `js-*`-хуки (`js-filter-option`, `js-search-input`, `js-task-cards`, `js-filters-clear`) и `data-has-more` — интерактива за ними больше нет: фильтры/поиск/догрузка не работают до тех пор, пока фичу не оживят. Хук `AjaxHook::FetchAllTasks` и `AllTasksCallbacks::ajaxFetchAllTasks()` живы, но клиентов у них нет — кандидат в раздел «Эндпоинты, недостижимые с фронта»

---

## P2. Вторая волна — системный долг

### 2.1 Бизнес-логика в Controllers → Services + templates ✅
- [x] `inc/Controllers/Course/LearningMenuController.php` (581 строка): usage-индексы → `Services/Course/BankUsageIndex`; фильтры списков → `Controllers/Builders/BankListFilters` + шаблон `admin/learning/bank-filters`; 581 → 394 строки, минус зависимость `PostManager`. Рендер банков уже шёл через шаблон (`admin/learning/bank-landing`) — аудит здесь опирался на устаревшее состояние
- [x] `inc/Controllers/Problems/ProblemsController.php` (536 строк): индексы → `BankUsageIndex::worksByProblem()/coursesByProblem()` (кросс-предметные), сортировка+фильтр → `Controllers/Builders/ProblemListFilters`, разметка (включая optgroup) → шаблон `admin/problems/problem-filters`, регистрация CPT/таксономии → `Registrars/ProblemBankRegistrar`; 536 → 302 строки, конструктор 6 → 4 зависимости
- [x] `inc/Controllers/Pages/AssessmentPageController.php`: сбор состояния попытки → `Services/Assessment/AttemptPageService` (+ DTO `AttemptPageDTO`), per-task данные → `Services/Assessment/AttemptTaskViewBuilder`; в контроллере остались только HTTP-побочки (редирект гостя, 404, заголовки, выбор рендерера/интро); 347 → 228 строк, 10 → 2 зависимости
- [x] `inc/Controllers/Subject/ContentDeletionGuard.php` — ссылочность и transient-журнал → `Services/Course/ContentDeletionPolicy` (`isReferenced()`/`blocksDeletion()`/`takeBlockReason()`); ключ `fs_lms_delete_blocked_` больше не дублируется в двух слоях (частично закрывает §2.5)
- [x] Убрать ручной `echo`/`printf` HTML из контроллеров → партиалы `admin/metaboxes/{fields-wrapper,builder-shell,template-select,course-builder-mount}` и `admin/components/admin-notice`; инлайновый `<style>` в `CourseMetaBoxController` заменён классом body (`admin_body_class` → `.fs-lms-course-screen`) и правилами в `_course-builder.scss`
  - **Оставлено осознанно:** `sprintf()` со ссылками в `ContentDeletionGuard::rowActions()` — это элементы массива `post_row_actions` (контракт WP ждёт строку-ссылку, а не вывод), и `echo esc_html()` одного значения в `ProblemsController::renderColumn()` (хук колонки таблицы)
- **Находка:** `Inc\Services\Assessment\ExamPayloadFilter` инжектился в `AssessmentPageController`, но не вызывался ни там, ни где-либо ещё — инъекция убрана, сам сервис остался мёртвым (кандидат в раздел «Мёртвый PHP-код»)

### 2.2 Длинные методы (200+ строк) + array→DTO ✅
- [x] `EnrollmentService::enroll()` (258 строк) → `EnrollmentPersonResolver` (поиск существующих физлиц) + `EnrollmentTransaction` (атомарная часть) + провизия учёток через **уже существовавший** `AccountProvisioningService`: метод 258 → ~95 строк, ~90 строк дублирования с импортом устранены (его докблок прямо предлагал этот переезд как follow-up). Все 50 тестов зачисления, включая интеграционные (откат транзакции, partial failure, письма), зелёные без правки ожиданий
- [x] `LearnerService::build()` (214 строк, возвращал array) → `LearnerContextDTO` (единожды прочитанные группы/занятия/строки программы) + секции отдельными методами (`upcoming`/`deadlines`/`grades`/`attendance`/`examLock`) + `LearnerDashboardDTO` со схемой ответа в `toArray()`; `build()` — 25 строк, попутно убрано двойное вычисление дневника
- [x] `Enqueue::enqueue_admin_assets()` / `enqueue_frontend_assets()` (220/203) → `Core/Assets/AdminScreenContext` (признаки экрана) + реестры локализаций `adminLocalizations()` / `frontendLocalizations()` (`имя переменной → данные|null`) + `enqueueAdminBase()`/`enqueueFrontendBase()`; каждая window-переменная собирается своим методом. Методы 220 → 33 и 203 → 37 строк
  - Проверено в рантайме (Enqueue юнит-тестами не покрыт): на экранах уроков/работ/заявок/предмета/чужом CPT набор window-переменных совпадает с прежним, `/lms/apply` отдаёт `fs_lms_apply_vars` со всеми полями

### 2.3 Дублирование PHP ✅
- [x] `AbstractRowImporter` для `StudentRowImporter` / `EnrolledStudentRowImporter` — общие `requireValues()`/`toDate()`/`toDateTime()` в базовом readonly-классе
- [x] `ProfileViewResolver::teacherConfig()` (127 строк) → `TeacherProfileView::build()` (витрина сама строит и меню, и блоки своих экранов); резолвер 334 → 165 строк, минус зависимость `CourseManager`. В bootstrap тестов добавлена заглушка `wp_create_nonce()`

### 2.4 Расширить трейт `Sanitizer` и закрыть сырые санитайзеры ✅
- [x] Добавлены `sanitizeIntList()`, `sanitizeKeyList()`, `unslashArray()`
- [x] Сырые вызовы заменены в `AllTasksCallbacks`, `WorkCallbacks`, `AssessmentAuthorCallbacks`, `LogsCallbacks` (×4), `ProgramCallbacks`, `LessonCallbacks`, `CourseBuilderCallbacks`, `TaskContentCallbacks`, `StudentGroupCallbacks`. Остались осознанно: `json_decode( wp_unslash( … ) )` над строковым JSON-полем (не массив запроса)

### 2.5 Транзиенты: ввести абстракцию ✅
- [x] `Managers/Wp/TransientManager` (get/set/delete/take) + `Enums/Wp/TransientKey` (Export, DeleteBlocked, RecentTasks, RecentArticles). Переведены `PiiController`, `SubjectBundleCommand` (CLI), `OneTimeDownloadService`, `SubjectDataCallbacks`, `ContentCacheService`, `ContentDeletionPolicy`
- **Найдено сверх аудита:** ключ `fs_lms_export_` дублировался в ТРЁХ местах (писал `OneTimeDownloadService`, читали контроллер и CLI) — теперь один источник
- Оставлены со своими инкапсулированными ключами: `RateLimitService`, `EmailOtpService`, `TaskPublishGuard`, `VideoLibrary/RecordingAlertService` — у каждого ключ уже локализован в одном классе

### 2.6 Modules: параллельная архитектура → общий стандарт ✅
- [x] 5 модульных `*Config` → общий базовый `Modules/Shared/ModuleConfig` (get/save/isEnabled/valueOrConstant/optionExists). Ключи опций осознанно ОСТАЛИСЬ в модулях, а не в core-`OptionName`: ядро не должно знать о модулях, удаление каталога модуля не ломает ядро
- [x] `AdSyncController` → трейт `AjaxResponse`
- [x] `SocialAuth/templates/settings-tab.php` — данные приходят из репозитория модуля через новый ключ `'data'` в контракте вкладок настроек (расширение контракта ядра, не знание о модуле)
- **Решено иначе, чем в аудите:** `SmartCaptchaModule` продолжает зависеть от `YandexSmartCaptchaProvider` — модуль ВЛАДЕЕТ реализацией и публикует её ядру фильтром `fs_lms_captcha_provider`; ядро видит только `CaptchaProviderInterface` (через `CaptchaProviderFactory`). Биндинг интерфейса в core-контейнере заставил бы ядро знать класс модуля
- ⏳ **Осталось (решение пользователя):** 5 `wp_localize_script` вне `Enqueue.php` — переносить их в ядро нельзя по той же причине; предлагается легализовать модульный паттерн в CLAUDE.md

### 2.7 DIP: точечные нарушения ✅
- [x] `ImportCallbacks` → `Services/Import/RowImporterRegistry` (`for( ImportMode )`), коллбэк зависит от контракта
- [x] `ContentUsageService::kindOf()` → `Services/Subject/ContentKindResolver::of()` (статический резолвер по образцу `PostTypeResolver`); старый метод оставлен делегатом для внешних вызовов
- [x] `'submission'|'attempt'` → enum `Enums/Course/WorkSourceType` во всех четырёх `match`
- **Решено иначе, чем в аудите:** `ProfileViewResolver` продолжает принимать конкретные `TeacherProfileView`/`LearnerProfileView` — резолвер является точкой СБОРКИ и обязан различать реализации (два аргумента одного интерфейса автовайринг не разрешит); контракт соблюдён на выходе — `viewFor()` возвращает `ProfileViewInterface`

### 2.8 JS: общий слой утилит ✅
- [x] Создан `src/js/common/utils.js` (escapeHtml, fmtDate/fmtDayMonth/fmtDateTime, todayIso, initials, debounce). `profile/utils.js`, `admin/modules/utils.js`, `player/icons.js` реэкспортируют канонические версии под привычными именами (`esc` ≡ `escapeHtml`); локальные `fmtDate` (groups/summary/indi-modal) и `debounce` (dadata-suggest, select-parent-modal) заменены импортами
- [x] AJAX вынесен из модалок: `admin/managers/enrollment-api.js` (поиск/назначение/снятие родителя) и `admin/managers/draft-api.js`; модалки стали чистым UI
- [x] Guard `_initialized` в `alert-modal`, `confirm-modal`, `bundle-export-modal`, `pii-export-modal`
- [x] Удалены мёртвые экспорты: `toggleButtonExtended`, `apiErrorEnhanced`, `fsEmpty`, `bindInnMask`, `icoFile`, `icoArrowRight` (`icoFlag` ЖИВ — используется плеером через `ICO.flag`)
- Тосты (5 реализаций) оставлены: у каждого своя разметка и свой бандл (`profToast`/`kegeToast`/`fsToast`); сведение требует общего DOM-контракта — отдельная задача

### 2.9 SCSS: недостающие слои токенов 🟡
- [x] `assessment/_attempt.scss` и все четыре партиала `kege/` переведены на общую rem-шкалу (`rem()` из `shared/tokens`) — 1/1.5/2/3px-хайрлайны и 999px оставлены как есть. **Эквивалентность доказана**: после нормализации rem→px CSS обоих бандлов совпадает с baseline
- [x] `$font-size-md!important` без пробела — исправлено
- ⏳ **Не сделано намеренно:** сведение 27+15 `!important` в один родительский сброс. Это тот же случай, что и Фаза 6 в `refactor.md`: `!important` держат перебивку WP-core/WPBakery, а изменение специфичности механически недоказуемо — нужна ручная визуальная проверка каждой модалки

---

## P3. Точечные правки (дёшево, можно между делом)

### PHP ✅
- [x] `TaskContentCallbacks` → `PostManager` (insert/update/updateMeta)
- [x] `ConsentSettingsCallbacks` → `PostManager::insert()` (`PageGeneratorService` умеет только страницы-маршруты плагина)
- [x] `SubjectValidationCallbacks` → новый кейс `Capability::ManageTerms`
- [x] `SubjectPageCallbacks` — `echo` → партиал `admin/components/admin-notice`
- [x] `WpOptionsEmailTemplate` → `EmailTemplatesRepository`
- [x] `ProfileController` → `ExpulsionPolicyRepository::getRetentionPolicy()`
- [x] `Init` → `OptionName::CapsVersion`
- [x] Сырые meta-ключи: `MetaKeys::PersonID` (`EnrollmentCallbacks`, `LogNameResolver` — там ещё и в SQL через плейсхолдер), `PostMetaName::ForkedFrom/ForkedForGroup` (`PostCollector`), legacy-ключ `TemplateResolver` → именованная константа. `ProblemDeduplicator` уже держал ключи в константах — оставлен
- [x] `NumericSorter` — трейт отдаёт фильтр (`numericSortFilter()`), `add_filter` вешает `SubjectController`
- [x] templates: `subject-1-stats.php` — два `WP_Query` НА КАЖДЫЙ терм заменены одним SQL `PostManager::countPublishedByTerms()` (счётчики приходят в DTO); `get_option()` убран из `application-enrollment-modal`, `settings-4-email-templates`, `settings-5-consents`
- [x] `ApplicationRepository` — правила переходов и trash-удаления → `ApplicationService::changeStatus()` / `deleteFromTrash()`; все 6 вызывающих переключены

### JS/SCSS/шаблоны ✅
- [x] `apply-fields.php`, `join.php` — `style="display:none"` → атрибут `hidden`; JS переключает `.hidden`, а не `style.display`
- [x] Инлайновые SVG в `step-assessment.php` (4) и `attempt-shell-header.php` (2) → `Icon::Clock/Flag/Check/ChevronRight/Lock`; бренд-логотипы провайдеров → партиал `modals/partials/provider-logo.php` (они фиксированных цветов, поэтому не в `Icon`, где `currentColor`)
- [x] Хардкод-цвета: `kege` — новые токены станции `--kege-toast-bg/--kege-toast-line/--kege-timer-line`; `admin/_mixins.scss` — `#fff` → `$color-text-inverse`. Скомпилированный CSS не изменился
- [x] Шрифт Ubuntu: CSS `@import` → `wp_enqueue_style` + `preconnect` (фильтр `wp_resource_hints`). Проверено на живой странице
- [x] Инлайн-стили в JS: `fadeDeleteRow` → класс `.is-deleting`, кнопки родителя → `.fs-parent-action`, нотис → `.fs-notice`; классы добавлены в `_utilities.scss`
- [x] `.css('opacity')` → класс `.is-loading` (`recent-posts.js`, `posts-table.js`)
- [x] Дубль HTML письма → `templates/emails/bodies/{otp_code,welcome_with_credentials}.php` как единственный источник: письмо подставляет значения, вкладка настроек показывает ту же разметку. Проверено рендером обоих писем
- ⏳ **Не решено (вопрос к владельцу):** конфликт двух базовых шрифтов (Ubuntu на публичных страницах vs Golos Text в кабинете/плеере) — это дизайн-решение

---

## P4. Решения / политика (обсудить и зафиксировать в CLAUDE.md)

- [ ] **Scoped-фильтры**: временные `add_filter/remove_filter` в `SubjectDeletionService`, `MediaSideloader`, `MediaManager`, `TaskPublishGuard` — легализовать как исключение или вынести в `Shared/Traits/ScopedFilter`
- [ ] **`LogNameResolver` как статика** — добавить в белый список статических утилит (рефакторинг не окупится)
- [ ] **`style.display` в JS** (~14 мест) — либо явно разрешить, либо ввести утилиту `toggleVisible()`
- [ ] **Правило `_types.js`** — 15 админ-файлов используют глобалы без импорта типов: автоматизировать (ESLint) или признать декоративным
- [ ] **`add_action` в Managers** (`CPTManager:45`, `TaxonomyManager:40`, `MenuManager:88`, `MetaBoxManager:73`, `MediaManager:67`) — сложившийся паттерн «менеджер сам вешает свой хук»: легализовать или переносить в контроллеры
- [ ] **Инлайн-стили в email-шаблонах** — исключить `templates/emails/` из запрета (требование почтовых клиентов)
- [ ] **`require.context` в `ui.js:59`** — нерекурсивный: модалки в `modals/enrollment/**` не автозагружаются (сейчас все инициализируются вручную — работает, но контракт из CLAUDE.md верен только для верхнего уровня); фолбэк `ui.js:82` берёт первый экспорт файла — хрупко

---

## Мёртвый PHP-код

### Точно мёртвое — удалить 🟡
- [ ] `inc/Services/Assessment/ExamPayloadFilter.php` — найдено при §2.1: класс нигде не вызывается (единственная инъекция была в `AssessmentPageController`, где он не использовался; остальные упоминания — комментарии в `WorkDetailService`, `FileAnswerTaskTemplate`, `ThreeInOneTemplate`). Проверить, не полагается ли на него модульный скин, и удалить
- [x] `AjaxHook::WithdrawConsent` + `Nonce::WithdrawConsent` — удалены
- [x] `AjaxHook::SaveTemplateAssignment` + запись в JSDoc `_types.js` — удалены
- [x] `CronController` — устаревший комментарий заменён ссылкой на реальное место регистрации (`RecoveryController` + `Activate`)

### Эндпоинты, недостижимые с фронта — РЕШЕНО: доделываем UI (2026-07-31)

**Сделано:**
- [x] **Клонирование контента** (`CloneLesson`/`CloneWork`/`CloneAssessment`/`CloneCourse`) — действие «Дублировать» в строке таблицы банка (`LearningMenuController::addCloneRowAction`) + сервис `admin/services/content-clone.js`; для курса спрашивается режим (`deep` — с копиями уроков, `shallow` — со ссылками). Проверено: копия создаётся черновиком, открывается на редактирование
- [x] **`AssignGroupRoom`** — поле «Основной кабинет» в модалке группы; после сохранения группы вызывается назначение, предупреждения сервиса (кабинет занят / чужой предмет) показываются перед перезагрузкой

**Решения владельца (2026-07-31):**

- [x] **`GetRooms`** — сделано: вкладка «Настройки → Кабинеты» после добавления/правки/удаления перечитывает список и перерисовывает таблицу без перезагрузки (`RoomModalManager.refresh()`)
- [x] **Представители — фича отменена**: модель — один ученик + один родитель, замена родителя = новое зачисление с новым договором. Удалены `RepresentativeCallbacks`, хуки `AddRepresentative`/`ReplaceRepresentative`, одноимённые нонсы, тип письма `NewRepresentative` и регистрация в `PiiController`. Живой путь смены родителя (`SelectExistingParent` / `RemoveParentAssignment`, UI в модалке заявки) не затронут
- **`SetLessonExtraWorks`** — UI не нужен (решение владельца)
- **`GradeBatchTask`, `ExportExpelledRecord`, `EmptyApplicationsTrash`, `LookupConsentByHash`,
  `ForkLessonForGroup`, `RevealPiiField`, `RequestPiiDeletion`** — оставлены в коде как есть
  (решение владельца 2026-07-31): UI не делаем, но и не удаляем
- **`GetWorkTaskCandidates`, `GetLessonArticles`, `CreateArticleDraft`** — UI не нужен (решение владельца)

### Замены v2 — СДЕЛАНО (2026-07-31)

Экран `/profile/` → «Замены» (роль «Офис») переписан под одно решение: **кого** заменяем,
**кто** заменяет, **в каком кабинете** и **на какой период** — одной формой.

- [x] **«Кого заменяем» явно** — подпись «Заменяем: {преподаватель группы}» над формой; если у группы
  преподавателя нет, форма блокируется с объяснением. Штатный преподаватель приходит с сервера
  (`SubstitutionCallbacks::groupTeacher()`), он же исключается из списка замещающих
- [x] **Кабинет в той же форме** — поле «Кабинет на период» рядом с замещающим; один период «С … По»
  на оба действия, после submit — `AssignSubstitute`, затем `SetRoomOverride` с тем же периодом.
  Ошибка по кабинету не откатывает уже сохранённую замену, а сообщается отдельно.
  Кнопка «Вернуть кабинет группы» осталась отдельным действием
- [x] **`GetGroupSubstitutions` задействован** — при переключении группы тянутся только её замены и её
  преподаватель; списки преподавателей и кабинетов (от группы не зависят) не перезапрашиваются
- [x] **Проверки** — на сервере (`SubstitutionService::assign`): замещающий ≠ преподаватель группы,
  период не в прошлом (`ClockInterface`), пересечение с уже назначенной заменой
  (`SubstitutionRepository::findOverlapping`, сообщение с датами конфликта). Клиент повторяет их же
  и показывает текст в форме (`.subs-error`), а не тостом
- [x] Список замен показывает статус (Активна / Запланирована / Завершена) и «кого → кем»

**Затронуто:** `src/js/profile/substitutions.js`, `_substitutions.scss`, `SubstitutionCallbacks`
(+`GroupsRepository`), `SubstitutionService` (+`ClockInterface`), `SubstitutionRepository`,
`TeacherProfileView` (action `getGroupSubs`). Тесты: +6 (сервис + коллбэки)

### Пояснения по остальным эндпоинтам (что это такое)

- [x] **`CopyScoreMap` + `ParseScoreMap` — СДЕЛАНО (2026-07-31)**: в поле «Таблица перевода баллов»
  (`ScoreMapField`) добавлена кнопка «Скопировать из другого экзамена» — список ЕГЭ-работ того же
  предмета с непустой шкалой (новый `GetScoreMapSources`, показывает число пар и диапазон
  «0–34 → 0–100»), выбор + «Скопировать» пишет шкалу в мету и в поле. `ParseScoreMap` больше не мёртв:
  он даёт живую подсказку «Распознано пар: N» при вставке из Excel. Поведение — `admin/services/score-map.js`;
  `sanitize_textarea_field()` в коллбэке заменён на новый `Sanitizer::sanitizeMultilineText()`. Тесты: +6
- **`GradeBatchTask`** (нонс `GradeBatch`) — пооценочная простановка балла и комментария сразу пачке сдач
  одного задания («проверка в потоке»); UI нет
- **`ExportExpelledRecord`** — выгрузка CSV по одной архивной записи об отчислении (массовый экспорт архива есть)
- **`EmptyApplicationsTrash`** — «Очистить корзину» заявок (одиночное удаление из корзины работает)
- [x] **`DeleteAcademicPeriod`, `DeleteStudentGroup` — УДАЛЕНЫ (2026-07-31)**: легаси-дубли живых
  `DeletePeriod` / `DeleteGroup`; убраны кейсы enum, регистрации в контроллерах и методы коллбэков
- **`LookupConsentByHash`** — поиск согласия по хэшу (проверка подлинности подписанного документа); UI нет
- **`ForkLessonForGroup`** — «сделать копию урока только для этой группы» (правки не затронут другие группы);
  сервис используется из конструктора, отдельного входа в UI нет
- **`RevealPiiField`** — раскрытие ОДНОГО поля ПД; в UI используется более грубый живой `RevealAllPersonPii`
- **`RequestPiiDeletion`** — мягкое удаление ПД по запросу субъекта (152-ФЗ); UI нет

### (исходный список аудита)

### (исходный список аудита)
Сервер проверяет nonce, который клиент нигде не создаёт (`->create()` отсутствует) — вызов физически невозможен:
- [ ] `RepresentativeCallbacks.php:57,91` — `AddRepresentative`, `ReplaceRepresentative`
- [ ] `BatchSubmissionCallbacks.php:94` — `GradeBatch` (хук `GradeBatchTask`)

Хуки зарегистрированы, но ни одного JS-потребителя (вероятно, недоделанные или брошенные фичи — решить по каждому):
- [ ] `PiiController.php:83-91` — `RevealPiiField`, `RequestPiiDeletion`, `AddRepresentative`, `ReplaceRepresentative`
- [ ] `CourseController.php:41-45` — весь блок клонирования `CloneLesson`/`CloneWork`/`CloneAssessment`/`CloneCourse`/`ForkLessonForGroup` (сервисный слой `ContentCloneService` при этом жив и используется из `CourseBuilderService`)
- [ ] `SettingsController.php:91,99` — `LookupConsentByHash`, `DeleteAcademicPeriod`
- [ ] `StudentGroupController.php:56` — `DeleteStudentGroup` (JS использует `checkGroupDeletion`/`deleteGroup`)
- [ ] `ExpulsionController.php:71` — `ExportExpelledRecord`; `EnrollmentController.php:72` — `EmptyApplicationsTrash`
- [ ] `WorkController.php:29` — `GetWorkTaskCandidates`; `LessonController.php:30,36` — `GetLessonArticles`, `CreateArticleDraft`
- [ ] `AssessmentController.php:37,38` — `ParseScoreMap`, `CopyScoreMap`; `ScheduleController.php:29` — `SetLessonExtraWorks`
- [ ] `SubstitutionController.php:28` — `GetGroupSubstitutions`; `RoomController.php:26,29` — `GetRooms`, `AssignGroupRoom`

### Проверить вручную ✅
- [x] `EmailOtpService` — тесты на обход НЕ полагаются (они проверяют bypass-код на этапе `verify`). Мёртвый закомментированный `return` удалён, докблок приведён к фактическому поведению: в `FS_LMS_TEST_ENV` письмо отправляется, войти без почты позволяет `FS_LMS_OTP_BYPASS_CODE`

### Чисто (проверено, мёртвого нет)
- Init::getServices() ↔ диск: все контроллеры/модули зарегистрированы или инжектятся (модули регистрируются вручную в `Init.php:181-186`)
- DTO (90+ классов), `inc/Shared/`, `inc/Contracts/`, все 114 шаблонов `templates/` — используются
- Трейты с единственным потребителем (кандидаты на инлайн, не на удаление): `ErrorHandler` (только SocialAuthController), `NumericSorter` (только SubjectController)

---

## Что трогать не нужно (проверено, вердикт «оставить»)

- `Migration_1_0_0::up()` (675 строк DDL) — снимок схемы, риск/выгода плохие
- `StudentRecordRepository` (28 методов) — узкие query-методы одной таблицы, SRP не нарушен
- `PostManager` — широкий фасад, не god-класс (кроме `buildListTable():154` — вынести)
- Log-export-провайдеры (`inc/Services/Export/Log/`) — здоровая Strategy
- Все 11 трейтов `inc/Shared/Traits/` — чистые, без state
- `match` на енумах в сервисах — идиоматичный PHP 8
- `error_log`, `get_header/get_footer`, `check_ajax_referer`/`wp_send_json` в Callbacks, хуки в Repositories/Registrars/Cli — нарушений нет
- SCSS: вложенность (0 нарушений), `@use`/`@forward` везде, инлайн-`<script>` в шаблонах отсутствует

---
Баги
1. Текстовые файлы 27го задания где числа разделены от их дробной частьи запятой, а не точкой, вызывают ошибку загрузки - "Извините, вам не разрешено загрузить этот тип файла.". - проверь это проблема WP или плагина. Предложи решения
2. В заданиях не получается вставить latex, но мы должны были решить это, подскажи, как вставлять формулы по типу дробей и корня
