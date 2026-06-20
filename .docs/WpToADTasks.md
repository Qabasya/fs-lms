# WP → AD: создание учёток в Active Directory через заявки

> Концепция и план реализации интеграции: заявка ученика на сайте → мгновенное создание
> учётной записи в домене Active Directory.
>
> **⚠️ Архитектура — PULL** (см. §6 «Этап 2»): домен-контроллер с Python в локальной сети без белого IP,
> поэтому **Python сам опрашивает публичный WP** (`GET /ad/jobs`, `POST /ad/ack`, `GET /ad/active-usernames`),
> а не WP пушит в Python. Реализованный контракт — раздел «Реализованные REST-эндпоинты» + `FS_LMS_API.md`.
> Документ — источник правды по этой фиче. Сопутствующее: `explain.md`, `basic_doc.md`.

---

## 0. TL;DR

- Ученик очно заполняет заявку (`/lms/apply`) → после email-OTP заявка создаётся → WP кладёт PII-free
  задание в очередь → **Python-сервис из локалки сам забирает его** (`GET /ad/jobs`) и создаёт учётку в AD
  с **логином и паролем из формы** → ученик через несколько секунд входит в домен (фронт показывает спиннер
  и опрашивает статус → «Готово, входите»).
- Логин/пароль ученик задаёт сам (поля уже есть в форме). Пароль постоянный, **один для WP и AD**,
  смена запрещена (set-once). Парольной политики нет.
- **PULL, не push:** у домен-контроллера нет белого IP, поэтому инициатор — Python (исходящий HTTPS).
  Ретраи — by design (failed-задания переотдаются очередью; отдельный WP-cron не нужен).
- Надёжность: **outbox-очередь (пароль не хранится)** + статус-поллинг на фронте + reconcile-сверка.
- Спам-защита: direction-код + honeypot + тайминг + captcha + rate-limit + email-OTP, плюс на стороне AD —
  ограниченная OU + авто-истечение неподтверждённых.
- **Вся AD-часть — отдельный модуль** `Inc\Modules\AdSync`, отключаемый одним тумблером и удаляемый
  из релиза удалением каталога + одной строки. Ядро о модуле не знает (связь только через хуки).

---

## 1. Концепция и поток

### 1.1. Бизнес-сценарий
Очный приём: ученик за компьютером в центре заполняет заявку и **почти сразу** должен войти в доменную
учётку на этом компьютере. Поэтому задание провижна ставится **в момент создания заявки** (не при
зачислении), а Python из локалки забирает его в течение секунд (частый поллинг) — аккаунт появляется,
пока ученик дочитывает экран успеха. Форма заполняется с телефона или гостевой учётки того же компьютера.

### 1.2. OU vs Security Group — две разные сущности (важно)
- **OU (Organizational Unit)** — контейнер-«папка» в дереве каталога; к ней применяются GPO (политики/настройки),
  делегирование. Объект ровно в **одной** OU. **Не** «группа пользователей».
- **Security Group** — список членов для выдачи **прав** (ACL на ресурсы); пользователь — в **многих** группах.

**Модель:** OU = **жизненный цикл** (`Pending`/`Active`/`Disabled`), Security Group = **направление**
(`subject_key → группа(ы)` = права + настройки через ACL и security-фильтр GPO). «Резолв по направлению» =
**добавление ученика в нужную security-группу** по `subject_key`. Несколько групп на направление — ок.

### 1.3. Жизненный цикл учётки AD
| Фаза | Триггер в WP | Действие в AD |
|---|---|---|
| **provision** | заявка создана (`application_created`, после OTP) | создать в `OU=Pending`, включён, пароль из формы; **добавить в security-группу направления** (Python выбирает по `subject_key`) |
| **promote** | зачисление (`student_enrolled`) | перенести в `OU=Active`; при необходимости — доп. группы зачисленного |
| **deprovision** | заявка истекла/в корзину, удаление person, отчисление | disable + перенос в `OU=Disabled` (логин освобождён, аудит сохранён); опц. убрать из групп |
| **reconcile** | по расписанию Python | Python забирает `GET /ad/active-usernames` (список «кто должен жить») → отключает в управляемой OU всё, чего нет в списке |

**Почему так решает «удалили из БД, а логин остался»:** deprovision на событиях (истечение/корзина) +
регулярная сверка-«пылесос» (`active-usernames`) отключает всё лишнее, даже если событие потерялось.

