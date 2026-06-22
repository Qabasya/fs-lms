<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Enrollment\AcademicPeriodService;

/**
 * Class SubjectArchiveGuard
 *
 * Определяет «активные» группы предмета — группы в ТЕКУЩЕМ учебном периоде.
 * Наличие хотя бы одной активной группы блокирует архивацию предмета:
 * нельзя «убрать в корзину» предмет, по которому ещё идёт обучение.
 * Группы прошедших периодов активными не считаются (история, можно архивировать).
 *
 * @package Inc\Services\Subject
 */
class SubjectArchiveGuard {

	public function __construct(
		private readonly GroupsRepository         $groups,
		private readonly AcademicPeriodRepository $periods,
		private readonly AcademicPeriodService    $periodService,
	) {}

	/**
	 * Группы предмета в текущем периоде (незавершённые).
	 *
	 * @param string $subjectKey Ключ предмета.
	 * @return array<int, object> Список групп (пустой, если активных нет).
	 */
	public function activeGroups( string $subjectKey ): array {
		$currentId = $this->currentPeriodId();
		if ( '' === $currentId ) {
			return array();
		}

		return $this->groups->findByPeriodAndSubject( $currentId, $subjectKey );
	}

	/**
	 * Есть ли у предмета активные группы (блокирующие архивацию).
	 *
	 * @param string $subjectKey Ключ предмета.
	 * @return bool
	 */
	public function hasActiveGroups( string $subjectKey ): bool {
		return array() !== $this->activeGroups( $subjectKey );
	}

	/**
	 * ID текущего учебного периода ('' — если текущий период не задан).
	 *
	 * @return string
	 */
	private function currentPeriodId(): string {
		$dtos = array_map(
			static fn( array $p ): AcademicPeriodDTO => AcademicPeriodDTO::fromArray( $p ),
			$this->periods->readAll()
		);

		$current = $this->periodService->getSortedPeriods( $dtos )['current'];

		return $current['id'] ?? '';
	}
}
