# FS LMS — REST API (интеграции с Python-сервисами)

> Интеграции плагина с внешними Python-сервисами. У каждой — свой модуль-лист (`inc/Modules/*`)
> и свой секрет HMAC:
>
> | Интеграция | Модуль | Модель | Разделы |
> |---|---|---|---|
> | Active Directory (учётки учеников) | `AdSync` | **push** — сайт сам шлёт задания серверу AdSync в офисе (белый IP, проброс порта) и получает результат в том же ответе; входящих эндпоинтов на сайте нет | §3 (кратко), полный контракт — `AdSyncPythonService.md` |
> | Видеозаписи занятий (S3 Beget) | `VideoLibrary` | **push** — сервис `fs-video-uploader` после загрузки видео в S3 шлёт регистрацию в WP | §7 |
>
> Связанные доки: AD — `AdSyncPythonService.md` (требования к Python-сервису, операции в AD,
> настройка AD-стороны); видео-сервис — `video-uploader.md`; задачи плагина по видео — `Tasks.md`.

---

## 1. База и доступность

- **Base URL:** `https://<ваш-сайт>/wp-json/fs-lms/v1`
- Эндпоинты модуля регистрируются **только при включённом модуле**
  (тумблер в «Настройки → Конфигурация», либо константа `FS_LMS_VIDEO_LIBRARY=true` в `wp-config.php`).
  При выключенном модуле его маршрутов нет (404).
- Требуется **HTTPS**.

| Метод | Путь | Модуль | Назначение |
|---|---|---|---|
| `POST` | `/videos` | VideoLibrary | зарегистрировать загруженную в S3 видеозапись занятия |

AdSync входящих эндпоинтов на сайте не имеет (с версии push-модели `/ad/jobs`, `/ad/ack`,
`/ad/active-usernames` удалены): сайт сам обращается к серверу в офисе, см. §3.

---

## 2. Аутентификация (HMAC)

Входящие запросы к сайту (VideoLibrary) подписываются секретом модуля (`wp-config.php` на стороне
WP и `.env` Python-сервиса — **одно и то же значение**):

| Модуль | Константа wp-config | Env на стороне Python |
|---|---|---|
| VideoLibrary | `FS_LMS_VIDEO_HMAC_SECRET` | `LMS_HMAC_SECRET` (fs-video-uploader) |

AdSync подписывает **исходящие** запросы сайта своей схемой — с методом и путём в подписи
(`FS_LMS_AD_HMAC_SECRET`, см. §3 и `AdSyncPythonService.md` §3).

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

## 3. AdSync — сайт → сервер в офисе (push)

Полный контракт, требования к серверу и операции в AD — **`AdSyncPythonService.md`**. Кратко:

- Сайт шлёт подписанные HTTPS-запросы на `https://<белый IP офиса>:8443` (адрес — в настройках модуля).
  Сертификат сервера самоподписанный с IP в SAN; сайт доверяет **только** ему (`FS_LMS_AD_SERVER_CERT`).
- Эндпоинты сервера: `POST /v1/jobs` (одно задание, результат синхронно), `POST /v1/reconcile`
  (сверка раз в сутки), `GET /v1/health` (кнопка «Проверить соединение»).
- Подпись: `X-Fs-Timestamp` + `X-Fs-Signature = hex(hmac_sha256("METHOD\nPATH\nTIMESTAMP\nBODY", FS_LMS_AD_HMAC_SECRET))`.
- Очередь (`fs_lms_ad_outbox`), бэкофф ретраев и «мёртвые» задания — на стороне сайта; при
  недоступности офиса задания ждут в очереди и попыток не тратят.

<!-- Разделы §4–6 (pull-эндпоинты AdSync и пример поллера) удалены вместе с pull-моделью;
     номера §2 и §7 сохранены — на них ссылается код VideoLibrary. -->

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
5. **Нет кандидата / неоднозначность:** видео регистрируется в реестре со статусом `unmatched`,
   ответ всё равно `200`. Такое занятие попадает в алёрт «Занятия без записи» (З3): блок в секции
   модуля («Настройки → Конфигурация») + `admin_notices` со счётчиком — оттуда запись привязывают
   вручную (`fs_lms_video_attach`) или вставляют ссылку руками (`fs_lms_video_set_url`).
   Второй путь — попап камеры в КТП группы (`AjaxHook::SetRecordingUrl`).

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
стабильный указатель; при рендере шага «Трансляция» (`broadcast`) модуль подменяет его
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
  ajax:          { url: '…/admin-ajax.php' },
  groups:        [ { id, name, subject }, … ],
  schedule:      { nonce, actions: { getCalendar, reflow, pin, getProgram, assignCourse } }, // КТП
  journal:       { nonce, actions: { getJournal, saveAttendance, bulkAttendance } },         // Журнал
  review:        { nonce, actions: { getSubmissions, saveGrade, returnSubmission } },        // Проверка работ
  notifications: { nonce, actions: { list, count, markRead, markAllRead } },                 // Колокольчик (общий для всех ролей)
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

