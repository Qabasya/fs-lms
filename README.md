# FS LMS — плагин WordPress

Плагин системы дистанционного обучения (LMS) для WordPress. Собирается из исходников
(`src/` → `assets/`) через Gulp/Webpack, PHP-зависимости — через Composer. Запускается
внутри Docker-стека с WordPress, MariaDB, phpMyAdmin и Mailpit.

> Официальный образ `wordpress:latest` **не содержит** Composer и Node — поэтому сборка
> плагина (`composer install`, `npm install`, `npx gulp build`) выполняется **на хосте**,
> а результат попадает в контейнер через смонтированный том.

---

## Требования

- Docker + Docker Compose (плагин `docker compose`, не старый `docker-compose`)
- Для сборки на хосте: **PHP 8.3+**, **Composer**, **Node.js 20**

---

## Структура проекта

Плагин — часть большего каталога `FS-LMS/`, который целиком и является Docker-стеком.
Том `./wordpress` монтируется в контейнер как `/var/www/html`, поэтому плагин виден
WordPress по стандартному пути `wp-content/plugins/fs-lms`.

```
FS-LMS/                              ← корень стека (здесь лежит docker-compose.yml)
├── docker-compose.yml              ← описание всех сервисов
├── uploads.ini                     ← PHP-лимиты загрузки (монтируется в wp_app)
├── db_data/                        ← том MariaDB (данные БД)
└── wordpress/                      ← весь WordPress, монтируется в /var/www/html
    └── wp-content/
        └── plugins/
            └── fs-lms/             ← ЭТОТ репозиторий (плагин)
                ├── fs-lms.php      ← точка входа плагина
                ├── inc/            ← PHP-код (PSR-4, namespace Inc\)
                ├── src/            ← исходники JS/SCSS
                ├── assets/         ← собранные JS/CSS (результат gulp build)
                ├── templates/      ← PHP-шаблоны
                ├── tests/          ← PHPUnit-тесты
                ├── composer.json
                └── package.json
```

Внутренняя архитектура плагина (слои, DI, правила) описана в [`CLAUDE.md`](CLAUDE.md).

---

## Сервисы стека

| Сервис     | Контейнер       | Порт (локально)                | Назначение                       |
| ---------- | --------------- | ------------------------------ | -------------------------------- |
| WordPress  | `wp_app`        | http://localhost:8080          | сам сайт                         |
| phpMyAdmin | `wp_phpmyadmin` | http://localhost:8081          | веб-доступ к БД                  |
| Mailpit    | `wp_mailpit`    | http://localhost:8025          | перехват исходящей почты (UI)    |
| MariaDB    | `wp_db`         | внутренний, host `db:3306`     | база данных                      |
| WP-CLI     | `wpcli`         | —                              | по требованию (профиль `cli`)    |

**phpMyAdmin / БД:** server `db`, user `root`, password `root`, база `wordpress`.
**Mailpit** ловит всю почту, отправленную из WordPress (SMTP на `1025`), — реальные
письма наружу не уходят.

Сервис `wpcli` со стеком **не поднимается** (он в профиле `cli`) — запускается по
требованию: `docker compose run --rm wpcli wp <команда>`.

---

## Быстрый старт (локально)

Все команды — из корня стека `FS-LMS/` (там, где `docker-compose.yml`).

### 1. Поднять стек

```bash
docker compose up -d
docker ps          # все контейнеры должны быть Up
```

Открыть http://localhost:8080 и пройти 5-минутную установку WordPress
(язык, название сайта, админ-логин/пароль).

### 2. Собрать плагин (на хосте)

Из каталога плагина `wordpress/wp-content/plugins/fs-lms`:

```bash
composer install --no-dev --optimize-autoloader   # PHP-зависимости
npm install                                        # node-зависимости
npx gulp build                                     # сборка JS + SCSS → assets/
```

Для разработки удобнее режим слежения (пересобирает на лету):

```bash
npx gulp watch
```

### 3. Активировать плагин

```bash
# из корня FS-LMS/
docker compose run --rm wpcli wp plugin activate fs-lms
```

