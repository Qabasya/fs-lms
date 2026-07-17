# FS LMS — REST API (интеграции с Python-сервисами)

> Публичный REST-контракт плагина (namespace `fs-lms/v1`) для внешних Python-сервисов.
> Интеграции две, у каждой — свой модуль-лист (`inc/Modules/*`) и свой секрет; схема аутентификации общая (HMAC, §2):
>
> | Интеграция | Модуль | Модель | Разделы |
> |---|---|---|---|
> | Active Directory (учётки учеников) | `AdSync` | **pull** — Python на DC в локалке без белого IP сам опрашивает WP исходящим HTTPS; WordPress наружу не ходит | §3–6 |
> | Видеозаписи занятий (S3 Beget) | `VideoLibrary` | **push** — сервис `fs-video-uploader` после загрузки видео в S3 шлёт регистрацию в WP | §7 |
>
> Связанные доки: AD — `WpToADTasks.md` (настройка AD-стороны — там же, Приложение A);
> видео-сервис — `video-uploader.md`; задачи плагина по видео — `Tasks.md`.

---

## 1. База и доступность

- **Base URL:** `https://<ваш-сайт>/wp-json/fs-lms/v1`
- Эндпоинты каждого модуля регистрируются **только при включённом модуле**
  (тумблер в «Настройки → Конфигурация», либо константа в `wp-config.php`:
  `FS_LMS_AD_SYNC=true` для AdSync, `FS_LMS_VIDEO_LIBRARY=true` для VideoLibrary).
  При выключенном модуле его маршрутов нет (404).
- Требуется **HTTPS** (передаются учётные данные).

| Метод | Путь | Модуль | Назначение |
|---|---|---|---|
| `GET`  | `/ad/jobs` | AdSync | забрать задания на выполнение в AD |
| `POST` | `/ad/ack`  | AdSync | отчитаться о выполнении задания |
| `GET`  | `/ad/active-usernames` | AdSync | список логинов «кто должен жить» (сверка/«пылесос») |
| `POST` | `/videos` | VideoLibrary | зарегистрировать загруженную в S3 видеозапись занятия |

---

## 2. Аутентификация (HMAC)

Каждый запрос подписывается секретом **своего модуля** (задаётся в `wp-config.php` на стороне WP
и в `.env` Python-сервиса — **одно и то же значение**; секреты модулей независимы, ротируются порознь):

| Модуль | Константа wp-config | Env на стороне Python |
|---|---|---|
| AdSync | `FS_LMS_AD_HMAC_SECRET` | `FS_LMS_AD_HMAC_SECRET` (поллер AD) |
| VideoLibrary | `FS_LMS_VIDEO_HMAC_SECRET` | `LMS_HMAC_SECRET` (fs-video-uploader) |

Заголовки на **каждом** запросе:

| Заголовок | Значение |
|---|---|
| `X-Fs-Timestamp` | текущее unix-время (секунды) |
| `X-Fs-Signature` | `hex( hmac_sha256( "{timestamp}.{raw_body}", secret ) )` |

- `raw_body` — **сырое тело запроса** (для `GET` — пустая строка `""`).
- Сервер отвергает запрос (`401`), если:
  - заголовки отсутствуют;
  - `|now − timestamp| > 300` секунд (анти-replay);
  - подпись не совпадает (сверка `hash_equals`).
- IP-allowlist **не используется** (у локальной сети нет фиксированного белого IP) — защита строится на
  секрете + HTTPS. Держите секрет в тайне и ротируйте при компрометации.

Пример вычисления подписи (Python):
```python
import time, hmac, hashlib

def sign(secret: str, body: str = "") -> dict[str, str]:
    ts = str(int(time.time()))
    sig = hmac.new(secret.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    return {"X-Fs-Timestamp": ts, "X-Fs-Signature": sig}
```

---

## 3. Эндпоинты

### 3.1. `GET /ad/jobs`

Возвращает задания, готовые к выполнению (статус `pending` либо `failed` с наступившим временем ретрая).

**Query:** `limit` (опц., 1–200, по умолчанию 50).

