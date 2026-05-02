<?php
// ВЫНЕСТИ ЛОГИКУ В АБСТРАКТНЫЙ КЛАСС ЕСЛИ В БУДУЩЕМ БУДЕТ МНОГО НАСТРОЕК
declare(strict_types=1);

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;


class SettingsRepository implements RepositoryInterface
{

    private string $option_name = OptionName::AUTH_SETTINGS->value;
    /**
     * Кэш полученных настроек для избежания повторных запросов к БД.
     *
     * @var array<string, mixed>
     */
    private static ?array $cache = null;

    /**
     * @inheritDoc
     */
    public function readAll(): array
    {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        $data = get_option( $this->option_name, [] );
        self::$cache = is_array( $data ) ? $data : [];

        return self::$cache;
    }

    /**
     * @inheritDoc
     */
    public function update( array $data ): bool
    {
        $result = update_option( $this->option_name, $data, 'no' );

        if ( $result ) {
            self::$cache = $data;
        }

        return $result !== false;
    }

    /**
     * @inheritDoc
     */
    public function delete( array $data = [] ): bool
    {
        $result = delete_option( $this->option_name );

        if ( $result ) {
            self::$cache = null;
        }

        return $result;
    }


    /**
     * Проверка, включен ли провайдер.
     */
    public function isProviderEnabled( string $provider_key ): bool
    {
        $settings = $this->readAll();
        $setting_key = strtolower( $provider_key ) . '_enabled';

        return ! empty( $settings[ $setting_key ] );
    }

    /**
     * Получение ключей провайдера для Hybridauth.
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
     * Обновление отдельного ключа внутри опции.
     *
     * @param string $key Ключ внутри массива настроек
     * @param mixed $value Новое значение
     * @return bool
     */
    public function updateKey( string $key, mixed $value ): bool
    {
        $current = $this->readAll();
        $current[ $key ] = $value;

        return $this->update( $current );
    }

    /**
     * Сброс кэша (для тестов или после внешнего обновления).
     */
    public function clearCache(): void
    {
        self::$cache = null;
    }

}