### 1.4. Диаграмма потока (PULL)
```
WP (публичный сайт)                              Python (локалка, без белого IP)
────────────────────                             ──────────────────────────────
[Ученик] → apply (логин+пароль+код направления)
   │ email-OTP
   ▼
ajaxCreateApplication ─► do_action('fs_lms_application_created', id)
   │                          │ (ядро НЕ знает про AD)
   │                          ▼
   │                  [AdSync] enqueue outbox(provision)   ← PII-free (без пароля)
   ▼
apply-ответ: apply_filters('fs_lms_apply_response') → notice + poll
   │
   ▼  (фронт: спиннер + опрос fs_lms_ad_status)        ┌───── цикл поллинга ─────┐
   │                                                    │ GET /ad/jobs (HMAC)     │ ◀── исходящий HTTPS
outbox: pending ──────────────────────────────────────▶│ → ldap3: create в AD    │
   │                                                    │ POST /ad/ack (done)     │
outbox: sent ◀──────────────────────────────────────── └─────────────────────────┘
   ▼
статус-опрос видит done → «Готово, входите» → [Ученик] логинится в домен
                                                    (по расписанию) GET /ad/active-usernames → disable лишних
```
Если задание не выполнено — остаётся `pending`/`failed` (с backoff) и переотдаётся при следующем поллинге;
экран показывает «создаётся…», далее — финальный статус. WP наружу не ходит.

---

## 2. Принцип изоляции (модуль как отдельный сервис)

Главное требование: AD-часть должна **легко вырезаться/отключаться**. Достигается тремя правилами.

### 2.1. Однонаправленная зависимость
- **Ядро → модуль: НИ ОДНОЙ ссылки.** В `inc/` (кроме `inc/Modules/AdSync/`) нельзя `use Inc\Modules\AdSync\*`.
- **Модуль → ядро: можно** через публичные сервисы (`PiiCryptoService`, `ApplicationRepository`,
  `PluginConfigRepository`, `PersonRepository`) и WP-хуки.
- Это правильное направление: стабильное ядро + опциональный аддон поверх него (как WP-плагин поверх ядра WP).

### 2.2. Точки расширения в ядре (generic-хуки, НЕ AD-специфичные)
Единственная правка ядра — добавить несколько **обобщённых** WP-хуков. Без модуля они no-op.

| Хук | Где фитится | Назначение |
|---|---|---|
| `do_action('fs_lms_application_created', int $applicationId)` | `ApplicationCallbacks::ajaxCreateApplication` после `createApplication()` | сигнал «заявка создана» |
| `apply_filters('fs_lms_apply_response', array $resp, int $applicationId)` | там же, перед `success()` | модуль вписывает статус AD/сообщение в ответ |
| `do_action('fs_lms_student_enrolled', int $recordId, int $personId)` | после зачисления | promote |
| `do_action('fs_lms_application_expired', int $applicationId)` | `expireStale()` | deprovision |
| `do_action('fs_lms_application_trashed', int $applicationId)` | trash-флоу | deprovision |
| `do_action('fs_lms_person_deleted', int $personId, string $type)` | `Deletion/*Handler` | deprovision |

> Альтернатива — подписка на существующий `LogEventDispatcher`. Но WP-хуки выбраны потому, что
> (а) позволяют **вписать данные в ответ** (`apply_filters`), (б) не требуют регистрировать модуль во
> внутреннем реестре подписчиков ядра — чище для «вырезать».

### 2.3. Feature-flag — «одна кнопка» (3 уровня)
1. **Runtime:** `fs_lms_plugin_config['ad_sync_enabled']` (тумблер в админке). `AdSyncModule::register()`
   при `false` сразу `return` — ноль хуков, ноль cron, таблица не трогается.
2. **Deploy:** константа `FS_LMS_AD_SYNC` в `wp-config.php` (если определена и `false` — жёсткий оффлайн,
   перекрывает тумблер; удобно для стейджа/прод-разделения).
3. **Release:** удалить каталог `inc/Modules/AdSync/` + **одну строку** `AdSyncModule::class` в
   `Init::getServices()`. Generic-хуки ядра остаются (безвредны). Таблицу `fs_lms_ad_outbox` — опционально дропнуть.

### 2.4. Структура каталога модуля (фактическая)
```
inc/Modules/AdSync/
├── AdSyncModule.php                  — bootstrap (ServiceInterface): флаг-гейт + регистрация
├── Controllers/
│   ├── AdSyncSettingsController.php  — config-UI (через хук ядра) + AJAX-save + enqueue своего JS (всегда)
│   ├── AdSyncController.php          — рантайм-хуки: enqueue provision/deprovision/promote + статус-AJAX
│   └── AdSyncRestController.php      — REST для Python (pull): /ad/jobs, /ad/ack, /ad/active-usernames
├── Services/
│   ├── AdProvisioningService.php     — enqueue + pendingJobs (расшифровка) + ack + statusForApplication
│   ├── AdReconcileService.php        — список «кто должен жить» (активные записи + живые заявки)
│   └── AdHmacAuth.php                — проверка HMAC-подписи REST-запросов
├── Repositories/
│   └── AdOutboxRepository.php        — CRUD fs_lms_ad_outbox
├── Schema/
│   └── AdSchema.php                  — своя таблица (version-gated dbDelta)
├── DTO/
│   └── AdOutboxItemDTO.php           — строка очереди (PII-free)
├── Enums/
│   ├── AdSyncEvent.php               — provision|promote|deprovision
│   └── AdOutboxStatus.php            — pending|sent|failed|dead
├── Config/
│   └── AdSyncConfig.php              — своя опция fs_lms_ad_sync (enabled/ttl) + isEnabled/hmacSecret
├── templates/settings-section.php    — секция «Синхронизация с доменом (AD)»
└── assets/admin.js                   — admin-JS секции (вне core-бандла и вне ESLint)
```
Namespace `Inc\Modules\AdSync\…`. Своя таблица, своя опция, своя секция конфига, свой JS, свои REST-маршруты.
*(Push-клиента `AdHttpClient` нет — отменён при пивоте на pull; cron на стороне WP не нужен.)*