**Ответ `200`:**
```json
{
  "jobs": [
    {
      "id": 7,
      "event": "provision",
      "idempotency_key": "app:5",
      "username": "i.petrov",
      "password": "СекретУченика",
      "first": "Иван",
      "last": "Петров",
      "subject_key": "inf"
    },
    { "id": 8, "event": "deprovision", "idempotency_key": "deprovision:app:9", "username": "a.sidorov" },
    { "id": 9, "event": "promote",     "idempotency_key": "promote:person:3",  "username": "p.orlov" }
  ]
}
```

**Поля по типу задания (`event`):**

| event | поля | что сделать в AD |
|---|---|---|
| `provision` | `username, password, first, last, subject_key` | создать учётку в `OU=Pending`, включить, задать пароль, **добавить в группу направления (Python сам выбирает её по `subject_key`)** |
| `deprovision` | `username` | отключить учётку (disable), перенести в `OU=Disabled` |
| `promote` | `username` | перенести в `OU=Active` (зачислен) |

> `password` присутствует **только** у `provision`. Это постоянный пароль, заданный учеником в форме —
> один и тот же для WordPress и для AD (без «сменить при входе»).
>
> **Группа направления решается на стороне Python:** WP отдаёт `subject_key` (стабильный слаг, напр. `inf`).
> Карту `предмет → DN security-группы` держите в конфиге Python — в WP она не хранится.
>
> **Чистка учёток** (брошенные заявки, удалённые/отчисленные) — через `deprovision` и регулярную сверку
> `GET /ad/active-usernames` (см. ниже). Авто-истечения по TTL на стороне WP нет.

---

### 3.2. `POST /ad/ack`

Отчёт о выполнении одного задания. Тело подписывается (body входит в подпись).

**Тело:**
```json
{ "id": 7, "status": "done", "error": "", "sam_account_name": "i.petrov" }
```
| Поле | Обяз. | Описание |
|---|---|---|
| `id` | да | id задания из `/ad/jobs` |
| `status` | да | `done` (успех) или `failed` (ошибка) |
| `error` | нет | текст ошибки (для `failed`, попадёт в `last_error`) |
| `sam_account_name` | нет | фактический логин в AD (для аудита) |

**Ответ `200`:** `{ "ok": true }`

- `done` → задание помечается `sent` и больше не выдаётся.
- `failed` → инкремент попыток, экспоненциальный backoff (`next_attempt_at`), после 6 попыток → `dead`
  (перестаёт выдаваться). До этого задание **снова появится** в `/ad/jobs` после наступления `next_attempt_at`.
- Если Python не прислал `ack` вовсе (упал) — задание остаётся `pending` и выдаётся на следующем поллинге.

---

### 3.3. `GET /ad/active-usernames`

Авторитетный список логинов, которые **должны оставаться активными** в AD:
активные зачисленные ученики + «живые» заявки (ещё не истёкшие/не отклонённые).

**Ответ `200`:**
```json
{ "usernames": ["i.petrov", "a.sidorov", "p.orlov"] }
```

Используется для сверки-«пылесоса»: Python отключает в управляемой OU все учётки, которых **нет** в списке.
Так закрывается случай «человека удалили из БД WP, а логин в домене остался».

---

## 4. Коды ошибок

| Код | Когда |
|---|---|
| `200` | успех |
| `400` | некорректное тело (напр. `id <= 0` в `/ad/ack`) |
| `401` | нет/неверная подпись, протухший timestamp, не задан секрет на стороне WP |
| `404` | модуль выключен (маршруты не зарегистрированы) |

---

## 5. Пример клиента на Python (поллер)

Это **не FastAPI-сервер** (входящие соединения не нужны) — это клиент-поллер, который крутится в локальной
сети. Зависимости: `requests` (или `httpx`), `ldap3`, планировщик (`APScheduler` или системный cron).

