<?php

declare( strict_types=1 );

namespace Inc\Managers\Wp;

use Inc\Enums\Wp\TransientKey;

/**
 * Class TransientManager
 *
 * Обёртка над Transients API: единственное место, где плагин зовёт
 * `get_transient()` / `set_transient()` / `delete_transient()`.
 *
 * @package Inc\Managers\Wp
 *
 * Ключи задаются {@see TransientKey} — сырых строк в вызывающем коде быть не должно.
 */
class TransientManager {

	/**
	 * Читает значение транзиента.
	 *
	 * @param TransientKey $key    Ключ
	 * @param string|int   $suffix Идентификатор записи
	 *
	 * @return mixed false — записи нет либо срок истёк
	 */
	public function get( TransientKey $key, string|int $suffix ): mixed {
		return get_transient( $key->for( $suffix ) );
	}

	/**
	 * Сохраняет значение транзиента.
	 *
	 * @param TransientKey $key    Ключ
	 * @param string|int   $suffix Идентификатор записи
	 * @param mixed        $value  Значение
	 * @param int          $ttl    Время жизни в секундах
	 *
	 * @return void
	 */
	public function set( TransientKey $key, string|int $suffix, mixed $value, int $ttl ): void {
		set_transient( $key->for( $suffix ), $value, $ttl );
	}

	/**
	 * Удаляет транзиент.
	 *
	 * @param TransientKey $key    Ключ
	 * @param string|int   $suffix Идентификатор записи
	 *
	 * @return void
	 */
	public function delete( TransientKey $key, string|int $suffix ): void {
		delete_transient( $key->for( $suffix ) );
	}

	/**
	 * Забирает значение один раз: читает и сразу удаляет.
	 *
	 * @param TransientKey $key    Ключ
	 * @param string|int   $suffix Идентификатор записи
	 *
	 * @return mixed false — записи нет
	 */
	public function take( TransientKey $key, string|int $suffix ): mixed {
		$value = $this->get( $key, $suffix );

		if ( false !== $value ) {
			$this->delete( $key, $suffix );
		}

		return $value;
	}
}
