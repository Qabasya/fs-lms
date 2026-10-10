# Этап 11a. Гость: заявка, бронь, оплата (веха M3a)

Зависимости: этапы 2, 3, 4 (для строки гостя в таблице сеанса — 8.8). Идёт параллельно этапам 5–8. Результат: гость по ссылке школы заполняет форму,
получает временную бронь, платит в существующем WooCommerce и после серверного подтверждения оплаты получает запись.
**Без этапа 11b не выпускается**: оплатившему гостю нужен вход на экзамен.

Перед началом прочитать `README.md`. SPEC: §1 «Участник, Person и дедупликация», §6 целиком, §9, §18 критерии 38–57. Решения: `../DECISIONS.md` 23–30.
Пути и ошибки пользователя: `../E2E-REVIEW.md` («Сквозной путь гостя»). Тексты: `../TEXTS.md`. Макет: `../signup.png`, `../mobile-signup.png`, `../payments.png`.

**Порядок:** 11a.6 (настройки) → 11a.1 → 11a.2 → 11a.3 → 11a.4 → 11a.5 → 11a.8 → 11a.7 (после 8.1).

## Статус (2026-10-09)

**Сделано:** 11a.6 (кроме 11a.6.4 и 11a.6.7), 11a.1, 11a.2 (кроме 11a.2.7 и 11a.2.8), 11a.3, 11a.4, 11a.5 (кроме 11a.5.9 — ограничение уже описано здесь), 11a.8 (кроме 11a.8.2). **Сделано также:** 11a.7.1–11a.7.6 (`GuestOnSiteService`, `AddExamGuestOnSite`/`IssueExamGuestPayLink`, `?pay=`, кнопка и форма в `exam-conduct.js`; браузерная проверка на dev не проходилась). **Не сделано:** 11a.6.4 (правка темы — нужно согласие владельца), 11a.6.7 (чек-лист в форме проведения), 11a.2.7 (журнал формы), 11a.8.2.

Проверено: PHPUnit (3008 тестов), `npm run ci`, и на dev с настоящим WooCommerce 10.2.1:
- ссылка с ключом → кука `fs_exam_inv` (сессионная, HttpOnly, SameSite=Lax) → редирект на адрес без ключа; заголовки `noindex`, `no-store`, `no-referrer`; неверный ключ и страница без куки — 404;
- форма в headless Chrome: организатор — текст, пустая отправка даёт ошибки у 5 полей, резюме обновляется при вводе и выборе сеанса, согласие на передачу результата не отмечено, капчи нет;
- «Перейти к оплате» → корзина с одной позицией (участник, дата и время, «место удерживается до»), обратный отсчёт `19:55`; прямой `?add-to-cart=<товар>` в корзину товар не кладёт;
- заказ с нулевой суммой (через CRUD WooCommerce, статус «Обработка»; хуки `status_changed` сами сверили заказ) → заявка `confirmed`, создан участник, запись, связь `paid` с суммой `0.00`, `occupied_count` вырос на 1; в `persons`/`wp_users` ничего не добавилось; страница «Спасибо» с верным ключом показывает «Оплата получена. Вы записаны.» и сеанс, с чужим ключом блока нет.
Данные стенда убраны (товар, купон, заказ, проведение).

**Найдено и исправлено попутно:**
- `consents.version` была `varchar(20)`, а `ConsentService` пишет SHA-256 (64 знака): в строгом режиме вставка согласия молча отвергалась (id = 0, таблица на dev пуста). `Migration_1_0_72` расширяет колонку до 64 (применилась сама на dev); заявка гостя теперь не принимается, если согласие не записалось.
- Шифртекст `PiiCryptoService` — бинарный, а колонки `exam_*` текстовые: черновик и данные участника хранятся в base64 (`GuestParticipantMaterializer::seal()/unseal()`).

**Отличия от текста этапа:**
- Страница формы — шорткод внутри страницы темы (как «Заявка»), а не отдельный шаблон с `ThemeCompatService::header()`; карусель — собственная (прокрутка + стрелки), без Splide темы.
- Создание участника вынесено в `GuestParticipantMaterializer` (иначе круг зависимостей с `ExamHoldService`); `GuestApplicationService::materializeParticipant()` делегирует в него.
- Данные страницы собирает `GuestSignupViewService`; исходы страниц (отрисовать / редирект / 404) — `GuestPageOutcome`, исполняет `GuestPageResponder`.
- Хуки магазина вешаются на `woocommerce_init` (LMS грузится раньше WooCommerce, ранняя проверка `class_exists` дала бы «не активен»).
- Сообщение «Сначала выберите дату…» при прямом добавлении в корзину в браузере не проверялось (товар в корзину не попадает — проверено).
- Блочное оформление (Store API) не поддерживается — ограничение, записать в `HANDOFF.md`.

**Не проверено:** оформление заказа кликами в браузере до конца (форма оформления темы содержит собственное поле «ФИО ребёнка»; заказ собран программно через CRUD WooCommerce); тестовый режим ЮKassa; параллельный стенд `pay-vs-release`; ручная проверка 390 px; проверка на VPN.

---

## Общие правила этапа

- **Капчи на форме нет** (решение 26). `CaptchaService` на этой форме не вызывать, «пропуск без токена» не добавлять.
- **Оплату подтверждает сервер WooCommerce**, а не возврат из банка и не кнопка «Я оплатил».
- **Заказы — только через WooCommerce CRUD** (`wc_get_order()`, методы `WC_Order`, `WC_Order_Item`). Никаких `get_post_meta`, `wp_posts`, прямых
  запросов к таблицам заказов: на проде HPOS без режима совместимости.
- **Существующий магазин не менять:** корзина, оформление, шлюз, письма, статусы, страница «Спасибо» остаются. LMS только добавляет свой блок статуса.
- **Refund API не вызывается никогда.** Писем LMS гостю нет.
- **Гость — не `Person`, не ученик и не пользователь WordPress.** Фиктивных `persons`, `student_records` и учёток не создавать.
- В ответах об ошибках и дублях нет чужих данных (ФИО, даты, статусы чужой заявки).
- Данные плательщика из WooCommerce (billing) не подменяют ФИО участника: платить может родитель.
- Весь код оплаты регистрируется, только если WooCommerce активен (`WooExamController::isWooActive()`, этап 0.8).
- На dev шлюза нет: оплата имитируется купоном 100% (этап 0.7).

---

## 11a.6 Настройки запуска и чек-лист готовности

**Зачем.** Товары, срок брони, лимиты, контакты и ссылки на согласия задаёт оператор; публикация гостевого проведения блокируется, пока чек-лист
не пройден (SPEC §6 «Настройки запуска экзаменов»). Делается первым: остальные пункты читают эти настройки.

**Проверить перед началом**
- `inc/Services/Shared/PluginConfig.php` — методы чтения и `viewState()`; `inc/Repositories/OptionsRepositories/PluginConfigRepository.php` —
  `get()`, `save( array $partial )` (частичное сохранение).