```python
import time, hmac, hashlib, json, requests
# from ldap3 import Server, Connection, MODIFY_ADD, MODIFY_REPLACE  # см. §4.2 WpToADTasks.md

BASE   = "https://example.com/wp-json/fs-lms/v1"
SECRET = "СЕКРЕТ_КАК_В_wp-config"   # FS_LMS_AD_HMAC_SECRET

def _headers(body: str = "") -> dict:
    ts = str(int(time.time()))
    sig = hmac.new(SECRET.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    return {"X-Fs-Timestamp": ts, "X-Fs-Signature": sig, "Content-Type": "application/json"}

def fetch_jobs(limit: int = 50) -> list[dict]:
    r = requests.get(f"{BASE}/ad/jobs", params={"limit": limit}, headers=_headers(), timeout=15)
    r.raise_for_status()
    return r.json().get("jobs", [])

def ack(job_id: int, ok: bool, error: str = "", sam: str = "") -> None:
    body = json.dumps({"id": job_id, "status": "done" if ok else "failed",
                       "error": error, "sam_account_name": sam}, ensure_ascii=False)
    requests.post(f"{BASE}/ad/ack", data=body.encode("utf-8"), headers=_headers(body), timeout=15)

def active_usernames() -> list[str]:
    r = requests.get(f"{BASE}/ad/active-usernames", headers=_headers(), timeout=30)
    r.raise_for_status()
    return r.json().get("usernames", [])

# --- AD-операции (заглушки; реализация через ldap3, см. §4.2 WpToADTasks.md) ---
def ad_provision(job: dict) -> str: ...   # create user, set password, add to группе по job["subject_key"]
def ad_disable(username: str) -> None: ... # userAccountControl=514, move to OU=Disabled
def ad_promote(username: str) -> None: ... # move to OU=Active (зачислен)
def ad_disable_absent(keep: list[str]) -> None: ...  # disable в управляемой OU всё, чего нет в keep

def run_jobs_once() -> None:
    for job in fetch_jobs():
        try:
            if   job["event"] == "provision":   sam = ad_provision(job); ack(job["id"], True, sam=sam)
            elif job["event"] == "deprovision": ad_disable(job["username"]); ack(job["id"], True)
            elif job["event"] == "promote":     ad_promote(job["username"]); ack(job["id"], True)
            else:                                ack(job["id"], False, error=f"unknown event {job['event']}")
        except Exception as e:                   ack(job["id"], False, error=str(e))

def run_reconcile_once() -> None:
    ad_disable_absent(active_usernames())

if __name__ == "__main__":
    # быстрый цикл заданий + редкая сверка (упрощённо; в проде — APScheduler/cron)
    last_reconcile = 0
    while True:
        run_jobs_once()
        if time.time() - last_reconcile > 3600:      # раз в час
            run_reconcile_once(); last_reconcile = time.time()
        time.sleep(3)                                 # интервал поллинга заданий
```

> **AD-операции (ldap3):** bind сервис-аккаунтом (LDAPS:636), `unicodePwd` (UTF-16LE в кавычках),
> `userAccountControl` (512/514), членство в группе через `member`
> группы (`MODIFY_ADD`/`MODIFY_DELETE`). Детали — `WpToADTasks.md` §4.2 и §4.6 (парольная политика OU).

---

## 6. Включение на стороне WP (памятка)

1. Секрет: в секции «Синхронизация с доменом (AD)» нажать **«Сгенерировать»** у `FS_LMS_AD_HMAC_SECRET` →
   скопировать строку `define( 'FS_LMS_AD_HMAC_SECRET', '...' );` в `wp-config.php`, а сам секрет — в `.env`
   Python (одно и то же значение). Бейдж покажет «Задан». Опц.: `define( 'FS_LMS_AD_SYNC', true );` — жёстко включить.
2. Админка → **Настройки → Конфигурация**: сначала включить **«Привязать заявку к направлению»**
   (раздел «Настройка заявок») и задать коды направлений — без этого у заявок не будет `subject_key`
   и AD-синхронизацию включить нельзя. Затем в **«Синхронизация с доменом (AD)»** включить тумблер.
   (Карта `предмет → группа` и срок жизни учёток в WP не задаются — это на стороне Python/reconcile.)
3. Python-сервис в локалке настроить на `BASE` (адрес сайта) + секрет и запустить поллер.

---

## 7. Видео-реестр (модуль VideoLibrary, push)

> Приём уведомлений от сервиса `fs-video-uploader` о загруженных в S3 записях занятий и привязка их
> к занятиям (`fs_lms_group_lessons`). Контракт со стороны сервиса — `video-uploader.md`
> («LMS REST (push)»); здесь — семантика на стороне плагина.

### 7.1. `POST /videos`

Заголовки — HMAC по §2 (секрет `FS_LMS_VIDEO_HMAC_SECRET`; тело входит в подпись).