### 2.5. Контрольный список «полностью вырезать»
- [ ] удалить `inc/Modules/AdSync/`
- [ ] убрать `AdSyncModule::class` из `Init::getServices()`
- [ ] (опц.) DROP `fs_lms_ad_outbox` + строки cleanup в миграции
- [ ] (опц.) убрать секцию `ad_sync_*` из `fs_lms_plugin_config`
- generic-хуки ядра и Stage 1 (направления) — **оставить**, они самостоятельны

---

## 3. Что меняется в плагине

### 3.1. Ядро — минимальные generic-сеймы (не AD-специфичны)
- Добавить 6 хуков из §2.2 в соответствующие места (по 1 строке).
- Больше ядро **ничего** про AD не знает.

### 3.2. Stage 1 — привязка заявки к направлению (ядро, нужно и без AD)
Самостоятельная фича; AD-модуль кладёт `subject_key` в задание, а группу направления выбирает Python.
- **Миграция:** `+ subject_key varchar(50) DEFAULT NULL` в `fs_lms_applications` (правка `Migration_1_0_0` +
  cleanup, сброс `fs_lms_schema_version` по dev-протоколу).
- **Конфиг:** `applications_bind_to_subject: bool` + `direction_codes: {subject_key: code}` в `fs_lms_plugin_config`.
- **UI «Настройка заявок»:** `fs-toggle` + строки «предмет → код».
- **apply:** при включённом тумблере — модалка «Введите код направления»; **серверная** валидация
  код→`subject_key`; запись в заявку.
- **Зачисление:** предвыбор предмета из `application.subject_key`.

### 3.3. Модуль AdSync — состав (см. §2.4)
- `AdSyncModule` в `Init::getServices()` (одна строка).
- Подписка на generic-хуки → оркестрация через `AdProvisioningService`.

### 3.4. Таблица outbox (пароль не хранится)
`fs_lms_ad_outbox` — **своя таблица модуля** (`Schema/AdSchema`, не в core `TableName`/`Migration`):
| Колонка | Назначение |
|---|---|
| `id` | PK |
| `event` | provision\|promote\|deprovision |
| `application_id` / `person_id` | ссылка на источник (для provision — заявка; для promote — person) |
| `target` | AD-username для deprovision/promote (резолвится при enqueue; для provision — null) |
| `idempotency_key` | `app:N` (provision) / `deprovision:app:N` / `promote:person:N` |
| `status` | pending\|sent\|failed\|dead |
| `attempts` / `next_attempt_at` / `last_error` | ретраи (backoff) и диагностика |
| `created_at` / `sent_at` | тайминги |

**Пароль в очереди не хранится.** Для `provision` логин/пароль расшифровываются из блоба заявки
`student_data_enc.login_password` (через `PiiCryptoService` + `ApplicationRepository`) **в момент выдачи
задания** (`GET /ad/jobs`). Для `deprovision`/`promote` пароль не нужен — только `username` (из `target`).

### 3.5. Конфиг (своя опция `fs_lms_ad_sync`, владеет модуль)
`enabled` (bool) — и всё. **Карта `предмет → группа` в WP НЕ хранится** — Python решает группу по
`subject_key` из `provision`-задания. **URL тоже не хранится** (в pull инициатор Python). OU жизненного
цикла и срок жизни учёток (TTL) — на стороне Python (чистка — через reconcile, не по таймеру в WP).
Секрет HMAC — **в `wp-config.php`** (`FS_LMS_AD_HMAC_SECRET`), генерируется кнопкой в UI, не в опции.
UI-секция «Синхронизация с доменом (AD)»: тумблер + генератор секрета (define для wp-config + raw для `.env`).

**Зависимость от «Привязки к направлению» (защита от пустого `subject_key`):** AD-провижн требует `subject_key`,
а он появляется только при включённой «Привязке заявки к направлению». Поэтому AD нельзя включить/работать без неё —
гейт на 3 уровнях: (1) UI — тумблер AD заблокирован + предупреждение, если привязка выключена; (2) сервер —
`ajaxSaveSettings` отклоняет включение AD при выключенной привязке; (3) runtime — `AdSyncModule::register()`
не поднимает рантайм (хуки/REST), если `ApplicationSettingsService::isBindToSubject()` == false (модуль инертен).
Слияния секций нет: «Настройка заявок» — core-фича (живёт без AD), AD — опциональный модуль поверх неё.

