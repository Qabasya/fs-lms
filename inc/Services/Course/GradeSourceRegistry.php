<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\GradeSourceInterface;

/**
 * Class GradeSourceRegistry
 *
 * Единая точка композиции источников оценок (GradeSourceInterface).
 * GradebookService зависит только от этого реестра и не знает о конкретных
 * источниках — добавление нового источника (напр. Этап 5) сводится к правке
 * конструктора реестра, без изменения GradebookService (OCP).
 *
 * @package Inc\Services\Course
 */
class GradeSourceRegistry {

	/** @var GradeSourceInterface[] */
	private array $sources;

	public function __construct(
		SubmissionGradeSource $submissionSource,
		AssessmentGradeSource $assessmentSource,
	) {
		$this->sources = array( $submissionSource, $assessmentSource );
	}

	/** @return GradeSourceInterface[] */
	public function all(): array {
		return $this->sources;
	}
}