- `inc/Callbacks/Settings/ConfigCallbacks.php::ajaxSaveConfig()` и шаблон `templates/admin/components/tabs/settings-tabs/settings-7-config.php` —
  как поле попадает из формы в опцию (образец — `consultation_url`).
- Тема: `wp-content/themes/fs-lms-theme/inc/Showcase/Site_Settings.php` — ключи `phone`, `email`, `hours`, `city`, `street`
  (`street` = «ул. Черняховского, д. 6, каб. 316»). **Тема — отдельный репозиторий**: её правку (подписку на фильтр) согласовать с владельцем.
- `ConsentService::getPageForType( string $typeKey )` — страница текста согласия.

**Шаги**
- [x] 11a.6.1 `PluginConfig` — новые методы чтения (ключи в существующей опции, **новую опцию не заводить**):
  | Метод | Ключ | По умолчанию |
  |---|---|---|
  | `examProductId( int $grade ): int` | `exam_product_9`, `exam_product_11` | `0` |
  | `examHoldMinutes(): int` | `exam_hold_minutes` | `20` |
  | `examIpActiveHoldsLimit(): int` | `exam_ip_active_holds` | `40` |
  | `examIpHourlyLimit(): int` | `exam_ip_hourly` | `60` |
  | `examSourceActiveHoldsLimit(): int` | `exam_source_active_holds` | `60` |
  | `examGuestRetentionDays(): int` | `exam_guest_retention_days` | `365` |
  | `examUnpaidRetentionDays(): int` | `exam_unpaid_retention_days` | `30` |
  | `centerContactsFallback(): array` | `center_phone`, `center_email`, `center_hours`, `center_address` | пустые строки |
  Значения приводить к границам (минуты брони 5…120, лимиты ≥ 1). `viewState()` дополнить блоком `exams`.
- [x] 11a.6.2 Вкладка настроек: в `settings-7-config.php` новая секция «Экзамены для гостей» (разметка — существующие `fs-field`), поля из таблицы.
  Товар выбирается выпадающим списком виртуальных товаров WooCommerce (список готовит контроллер вкладки через `wc_get_products( array( 'virtual' => true, 'limit' => 50 ) )`,
  **только если WooCommerce активен**; иначе вместо полей — «WooCommerce не активен: гостевая оплата недоступна.»). Рядом с каждым товаром — признак
  «доступен к покупке» и цена, полученные от WooCommerce. Сохранение — `ajaxSaveConfig()` (дописать чтение новых ключей через `Sanitizer`).
- [x] 11a.6.3 `inc/Services/Shared/CenterContactsService.php`: `public const FILTER = 'fs_lms_center_contacts';`
  `get(): array{phone:string, email:string, hours:string, city:string, street:string}` — `apply_filters( self::FILTER, <запасные значения из PluginConfig> )`,
  пустое значение из фильтра заменяется запасным. `addressWithoutRoom(): string` — город + улица **до слова «каб.»**
  (`preg_split( '/,?\s*каб\./u', $street )[0]`): «Калининград, ул. Черняховского, д. 6». Номер кабинета берётся из сеанса, не из адреса.
  Ядро тему не знает: тема сама подписывается на фильтр.
- [ ] 11a.6.4 Тема (по согласованию с владельцем, отдельным изменением в репозитории темы): в `inc/PluginRoutes.php` или рядом —
  `add_filter( 'fs_lms_center_contacts', … )`, отдающий значения `FS_LMS_Theme_Site_Settings`. До этой правки работают запасные тексты из настроек плагина.
- [x] 11a.6.5 `inc/Services/Exam/ExamLaunchChecklist.php`: `check( ?ExamEventDTO $event = null ): array` — список
  `array{key, label, ok:bool, hint}`:
  | Проверка | Подсказка при провале |
  |---|---|
  | WooCommerce активен | «Включите WooCommerce.» |
  | товар для класса направления задан, существует, виртуальный, доступен к покупке, цена > 0 | «Выберите товар экзамена в настройках.» |
  | у всех кабинетов сеансов проведения `seats > 0` | «Укажите вместимость кабинета {название}.» |
  | контакты центра (телефон и адрес) не пусты | «Заполните контакты центра.» |
  | страницы согласий `pd_processing` и `pd_transfer` существуют | «Создайте страницу согласия.» |
  | срок брони и лимиты в допустимых границах | «Проверьте срок брони и лимиты.» |
  | есть хотя бы один пользователь-получатель уведомлений об оплатах | «Назначьте администратора платформы.» |
  `isReady( ?ExamEventDTO $event ): bool`. Фиктивные значения не подставляются.
- [x] 11a.6.6 `ExamEventService::publish()` и `updateEvent()`: если `guest_registration_enabled = 1` и `! isReady( $event )` —
  `CodedException( ErrorCode::ExamConflict, 'Запись гостей недоступна: настройки запуска не завершены.' )` с перечнем непройденных пунктов в контексте.
  Проведение без гостей публикуется независимо от чек-листа.
- [ ] 11a.6.7 Чек-лист показать в секции настроек (галочки и подсказки) и в форме «Настройки проведения» рядом с переключателем «Запись гостей».

**Тесты**
- `tests/Unit/Services/Shared/PluginConfigTest.php` (дописать): значения по умолчанию, приведение к границам, один товар для 9 и 11 класса допустим.
- `tests/Unit/Services/Shared/CenterContactsServiceTest.php`: `test_filter_values_win_over_fallback`, `test_empty_filter_value_uses_fallback`,
  `test_address_is_cut_before_room` («ул. Черняховского, д. 6, каб. 316» → без кабинета), `test_address_without_room_word_is_unchanged`.
- `tests/Unit/Services/Exam/ExamLaunchChecklistTest.php`: по тесту на каждую строку таблицы; `test_ready_when_all_ok`.
- `ExamEventServiceTest.php`: `test_guest_event_publish_blocked_until_checklist_ready`, `test_non_guest_event_ignores_checklist`.

**Готово, когда:** тесты зелёные; на dev секция настроек показывает чек-лист, после заполнения всех пунктов он зелёный.

---

## 11a.1 Участник-гость, заявка, защита от дублей

**Зачем.** Гость хранится отдельным участником без `Person`; заявка до оплаты — не запись; у личности одна активная заявка на проведение;
одинаковый телефон двух детей людей не склеивает (SPEC §1, §6, критерий 46).

**Проверить перед началом**
- DDL `exam_participants`, `exam_guest_applications` (2.1); `GuestApplicationRepository`, `ExamHoldService` (3.4).
- `inc/Services/Security/PiiCryptoService.php`: `encrypt()`, `decrypt()`, `hash()` (нормализует регистр и пробелы по краям).
- Нормализация телефона: `grep -rn "normalizePhone\|preg_replace( '/\\\\D" inc | head` — взять существующую, свою не писать.

