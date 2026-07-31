<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Repositories\WPDBRepositories\PersonRepository;

/**
 * Class LogOptionsResolver
 *
 * Превращает набор ID в подписи для выпадающих фильтров страницы «Журналы»:
 * пользователи → display_name, физлица → ФИО.
 *
 * @package Inc\Services\Log\Pages
 *
 * Общий для всех провайдеров вкладок, чтобы правило «нет записи → `User #12`»
 * не расползалось по каналам.
 */
readonly class LogOptionsResolver {

	/**
	 * @param PersonRepository $persons Репозиторий физлиц
	 */
	public function __construct(
		private PersonRepository $persons,
	) {}

	/**
	 * Подписи пользователей WP.
	 *
	 * @param int[] $userIds ID пользователей
	 *
	 * @return array<int, string> id => display_name
	 */
	public function actors( array $userIds ): array {
		$options = array();

		foreach ( $userIds as $uid ) {
			$user            = get_userdata( $uid );
			$options[ $uid ] = $user ? $user->display_name : "User #{$uid}";
		}

		return $options;
	}

	/**
	 * Подписи физлиц.
	 *
	 * @param int[] $personIds ID физлиц
	 *
	 * @return array<int, string> id => ФИО
	 */
	public function persons( array $personIds ): array {
		if ( empty( $personIds ) ) {
			return array();
		}

		$persons = $this->persons->findByIds( $personIds );
		$options = array();

		foreach ( $personIds as $pid ) {
			$options[ $pid ] = isset( $persons[ $pid ] ) ? $persons[ $pid ]->fullName() : "Person #{$pid}";
		}

		return $options;
	}
}
