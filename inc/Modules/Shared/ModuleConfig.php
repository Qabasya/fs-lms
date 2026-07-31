<?php

declare( strict_types=1 );

namespace Inc\Modules\Shared;

/**
 * Class ModuleConfig
 *
 * Общий доступ к опции модуля: чтение с дефолтами, частичное сохранение и
 * тумблер включения с приоритетом константы `wp-config.php`.
 *
 * @package Inc\Modules\Shared
 *
 * ### Почему не core-репозиторий
 *
 * Ключи модульных опций намеренно ЖИВУТ В МОДУЛЕ и не попадают в `OptionName`:
 * ядро о модулях не знает, удаление каталога модуля не должно ломать ядро.
 * Наследник объявляет свою опцию, дефолты и (опционально) константу-тумблер —
 * всё остальное одинаково у всех модулей (аудит §2.6).
 */
abstract class ModuleConfig {

	/**
	 * Ключ опции модуля (вне core OptionName — изоляция).
	 */
	abstract protected function option(): string;

	/**
	 * Значения по умолчанию: набор ключей задаёт и «белый список» для save().
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function defaults(): array;

	/**
	 * Константа wp-config, перекрывающая тумблер (null — только опция).
	 */
	protected function toggleConstant(): ?string {
		return null;
	}

	/**
	 * Текущая конфигурация модуля.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( $this->option(), array() );

		return array_merge( $this->defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Мержит $partial поверх текущего значения; неизвестные ключи игнорирует.
	 *
	 * @param array<string, mixed> $partial Частичные изменения
	 *
	 * @return void
	 */
	public function save( array $partial ): void {
		update_option(
			$this->option(),
			array_merge( $this->get(), array_intersect_key( $partial, $this->defaults() ) ),
			false
		);
	}

	/**
	 * Включён ли модуль в рантайме. Константа wp-config перекрывает тумблер.
	 */
	public function isEnabled(): bool {
		$constant = $this->toggleConstant();

		if ( null !== $constant && defined( $constant ) ) {
			return (bool) constant( $constant );
		}

		return (bool) ( $this->get()['enabled'] ?? false );
	}

	/**
	 * Значение настройки с приоритетом константы wp-config.
	 *
	 * @param string      $key      Ключ настройки
	 * @param string|null $constant Константа-переопределение
	 */
	protected function valueOrConstant( string $key, ?string $constant ): string {
		if ( null !== $constant && $this->hasConstant( $constant ) ) {
			return (string) constant( $constant );
		}

		return (string) ( $this->get()[ $key ] ?? '' );
	}

	/**
	 * Задана ли непустая константа (тогда поле в UI только для чтения).
	 *
	 * @param string $constant Имя константы
	 */
	protected function hasConstant( string $constant ): bool {
		return defined( $constant ) && '' !== (string) constant( $constant );
	}

	/**
	 * Существует ли уже собственная опция модуля (для разовых миграций из легаси).
	 */
	protected function optionExists(): bool {
		return false !== get_option( $this->option(), false );
	}
}
