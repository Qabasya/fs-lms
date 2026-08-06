---
name: db-migrations
description: Правила миграций схемы и данных fs-lms в dev-окружении. Использовать при добавлении или удалении таблиц и колонок, при правке Migration_1_0_0, при написании data-миграции над wp_options/postmeta, а также когда миграция «не применяется» после перезагрузки страницы.
---

# Миграции в dev-окружении

**DDL-миграции (`Migration_1_0_0`) запускаются только при (ре)активации плагина.**
`MigrationRunner::run()` вызывается единственный раз — из `register_activation_hook`
(`Activate::activate()`), НЕ на обычной загрузке. Простая перезагрузка страницы миграции
не перезапускает.

**Удаление колонки** — не создавать новый файл миграции. Вместо этого:
1. Удалить колонку из DDL в `Migration_1_0_0::up()`
2. Добавить строку в секцию "Cleanup" того же файла: `$wpdb->query( "ALTER TABLE \`$table\` DROP COLUMN IF EXISTS \`col\`" );`
3. Сбросить версию схемы: `docker exec wp_db mariadb -u root -proot wordpress -e "UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';"`
4. Реактивировать плагин, чтобы миграции перезапустились — из каталога со стеком (где лежит `docker-compose.yml`; из другого места добавь `-f <путь>/docker-compose.yml`): `docker compose run --rm wpcli wp plugin deactivate fs-lms && docker compose run --rm wpcli wp plugin activate fs-lms` (или тумблер в админке). ⚠️ Сброс версии + реактивация прогонит `up()` целиком, включая `CREATE TABLE`/DROP — на dev это безопасно, на данных с людьми — нет.

**Новые таблицы** — добавлять в `Migration_1_0_0::up()` и `down()`, не создавать отдельный файл.

**Data-миграция, которая обязана доехать до живых установок** (правка уже сохранённых
`wp_options`/`postmeta`, а не схемы) — НЕ класть в `Migration_1_0_0::up()`: на инсталляциях с
уже проставленным `fs_lms_schema_version` он не запустится. Делать самодостаточный класс,
version-gated собственной опцией (паттерн `VideoSchema`/`AdSchema`), и звать его на обычной
загрузке из `Init::run()`. Образец — `BroadcastStepMigration` (recording_slot → broadcast):
прямой `$wpdb` по `postmeta` (не зависит от регистрации CPT), дешёвый option-read при уже
выполненной миграции.
