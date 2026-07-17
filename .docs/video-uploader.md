# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`fs-video-uploader` — фоновый сервис, который переносит видеозаписи занятий с SMB-шары учебного центра в S3-хранилище Beget и регистрирует их в LMS (WordPress-плагин `fs-lms`).

Контекст среды:

- Занятие записывается в Яндекс Телемосте; преподаватель сохраняет файл записи в `\\dc.fs.loc\Shares$\Video\<Группа>` (имена групп кириллицей: `КЕГЭ-1`, `ОГЭ-1`, …). В рантайме сервиса шара смонтирована в `VIDEO_ROOT` (default `/mnt/video`); **подпапка = учебная группа, либо персональная папка преподавателя** (записи индивидуальных занятий — см. groups.yaml ниже). Сервис папки не различает — семантику задаёт состав `lms`-блока в конфиге.
- Формат — `.webm`, имя вида `Встреча_в_Телемосте_08_07_26_16_04_45_—_запись.webm` (блок даты/времени начала: `ДД_ММ_ГГ_ЧЧ_ММ_СС`). Файл появляется копированием/скачиванием и в процессе растёт — трогать его можно только после проверки стабильности.
- После подтверждённой загрузки и регистрации исходник **не удаляется**, а перемещается в архивную подпапку `_uploaded` внутри папки своей группы (сервисная учётка `svc-video-upload` имеет права Modify). Очистка архива — ручная, администратором.
- Плагин получает при регистрации готовый блок `lms` с ID (группа / курс / преподаватель — состав задаёт админ в конфиге) и раскладывает видео строго по ним; человекочитаемые названия и ФИО в интеграции не участвуют.

Pipeline одного файла:

```
scan → stability → dedup (реестр) → metadata (дата) → resolve (папка группы → slug + блок lms)
     → upload (S3) → verify → register (LMS REST) → cleanup (перемещение исходника в архив)
```

Доменные события публикуются на значимых шагах (см. Observer ниже).

## Commands

```bash
uv sync                          # окружение + зависимости (включая dev)
uv run video-uploader              # локальный запуск сервиса
uv run pytest                    # тесты
uv run ruff format .             # автоформат (PEP 8)
uv run ruff check --fix .        # линт
uv run mypy video_uploader       # проверка типов (strict)
docker compose -f docker/docker-compose.yml up -d --build   # прод-запуск
```

Обязательная проверка перед завершением любого этапа (должна проходить чисто):

```bash
uv run ruff format . && uv run ruff check . && uv run mypy video_uploader && uv run pytest
```

- **pip / poetry / pdm не использовать.** Только uv: зависимости добавлять через `uv add <pkg>` (dev: `uv add --dev <pkg>`); `uv.lock` коммитится.
- Новые зависимости — только после явного согласования с пользователем.

## Tech Stack

- Python 3.12+, менеджер — **uv** (`pyproject.toml` + `uv.lock`; flat layout: пакет `video_uploader/` в корне репозитория, в pyproject — `module-root = ""`)
- pydantic v2 + pydantic-settings — конфиг и внешние DTO с валидацией
- boto3 — S3 (Beget); httpx — LMS REST / Telegram / Loki
- SQLite — реестр состояния; доступ через ORM SQLAlchemy 2.0 (зависимость добавляется на этапе 3 через `uv add sqlalchemy`)
- PyYAML — `config/groups.yaml`
- FastAPI + uvicorn — тонкий HTTP-слой (`/health`, `/status`, `/rescan`)
- dev: pytest, ruff, mypy

## Code Style

- PEP 8 через `ruff format` + `ruff check`; line-length 100; конфиг в `pyproject.toml`
- `mypy --strict`; типы у всех параметров и возвращаемых значений
- ООП: логика в классах, зависимости — через конструктор; интерфейсы — `typing.Protocol` в `base.py` соответствующего пакета
- Доменные модели — frozen `dataclass(slots=True)`; конфиг и внешние DTO — pydantic
- Пути — только `pathlib.Path`
- `print` запрещён — только `logging` (логгеры `video_uploader.<module>`)
- Docstrings (Google style) на публичных классах и методах
- Никакого глобального изменяемого состояния и синглтонов; вся сборка зависимостей — в composition root (`main.py`)

