<?php
// ВЫНЕСТИ ЛОГИКУ В АБСТРАКТНЫЙ КЛАСС ЕСЛИ В БУДУЩЕМ БУДЕТ МНОГО НАСТРОЕК
declare(strict_types=1);

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class SettingsRepository
 *
 * Репозиторий для работы с настройками плагина в таблице wp_options.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, обновление и удаление настроек в wp_options.
 * 2. **Кеширование** — хранение настроек в статическом свойстве для избежания повторных запросов к БД.
 * 3. **Работа с провайдерами** — проверка включения провайдера и получение ключей.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций (get_option, update_option, delete_option).
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Используется в AuthService и AuthConfigFactory для получения настроек аутентификации.
 */
class SettingsRepository implements RepositoryInterface
{

    private string $option_name = OptionName::AUTH_SETTINGS->value;

    /**
     * Кэш полученных настроек для избежания повторных запросов к БД.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $cache = null;

    /**
     * Возвращает все настройки плагина.
     *
     * @inheritDoc
     * @return array<string, mixed>
     */
    public function readAll(): array
    {
        // Возвращаем кэш, если он есть
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        // get_option() — получает опцию из таблицы wp_options
        $data = get_option( $this->option_name, [] );
        self::$cache = is_array( $data ) ? $data : [];

        return self::$cache;
    }

    /**
     * Обновляет настройки плагина.
     *
     * @inheritDoc
     * @param array $data Массив настроек
     *
     * @return bool
     */
    public function update( array $data ): bool
    {
        // update_option() — обновляет опцию (третий параметр 'no' — не автозагружать)
        $result = update_option( $this->option_name, $data, 'no' );

        if ( $result ) {
            self::$cache = $data;
        }

        return $result !== false;
    }

    /**
     * Удаляет настройки плагина.
     *
     * @inheritDoc
     * @param array $data Не используется (для совместимости с интерфейсом)
     *
     * @return bool
     */
    public function delete( array $data = [] ): bool
    {
        // delete_option() — удаляет опцию из таблицы wp_options
        $result = delete_option( $this->option_name );

        if ( $result ) {
            self::$cache = null;
        }

        return $result;
    }

    /**
     * Проверяет, включён ли провайдер аутентификации.
     *
     * @param string $provider_key Ключ провайдера (например, 'google', 'vk')
     *
     * @return bool
     */
    public function isProviderEnabled( string $provider_key ): bool
    {
        $settings = $this->readAll();
        // Формируем ключ: {provider}_enabled (например, 'google_enabled')
        $setting_key = strtolower( $provider_key ) . '_enabled';

        return ! empty( $settings[ $setting_key ] );
    }

    /**
     * Получает ключи провайдера (ID и Secret) для Hybridauth.
     *
     * @param string $provider_key Ключ провайдера (например, 'google', 'vk')
     *
     * @return array{id: string, secret: string}
     */
    public function getProviderKeys( string $provider_key ): array
    {
        $settings = $this->readAll();
        $prefix = strtolower( $provider_key );

        return [
            'id'     => $settings[ $prefix . '_id' ] ?? '',
            'secret' => $settings[ $prefix . '_secret' ] ?? '',
        ];
    }

    /**
     * Обновляет отдельный ключ внутри массива настроек.
     *
     * @param string $key   Ключ внутри массива настроек
     * @param mixed  $value Новое значение
     *
     * @return bool
     */
    public function updateKey( string $key, mixed $value ): bool
    {
        $current = $this->readAll();
        $current[ $key ] = $value;

        return $this->update( $current );
    }

    /**
     * Сбрасывает кэш настроек (для тестов или после внешнего обновления).
     *
     * @return void
     */
    public function clearCache(): void
    {
        self::$cache = null;
    }
}