**Тело:**
```json
{
  "s3_bucket": "f6bcd57c2800-fs-video",
  "s3_key": "videos/kege-1/2026/07/2026-07-08_16-04_a1b2c3d4.webm",
  "manifest_key": "videos/kege-1/2026/07/2026-07-08_16-04_a1b2c3d4.webm.json",
  "group_slug": "kege-1",
  "lms": {"group_id": 3, "course_id": 42, "teacher_id": 7},
  "recorded_at": "2026-07-08T16:04:45+03:00",
  "size_bytes": 123456789, "sha256": "…", "duration_sec": null
}
```

| Поле | Обяз. | Описание |
|---|---|---|
| `s3_key` | да | ключ видео в бакете; **ключ идемпотентности** (upsert) |
| `s3_bucket`, `manifest_key` | да | бакет и ключ JSON-манифеста рядом с видео |
| `group_slug` | да | slug папки-источника (для логов/диагностики, в резолве не участвует) |
| `lms` | да | блок ID из `groups.yaml` — см. варианты ниже |
| `recorded_at` | да | ISO-8601 с offset — время начала записи; ключ резолва занятия |
| `size_bytes`, `sha256` | да | для сверки с манифестом/HEAD объекта |
| `duration_sec` | нет | может быть `null` |

**Варианты `lms`-блока (определяют ветку резолва):**

| Состав | Источник (папка на шаре) | Ветка |
|---|---|---|
| `{group_id, course_id, teacher_id}` | папка группы | групповая: занятие группы `group_id` по дате/времени |
| `{teacher_username}` | персональная папка препода | индивидуальная: занятие `kind='individual'` этого препода по дате/времени |

`course_id`/`teacher_id` в групповом варианте — кросс-чек против `fs_lms_groups` (расхождение → WARNING в лог,
не отказ). `teacher_username` = WP `user_login` (= sAMAccountName в домене — конвенция).

### 7.2. Резолв занятия

1. `recorded_at` нормализуется к таймзоне сайта (`wp_timezone()`); `scheduled_at` занятий хранится
   в локальном wall-clock.
2. Кандидаты — занятия **того же календарного дня**: групповая ветка — все занятия группы
   (кроме `status='cancelled'`); индивидуальная — `kind='individual'` занятия преподавателя по всем
   его группам (эффективный препод: `teacher_user_id` занятия, иначе `teacher_id` группы).
3. Выбор: попадание `recorded_at` в окно `[scheduled_at − 45 мин; ends_at + 45 мин]`; из нескольких —
   ближайшее по `|recorded_at − scheduled_at|`; равноудалённые кандидаты → неоднозначность → unmatched.
4. **Матч:** upsert строки реестра (`fs_lms_video_recordings`) + запись указателя в
   `group_lessons.recording_url` + занятие помечается **`status='held'`** («запись есть → занятие
   состоялось») — это фиксирует его дату от пересборок КТП (`reflow` не трогает `held`).
5. **Нет кандидата / неоднозначность:** видео регистрируется в реестре со статусом `unmatched`
   (ручная привязка в админке), ответ всё равно `200`.

### 7.3. Ответы

| Код | Тело | Когда |
|---|---|---|
| `200` | `{ "ok": true, "matched": true, "group_lesson_id": 123 }` | зарегистрировано и привязано |
| `200` | `{ "ok": true, "matched": false, "group_lesson_id": null }` | зарегистрировано, занятие не найдено — **не ошибка** |
| `400` | `{ "ok": false, "error": "…" }` | некорректное тело (нет `s3_key`/`recorded_at`, битый `lms`-блок) |
| `401` | — | нет/неверная подпись, протухший timestamp, не задан секрет |
| `404` | — | модуль выключен (маршрут не зарегистрирован) |

- **Идемпотентность:** upsert по `s3_key`. Повторная отправка обновляет метаданные; существующую
  привязку (в т.ч. ручную) **не перерезолвит** — пере-резолв только для строк `unmatched`.
- Любой `4xx` сервис трактует как терминальный `failed` (без ретраев) — поэтому «занятие не найдено»
  всегда `200`, а `400` — только структурная невалидность payload.

### 7.4. Выдача видео ученикам