## SOLID в этом проекте

- **S** — модуль отвечает за одно: scanner не знает про S3, gateway не знает про группы, репозиторий не знает про пайплайн.
- **O** — новая стратегия даты / лог-синк / резолвер = новая реализация Protocol + регистрация в фабрике; существующий код не правится.
- **L** — реализации Protocol полностью взаимозаменяемы: не сужать контракт, не бросать неожиданных исключений.
- **I** — интерфейсы узкие: `DateExtractor.extract(path) -> datetime | None`, а не «универсальный обработчик».
- **D** — `pipeline` зависит только от Protocol; boto3/httpx/SQLAlchemy живут только внутри Gateway/Client/Repository и подключаются в `main.py`.

## Architecture

| Модуль (`video_uploader/`) | Роль |
|---|---|
| `main.py` | Composition root: конфиг → сборка зависимостей → воркер (фоновый поток) + uvicorn; graceful shutdown по SIGTERM |
| `config.py` | `Settings` (pydantic-settings; источник — env/`.env`) |
| `domain/models.py` | `VideoFile`, `LessonMeta`, `UploadResult` — frozen dataclasses |
| `domain/events.py` | Доменные события + `EventBus` (Observer): `subscribe` / `publish` |
| `scanner/scanner.py` | Обход `VIDEO_ROOT/<группа>/*`, отбор кандидатов |
| `scanner/stability.py` | Проверка «файл дописан» |
| `metadata/base.py` | `DateExtractor` (Protocol) |
| `metadata/filename.py` | Дата из имени файла (regex с именованными группами, default под Телемост) |
| `metadata/filestat.py` | Fallback: mtime |
| `resolving/resolver.py` | `GroupResolver`: имя папки → `slug` + блок `lms` (`groups.yaml`) |
| `storage/key_builder.py` | Ключи S3 (видео + манифест) по соглашению — единственный источник |
| `storage/s3_gateway.py` | Adapter над boto3: multipart upload, put manifest, head/verify |
| `lms/client.py` | REST-клиент fs-lms (httpx): регистрация видео |
| `state/repository.py` | Repository над SQLite (SQLAlchemy 2.0): реестр файлов и статусов |
| `notifications/telegram.py` | Подписчик EventBus: бизнес-уведомления в Telegram |
| `logging_setup/factory.py` | Фабрика лог-хендлеров из конфига (Strategy + Factory) |
| `logging_setup/loki.py`, `logging_setup/telegram.py` | Кастомные `logging.Handler` |
| `pipeline.py` | Оркестратор шагов обработки одного файла |
| `api/app.py` | FastAPI: `/health`, `/status`, `/rescan` — без бизнес-логики |

### Design Patterns (обязательные)

- **Strategy** — извлечение даты (`metadata/*`); лог-синки (каждый `logging.Handler` — стратегия вывода).
- **Observer** — `EventBus` в `domain/events.py`; издатель — pipeline, подписчики — `notifications/*`. События: `VideoDiscovered`, `VideoUploaded`, `VideoRegistered`, `VideoArchived`, `VideoFailed`, `GroupUnmapped`, `DateFallback`. EventBus — только для побочных эффектов (уведомления, метрики); критичный путь (реестр, S3, LMS) — явные вызовы, не события.
- **Factory** — `logging_setup/factory.py`; сборка стратегий из `Settings`.
- **Repository** — `state/repository.py`, единственная точка доступа к SQLite.
- **Adapter/Gateway** — `storage/s3_gateway.py`, `lms/client.py`.

Важно про Observer: следить за SMB-шарой событийно нельзя — inotify не работает поверх CIFS. Обнаружение файлов делается **опросом** по `SCAN_INTERVAL_SECONDS`; Наблюдатель живёт на уровне доменных событий, а не файловой системы. watchdog/inotify не предлагать.

Новые слои и паттерны не вводить без явного запроса пользователя.

## Contracts

### S3 (Beget)