### 8.1. Уведомления кабинета (колокольчик)

> In-app уведомления `/profile/` — плитки-события (запись занятия, дедлайны, оценки, замена,
> пропуск занятия…). Блок `fsProfile.notifications` присутствует **для всех ролей кабинета**
> (не только препода/ученика) — собирается безусловно в `ProfileViewResolver::jsConfig()`.
> Домен: `Inc\Services\Profile\NotificationService` + `Inc\Repositories\WPDBRepositories\NotificationRepository`
> (таблица `fs_lms_notifications`). Событийные продюсеры — `Inc\Controllers\Subscribers\NotificationSubscriber`
> (шина `LogEventDispatcher` + WP-хук `fs_lms_recording_attached`) и cron-продюсер
> `Inc\Services\Profile\NotificationCronService` (`fs_lms_notifications_tick`, раз в 15 минут).

**Действия** (`fsProfile.notifications.actions`):

| actionKey     | AJAX-хук                       | Params | Возвращает |
|---|---|---|---|
| `list`        | `get_notifications`            | — | `{ items: Tile[], unseen: 0 }` — 30 последних + сервер сразу помечает их **seen** |
| `count`       | `get_notifications_count`      | — | `{ unseen: number }` — для поллинга badge (раз в 60 с) |
| `markRead`    | `mark_notification_read`       | `id` | `{}` — помечает одну плитку **read** |
| `markAllRead` | `mark_all_notifications_read`  | — | `{}` — помечает **read** все уведомления получателя |

Получатель везде — `get_current_user_id()` на сервере; клиентский id не принимается ни в одном действии
(чужие уведомления недостижимы — репозиторий скоупит по `recipient_user_id`).

**Формат плитки `Tile`** (`NotificationService::toClientArray()` — единственная точка сборки текста;
клиент по `type`/`tone` выбирает только иконку/цвет, русский текст уже готов):

```ts
{
  id:     number,   // id строки fs_lms_notifications
  type:   string,   // 'video_uploaded'|'deadline_soon'|'deadline_missed'|'lesson_soon'|
                     // 'work_graded'|'work_returned'|'attempt_graded'|'review_needed'|
                     // 'substitute_assigned'|'attendance_missed'
  tone:   string,   // 'ok'|'warn'|'err'|'info' — цвет кружка (NotificationType::tone())
  title:  string,   // заголовок плитки (готовый русский текст)
  body:   string,   // подпись плитки (тема/группа/балл/имя — из payload, готовый текст)
  url:    string,   // deep-link (плеер занятия, /profile/?screen=…)
  time:   string,   // created_at, 'Y-m-d H:i:s' (локальное время сайта)
  unread: boolean,  // read_at === null
}
```

**Двухступенчатое прочтение (как в macOS):** `seen_at` проставляется всем непрочитанным строкам
получателя при первом же вызове `list` (открытие поповера гасит badge колокольчика, независимо от того,
кликнул ли пользователь на конкретную плитку); `read_at` — точечно через `markRead`/`markAllRead` (клик
по плитке/кнопка «Прочитать все», гасит точку непрочитанного на самой плитке). `unseen` в ответе `list`
всегда `0` — эта пометка уже произошла внутри того же запроса.

**Идемпотентность на сервере** (важно для будущего REST-фасада/Telegram-порта, п. 3 ниже): вставка
уведомлений идёт через `INSERT IGNORE` по `UNIQUE(recipient_user_id, dedupe_key)` — повторный cron-тик
или повторная доставка одного и того же доменного события не плодят дубли плиток.

**Путь к внешним клиентам (Telegram / мобилка).** Чтобы кабинет заработал вне WP-куки, нужны три вещи;
логику (Services/Repositories) **не трогаем** — только фасад транспорта и авторизации:

1. **Auth-мост** вместо nonce+куки: Telegram `initData` (HMAC от токена бота) или токен
   (Application Passwords / JWT) → маппинг на WP-пользователя. Строится **отдельным модулем**
   `Inc\Modules\…` (по образцу SocialAuth/AdSync), ядро на него не ссылается.
2. **REST-фасад**, зеркалящий те же `actions`, делегируя в **те же Callbacks/Services** (тонкие контроллеры).
3. **Bootstrap-эндпоинт**, отдающий `ProfileViewResolver::jsConfig()` как JSON (сейчас payload
   инъектится в HTML) — чтобы не-WP-клиент получил `fsProfile` запросом.

На клиенте меняется ровно одно — `FS_LMS_API.request`. Экраны остаются как есть.