**Шаги**
- [x] 11a.1.1 `inc/Services/Exam/GuestIdentity.php` (без состояния; зависимость — `PiiCryptoService`):
  - `nameHash( string $last, string $first, string $middle ): string` — хеш от нормализованного ФИО (нижний регистр, `ё` → `е`, один пробел между словами);
  - `phoneHash( string $phone ): string` — хеш от телефона, приведённого к цифрам;
  - `identityHash( … ): string` — хеш от пары «ФИО + телефон». **Личность = ФИО и телефон вместе**: два ребёнка с одним телефоном родителя дают разные хеши.
- [x] 11a.1.2 `inc/Services/Exam/GuestApplicationService.php`, `use TransactionRunner;`. Зависимости: `GuestApplicationRepository`,
  `ExamParticipantRepository`, `ExamSourceRepository`, `ExamEventRepository`, `ExamSessionRepository`, `ExamHoldService`, `GuestIdentity`, `PiiCryptoService`,
  `ConsentService`, `PluginConfig`, `ExamOutbox`, `ExamTime`.
- [x] 11a.1.3 `apply( ExamSourceDTO $source, array $form, RequestContextDTO $ctx, string $requestKey, ?int $staffUserId = null ): GuestApplicationDTO`:
  1. проверки формы (11a.3.5) — на сервере, независимо от клиента;
  2. источник активен, ключ не отозван, проведение `published`, `guest_registration_enabled`, сеанс принадлежит этому проведению;
  3. `source_snapshot` — JSON со школой, классом, ФИО преподавателя **из источника** (не из формы);
  4. `draft_enc` — зашифрованный JSON формы (ФИО, телефон, мессенджер);
  5. согласия — `ConsentService::recordSelfConsent( null, $typeKey, $ctx )` для каждого отмеченного; ID — в `consent_refs`;
  6. `ExamHoldService::capture( …, PluginConfig::examHoldMinutes() )`.
  Школа, класс и преподаватель из запроса **игнорируются**: источник определяется по куке приглашения (11a.2).
- [x] 11a.1.4 Участник создаётся только при подтверждении оплаты (`ExamHoldService::convert()`, 3.4.4): тогда из `draft_enc` заполняется
  `exam_participants` (`name_enc`, `phone_enc`, `messenger_enc`, `name_hash`, `phone_hash`, `school_name`, `grade` — из снимка источника).
  Реализовать этот шаг в `convert()` через метод `GuestApplicationService::materializeParticipant( GuestApplicationDTO $app ): int`.
- [x] 11a.1.5 Кандидаты на дубль — для сотрудника: `duplicateCandidates( int $eventId, int $participantId ): array` — участники того же проведения
  с совпавшим `name_hash` **или** `phone_hash`. Это подсказка сотруднику, **не** объединение и не отказ. Совпадение только по телефону — кандидат, не дубль.