- Endpoint: `https://s3.ru1.storage.beget.cloud`, регион `ru-1`, **path-style addressing** (`s3={"addressing_style": "path"}`).
- Имя бакета Beget автогенерирует с префиксом (вида `f6bcd57c2800-fs-video`) — берётся из `S3_BUCKET`, в коде не хардкодить.
- Соглашение о ключах (реализуется только в `key_builder.py`):

```
{S3_KEY_PREFIX}/{group_slug}/{yyyy}/{mm}/{yyyy-mm-dd}_{hh-mm}_{sha8}{ext}
пример: videos/kege-1/2026/07/2026-07-08_16-04_a1b2c3d4.webm
```

  - `group_slug` — из `groups.yaml`; `sha8` — первые 8 hex от sha256 файла; `ext` — оригинальное расширение lowercase.
  - В ключах допустимы только `[a-z0-9./_-]`; кириллица и пробелы запрещены.
- Рядом с каждым видео кладётся манифест `{video_key}.json` (UTF-8, `ContentType: application/json`) — бакет самоописывающий, LMS может свериться с ним без сервиса:

```json
{
  "schema": 2,
  "group_slug": "kege-1",
  "source_folder": "КЕГЭ-1",
  "lms": {"group_id": 3, "course_id": 42, "teacher_id": 7},
  "recorded_at": "2026-07-08T16:04:45+03:00",
  "original_name": "Встреча_в_Телемосте_08_07_26_16_04_45_—_запись.webm",
  "size_bytes": 123456789,
  "sha256": "…",
  "uploaded_at": "2026-07-14T21:00:05+00:00",
  "service": {"name": "fs-video-uploader", "version": "0.1.0"}
}
```

- `x-amz-meta-*` — **только ASCII**: `group-slug`, `recorded-at`, `sha256`, плюс зеркало блока `lms`: каждая пара кладётся как `x-amz-meta-lms-<key>` (символ `_` в ключе заменяется на `-`). Так плагин получает все ID одним `HEAD`-запросом, не скачивая манифест. Кириллица (`source_folder`) — только в манифест.
- `ContentType` по расширению: `.webm → video/webm`, `.mp4 → video/mp4`, `.mkv → video/x-matroska`.
- Multipart: `upload_file` + `TransferConfig` (threshold и chunk — 64 MiB). Верификация: `head_object` → `ContentLength == size_bytes`. ETag при multipart ≠ md5 — им не верифицировать.

### LMS REST (push)

Аутентификация — **HMAC** (единая схема интеграций fs-lms, как у модуля AdSync; статический токен `X-FS-Uploader-Token` упразднён):

```
POST {LMS_BASE_URL}/wp-json/fs-lms/v1/videos
Headers:
  X-Fs-Timestamp: <unix-время, секунды>
  X-Fs-Signature: hex( hmac_sha256( "{timestamp}.{raw_body}", LMS_HMAC_SECRET ) )
  Content-Type: application/json
```

- `raw_body` — байты JSON-тела ровно в том виде, в каком отправлены (подпись считается от них).
- Плагин отвергает запрос (`401`), если `|now − timestamp| > 300 c` (анти-replay) или подпись не сошлась → **часы LXC должны быть синхронизированы (NTP)**.
- Секрет: env `LMS_HMAC_SECRET` = константа `FS_LMS_VIDEO_HMAC_SECRET` в wp-config (одно значение; отдельный секрет от AD-синка).

```json
{
  "s3_bucket": "…", "s3_key": "…", "manifest_key": "…",
  "group_slug": "kege-1",
  "lms": {"group_id": 3, "course_id": 42, "teacher_id": 7},
  "recorded_at": "2026-07-08T16:04:45+03:00",
  "size_bytes": 123456789, "sha256": "…", "duration_sec": null
}
```