Либо вручную: **wp-admin → Плагины → активировать «FS LMS»**.

> После правок PHP WordPress держит байт-код в OPcache. Если поведение не меняется —
> `docker restart wp_app`.

---

## Команды сборки и проверки

Выполняются в каталоге плагина.

```bash
npx gulp build            # собрать всё (JS + все CSS) один раз
npx gulp watch            # следить и пересобирать
npx gulp scripts          # только JS
npx gulp styles:admin     # только admin CSS
npx gulp styles:frontend  # только frontend CSS

npm run lint:js           # ESLint
npm run fix:js            # ESLint auto-fix
npm run lint:css          # stylelint
npm run fix:css           # stylelint auto-fix
```

---

## Тесты и CI

CI (`.github/workflows/ci.yml`) состоит из двух job:

1. **assets** — `npm run lint:js` + `npm run build:check` (падает, если какой-либо
   SCSS-бандл не собирается).
2. **PHPUnit** — `composer install` + `vendor/bin/phpunit`.

Локально:

```bash
# из каталога плагина
vendor/bin/phpunit        # PHPUnit
npm run ci                # lint:js + lint:css + build:check + phpunit — всё сразу
```

---

## Релиз

Плагин не в каталоге wordpress.org — источник версий и обновлений GitHub Releases
репозитория [`Qabasya/fs-lms`](https://github.com/Qabasya/fs-lms) (публичный, токен не
нужен).

### Выпустить новую версию

1. Поднять версию в трёх местах: шапка `fs-lms.php` (`Version:`), константа
   `FS_LMS_VERSION` там же, `package.json`.
2. Закоммитить, запушить в `master`.
3. Поставить тег и запушить его:

```bash
git tag v1.0.1
git push --tags
```

Тег `v*` запускает `.github/workflows/release.yml`: линтеры + тесты → сверка версии тега
с шапкой плагина (несовпадение — красный CI) → сборка ассетов → `composer install --no-dev`
→ `fs-lms-1.0.1.zip` в GitHub Release. Без зелёного CI релиз не публикуется.

### Как боевой сайт узнаёт об обновлении

`Inc\Services\Update\GithubReleaseUpdater` (ядро, не модуль) сверяет `FS_LMS_VERSION` с
последним релизом на GitHub и, если он новее, показывает стандартное «Доступно обновление»
на экране «Плагины» — обновление всегда по клику администратора, полностью автоматического
наката без участия человека нет (сознательное решение, см. `.docs/Tasks.md`, Этап 8).

Проверка идёт по тому же циклу, что и у плагинов из каталога wordpress.org (транзиент
`update_plugins`, WP обновляет его сам, обычно раз в ~12 часов) плюс собственный кэш на
6 часов поверх GitHub API — не бьёт лимит анонимных запросов (60/час на IP).

**Форсировать проверку** (для теста на любом сайте с плагином):

```bash
wp transient delete update_plugins
wp plugin list          # колонка "update" покажет "available", если релиз новее
wp plugin update fs-lms --dry-run
```

Либо в админке: **Плагины → Проверить ещё раз** (внизу списка).

---

## Полезные команды

Из корня `FS-LMS/`:

| Задача                             | Команда                                                            |
| ---------------------------------- | ----------------------------------------------------------------- |
| Поднять стек                       | `docker compose up -d`                                            |
| Остановить стек                    | `docker compose down`                                            |
| Список контейнеров                 | `docker ps`                                                       |
| Логи приложения                    | `docker logs --tail 50 wp_app`                                   |
| Перезапустить WordPress (OPcache)  | `docker restart wp_app`                                          |
| WP-CLI (любая команда)             | `docker compose run --rm wpcli wp <команда>`                     |
| Список плагинов                    | `docker compose run --rm wpcli wp plugin list`                  |
| Запрос к БД напрямую               | `docker exec wp_db mariadb -u root -proot wordpress -e "SELECT ..."` |

- После сборки — вернуть права: `chown -R www-data:www-data <путь к плагину>`.