### 3.6. Безопасность канала (pull)
- Только HTTPS на сайте WP. На **каждом** запросе Python шлёт заголовки `X-Fs-Timestamp` +
  `X-Fs-Signature = hex(hmac_sha256("{ts}.{rawBody}", FS_LMS_AD_HMAC_SECRET))` (для GET body=`""`).
- WP (`AdHmacAuth`) отвергает при `|now - ts| > 300s` (анти-replay) и сверяет подпись `hash_equals`.
- IP-allowlist **не применяется** — у локалки нет фиксированного белого IP; защита — секрет + HTTPS.
- Эндпоинт `/ad/jobs` отдаёт пароль (нужен Python для создания учётки) — только под HTTPS, не логируется;
  в очереди пароль не хранится (перечитывается из блоба при выдаче).

---

## 4. Что на стороне Python (поллер в локалке)

> ⚠️ **Pull:** Python — НЕ сервер с эндпоинтами, а **клиент-поллер**: он опрашивает REST WP
> (эндпоинты — на стороне WP, см. раздел «Реализованные REST-эндпоинты» и `FS_LMS_API.md`).
> Рекомендую **httpx**/**requests** + **ldap3** (LDAP/LDAPS) + планировщик (APScheduler/cron).

### 4.1. Поллер (что делает Python)
| Цикл | Запрос к WP | Действие в AD |
|---|---|---|
| задания (часто, ~2–5с) | `GET /ad/jobs` → по `event` | `provision`: создать в `OU=Pending`, вкл., пароль из формы, add member в группу направления (Python выбирает по `subject_key`) · `deprovision`: disable + `OU=Disabled` · `promote`: `OU=Active` |
| отчёт | `POST /ad/ack` `{id,status,error?}` | — (WP: markSent/markFailed) |
| сверка (реже, напр. раз в час/сутки) | `GET /ad/active-usernames` | отключить в управляемой OU всех, кого нет в списке |

### 4.2. Операции в AD (ldap3)
- Bind **сервис-аккаунтом наименьших прав** (делегирование только на управляемую OU).
- `add`: `objectClass=user`, `sAMAccountName`, `userPrincipalName=username@domain`, `cn`, `displayName`.
- Пароль: атрибут `unicodePwd` (UTF-16LE в кавычках) — **только по LDAPS (636)**.
- Включение: `userAccountControl = 512` (норм. включён) / `514` (disabled).
- (опц.) TTL целиком на стороне Python, если нужен; основная чистка — через reconcile (см. §4.1).
- Перенос OU (жизненный цикл): `modify_dn`.
- **Членство в группе (направление):** `modify` атрибута `member` группы — добавить/убрать DN пользователя
  (`MODIFY_ADD`/`MODIFY_DELETE`). `memberOf` напрямую не пишется — оно вычисляемое.
- **Карту `предмет → DN группы` держит Python** (env/конфиг), ключ — `subject_key` из задания.

### 4.3. Идемпотентность
- `idempotency_key` приходит в задании (`app:N` / `deprovision:app:N` / `promote:person:N`). Если учётка уже
  есть → **update**, не дубль. Можно опираться на состояние AD (учётка по `sAMAccountName`/кастом-атрибуту).
- Задание не повторно: после успеха Python шлёт `ack(done)` → WP помечает `sent`, и оно больше не выдаётся.
  При сбое — `ack(failed)` (или вообще без ack) → задание переотдастся (backoff).

### 4.4. Безопасность
- Python подписывает запросы HMAC+timestamp (см. §3.6). Секрет — в env, не в коде.
- Сервис-аккаунт AD: права только на свою OU; пароль аккаунта — в env/secrets.

### 4.5. Логи/аудит
- Структурный лог каждого запроса (action, person_id, результат) — **без пароля**.
- Метрика «создано/отключено/ошибок» для мониторинга.

### 4.6. ⚠️ Прерогатива AD-админа
- **Парольная политика домена.** Чтобы принимать простые пароли («123»), на управляемой OU нужна
  **Fine-Grained Password Policy** с отключённой сложностью/мин. длиной. Иначе AD отвергнет слабый пароль
  при создании, и провижн упадёт. WP политику не проверяет — значит её должен ослабить AD.
- OU-структура жизненного цикла (`Pending`/`Active`/`Disabled`) + **security-группы по направлениям**
  (их DN → в конфиг Python), сервис-аккаунт, LDAPS-сертификат.

---

## 5. Контракт API

Реализованный контракт (pull) — ниже в разделе **«Реализованные REST-эндпоинты (pull)»** и в отдельном
файле **`.docs/FS_LMS_API.md`** (полное описание + пример клиента на Python/FastAPI-окружении).
Старая push-схема (`POST /provision` на Python) **отменена** — см. пивот в §0/§6 (Этап 2).

---

## 6. Задачи реализации

### Этап 0 — ядро-сеймы + Stage 1 (направления)  `[x]` готово
- [~] 0.1 Generic-хуки §2.2: добавлено 4/6 — `fs_lms_application_created`, `fs_lms_apply_response`
      (в `ApplicationCallbacks::ajaxCreateApplication`), `fs_lms_student_enrolled` (`EnrollmentService`),
      `fs_lms_application_expired` (`ApplicationService::expireStale`). **Отложено:** `fs_lms_application_trashed`
      (3 сайта в `EnrollmentCallbacks`) + `fs_lms_person_deleted` (`Deletion/*Handler`) — добавить при wiring
      деправижна (Этап 3), где есть потребитель и проверяемый payload.
- [x] 0.2 Миграция: `+ subject_key varchar(50)` в `fs_lms_applications` (+ KEY, + cleanup); схема применена.
- [x] 0.3 `ApplicationInputDTO` + `ApplicationRecordInputDTO` + `ApplicationService::createApplication` — `subject_key` сохраняется.
- [x] 0.4 Конфиг: `applications_bind_to_subject` + `direction_codes` в `PluginConfigRepository` + `ConfigCallbacks`
      (+ `ApplicationSettingsService` — типизированный доступ + резолвер кода→subject_key).
- [x] 0.5 UI «Настройка заявок»: раздел в табе конфигурации (`fs-toggle` + строки предмет→код) + `viewState`
      + admin JS (`config-settings.js` собирает `direction_codes`) + SCSS.
- [x] 0.6 apply: серверная валидация кода→`subject_key` (`ajaxCreateApplication`) + **клиентская модалка-гейт**
      (`apply.php` + `apply-form.js` + SCSS); ранняя валидация через `ValidateDirectionCode` (nopriv, `Nonce::Apply`).
- [x] 0.7 Зачисление: предвыбор предмета из `application.subject_key` (`ApplicationDTO` + `ajaxGetApplicationData`
      возвращает `subject_key` → `#enroll-subject` предвыбирается с `trigger('change')`).
- [x] 0.8 Тесты: `ApplicationSettingsServiceTest` (резолвер, 6) + `ApplicationSubjectKeyTest` (запись/чтение, 4).
      Предвыбор — JS (без юнит-теста).

> **Чекпоинт (Этап 0 завершён):** PHPUnit **420/420** зелёные, lint:js + gulp build чистые, контейнер перезапущен.
> Сделано: миграция+колонка, DTO/сервис, конфиг+резолвер+UI, серверная+клиентская валидация кода (модалка-гейт),
> предвыбор предмета при зачислении, 4 generic-хука, тесты. **Отложено в Этап 3** (деправижн): хуки
> `fs_lms_application_trashed` + `fs_lms_person_deleted`.

### Этап 1 — скелет модуля + флаг  `[x]` готово
- [x] 1.1 Каталог `inc/Modules/AdSync/` (Config/Controllers/Callbacks/templates/assets), namespace `Inc\Modules\AdSync`, PSR-4 (`composer dump-autoload`), рантайм-автозагрузка проверена.
- [x] 1.2 `AdSyncModule` (ServiceInterface): флаг-гейт `AdSyncConfig::isEnabled()` (опция `fs_lms_ad_sync.enabled` + константа `FS_LMS_AD_SYNC` перекрывает); 1 строка в `Init::getServices()`. Config-UI регистрируется всегда, рантайм — только при включённом флаге.
- [x] 1.3 Конфиг `ad_sync_*` (своя опция `fs_lms_ad_sync`: только `enabled`) + UI-секция «Синхронизация с доменом (AD)» — рендерится **через generic-хук ядра `fs_lms_config_sections`** (ядро о модуле не знает); генератор HMAC-секрета (define + raw для `.env`); свой AJAX-save (`fs_lms_ad_sync_save`) + свой admin-JS (вне core-бандла) + свой enqueue. *(Карта групп в WP не хранится — её решает Python.)*
- [x] 1.4 Константы `FS_LMS_AD_SYNC` + `FS_LMS_AD_HMAC_SECRET` задокументированы в `basic_doc.md` (wp-config); секрет читается `AdSyncConfig::hmacSecret()`, в БД не хранится. **Секрет генерируется кнопкой в UI секции AD** (клиентский `crypto.getRandomValues` → строка `define()`; копирование — core `.js-copy-key`).
- [x] 1.5 Изоляция подтверждена: `Inc\Modules\AdSync` упоминается в ядре **только в `Init`** (use + 1 строка регистрации) — единственная точка связи; всё прочее — через хуки.

> **Чекпоинт (Этап 1 завершён):** PHPUnit **420/420**, PHP-lint чист, модуль автозагружается, гейт по умолчанию выкл.
> Изоляция: один generic-хук в ядре (`fs_lms_config_sections`) + одна строка в `Init`. Вырезается удалением
> каталога `inc/Modules/AdSync/` + строки в `Init`. Дальше — **Этап 2** (outbox + provision, pull).

### Этап 2 — provision (**pull-модель**)  `[x]` готово
> ⚠️ **Пивот push → pull.** Домен-контроллер с Python в локальной сети **без белого IP** → WP не может
> достучаться (push невозможен). Инициатор — **Python**: из локалки исходящим HTTPS опрашивает публичный
> WP, забирает задания и отчитывается. WP наружу не ходит. (Прошлый выбор «push» из AskUserQuestion отменён
> топологией сети.)

- [x] 2.1 Таблица `fs_lms_ad_outbox` — **внутри модуля** (`Schema/AdSchema::ensure()`, version-gated своей опцией),
      **не** в core `TableName`/`Migration` (§3.4 — модуль владеет данными). Создаётся лениво при включении.
- [x] 2.2 `AdOutboxRepository` (enqueue/markSent/markFailed+backoff/find/**listPending**/latestByApplication)
      + `AdOutboxItemDTO` (PII-free) + enum'ы `AdSyncEvent`/`AdOutboxStatus`.
