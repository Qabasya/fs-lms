<?php

declare( strict_types=1 );

namespace Inc\Modules\PublicExams\Services;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Managers\Wp\PostManager;

/**
 * Class PublicExamPolicy
 *
 * Единственная точка решения «этот экзамен открыт всем без авторизации»:
 * опубликован, это станция ЕГЭ (лист ответов с эталоном есть только у неё) и
 * автор включил флажок «Публичный экзамен». Ею пользуются и страница экзамена,
 * и AJAX-расчёт листа — черновик или непубличный экзамен гость не получит ни
 * там, ни там.
 *
 * @package Inc\Modules\PublicExams\Services
 */
class PublicExamPolicy {

	/** Ключи меты экзамена (`fs_lms_meta`), которыми владеет модуль. */
	public const META_PUBLIC = 'is_public';
	public const META_YEAR   = 'exam_year';

	public function __construct(
		private readonly PostManager $posts,
	) {}

	public function isPublic( AssessmentDTO $assessment ): bool {
		if ( 'publish' !== $assessment->status || AssessmentKind::EgeComputer !== $assessment->kind ) {
			return false;
		}

		return in_array( $this->meta( $assessment->id )[ self::META_PUBLIC ] ?? null, array( 1, '1', true ), true );
	}

	/** Год экзамена (4 цифры) либо '' — не задан. */
	public function year( int $assessmentId ): string {
		$year = trim( (string) ( $this->meta( $assessmentId )[ self::META_YEAR ] ?? '' ) );

		return 1 === preg_match( '/^\d{4}$/', $year ) ? $year : '';
	}

	/** @return array<string, mixed> */
	private function meta( int $assessmentId ): array {
		return $this->posts->taskMeta( $assessmentId );
	}
}