- [x] 11a.1.6 `decryptName( ExamParticipantDTO $p ): string` — для экранов сотрудника (заменяет заглушку «Гость #id» из 8.1.2);
  расшифровка телефона — отдельный метод `decryptContacts()`, вызывается только с правом `ManageExamGuests` и пишет событие доступа к ПД
  (посмотреть, как это делает раскрытие ПД: `grep -rn "PiiRevealedEvent(" inc | head -2`).
- [x] 11a.1.7 Авторизованный ученик на гостевой форме: если запрос пришёл от вошедшего пользователя с ролью ученика или родителя — заявку не создавать,
  ответ с адресом кабинета («Вы уже учитесь у нас: запись на экзамен — в личном кабинете.»). Роль «гость» выбрать нельзя; политика раскрытия
  определяется сервером (SPEC §1).

**Тесты**
- `tests/Unit/Services/Exam/GuestIdentityTest.php`: `test_same_phone_different_names_give_different_identity`,
  `test_name_normalization_ignores_case_spaces_and_yo`, `test_phone_normalization_ignores_format`.
- `tests/Unit/Services/Exam/GuestApplicationServiceTest.php`: `test_apply_takes_school_and_grade_from_source_not_from_form`,
  `test_apply_stores_form_encrypted`, `test_apply_records_consents_and_keeps_refs`,
  `test_apply_rejected_for_revoked_or_inactive_source`, `test_apply_rejected_when_guest_registration_disabled`,
  `test_second_application_of_same_identity_is_conflict_without_details`,
  `test_two_children_with_same_phone_get_two_applications`,
  `test_logged_in_student_is_redirected_to_cabinet_not_registered_as_guest`,
  `test_participant_is_created_only_on_confirmation`,
  `test_duplicate_candidates_by_phone_are_hints_only`.

**Готово, когда:** тесты зелёные; в базе после заявки нет строк в `wp_fs_lms_persons` и `wp_users` (сравнить счётчики до и после).

---

## 11a.2 Страница приглашения: ключ, кука, защита

**Зачем.** Форма не публичная: открывается только по ссылке с ключом; ключ обменивается на куку и исчезает из адреса; страница не индексируется;
защита — honeypot, проверка скорости и лимиты с запасом на школу (SPEC §6 «Защита гостевой формы», критерии 51–53).

**Проверить перед началом**
- `ExamAccessTokenService::exchange()`, `currentGeneration()` (2.5); `exam_guest_sessions` (2.1); `PageRoutes::ExamSignup` (4.6.3).
- Образец 404 без раскрытия: `AssessmentPageController::loadTemplate()` (`set_404()`, `status_header( 404 )`, `nocache_headers()`, `get_404_template()`).
- Создание служебных страниц: `Activate::generatePages()` + `PageGeneratorService::ensurePublished()`; шорткоды — `inc/Enums/Wp/ShortCode.php`.
- `inc/Services/Security/FormGuardService.php`: `honeypotField()`, `timestampToken()`, `isHuman()`.
- `inc/Services/Security/RateLimitService.php`: приватный `checkIp()`, публичные `allow…()`; `TRUSTED_IP_MULTIPLIER`.
- Журнал формы заявки: `git show master:inc/Services/Application/ApplyFormTrackingService.php` — образец записи событий формы.
- Карта сайта: `inc/Services/Security/UserEnumerationGuard.php` — как убирается провайдер из `wp_sitemaps`.

**Шаги**
- [x] 11a.2.1 Страница: `PageRoutes::ExamSignup`, шорткод `ShortCode::ExamSignup = 'fs_lms_exam_signup'`, создание в `Activate::generatePages()`
  (`ensurePublished( PageRoutes::ExamSignup, 'Запись на экзамен', ShortCode::ExamSignup->tag() )`). На уже работающих установках страница создаётся
  одноразовым шагом на обычной загрузке по образцу data-миграций в `Init::run()` (скилл `db-migrations`: самодостаточный класс со своей опцией-флагом).
- [x] 11a.2.2 `inc/Services/Exam/GuestSessionService.php` (зависимости: `ExamGuestSessionRepository`, `ExamAccessTokenService`, `PiiCryptoService`, `ExamTime`):
  - `openInvitation( int $sourceId, int $generation, string $expiresAtUtc ): string` — создаёт строку `exam_guest_sessions` (`scope = invitation`),
    возвращает случайное значение куки (`bin2hex( random_bytes( 32 ) )`), в базе — только хеш;
  - `resolveInvitation( string $cookie ): ?int` — ID источника, если сессия не отозвана, не истекла и её `generation` равно
    `currentGeneration( Invitation, source_id )`; иначе `null`;
  - `revokeBySource( int $sourceId ): void` — вызывается при перевыпуске и отзыве ссылки (дописать вызов в `ExamSourceService::reissueLink()` и `revokeLink()`).
- [x] 11a.2.3 `inc/Controllers/Exam/ExamGuestPageController.php` (`ServiceInterface`, в `Init::getServices()`): хук `template_redirect`. Только для страницы `ExamSignup`:
  1. отправить заголовки: `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`;
  2. есть параметр `k`: лимит неудач (11a.2.5) → `exchange( Invitation, k )` → успех: `openInvitation()`, кука `fs_exam_inv`
     (`HttpOnly`, `SameSite=Lax`, `Secure` при https, без срока — сессионная, путь `/`), **редирект на адрес страницы без параметра `k`**;
     неуспех: засчитать неудачу → 404;
  3. нет `k`: `resolveInvitation( кука )` → источник найден: отдать страницу; не найден: 404.
  404 — обычная страница темы, без слов «ссылка недействительна» и без сведений о проведении.
  Логика — в отдельном классе `inc/Callbacks/Exam/GuestApplicationCallbacks.php` (метод `handleInvitationPage()`), контроллер только вешает хук.
- [x] 11a.2.4 Не индексируется и не утекает: meta `robots noindex` в `wp_head` для этой страницы; исключение из карты сайта
  (`wp_sitemaps_posts_query_args` — добавить ID страницы в `post__not_in`) и из поиска по сайту (`pre_get_posts` для поискового запроса);
  канонический адрес не выводить. Ключ не писать в логи: `PluginLogger` получает только ID источника.
  Яндекс.Метрика ключ не видит, потому что рендер страницы происходит уже по адресу без `k` — проверить в браузере (11a.2.8).
- [x] 11a.2.5 Лимиты — новые методы `RateLimitService` (ключи и константы внутри сервиса, как у остальных):
  | Метод | Правило |
  |---|---|
  | `registerInvitationFailure( string $ip ): void`, `isInvitationLocked( string $ip ): bool` | не больше 20 **неудачных** проверок ключа за 15 минут на IP; успешные не считаются |
  | `allowExamHoldCreation( string $ip, int $hourlyLimit ): bool` | не больше `examIpHourlyLimit()` созданий брони в час на IP; для доверенных IP — существующий множитель |
  Лимит «одновременно активных броней на IP» и «на источник» — не счётчик, а запрос к базе: `GuestApplicationRepository::countHeldByIp()` /
  `countHeldBySource()` против `examIpActiveHoldsLimit()` / `examSourceActiveHoldsLimit()`. `ip_hash` — `RateLimitService::ipKey()`-подобный хеш IP с солью
  (сырой IP в таблицу не писать).
- [x] 11a.2.6 Превышение лимита → `CodedException( ErrorCode::ExamLimit, … )`: по IP — «Слишком много заявок с этого адреса. Обратитесь к сотруднику: {телефон}.»,
  по источнику — «Слишком много заявок по этой ссылке, обратитесь к сотруднику.» + outbox `SourceLimitExceeded` и запись в журнал.
  Введённые данные формы сохраняются (клиент форму не очищает).
- [ ] 11a.2.7 Журнал формы: события «открыта», «ошибка проверки», «бронь создана», «лимит» — по образцу `ApplyFormTrackingService`
  (посмотреть его таблицу и канал; если механизм универсален — добавить события экзаменной формы в его энум, а не заводить параллельный).
- [ ] 11a.2.8 Проверка в браузере: открыть ссылку с ключом → адресная строка без ключа; «Сеть» → в запросах к `mc.yandex.ru` нет ключа; заголовки ответа
  содержат `noindex`, `no-store`, `no-referrer`; повторное открытие исходной ссылки работает; после «Перевыпустить» старая ссылка и уже открытая вкладка
  (после обновления) дают 404.

**Тесты**
- `tests/Unit/Services/Exam/GuestSessionServiceTest.php`: `test_invitation_cookie_stored_as_hash`,
  `test_invitation_invalid_after_generation_bump`, `test_revoked_invitation_is_not_resolved`, `test_expired_invitation_is_not_resolved`.
- `tests/Unit/Callbacks/Exam/GuestApplicationCallbacksTest.php`: `test_valid_key_sets_cookie_and_redirects_without_key`,
  `test_invalid_key_is_plain_404_and_counts_failure`, `test_no_key_no_cookie_is_404`,
  `test_successful_openings_are_not_counted`, `test_locked_ip_gets_404_even_with_valid_key` (после 20 неудач за 15 минут).
- `tests/Unit/Services/Security/RateLimitServiceTest.php`: `test_invitation_lock_after_twenty_failures`,
  `test_exam_hold_hourly_limit_and_trusted_multiplier`.
- `GuestApplicationServiceTest.php`: `test_forty_first_active_hold_from_ip_is_limited`, `test_thirty_holds_from_one_ip_pass`,
  `test_source_limit_writes_outbox_event`.

**Готово, когда:** тесты и проверка 11a.2.8 пройдены.

---

## 11a.3 Форма заявки

**Зачем.** Гость видит, кто его пригласил, вводит данные участника, выбирает сеанс, даёт согласия и перед оплатой видит резюме (SPEC §6, «карусель»).

**Проверить перед началом**
- Существующие элементы (`../QA.md`): `fs-join-card` (секции, замок `locked-notice`, согласие, кнопка), `fs-field-control` с dashicons,
  `fs-apply-card__status` + спиннер, `fs-apply-card__success`. Шаблоны-образцы: `templates/frontend/apply.php`, `apply-fields.php`, `join.php`.
- Валидация: скилл `form-validation`, `src/js/common/validators/`, `validation-manager.js`; маска телефона — `src/js/common/input-masks.js`.
- Согласия в формах: как `join.php` выводит чекбокс согласия со ссылкой «Прочитать» (`ConsentDefinitionsRepository`).
- Карусель темы: `wp-content/themes/fs-lms-theme/src/js/carousels.js`, стили `.fs-carousel-mask .splide__arrow` — на публичной странице переиспользуется
  она (листание по одной карточке, без автопрокрутки). Проверить, подключён ли Splide темой на произвольной странице.
- Раскладка «сайдбар + контент» привязана к обёрткам страниц (`../QA.md`, «Доработка существующего CSS»).
- Шкала `rem` публичных страниц — 19.2 px (не 16 px кабинета).

**Шаги**
- [x] 11a.3.1 Шаблон `templates/frontend/exam-signup.php` (шапка и подвал — только `ThemeCompatService::header()` / `footer()`); рендер из шорткода
  в `ExamGuestPageController` (образец — `ApplyPageController::renderApplyForm()`). Данные шаблона: источник (школа, класс, преподаватель), проведение
  (название, направление), сеансы (как 5.3.3: дата, день недели, время, кабинет, свободные места, `selectable`), согласия (тип, название, ссылка),
  цена (11a.3.6), контакты центра, срок брони в минутах, срок хранения данных (`examGuestRetentionDays()`), honeypot и токен времени `FormGuardService`.
- [x] 11a.3.2 Блоки формы в карточке `fs-join-card`:
  1. **«Организатор приглашения»** — школа, класс, ФИО учителя: текст с замком `locked-notice`, не поля ввода, не hidden-поля;
  2. **«Данные участника»** — подзаголовок «ФИО того, кто будет сдавать экзамен»; поля: фамилия, имя, отчество («если есть», необязательное),
     телефон (обязателен, маска), «Связь через мессенджер» (необязательное, **простой текстовый ввод любых символов**, без кнопки и без проверки формата);
  3. **«Дата и время»** — карусель сеансов; заполненные сеансы видны, но не выбираются;
  4. **Согласия** — чекбоксы действующего механизма: обработка ПД (обязательное), передача результата школьному преподавателю (отдельное,
     **необязательное, не отмечено по умолчанию**), маркетинговая связь (необязательное, не отмечено). Отдельного блока про несовершеннолетних нет;
  5. ссылка «Уже учитесь у нас? Войти» — на `/sign-in/` с возвратом в кабинет на «Мои экзамены»;
  6. **резюме** и кнопка «Перейти к оплате».
- [x] 11a.3.3 Резюме перед оплатой («ключ — значение», новый элемент №2 в `../QA.md`): ФИО участника (как введено), направление,
  дата с годом и днём недели, время, адрес (`CenterContactsService::addressWithoutRoom()` + кабинет сеанса), цена. Обновляется при вводе и выборе сеанса.
  Срок хранения данных — строкой под резюме, число из настроек.
- [x] 11a.3.4 Карусель: на компьютере три карточки и край четвёртой, на телефоне — одна и край следующей; стрелки в своих колонках и недоступны
  на границах; перетаскивание не выбирает сеанс; **сеанс не выбран автоматически** — без явного выбора отправка невозможна, ошибка у карусели:
  «Выберите дату и время.» Смена сеанса не сбрасывает введённые поля. Карточка сеанса — тот же вид, что в кабинете (5.3.4), стили — токенами публичного бандла
  (`src/scss/frontend/components/_exam-signup.scss`).
- [x] 11a.3.5 Проверки (клиент — валидаторы `validation-manager`; сервер — те же правила в `GuestApplicationService::apply()`):
  фамилия и имя — обязательны, до 100 символов; отчество — необязательно; телефон — обязателен, 11 цифр; мессенджер — до 100 символов, любые;
  сеанс — обязателен и принадлежит проведению источника; согласие на обработку ПД — обязательно (`ErrorCode::ExamConsent`).
  Ошибка — у своего поля; форма не очищается; после исправимой ошибки все введённые данные на месте.
- [x] 11a.3.6 Цена: только с сервера из WooCommerce — `wc_get_product( PluginConfig::examProductId( $grade ) )->get_price()` через адаптер (11a.5.1),
  форматирование — `wc_price()`. Цена из формы и из настроек LMS не берётся.
- [x] 11a.3.7 Состояния страницы без формы: мест нет ни в одном сеансе — «Свободных мест нет. Обратитесь к сотруднику: {телефон}.»;
  запись закрыта — «Запись закрыта.» + контакт; WooCommerce или товар недоступен — «Запись временно недоступна.» + контакт.
  Кнопки оплаты в этих состояниях нет.
- [x] 11a.3.8 JS — `src/js/frontend/services/exam-signup.js` (`export function initExamSignup()`, подключить в `frontend.js`), переменные —
  `fs_lms_exam_signup_vars` через слой `inc/Core/Assets` (`FrontendAssets`), не в шаблоне: `ajax_url`, `actions`, `nonce` (`Nonce::ExamGuest`).
  Обёртку страницы добавить в список раскладки «сайдбар + контент» с кнопкой формы как у `fs-join-card` (`../QA.md`).

**Тесты**
- `tests/Unit/Templates/…ExamSignupTemplateTest.php` (посмотреть, как устроены тесты шаблонов в `tests/Unit/Templates`):
  `test_source_fields_are_text_not_inputs`, `test_transfer_and_marketing_consents_unchecked_by_default`,
  `test_no_captcha_markup`, `test_price_is_rendered_from_server_value`.
- `GuestApplicationServiceTest.php`: `test_server_validation_rules` (по случаю на правило), `test_session_of_other_event_is_rejected`,
  `test_pd_consent_is_required`, `test_transfer_consent_is_optional_and_stored_separately`.
- `tests/js/exam-signup.test.mjs` — чистые функции формы (сборка резюме, проверка «сеанс выбран»).
- Ручная проверка на 1440 px и 390 px, с включённым VPN: форма открывается и отправляется.

**Готово, когда:** тесты и ручная проверка пройдены; вёрстка сверена с `../signup.png` по составу блоков (макет — иллюстрация, не эталон пикселей).

---

## 11a.4 «Перейти к оплате»: бронь и корзина

**Зачем.** Одно нажатие атомарно занимает место и кладёт товар в существующую корзину с защищённой привязкой к заявке. Повтор клика и вторая вкладка
не создают вторую бронь. Покупка товара экзамена без заявки невозможна (SPEC §6, критерии 38, 40).

**Проверить перед началом**
- `GuestApplicationService::apply()` (11a.1.3), `ExamHoldService::capture()` / `release()` (3.4).
- Хуки классического WooCommerce: `woocommerce_add_cart_item_data`, `woocommerce_add_to_cart_validation`, `woocommerce_get_item_data`,
  `woocommerce_cart_item_quantity`, `woocommerce_cart_item_removed`, `woocommerce_checkout_create_order_line_item`.
- Сессия корзины гостя: `WC()->session` (у гостя появляется после `WC()->session->set_customer_session_cookie( true )`).

**Шаги**
- [x] 11a.4.1 Экшен `SubmitExamGuestApplication` в `GuestApplicationCallbacks` — **публичный** (`publicAjaxActions()`), nonce `ExamGuest`. Порядок:
  1. источник — только из куки приглашения (`resolveInvitation()`); нет источника → `fail( ErrorCode::ExamLink, 'Откройте форму по ссылке из приглашения.' )`;
  2. `FormGuardService::isHuman( honeypot, token )` — провал → тихий отказ общим текстом («Не удалось отправить форму. Обновите страницу.»);
  3. лимиты 11a.2.5;
  4. `request_key` — обязателен (клиент генерирует `crypto.randomUUID()` один раз при загрузке формы и переиспользует при повторе и после ошибки сети);
  5. `GuestApplicationService::apply()` → заявка с бронью;
  6. `WooExamAdapter::addToCart( $application )` (11a.4.3);
  7. ответ: `redirect` (адрес корзины `wc_get_cart_url()`), `hold_expires_at` (местное время), `seconds_left`.
  Транзакция брони **закрыта до** обращения к WooCommerce.
- [x] 11a.4.2 Компенсация: если шаг 6 бросил исключение — `ExamHoldService::release( $applicationId, GuestApplicationState::Failed )` (место освобождается
  ровно один раз), ответ — восстанавливаемая ошибка «Не удалось перейти к оплате. Попробуйте ещё раз.»; форма остаётся заполненной. Повтор с тем же
  `request_key` после компенсации должен создать новую бронь: при `state = failed` идемпотентная ветка `capture()` заявку не возвращает —
  клиент после такой ошибки генерирует **новый** `request_key`.
- [x] 11a.4.3 `inc/Services/Exam/Payment/WooExamAdapter.php` — единственный класс, который вызывает функции WooCommerce.
  `addToCart( GuestApplicationDTO $app ): void`:
  - товар — `PluginConfig::examProductId( grade из снимка источника )`; не найден или недоступен → исключение;
  - **одна незавершённая экзаменная заявка на корзину:** если в корзине уже есть экзаменная позиция другой заявки — исключение с текстом
    «Завершите оформление первой записи, затем запишите второго участника.»; чужие (не экзаменные) товары **не удалять**;
  - `WC()->cart->add_to_cart( $productId, 1, 0, array(), array( 'fs_exam_application' => $app->id, 'fs_exam_sig' => <подпись> ) )` —
    данные позиции включают ID заявки, поэтому позиции двух участников не сливаются; подпись — `hash_hmac( 'sha256', "$app->id|$app->requestKey", FS_LMS_HASH_SALT )`
    (защита от подмены ID заявки в сессии).
- [x] 11a.4.4 Хуки корзины — регистрация в `WooExamController::register()` (только при `isWooActive()`), обработчики — методы `WooExamAdapter`
  или отдельного `inc/Callbacks/Exam/WooExamCallbacks.php` (контроллер логики не содержит):
  | Хук | Поведение |
  |---|---|
  | `woocommerce_add_to_cart_validation` | товар экзамена **без** данных заявки → запрет с уведомлением «Сначала выберите дату: откройте ссылку-приглашение.» Прямая ссылка «в корзину» место не бронирует |
  | `woocommerce_cart_item_quantity` / `woocommerce_update_cart_validation` | количество экзаменной позиции всегда 1; изменить нельзя |
  | `woocommerce_get_item_data` | под названием товара: участник (ФИО из заявки), дата и время сеанса, «Место удерживается до {время}» |
  | `woocommerce_cart_item_removed` | позицию удалили → `ExamHoldService::release( …, Cancelled )`; повторное добавление требует новой заявки |
  | `woocommerce_checkout_create_order_line_item` | перенос привязки в мета позиции заказа: `_fs_exam_application` (через `$item->add_meta_data()`), создание строки `exam_payment_links` после сохранения заказа (11a.5.3) |
  | `woocommerce_check_cart_items` | заявка позиции истекла или отменена → убрать позицию с уведомлением «Время брони истекло. Выберите дату заново.» |
- [x] 11a.4.5 Обратный отсчёт: блок «Место удерживается до {время}, осталось {мм:сс}» (строка в `fs-apply-card__status`, новый элемент №3 `../QA.md`)
  на форме после брони, в корзине и на оформлении (хуки `woocommerce_before_cart`, `woocommerce_before_checkout_form`). Время — с сервера
  (`hold_expires_at`, `seconds_left`); клиент только тикает. **Обновление страницы и повторное нажатие бронь не продлевают.**
- [x] 11a.4.6 Состояние заявки при переходе в корзину: `hold` → `awaiting_payment` (место по-прежнему занято, срок тот же).
- [x] 11a.4.7 Вторая вкладка и кнопка «Назад»: повторная отправка той же формы даёт ту же заявку (тот же `request_key`) и ведёт в ту же корзину
  без второй позиции (перед `add_to_cart` проверить, нет ли уже позиции этой заявки).

**Тесты**
- `GuestApplicationCallbacksTest.php`: `test_submit_without_invitation_cookie_is_denied`, `test_submit_ignores_school_and_grade_from_request`,
  `test_honeypot_filled_is_rejected_without_details`, `test_cart_failure_releases_hold_once`,
  `test_repeat_with_same_request_key_returns_same_hold`, `test_response_has_server_expiry_and_cart_url`.
- `tests/Unit/Services/Exam/Payment/WooExamAdapterTest.php` (функции WooCommerce — заглушки в тесте или тонкая обёртка-шлюз
  `inc/Services/Exam/Payment/WooGateway.php` с методами `cart()`, `product()`, `order()`, которую можно замокать — предпочтительно):
  `test_exam_product_without_application_cannot_be_added`, `test_quantity_is_always_one`,
  `test_second_application_in_same_cart_is_rejected_and_foreign_items_kept`,
  `test_removed_item_releases_hold`, `test_expired_application_item_is_removed_from_cart`,
  `test_item_data_signature_mismatch_is_rejected`.
- Стенд: двойной клик и две вкладки → одна бронь (`SELECT COUNT(*) … WHERE is_held = 1` → 1).

**Готово, когда:** тесты зелёные; на dev: форма → корзина с одной позицией, подписанной ФИО участника и датой; прямой переход по
`?add-to-cart=<ID товара>` даёт отказ с текстом; удаление позиции освобождает место.

---

## 11a.5 Адаптер WooCommerce: подтверждение оплаты

**Зачем.** Запись подтверждается по серверным данным заказа. Поздняя оплата, повторные события и старые неуспешные заказы не ломают состояние
(SPEC §6 «Временная бронь и подтверждение оплаты», критерии 42–45, 47, 48, 55).

**Проверить перед началом**
- Факты прода (SPEC §6): классическое оформление, HPOS без синхронизации, гостям оформление разрешено, шлюз ЮKassa, оплаченные заказы — в «Обработка».
- `ExamHoldService::convert()` (3.4.4) — все ветки подтверждения уже реализованы и покрыты тестами.
- DDL `exam_payment_links`; `ExamPaymentState`.

**Шаги**
- [x] 11a.5.1 `WooExamAdapter` — чтение через шлюз `WooGateway`: `productPrice( int $grade ): ?string`, `isProductPurchasable( int $productId ): bool`,
  `isOrderPaid( int $orderId ): bool` → `wc_get_order( $id )->is_paid()` (статусы «Обработка» и «Выполнен»; нулевой заказ по купону тоже `true`),
  `applicationIdsOfOrder( int $orderId ): array` — из меты позиций `_fs_exam_application`.
  Признак оплаты — **только** `is_paid()`; `pending`, `on-hold`, `failed`, `cancelled` оплатой не считаются. Данные шлюза (ЮKassa) не читать.
- [x] 11a.5.2 `inc/Repositories/WPDBRepositories/Exam/ExamPaymentLinkRepository.php` и `inc/DTO/Exam/ExamPaymentLinkDTO.php`:
  `create`, `findByOrderItem( int $itemId )`, `listByApplication( int $applicationId )`, `listByOrder( int $orderId )`, `update`, `listPendingForReconcile( string $olderThanUtc, int $limit )`.
- [x] 11a.5.3 Создание связи: на `woocommerce_checkout_order_processed` (заказ уже сохранён, у позиций есть ID) — для каждой экзаменной позиции
  строка `exam_payment_links`: `application_id`, `wc_order_id`, `wc_order_item_id` (уникальный), `product_id`, `amount` (сумма позиции после скидок — может быть `0`),
  `currency`, `payment_state = pending`. Заявка → `payment_pending`. Повторный вызов хука не создаёт дубль (уникальный индекс).
- [x] 11a.5.4 `inc/Services/Exam/Payment/ExamPaymentReconciler.php` — `reconcileOrder( int $orderId ): void`, **идемпотентен**. Порядок блокировок:
  заявка → сеанс → запись оплаты. Для каждой связи заказа:
  | Состояние заказа | Действие |
  |---|---|
  | `is_paid()` и связь не `paid` | связь → `paid`; `ExamHoldService::convert( application_id )` |
  | `is_paid()` и связь уже `paid` | ничего (повтор события) |
  | заказ `failed` / `cancelled` | связь → `failed` / `cancelled`; **заявку не трогать, если она уже `confirmed` по другой оплате**; иначе место остаётся до `hold_expires_at` |
  | заказ возвращён вручную в WooCommerce (`refunded`) при подтверждённой записи | запись не удалять; outbox `ReconcileFailed` («расхождение»), разбор сотрудником |
  Второй оплаченный заказ по уже подтверждённой заявке: связь → `paid`, заявка без изменений, outbox `PaidNeedsResolution` с причиной «лишняя оплата».
  Ошибка базы во время `convert()` — исключение наружу не глотать: связь остаётся `pending`, `last_reconciled_at` обновляется, outbox `ReconcileFailed`;
  повтор сделает тик (11a.8). До успешного завершения гостю не показывается «Вы записаны».
- [x] 11a.5.5 Хуки (в `WooExamController`, только при активном WooCommerce): `woocommerce_payment_complete` и `woocommerce_order_status_changed` →
  `reconcileOrder( $orderId )`. Оба хука могут прийти дважды и в любом порядке — безопасность даёт идемпотентность 11a.5.4.
- [x] 11a.5.6 Блок статуса на существующей странице «Спасибо» (`woocommerce_thankyou`, **страница не заменяется**): для каждой экзаменной позиции —
  статус записи и данные сеанса:
  | Состояние заявки | Текст |
  |---|---|
  | `confirmed` | «Оплата получена. Вы записаны.» + дата, время, адрес, кабинет + «Ссылку для входа на экзамен выдаст сотрудник на площадке.» |
  | `payment_pending`, `awaiting_payment` | «Подтверждение оплаты ещё не получено. Не оплачивайте повторно.» + кнопка «Проверить статус» |
  | `paid_needs_resolution` | «Оплата получена, запись пока не подтверждена. Сотрудник свяжется с вами.» + номер заказа и контакт центра |
  | `expired_unpaid`, `failed` | «Время брони истекло» / «Оплата не подтверждена» + «Проверьте оплату, затем выберите дату заново.» |
  Блок виден **только владельцу заказа** — проверка ключа заказа из адреса (`$order->key_is_valid( $_GET['key'] )` через шлюз) или авторизованного владельца.
  По одному номеру заказа блок не показывается.
- [x] 11a.5.7 «Проверить статус» — публичный экшен `CheckExamApplicationStatus` (`order_id`, `key`; nonce `ExamGuest`): проверка ключа заказа →
  `reconcileOrder()` → текущий статус. Кнопки «Я оплатил» и повторной оплаты нет. Поиск по телефону или номеру заказа без ключа невозможен.
- [x] 11a.5.8 Снимок суммы и товара в `exam_payment_links` не меняется при смене настроек товара или цены (критерий 48).
- [ ] 11a.5.9 Поддержка блочного оформления (Store API) не делается; записать это ограничением в `HANDOFF.md`.

**Тесты**
- `tests/Unit/Services/Exam/Payment/ExamPaymentReconcilerTest.php` (шлюз и сервисы — моки):
  `test_paid_order_converts_live_hold`, `test_processing_paid_order_is_enough`, `test_on_hold_and_pending_do_not_confirm`,
  `test_zero_total_coupon_order_confirms`, `test_repeat_event_is_noop`,
  `test_late_payment_with_free_seat_confirms`, `test_late_payment_without_seat_becomes_needs_resolution`,
  `test_old_failed_order_does_not_cancel_confirmed_registration`,
  `test_second_paid_order_for_confirmed_application_goes_to_manual_resolution`,
  `test_manual_refund_in_woo_flags_discrepancy_and_keeps_attempt`,
  `test_db_failure_keeps_link_pending_and_reports`,
  `test_payment_after_staff_cancel_does_not_resurrect_registration`.
- `WooExamAdapterTest.php`: `test_status_block_requires_valid_order_key`, `test_status_check_by_order_number_only_is_denied`,
  `test_link_snapshot_keeps_amount_and_product`.
- Стенд, сценарий `pay-vs-release`: параллельно `reconcileOrder`, тик освобождения броней и отмена сотрудником для одной заявки →
  один согласованный исход, `occupied_count` не уходит в минус и не удваивается.
- e2e на dev (купон 100%): форма → корзина → оформление гостем → страница «Спасибо» показывает «Оплата получена. Вы записаны.»;
  в базе одна запись `confirmed`, `occupied_count` сеанса вырос на 1 (а не на 2).

**Готово, когда:** тесты, стенд и e2e пройдены. Проверка на настоящем шлюзе (тестовый режим ЮKassa) — условие приёмки этапа 13, не этого пункта.

---

## 11a.8 Минутный тик: брони и сверка

**Зачем.** Истёкшие брони освобождаются, а оплаты, подтверждение которых не дошло хуком, досверяются (SPEC §6, критерий 44).

**Проверить перед началом:** `ExamTickService::releaseHolds()` и заготовка `reconcilePayments()` (9.6.1).

**Шаги**
- [x] 11a.8.1 `ExamTickService::reconcilePayments()`: связи `payment_state = pending` старше 2 минут (`listPendingForReconcile()`), не более 50 за тик →
  `ExamPaymentReconciler::reconcileOrder( wc_order_id )`; ошибка одной связи не останавливает остальные.
- [ ] 11a.8.2 Связи заявок в `paid_needs_resolution` повторно не сверяются каждую минуту: только обновляется `last_reconciled_at`
  раз в 15 минут (чтобы очередь показывала актуальное «время последней сверки»); уведомление не повторяется (9.5.2).
- [x] 11a.8.3 Истёкшая бронь с **оплаченным** заказом: порядок тика — сначала сверка оплат, потом освобождение броней; так оплата,
  пришедшая за секунду до истечения, успевает подтвердить живую бронь. Поменять порядок шагов в `releaseHolds()` (и докблок 9.6.2).
- [x] 11a.8.4 При выключенном WooCommerce `reconcilePayments()` ничего не делает и не падает.

**Тесты** — `ExamTickServiceTest.php`: `test_reconcile_runs_before_hold_release`, `test_reconcile_skips_when_woo_inactive`,
`test_reconcile_continues_after_single_failure`; `ExamPaymentReconcilerTest.php`: `test_reconcile_twice_gives_same_state`.

**Готово, когда:** тесты зелёные; на dev: создать заказ, не дожидаясь хука вручную перевести его в «Обработка» через
`wp wc shop_order update <ID> --status=processing --user=1`, выполнить `wp fs-lms exam tick --name=hold-release` → заявка `confirmed`.

---

## 11a.7 «Добавить гостя на месте»

**Зачем.** Сотрудник оформляет заявку гостя на площадке; оплата идёт тем же потоком, обхода оплаты нет (SPEC §6, решение 28, критерий 55).

**Проверить перед началом**
- Экран сеанса (8.1), право `ManageExamGuests`, `GuestApplicationService::apply()` с параметром `$staffUserId`.
- Корзина WooCommerce привязана к сессии браузера: заявку создаёт сотрудник, а платит гость со своего устройства. Поэтому нужна **ссылка на оплату**.
- `ExamTokenPurpose` (0.5) — назначения `Invitation`, `Entry`, `Result`, `Report`. Для ссылки на оплату добавляется пятое: `Payment` (`payment`),
  `target_id = application_id`. Это дополнение контракта: внести кейс в README §7.2.

**Шаги**
- [x] 11a.7.1 `ExamTokenPurpose::Payment`. Экшен `AddExamGuestOnSite` в `ExamConductCallbacks` (`session_id`, `source_id`, поля участника, флаги согласий,
  `request_key`): право `ManageExamGuests` + `canManageEvent()`. Источник обязателен: для «общих» заявок организатор заводит источник с данными центра (SPEC §6).
  Согласия сотрудник отмечает со слов участника — те же типы, что в форме.
- [x] 11a.7.2 Перед созданием — кандидаты на дубль (`duplicateCandidates()` по хешам введённых ФИО и телефона): если есть, ответ `needs_confirmation`
  со списком (ФИО, сеанс, состояние); сотрудник подтверждает «Это другой человек» (повтор запроса с `confirmed = 1`) или отменяет.
- [x] 11a.7.3 Бронь: `GuestApplicationService::apply( …, $staffUserId )` → `capture()` с `created_by_user_id`: срок брони = `min( сейчас + срок, planned_end_at )`
  (3.4.3). После планового конца сеанса добавление невозможно. Лимиты по IP к заявке сотрудника не применяются; лимит источника — применяется.
- [x] 11a.7.4 Ссылка на оплату: `ExamAccessTokenService::issue( Payment, application_id, actor, hold_expires_at )` → адрес
  `home_url( '/exam-signup/' ) . '?pay=' . $plain`. Ответ экшена: `pay_url` (один раз), `hold_expires_at`, `seconds_left`. Сотрудник копирует адрес
  (или показывает QR-код: если готового генератора в проекте нет — только копирование, QR не добавлять).
- [x] 11a.7.5 Обработка `?pay=` в `GuestApplicationCallbacks::handleInvitationPage()` (11a.2.3): `exchange( Payment, pay )` → заявка действующая и держит место →
  `WooExamAdapter::addToCart()` → редирект в корзину. Недействительный ключ, истёкшая или отменённая заявка → обычная 404. Те же заголовки
  `noindex` / `no-store` / `no-referrer` и тот же лимит неудачных проверок ключа. Повторное открытие ссылки вторую позицию в корзину не кладёт (11a.4.7).
- [x] 11a.7.6 Кнопок «отметить оплаченным», «записать бесплатно», «наличные» **нет**. Бесплатный допуск — только купон WooCommerce, который создаёт владелец;
  заказ с нулевой суммой подтверждает запись как обычный (11a.5.1).
- [x] 11a.7.7 На экране сеанса после добавления — строка гостя «Место удерживается до {время}» с оставшимся временем (8.8.1) и действием «Скопировать ссылку на оплату»
  (перевыпуск ключа: старый отзывается).

**Тесты**
- `ExamConductCallbacksTest.php`: `test_add_guest_requires_manage_exam_guests`, `test_add_guest_denied_after_planned_end`,
  `test_add_guest_returns_duplicate_candidates_before_creating`, `test_add_guest_returns_pay_url_once`.
- `GuestApplicationCallbacksTest.php`: `test_pay_link_adds_item_and_redirects_to_cart`, `test_pay_link_for_expired_hold_is_404`,
  `test_pay_link_token_of_other_purpose_is_404`.
- `ExamHoldServiceTest.php`: `test_staff_on_site_expiry_is_capped_by_planned_end` (уже есть в 3.4).
- Поиск обхода оплаты: `grep -rn "mark.*paid\|setPaid\|free_admission" inc/Callbacks/Exam inc/Services/Exam` → пусто.

**Готово, когда:** тесты зелёные; на dev: сотрудник добавляет гостя, открывает ссылку на оплату в другом браузере, оформляет заказ с купоном →
строка гостя на экране сеанса становится «Оплата получена».

---

## Проверка этапа (SPEC §18: 38–57 без входа гостя)

- [ ] Гонка ученик/гость за последнее место — один победитель.
- [ ] Повтор клика и две вкладки → одна бронь.
- [ ] Оплата без возврата из банка подтверждает запись; возврат из банка без оплаты — нет.
- [ ] Поздняя оплата: при свободном сеансе подтверждает, при занятом — «Оплачено, требуется помощь», без сверхброни.
- [ ] Заказ с нулевой суммой по купону подтверждает запись.
- [ ] Форма открывается и отправляется с VPN без капчи; 30 человек с одного IP проходят; 41-я активная бронь с IP получает понятный отказ.
- [ ] Без ключа и с чужим ключом — 404; перевыпуск требует модального подтверждения и отключает старую ссылку.
- [ ] `lms_office` видит очередь оплат, но не создаёт проведения.
- [ ] Оформление, письма и шлюз WooCommerce не изменены (`git diff` не содержит правок шаблонов и настроек магазина).
- [ ] Осмотр оформления заказа на проде вместе с владельцем (тестовый режим шлюза) — записать результат в `NOTES.md`.
- [ ] `npm run ci`, `npx gulp build` — зелёные. Новые элементы — в `../QA.md`.