- [x] 2.3 **REST для Python (pull)**: `AdSyncRestController` — `GET /wp-json/fs-lms/v1/ad/jobs` (забрать задания
      с логином/паролем/группой), `POST /wp-json/fs-lms/v1/ad/ack` (отчёт done/failed). Аутентификация —
      `AdHmacAuth` (`X-Fs-Timestamp` + `X-Fs-Signature=hmac_sha256(ts."."+body, FS_LMS_AD_HMAC_SECRET)`, ±300с).
      *(push-клиент `AdHttpClient` удалён.)*
- [x] 2.4 `AdProvisioningService`: `enqueueProvision()` (PII-free enqueue), `pendingJobs()` (расшифровка
      `student_data_enc.login_password`/username + `subject_key` для Python), `ack()`,
      `statusForApplication()`. Пароль не логируется и не хранится в очереди.
- [x] 2.5 `AdSyncController` (рантайм, за флаг-гейтом): `fs_lms_application_created` → enqueue;
      `fs_lms_apply_response` → generic-поля `notice` + `poll` (фронт-поллинг); nopriv-AJAX `fs_lms_ad_status`.
      Фронт apply: спиннер + опрос статуса → «Готово, входите» / «обратитесь к администратору» (**тексты — TODO**).
- [x] 2.6 Тесты: `AdProvisioningServiceTest` (8) — enqueue **PII-free**, skip без заявки, состав job
      (логин/пароль/группа/ttl), ack ok/fail, маппинг статусов.

