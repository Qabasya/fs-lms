<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Course\ContentUsageService;

/**
 * Какой вид работы можно сохранить (этап 6.6.3).
 *
 * Станция экзамена (ЕГЭ/ОГЭ) живёт вне курса и шагом урока быть не может. Поэтому работе, которая уже стоит в уроке контрольной,
 * вид «экзамен» не назначается: она остаётся с прежним видом, а автору показывается предупреждение. Работа, уже ставшая станцией
 * (dev-данные), сохраняется как есть.
 */
class AssessmentKindGuard {

	/** Текст предупреждения автору в админке. */
	public const BLOCKED_MESSAGE = 'Работа используется в уроках как контрольная: вид «экзамен» недоступен.';

	public function __construct(
		private readonly AssessmentManager $assessments,
		private readonly ContentUsageService $usage,
	) {}

	/**
	 * Вид, который будет сохранён: запрошенный либо, если смена на станцию запрещена, прежний.
	 */
	public function allowedKind( int $assessmentId, AssessmentKind $requested ): AssessmentKind {
		if ( ! $requested->isStation() ) {
			return $requested;
		}

		$current = $this->assessments->get( $assessmentId )->kind ?? AssessmentKind::Control;
		if ( $current->isStation() ) {
			return $requested;
		}

		return $this->isUsedInLessons( $assessmentId ) ? $current : $requested;
	}

	/** Стоит ли работа шагом хотя бы одного урока (черновики и архив тоже считаются). */
	public function isUsedInLessons( int $assessmentId ): bool {
		return array() !== $this->usage->usageList( 'assessment', $assessmentId );
	}
}
