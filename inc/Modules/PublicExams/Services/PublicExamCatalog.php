<?php

declare( strict_types=1 );

namespace Inc\Modules\PublicExams\Services;

use Inc\Managers\Assessment\AssessmentManager;

/**
 * Class PublicExamCatalog
 *
 * Каталог публичных экзаменов предмета для раздела `/{key}/exams/` и подсказки
 * годов в конструкторе. Данные — через {@see AssessmentManager}, без собственных запросов.
 *
 * @package Inc\Modules\PublicExams\Services
 */
class PublicExamCatalog {

	public function __construct(
		private readonly AssessmentManager $assessments,
		private readonly PublicExamPolicy  $policy,
	) {}

	/**
	 * Опубликованные публичные экзамены предмета, сгруппированные по году
	 * (свежие годы сверху; внутри года — по названию).
	 *
	 * @return array<string, array<int, array{title: string, url: string}>>
	 */
	public function groups( string $subjectKey ): array {
		$groups = array();

		foreach ( $this->assessments->getBankBySubject( $subjectKey, array( 'post_status' => 'publish' ) ) as $assessment ) {
			$year = $this->policy->year( $assessment->id );
			if ( '' === $year || ! $this->policy->isPublic( $assessment ) ) {
				continue;
			}

			$groups[ $year ][] = array(
				'title' => $assessment->title,
				'url'   => (string) get_permalink( $assessment->id ),
			);
		}

		krsort( $groups, SORT_NUMERIC );

		return $groups;
	}

	/**
	 * Годы, уже введённые у экзаменов предмета (любой статус) — подсказки поля «Год».
	 *
	 * @return string[]
	 */
	public function years( string $subjectKey ): array {
		$years = array();

		foreach ( $this->assessments->getBankBySubject( $subjectKey, array( 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ) ) ) as $assessment ) {
			$year = $this->policy->year( $assessment->id );
			if ( '' !== $year ) {
				$years[ $year ] = $year;
			}
		}

		krsort( $years, SORT_NUMERIC );

		return array_values( $years );
	}
}