> **Чекпоинт (Этап 2, pull):** PHPUnit **427/427**, lint:js + build чисто. Рантайм проверен end-to-end:
> при включённом флаге — REST-маршруты `/ad/jobs` + `/ad/ack`, статус-AJAX, **без подписи → 401, с валидной HMAC → 200**;
> таблица `fs_lms_ad_outbox` (11 колонок, PII-free); при выключенном — ноль рантайм-хуков/маршрутов (config-UI остаётся).
> Мгновенность = интервал поллинга Python (~секунды) + фронт-спиннер до ответа.
> **TODO (тексты ученику):** `AdSyncController::filterApplyResponse()` (notice) и `ajaxStatus()` (done/failed/pending).
> **Не сделано (Этап 3):** cron-добивание ретраев, deprovision, reconcile.

### Этап 3 — ретраи, deprovision, promote (**pull**)  `[x]` готово
> Под pull смысл части пунктов меняется (см. ниже).

- [x] 3.1/3.2 **Ретраи — by design в pull, без cron.** `ack(failed)` → `markFailed` (backoff `next_attempt_at`,
      `dead` после 6 попыток); `listPending` снова отдаёт failed-задания с наступившим сроком → Python их добирает.
      WP наружу не ходит, отдельный cron-воркер не нужен.
- [x] 3.3 **Deprovision (app-based)**: подписки `fs_lms_application_expired` + `fs_lms_application_trashed`
      (хук добавлен на сайт *trash*, заявка ещё существует) → `enqueueDeprovisionByApplication()` — username
      резолвится из блоба **при enqueue** и кладётся в `target` (устойчиво к удалению заявки); пароль не нужен.
      Person-based удаление/отчисление → закрывается **reconcile (Этап 4)** как надёжный «пылесос».
- [x] 3.4 **Promote**: подписка `fs_lms_student_enrolled` → `enqueuePromoteByPerson()` — username из
      `person → wp_user.user_login`, кладётся в `target`. Python переносит учётку в активную OU.
- [x] Схема v2: + колонка `target` (AD-username для deprovision/promote; dbDelta добавляет на существующих).
      `pendingJobs()` маршрутизирует payload по `event` (provision = creds из блоба; deprovision/promote = только username).
- [x] 3.5 Тесты: `AdProvisioningServiceTest` (9) — provision PII-free, deprovision (target без пароля),
      promote (username из person), ack ok/fail, статусы.

> **Чекпоинт (Этап 3, pull):** PHPUnit **429/429**, lint:js + build чисто, schema v2 (`target`) применяется.
> Один новый generic-хук ядра: `fs_lms_application_trashed` (на сайте trash). Изоляция цела (ядро ↔ модуль
> только через хуки + 1 строка `Init`). **Отложено в Этап 4:** reconcile-эндпоинт (список «кто должен жить»
> → Python отключает лишних, в т.ч. удалённых из БД) — он же закрывает person-based deprovision.