Бакет **приватный**. Плагин хранит `s3_bucket`+`s3_key` в реестре, в `recording_url` занятия пишется
стабильный указатель; при рендере шага-видео со слотом записи (`recording_slot`) модуль подменяет его
временной presigned-ссылкой (SigV4, TTL часы) через фильтр `fs_lms_recording_url`. Доступ гейтится
существующими правилами плеера (членство в группе). При выключенном модуле указатель не рендерится
(graceful absence). S3-креды (read-only ключ) — константы `FS_LMS_S3_*` в wp-config.

---

## 8. Клиентский шов `FS_LMS_API` (браузерный кабинет `/profile/`)

> Отдельная сущность от AdSync-REST выше. Это **единственная точка**, через которую SPA личного кабинета
> общается с бэкендом. Держим её изолированной, чтобы кабинет можно было перенести в Telegram Web App
> или мобильное приложение, не переписывая экраны. Подробный гайд по выносу — `basic_doc.md` →
> «Личный кабинет /profile/: вынос в приложение».

**Где:** `src/js/profile/api.js` (собирается в `assets/js/profile.min.js`). Экспортирует объект `FS_LMS_API`
и хелпер `createApi`; при загрузке кладёт себя в `window.FS_LMS_API`.

**Контракт транспорта (сейчас — admin-ajax):**

| Что | Значение |
|---|---|
| Метод | `POST` `admin-ajax.php` (`fsProfile.ajax.url`) |
| Тело | `application/x-www-form-urlencoded`: `action` (snake_case) + `security` (nonce) + params |
| Куки | `credentials: 'same-origin'` (WP-сессия) |
| Успех | `{ success: true, data }` → `createApi` возвращает `data` |
| Ошибка | `{ success: false, data }` → бросает `Error(data.message ?? data)` |

**Конфиг приходит из PHP** через `window.fsProfile` (собирается в `ProfileViewResolver::jsConfig()`,
локализуется в `Enqueue`). Каждому экрану — свой блок `{ nonce, actions }`:

```js
window.fsProfile = {
  ajax:     { url: '…/admin-ajax.php' },
  groups:   [ { id, name, subject }, … ],
  schedule: { nonce, actions: { getCalendar, reflow, pin, getProgram, assignCourse } }, // КТП
  journal:  { nonce, actions: { getJournal, saveAttendance, bulkAttendance } },         // Журнал
  review:   { nonce, actions: { getSubmissions, saveGrade, returnSubmission } },        // Проверка работ
};
```

**Использование в экране** (журнал/КТП/проверка — одинаково):

```js
import { createApi } from './api.js';
const api = createApi(window.fsProfile.journal);   // блок конфига экрана
const data = await api('getJournal', { group_id: 1 });   // actionKey → actions[...] + nonce
```

**Точка переопределения без пересборки.** Экраны вызывают транспорт через объект
(`FS_LMS_API.request(...)`), поэтому внешний код может подменить его целиком:

```js
// Пример: мост Telegram Web App шлёт initData вместо WP-nonce на REST-фасад.
window.FS_LMS_API.request = async (action, _nonce, params) => {
  const res = await fetch(`/wp-json/fs-lms/v1/profile/${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Fs-Tg-Init': window.Telegram.WebApp.initData },
    body: JSON.stringify(params || {}),
  });
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'Ошибка запроса');
  return json.data;
};
```

**Путь к внешним клиентам (Telegram / мобилка).** Чтобы кабинет заработал вне WP-куки, нужны три вещи;
логику (Services/Repositories) **не трогаем** — только фасад транспорта и авторизации:

1. **Auth-мост** вместо nonce+куки: Telegram `initData` (HMAC от токена бота) или токен
   (Application Passwords / JWT) → маппинг на WP-пользователя. Строится **отдельным модулем**
   `Inc\Modules\…` (по образцу SocialAuth/AdSync), ядро на него не ссылается.
2. **REST-фасад**, зеркалящий те же `actions`, делегируя в **те же Callbacks/Services** (тонкие контроллеры).
3. **Bootstrap-эндпоинт**, отдающий `ProfileViewResolver::jsConfig()` как JSON (сейчас payload
   инъектится в HTML) — чтобы не-WP-клиент получил `fsProfile` запросом.

На клиенте меняется ровно одно — `FS_LMS_API.request`. Экраны остаются как есть.
