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
	 * @return list<array{roles: list<string>, layout: mixed}>
	 */
	public function savedLayouts(): array {
		$users = get_users(
			array(
				'blog_id'    => get_current_blog_id(),
				// Выборка только сохранённых раскладок; запускается при открытии статистики.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'     => MetaKeys::HomeLayout->value,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$rows  = array();
		foreach ( $users as $user ) {
			$rows[] = array(
				'roles'  => $user->roles,
				'layout' => get_user_meta( $user->ID, MetaKeys::HomeLayout->value, true ),
			);
		}
		return $rows;
	}
}