- `lms`-блок пробрасывается из `groups.yaml` как есть: для папки группы — `{group_id, course_id, teacher_id}`, для персональной папки препода — `{teacher_username}` (индивидуальные занятия). Сервис состав не интерпретирует.
- Идемпотентность — на стороне плагина, upsert по `s3_key`; повторная отправка безопасна.
- **Ответ плагина:** `200` + `{"ok": true, "matched": true|false, "group_lesson_id": <int|null>}`. `matched:false` — **не ошибка**: видео зарегистрировано, но занятие по дате/времени не найдено — плагин оставил его на ручную привязку. Для сервиса оба случая = `registered`; `matched:false` логировать WARNING (диагностика расписания).
- `200/201` → `registered`; `5xx` и сетевые ошибки → ретраи с экспоненциальным backoff в следующих циклах; прочие `4xx` (в т.ч. `401`) → `failed` + ERROR, без ретраев.
- Эндпоинт в fs-lms реализуется по контракту `FS_LMS_API.md` (репозиторий плагина, раздел «Видео-реестр»). До его появления используется `DRY_RUN=true`: шаги с побочными эффектами (в т.ч. register) логируются и считаются успешными.

### config/groups.yaml

```yaml
groups:
  "КЕГЭ-1":                    # папка группы — групповые занятия
    slug: kege-1
    lms:               # произвольные ID для плагина; сервис их не интерпретирует
      group_id: 3
      course_id: 42
      teacher_id: 7
  "Индивидуальные-Петров":     # персональная папка препода — индивидуальные занятия
    slug: ind-petrov
    lms:
      teacher_username: "i.petrov"   # == user_login на сайте (== sAMAccountName в домене)
```

- Ключ — точное имя подпапки в `VIDEO_ROOT` (кириллица допустима).
- `slug`: уникален, `^[a-z0-9]+(-[a-z0-9]+)*$` — единственное поле, которое сервис использует сам (ключи S3).
- `lms`: обязательный непустой **плоский** словарь: ключи `^[a-z0-9_]+$`, значения — int или ASCII-строка. Сервис не знает семантики этих полей — валидирует формат и пробрасывает блок как есть в манифест, REST-payload и `x-amz-meta-lms-*`. Состав определяет плагин; новые поля добавляются в конфиг без изменений кода.
- **Семантика состава (сторона плагина):** `group_id` в блоке → групповая ветка резолва (видео крепится к занятию группы по дате/времени `recorded_at`); только `teacher_username` без `group_id` → индивидуальная ветка (занятие `kind='individual'` этого преподавателя). Для персональных папок код сервиса не меняется — это обычная запись `groups.yaml`.
- **Конвенция имён:** `teacher_username` = WP `user_login` = sAMAccountName в домене (для учеников это гарантирует AdSync, для преподавателей — правило при заведении учёток). Точка синхронизации — этот файл.
- Файл валидируется на старте (pydantic); ошибка схемы — падение на старте с внятным сообщением.
- Папка без записи в `groups.yaml`: файлы пропускаются (`skipped_unmapped`), событие `GroupUnmapped` (WARNING, не чаще раза за цикл на папку); сервис не падает.

## Processing Rules

- Сканируются только прямые подпапки `VIDEO_ROOT` (глубина ровно 1: `VIDEO_ROOT/<группа>/<файл>`).
- Кандидаты: расширение из `ALLOWED_EXTENSIONS`; имена, начинающиеся с `.` или `~`, игнорируются.
- Стабильность: `size` и `mtime` не менялись ≥ `STABILITY_MINUTES` **и** файл открывается на чтение; иначе — ждать следующего цикла (это не ошибка, `failed` не ставить).
- Дата занятия: цепочка стратегий — `FilenameDateExtractor` → `FileStatDateExtractor` (mtime; событие `DateFallback`, WARNING). `FilenameDateExtractor` работает по `DATE_REGEX` с именованными группами `day/month/year/hour/minute/second`; default — под записи Телемоста: `(?P<day>\d{2})_(?P<month>\d{2})_(?P<year>\d{2})_(?P<hour>\d{2})_(?P<minute>\d{2})_(?P<second>\d{2})`. Двузначный год → `20ГГ`; префикс имени не важен (переименованная встреча тоже распарсится); невалидная дата (месяц > 12 и т.п.) → fallback на mtime. Часовой пояс — `TZ_NAME`.
- `SKIP_OLDER_THAN_DAYS` (опция): более старые файлы → `skipped_old`, не загружаются.
- `sha256` считается потоково (чанк 1 MiB) один раз и кешируется в реестре по `(path, size, mtime)`.
- Идемпотентность: если `sha256` уже `registered`/`archived` — повторно не грузить; сразу cleanup (если разрешён).
- **Cleanup — перемещение исходника в архив, не удаление**: файл переносится в `<папка группы>/{ARCHIVE_SUBDIR}/` серверным rename в пределах шары (без копирования; при коллизии имён к имени добавляется `_{sha8}`). Выполняется **только при всех условиях**: `uploaded` + верификация пройдена + `registered` + `ARCHIVE_AFTER_REGISTER=true`. Иначе файл остаётся на месте. Архивная подпапка сканером игнорируется (сканируется только глубина 1); очистка архива — ручная, вне сервиса.
- Изоляция ошибок: обработка каждого файла в try/except; ошибка → `failed`, `last_error`, инкремент `attempts`; ретраи в следующих циклах до `MAX_ATTEMPTS`, затем событие `VideoFailed` (ERROR).
- Порядок обработки — от старых к новым (по `recorded_at`/mtime).

