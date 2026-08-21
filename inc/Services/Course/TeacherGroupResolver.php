<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;

/**
 * Class TeacherGroupResolver
 *
 * Набор групп, доступных пользователю: свои (или все — офис) + группы, которые
 * он замещает. Вынесено из {@see ReviewQueueService} (Tasks.md, задача «Сводка
 * по ученику»), чтобы тот же принцип доступа переиспользовали новые сервисы
 * стартового пикера ученика/курсов, не дублируя логику.
 *
 * @package Inc\Services\Course
 */
class TeacherGroupResolver {

	public function __construct(
		private readonly GroupsRepository $groups,
		private readonly SubstitutionRepository $substitutions,
	) {}

	/**
	 * @param int  $userId    ID текущего пользователя (wp user id)
	 * @param bool $allGroups офис видит все группы, препод — свои + замены
	 *
	 * @return int[]
	 */
	public function idsFor( int $userId, bool $allGroups ): array {
		$ids = array();
		foreach ( $allGroups ? $this->groups->findAll() : $this->groups->findByTeacherId( $userId ) as $g ) {
			$ids[ (int) $g->id ] = true;
		}
		foreach ( $this->substitutions->findUpcomingOrActiveBySubstitute( $userId, current_time( 'Y-m-d' ) ) as $sub ) {
			$ids[ $sub->groupId ] = true;
		}

		return array_keys( $ids );
	}
}
