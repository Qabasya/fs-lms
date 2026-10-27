<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

/**
 * Trait AuthorFilter
 *
 * Селект «Автор» над таблицами заданий (банк, предметные задания, задачи): набор
 * авторов из найденных записей. Использующему классу нужен {@see Sanitizer}.
 */
trait AuthorFilter {

	/**
	 * Селект «Автор» — только когда авторов больше одного.
	 *
	 * @param object[] $posts Записи списка (нужен `post_author`)
	 *
	 * @return array{name: string, options: array, selected: string, all_label: string}|null
	 */
	private function authorSelectFor( array $posts ): ?array {
		$authorIds = array_unique( array_map( static fn( $p ): int => (int) $p->post_author, $posts ) );

		if ( count( $authorIds ) < 2 ) {
			return null;
		}

		$options = array();
		foreach ( $authorIds as $uid ) {
			$user = get_user_by( 'id', $uid );
			if ( false !== $user ) {
				$options[ $uid ] = $user->display_name;
			}
		}

		return array(
			'name'      => 'author',
			'options'   => $options,
			'selected'  => (string) $this->sanitizeGetInt( 'author' ),
			'all_label' => 'Все авторы',
		);
	}
}
