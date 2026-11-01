<?php
/**
 * Статистика настроек главной страницы.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Repositories\Profile;

use Inc\Enums\Wp\MetaKeys;

/** Читает сохранённые раскладки существующих пользователей текущего сайта. */
class HomeLayoutRepository {

	/**
	 * Читает раскладки и роли без персональных данных.
	 *
	 * @return iterable<array{roles: list<string>, layout: mixed}>
	 */
	public function savedLayouts(): iterable {
		$offset = 0;
		do {
			$users = get_users(
				array(
					'blog_id' => get_current_blog_id(),
					'number'  => 200,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
					// Выборка только сохранённых раскладок; запускается при открытии статистики.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => array(
					array(
						'key'     => MetaKeys::HomeLayout->value,
						'compare' => 'EXISTS',
					),
				),
				)
			);
			foreach ( $users as $user ) {
				yield array(
					'roles'  => $user->roles,
					'layout' => get_user_meta( $user->ID, MetaKeys::HomeLayout->value, true ),
				);
			}
			$batchSize = count( $users );
			$offset   += $batchSize;
		} while ( 200 === $batchSize );
	}
}