### Этап 4 — reconcile (сверка-«пылесос», **pull**)  `[x]` готово
- [x] 4.1 `AdReconcileService::activeUsernames()` — авторитетный список логинов «кто должен жить»:
      активные `student_records` (status=active → person → `wp_user.user_login`) + живые заявки
      (`pending_parent`/`ready_for_review`/`enrolling` → username из блоба), с дедупом. Core-репозитории
      получили generic-методы `StudentRecordRepository::allActiveStudentPersonIds()` и
      `ApplicationRepository::findByStatuses()`.
- [x] 4.2 **REST `GET /wp-json/fs-lms/v1/ad/active-usernames`** (HMAC) → `{ usernames: [...] }`. Python
      опрашивает по своему расписанию (cron на стороне Python) и отключает в управляемой OU всех, кого нет
      в списке. **WP-cron не нужен.** Это закрывает person-based удаление/отчисление («удалили из БД → логин освободить»).
- [x] 4.3 Тесты: `AdReconcileServiceTest` (3) — оба источника, дедуп, пропуск person без WP-юзера.

> **Чекпоинт (Этап 4, pull):** PHPUnit **432/432**, lint/build чисто. Эндпоинт проверен (signed → 200, ключ `usernames`).
> **WP-сторона интеграции (Этапы 0–4) завершена.** Остаётся §5 — Python-сервис (FastAPI + ldap3) и §6 — E2E.

---

## Реализованные REST-эндпоинты (pull) — контракт для Python

База: `https://<сайт>/wp-json/fs-lms/v1`. Аутентификация на **каждом** запросе (заголовки):
```
X-Fs-Timestamp: <unix>
X-Fs-Signature: hex( hmac_sha256( f"{timestamp}.{raw_body}", FS_LMS_AD_HMAC_SECRET ) )
```
(для GET `raw_body=""`; сервер отвергает при расхождении времени > 300с или неверной подписи → 401).

| Метод | Путь | Назначение | Ответ |
|---|---|---|---|
| `GET`  | `/ad/jobs?limit=50` | забрать задания | `{ "jobs": [ {id,event,idempotency_key, …} ] }` |
| `POST` | `/ad/ack` | отчёт о выполнении | `{ "ok": true }` |
| `GET`  | `/ad/active-usernames` | список «кто должен жить» (сверка) | `{ "usernames": ["i.petrov", …] }` |

**Задание (`/ad/jobs`) по `event`:**
- `provision`  → `{id, event, idempotency_key, username, password, first, last, subject_key}` — создать учётку, добавить в группу направления (**Python выбирает её по `subject_key`**).
- `deprovision`→ `{id, event, idempotency_key, username}` — отключить учётку.
- `promote`    → `{id, event, idempotency_key, username}` — перенести в активную OU (зачислен).

**`/ad/ack` тело:** `{ "id": <job id>, "status": "done"|"failed", "error": "...", "sam_account_name": "..." }`.
Идемпотентность задач — по `idempotency_key` (`app:N` / `deprovision:app:N` / `promote:person:N`).

### Этап 5 — Python-сервис (поллер, pull)  `[ ]`
> Не FastAPI-сервер с эндпоинтами (входящие не нужны) — клиент-поллер в локалке. Контракт и пример — `FS_LMS_API.md`.
- [ ] 5.1 HTTP-клиент с HMAC-подписью (`X-Fs-Timestamp`/`X-Fs-Signature`) + планировщик (APScheduler/cron).
- [ ] 5.2 ldap3-обёртка: connect (LDAPS, сервис-аккаунт), create/update/disable/move OU, **add/remove member группы**, set `unicodePwd`.
- [ ] 5.3 Циклы: `GET /ad/jobs` → обработка по `event` (provision/deprovision/promote) → `POST /ad/ack`;
      по расписанию `GET /ad/active-usernames` → отключить лишних. Идемпотентность по `idempotency_key`.
- [ ] 5.4 Конфиг (env): URL сайта WP, `FS_LMS_AD_HMAC_SECRET`, домен/OU жизненного цикла, сервис-аккаунт.
- [ ] 5.5 Логи/метрики без пароля; деплой (systemd/docker).
- [ ] 5.6 AD-prereq: Fine-Grained Password Policy на OU, OU-структура, сервис-аккаунт (см. §4.6).

### Этап 6 — сквозное + документация  `[~]` документация готова, E2E — за Python
- [ ] 6.1 E2E (на живом домене): заявка → AD-аккаунт → вход; expire → disable; reconcile-«пылесос».
      **Блокируется Этапом 5** (нужен запущенный Python-сервис + домен).
- [~] 6.2 Тест отключения: «тумблер off → ноль рантайм-хуков/маршрутов» **проверено в dev** (Этапы 1–2);
      вырезание каталога — by design (единственная ссылка в `Init`), литерально не вырезали.