## State (SQLite)

- Файл `DATA_DIR/state.db`; режим WAL; доступ — только через `StateRepository` (внутри — SQLAlchemy 2.0 ORM: модели-таблицы и сессии не покидают `state/`).
- Таблица `files`: `id`, `path`, `group_name`, `size_bytes`, `mtime`, `sha256`, `status`, `s3_key`, `archived_path`, `attempts`, `last_error`, `created_at`, `updated_at`.
- Статусы: `discovered → uploading → uploaded → registered → archived`; терминальные пропуски/ошибки: `failed`, `skipped_old`, `skipped_unmapped`.
- Все переходы статусов — методами репозитория (никаких сырых UPDATE из пайплайна); времена в БД — UTC ISO 8601.

## Logging & Notifications

- Только stdlib `logging`; хендлеры собирает `logging_setup/factory.py` из `Settings`:
  - file — `RotatingFileHandler` `DATA_DIR/logs/uploader.log` (10 MiB × 5), всегда включён;
  - loki — HTTP push (`/loki/api/v1/push`), включается при заданном `LOKI_URL`;
  - telegram — только `ERROR`+, включается при `TELEGRAM_*`; защита от флуда (одинаковый текст не чаще 1 раза в 30 с).
- Бизнес-уведомления (успешная загрузка, финальный сбой) — это **не логи**: их шлют подписчики EventBus из `notifications/` тем же Telegram Bot API через httpx.

## Configuration

`.env` → pydantic-settings. Секреты — только через env; `.env` в `.gitignore`; `.env.example` поддерживать актуальным.

