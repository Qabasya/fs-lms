<?php
/**
 * Статистика настроек главной страницы.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Enums\Access\UserRole;
use Inc\Repositories\Profile\HomeLayoutRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;

/** Агрегаты сохранённых предпочтений; автоматические плитки не считаются выбранными. */
class HomeLayoutStatisticsService {

	/** Зависимости чтения и нормализации предпочтений. */
	public function __construct(
		private readonly HomeLayoutRepository $repository,
		private readonly HomeLayoutService $layouts,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Подсчитывает выбор секций и плиток по сохранённым раскладкам.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$sections = array(
			'tiles'     => array(
				'label'     => 'Плитки',
				'positions' => array( 0, 0, 0 ),
			),
			'schedule'  => array(
				'label'     => 'Расписание',
				'positions' => array( 0, 0, 0 ),
			),
			'deadlines' => array(
				'label'     => 'Дедлайны и оценки',
				'positions' => array( 0, 0, 0 ),
			),
			'attention' => array(
				'label'     => 'Требует внимания',
				'positions' => array( 0, 0, 0 ),
			),
		);
		$labels   = array(
			'deadlines'     => 'Дедлайнов',
			'attendance'    => 'Посещаемость',
			'streak'        => 'Серия без пропусков',
			'to_fix'        => 'Осталось исправить',
			'last_result'   => 'Последний результат',
			'lessons_today' => 'Занятий сегодня',
			'to_review'     => 'На проверке',
		);
		foreach ( $this->subjects->readAll() as $subject ) {
			$labels[ 'exam:' . $subject->key ]    = 'До экзамена · ' . $subject->name;
			$labels[ 'ranking:' . $subject->key ] = 'Рейтинг · ' . $subject->name;
		}
		$tiles = array();
		foreach ( $labels as $id => $label ) {
			$tiles[ $id ] = array(
				'label' => $label,
				'users' => 0,
			);
		}
		$total     = 0;
		$automatic = 0;
		foreach ( $this->repository->savedLayouts() as $row ) {
			$role = UserRole::primaryViewRole( $row['roles'] );
			if ( null === $role && UserRole::FSOffice !== UserRole::primaryForCabinet( $row['roles'] ) ) {
				continue;
			}
			$role ??= UserRole::FSOffice;
			$raw    = $row['layout'];
			if ( ! is_array( $raw ) || ! isset( $raw['sections'] ) || ! is_array( $raw['sections'] ) ) {
				continue;
			}
			$layout = $this->layouts->normalize( $raw, $role );
			// Повреждённые/устаревшие секции не превращаем в статистику порядка по умолчанию.
			if ( array_values( $raw['sections'] ) !== $layout['sections'] ) {
				continue;
			}
			++$total;
			foreach ( $layout['sections'] as $position => $id ) {
				++$sections[ $id ]['positions'][ $position ];
			}
			if ( null === $layout['tiles'] ) {
				++$automatic;
				continue;
			}
			foreach ( $layout['tiles'] as $id ) {
				if ( ! isset( $tiles[ $id ] ) ) {
					$tiles[ $id ] = array(
						'label' => $id,
						'users' => 0,
					);
				}
				++$tiles[ $id ]['users'];
			}
		}
		return array(
			'users'     => $total,
			'automatic' => $automatic,
			'sections'  => $sections,
			'tiles'     => $tiles,
		);
	}
}