- [x] 6.3 Кросс-ссылки добавлены: `explain.md` (шапка) и `basic_doc.md` (раздел констант) → `WpToADTasks.md` + `FS_LMS_API.md`.
- [x] 6.4 **`.docs/FS_LMS_API.md`** — полный REST-контракт (auth/HMAC, `/ad/jobs`, `/ad/ack`,
      `/ad/active-usernames`, коды ошибок, идемпотентность) + рабочий пример клиента-поллера на Python.

> **Документация завершена.** `WpToADTasks.md` приведён к pull целиком (§0/§1.4/§2.4/§3.5–3.6/§4/§5),
> добавлен раздел «Реализованные REST-эндпоинты», создан `FS_LMS_API.md`, проставлены кросс-ссылки.
> Остаётся **Этап 5** (Python-сервис) и **6.1** (E2E) — вне WP.
---

## 7. Открытые вопросы / решения по умолчанию
- **Чистка непревращённых/удалённых аккаунтов:** через `deprovision` + reconcile (TTL в WP убран; при желании — на стороне Python).
- **deprovision:** disable + `OU=Disabled` (не hard-delete) *(по умолчанию)*.
- **promote на зачислении:** включаем (перенос `OU=Pending`→`Active` + доп. группы) — можно отложить в фазу 2.
- **AD username-availability в форме:** позже (эндпоинт `/check-username` на Python), сейчас не делаем.
- **Парольная политика:** WP не проверяет; ослабление политики AD — на стороне домена (§4.6).
- **Синхронизация смен пароля:** не нужна (смена паролей запрещена, set-once).
- **Подтвердить:** домен, OU жизненного цикла, **карта `предмет → DN security-группы` (в конфиге Python)**,
  формат `userPrincipalName`, кастом-атрибут для `person_id`.

---

## Приложение A. Настройка AD-стороны (админ, одноразово)

> Всё ниже настраивается **один раз** администратором в AD/GPO и завязано на **членство в
> security-группе направления**. Плагин/Python в рантайме делает только одно — **кладёт ученика
> в нужную группу** (`subject_key → группа`). Логики политик в коде нет.

### A.1. Группы и базовая раскладка
- На каждое направление — security-группа: `G-Dir-INF`, `G-Dir-MATH`, … (их DN → в конфиг Python).
- Ученики живут в OU жизненного цикла: `OU=Pending` → `OU=Active` → `OU=Disabled`.
- GPO «Students-Directions» линкуется на `OU=Active` (где активные ученики).

### A.2. Доступ к общим папкам (права + видимость)
- **Право (обязательно):** NTFS/Share **ACL** на папке → нужной группе.
  `\\server\FolderA` → `G-Dir-INF` (Modify/Read), `\\server\FolderB` → `G-Dir-MATH`.
- **Видимость (удобство):** GPP → **Drive Maps** с **ILT** «member of G-Dir-INF» → `Z: → \\server\FolderA`.
- Доступ даёт **ACL**; маппинг диска — GPP. Можно и без диска (UNC-ярлык), но диск нагляднее.

### A.3. Ярлыки на рабочем столе
- GPP → **Shortcuts**: на каждый ярлык — **ILT** по группе направления.
- Один GPO, набор ярлыков, каждый виден только своей группе.

### A.4. Приложения в панели Windows (Пуск / таскбар)
- **Раскладка Пуск/панели:** `Export-StartLayout` → GPO «Start Layout» / «Taskbar Layout».
  Применять по направлениям — **отдельный GPO на группу с Security Filtering** (layout-политика
  применяется целиком, поэтому ILT здесь не подходит — нужен GPO-per-group).
- **Ограничение запуска (опц.):** **AppLocker** — правила таргетятся **прямо на security-группу**
  (`G-Dir-INF` → разрешить app X). Чистый способ «кто что может запускать».

### A.5. Нюансы
- **Token refresh:** членство в группе попадает в токен при логоне. Для новой учётки (создали → сразу
  вход) — ок; существующему залогиненному после смены групп нужен повторный вход.
- **Loopback (опц.):** если компьютеры общие (классы) и часть настроек должна зависеть от компьютера,
  а не пользователя — включить Group Policy Loopback Processing на OU компьютеров. Для наших
  per-направление настроек (по пользователю) не требуется.
- **Проверка:** `gpupdate /force` + релогон; `gpresult /r` — что реально применилось.

### A.6. Чек-лист админа (одноразово)
- [ ] Security-группы направлений; их DN → в конфиг Python.
- [ ] OU жизненного цикла: `Pending` / `Active` / `Disabled`.
- [ ] ACL общих папок по группам.
- [ ] GPO «Students-Directions» на `OU=Active`: GPP Shortcuts + Drive Maps с ILT по группам.
- [ ] GPO раскладки Пуск/таскбара по направлениям (Security Filtering на группу).
- [ ] (Опц.) AppLocker-правила по группам.
- [ ] Fine-Grained Password Policy на OU учеников (если нужны простые пароли — см. §4.6).

> Связь с интеграцией: рантайм-задача плагина/Python — только `add member` в группу направления
> (provision) и группы зачисленного (promote). Вся настройка прав/политик/ярлыков — здесь, в AD.