| Переменная                                    | Default | Назначение |
|-----------------------------------------------|---|---|
| `VIDEO_ROOT`                                  | `/mnt/video` | Корень смонтированной шары |
| `DATA_DIR`                                    | `/data` | state.db + логи |
| `GROUPS_FILE`                                 | `/app/config/groups.yaml` | Маппинг групп |
| `SCAN_INTERVAL_SECONDS`                       | `300` | Период опроса |
| `STABILITY_MINUTES`                           | `5` | Порог «файл дописан» |
| `ALLOWED_EXTENSIONS`                          | `.webm,.mp4,.mkv` | Кандидаты (через запятую) |
| `DATE_REGEX`                                  | под Телемост | Именованные группы `day/month/year/hour/minute/second` (см. Processing Rules) |
| `SKIP_OLDER_THAN_DAYS`                        | — | Пропуск залежей (пусто = выкл) |
| `ARCHIVE_AFTER_REGISTER`                      | `true` | Перемещать исходник в архив после регистрации |
| `ARCHIVE_SUBDIR`                              | `_uploaded` | Имя архивной подпапки внутри папки группы |
| `MAX_ATTEMPTS`                                | `5` | Ретраи на файл |
| `TZ_NAME`                                     | `Europe/Kaliningrad` | TZ для `recorded_at` |
| `S3_ENDPOINT_URL`                             | `https://s3.ru1.storage.beget.cloud` | Beget S3 |
| `S3_REGION`                                   | `ru-1` | Для SigV4 |
| `S3_BUCKET`, `S3_ACCESS_KEY`, `S3_SECRET_KEY` | — | Реквизиты Beget |
| `S3_KEY_PREFIX`                               | `videos` | Префикс ключей |
| `LMS_BASE_URL`                                | — | Напр. `http://11.11.11.20:8080` |
| `LMS_HMAC_SECRET`                             | — | Секрет HMAC-подписи запросов к LMS (= `FS_LMS_VIDEO_HMAC_SECRET` в wp-config; заменил `LMS_UPLOADER_TOKEN`) |
| `DRY_RUN`                                     | `false` | Сухой прогон: S3/LMS/архивация подменяются заглушками (composition root) |
| `LOKI_URL`                                    | — | Опция |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`      | — | Опция |
| `API_PORT`                                    | `8090` | FastAPI |

## HTTP API

- `GET /health` → `{"status": "ok", "last_scan_at": …}` — для Docker HEALTHCHECK.
- `GET /status` → счётчики по статусам + последние 20 записей реестра.
- `POST /rescan` → триггер внеочередного цикла сканирования.
- API — тонкий слой над репозиторием/воркером; бизнес-логики не содержит. Аутентификации нет: доступ есть только из Tech/Admin-сегментов.

## Runtime Environment

- Отдельный LXC (Ubuntu, privileged) в Tech-сегменте `11.11.11.0/24`; внутри — Docker + Compose.
- Шара монтируется на уровне LXC через cifs-utils (fstab), в контейнер — bind mount:

```
//11.11.11.11/Shares$/Video  /mnt/video  cifs  credentials=/root/.smb-video,vers=3.1.1,iocharset=utf8,uid=1000,gid=1000,file_mode=0660,dir_mode=0770,_netdev  0 0
```

  Креды — доменная учётка `svc-video-upload` (файл `/root/.smb-video`, chmod 600). **`iocharset=utf8` обязателен** — имена папок кириллические.
- docker-compose: тома `/mnt/video:/mnt/video`, `./data:/data`, `./config:/app/config:ro`; `TZ=${TZ_NAME}`; `restart: unless-stopped`; HEALTHCHECK → `GET /health`.
- Dockerfile: multi-stage на `ghcr.io/astral-sh/uv:python3.12-bookworm-slim`, `uv sync --frozen --no-dev`, запуск от непривилегированного пользователя (uid 1000).

## Testing

- pytest; `tests/` зеркалит структуру `video_uploader/`.
- Всё внешнее — за Protocol; в тестах фейки: `FakeS3Gateway`, `FakeLmsClient`, репозиторий на tmp SQLite, файлы через `tmp_path`.
- В тестах запрещены: сеть, реальная шара, реальный S3/LMS/Telegram.
- Обязательное покрытие: `key_builder` (все правила ключей), стратегии даты (включая fallback), `resolver` (валидация `lms`-блока, неизвестная папка), `stability`, переходы статусов в репозитории, `pipeline` — happy path и все ветки отказов (upload fail, verify fail, register 5xx/4xx).
- Ручная проверка против реального Beget — `scripts/smoke_s3.py` (вне pytest).

## CI (GitHub Actions)

- Workflow: `.github/workflows/ci.yml`. Триггеры: `pull_request` в `main` и `push` в `main`.
- Одна джоба на `ubuntu-latest`, Python 3.12: checkout → официальный `astral-sh/setup-uv`, приколот к конкретной неизменяемой версии тега (с `v8.0.0` `astral-sh` не публикует плавающие мажорные теги вроде `@v8` — только полные версии, `@v8.3.2` и т.п.; обновлять вручную по мере выхода новых версий), `enable-cache: true` → `uv sync --frozen` → `uv run ruff format --check .` → `uv run ruff check .` → `uv run mypy video_uploader` → `uv run pytest`.
- Секреты в CI не нужны: тесты не ходят в сеть/S3/LMS (только фейки) — это гарантировано разделом Testing.
- Мердж в `main` — только при зелёном CI. Branch protection (required status check `ci`) настраивается руками в GitHub: Settings → Branches → правило для `main`.

## Strict Rules

- pip запрещён; зависимости — только через `uv add` и только по согласованию.
- Сервис **никогда не удаляет** файлы на шаре. Единственная операция над исходником — перемещение в архивную подпапку на шаге cleanup при выполненных условиях; `unlink` по исходникам не вызывается нигде.
- boto3 / httpx / SQLAlchemy — только внутри `s3_gateway` / `lms.client` + лог-хендлеры / `state.repository`; остальной код работает через Protocol.
- Кириллица и пробелы в ключах S3 и `x-amz-meta-*` запрещены.
- Ошибка обработки одного файла не останавливает сервис и цикл сканирования.
- `print` запрещён; исключения не глотаются молча (минимум — лог с traceback).
- Не вводить новые слои, паттерны и зависимости без явного запроса.
- Работать поэтапно (план — в стартовом промпте): в конце этапа прогнать ruff + mypy + pytest и остановиться до подтверждения пользователя.

## TODO интеграции с fs-lms (изменения в ЭТОМ репозитории)

> Зафиксировано совместно с плагином (fs-lms, ветка stage_11). Контракт плагина — `FS_LMS_API.md`
> в репозитории плагина, раздел «Видео-реестр». Объём правок сервиса минимален — контрактные решения
> плагина (индивидуальные занятия, unmatched) переварены конфигом и не требуют нового кода пайплайна.

1. **`lms/client.py` — HMAC вместо статического токена.** Убрать заголовок `X-FS-Uploader-Token`;
   подписывать каждый запрос парой `X-Fs-Timestamp` + `X-Fs-Signature` =
   `hex(hmac_sha256(f"{ts}.{raw_body}", LMS_HMAC_SECRET))`, где `raw_body` — те же байты, что уходят
   в теле (сериализовать JSON один раз и переиспользовать для подписи и отправки). См. блок
   «LMS REST (push)» выше.
2. **`config.py` / `.env.example`** — переименовать `LMS_UPLOADER_TOKEN` → `LMS_HMAC_SECRET`.
3. **Обработка ответа register** — читать `matched` из тела `200`-ответа: `matched:false` — это
   успех (`registered`), но логировать WARNING «занятие не найдено, оставлено на ручную привязку».
   Правила ретраев не меняются (5xx/сеть — ретрай, 4xx — `failed`).
4. **NTP** — убедиться, что часы LXC синхронизированы: HMAC-окно ±300 с, расхождение часов = `401`.
5. **`config/groups.yaml`** — завести персональные папки преподавателей для индивидуальных занятий
   (пример выше): запись с `lms: {teacher_username: "<user_login>"}` без `group_id`. Кода это не
   требует — `lms`-блок opaque, сканер уже обходит все папки глубины 1.
6. **Тесты** — обновить `FakeLmsClient`/тесты `lms/client.py` под HMAC-заголовки и поле `matched`.
7. **Процесс (вне кода):** создать на шаре персональные папки преподов (`Sync-VideoGroups.ps1` /
   вручную), договориться с преподавателями: групповые записи — в папку группы, индивидуальные —
   в свою папку. Логин в домене = логин на сайте (конвенция для преподавателей).

## Related Work (вне этого репозитория)

- **fs-lms**: REST-роут `fs-lms/v1/videos` (upsert по `s3_key`, HMAC) и выдача видео ученикам
  (приватный бакет + presigned URL, генерируется плагином) — модуль `VideoLibrary` в репозитории
  плагина; контракт и правила резолва — `FS_LMS_API.md` (раздел «Видео-реестр»), задачи — `Tasks.md`
  там же. Контракт: блок `lms` приходит в REST-payload и продублирован в `x-amz-meta-lms-*` и в
  манифесте — плагин раскладывает видео только по этим ID + `recorded_at` (дата/время → занятие),
  не разбирая имена файлов и папок. Занятие с привязанной записью плагин помечает проведённым
  (`held`) — его дата фиксируется от пересборок КТП.
- **Windows-сторона**: папки групп на шаре создаёт `Sync-VideoGroups.ps1` из `video-groups.csv`; `groups.yaml` должен оставаться согласованным с этим списком. Архив `_uploaded` копится внутри FSRM-квоты группы (авто-квота 30 GB на `C:\Shares\Video`) — квоту нужно расширить (`Set-FsrmQuotaTemplate ... -UpdateDerived`) либо регулярно чистить архив, иначе при переполнении новые записи не сохранятся на шару.
