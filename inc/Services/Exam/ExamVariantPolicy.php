<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Shared\CodedException;
use Inc\Enums\Log\ErrorCode;

/**
 * Валидатор варианта проведения (работа-станция).
 *
 * Вариант проведения — опубликованная работа формата станции, полная и подходящая
 * предмету проведения.
 *
 * @package Inc\Services\Exam
 */
class ExamVariantPolicy {

	public function __construct(
		private readonly AssessmentManager      $assessments,
		private readonly ExamFormatRegistry     $formats,
		private readonly EgeCompletenessChecker $completeness,
	) {}

	/**
	 * Проверка варианта. Возвращает `null` если годится, иначе текст причины отказа.
	 *
	 * @param int    $assessmentId ID варианта (работы)
	 * @param string $subjectKey   Ключ предмета проведения
	 *
	 * @return string|null Причина отказа или `null` если вариант годится
	 */
	public function check( int $assessmentId, string $subjectKey ): ?string {
		$assessment = $this->assessments->get( $assessmentId );

		if ( null === $assessment ) {
			return 'Вариант не найден.';
		}

		if ( $assessment->subjectKey !== $subjectKey ) {
			return 'Вариант относится к другому предмету.';
		}

		if ( ! $assessment->kind->isStation() ) {
			return 'Для проведения подходит только работа формата экзамена.';
		}

		if ( null === $this->formats->for( $assessment->kind ) ) {
			return 'Формат экзамена недоступен: модуль экзаменов выключен.';
		}

		if ( 'publish' !== $assessment->status ) {
			return 'Вариант не опубликован.';
		}

		$result = $this->completeness->validate( $assessment, $subjectKey );
		if ( ! $result->isStrictlyComplete() ) {
			return 'Вариант не укомплектован: ' . $result->summary();
		}

		return null;
	}

	/**
	 * Проверка с выбросом исключения при ошибке.
	 *
	 * @param int    $assessmentId ID варианта
	 * @param string $subjectKey   Ключ предмета
	 *
	 * @throws CodedException с кодом ErrorCode::ExamConflict
	 */
	public function assert( int $assessmentId, string $subjectKey ): void {
		$reason = $this->check( $assessmentId, $subjectKey );

		if ( null !== $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, $reason );
		}
	}

	/**
	 * Список опубликованных годных вариантов предмета.
	 *
	 * Нужен банку вариантов в календаре (этап 4.2).
	 *
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return array[] Массив вариантов: `{id: int, title: string, kind: string, direction: string, duration_minutes: int}`
	 */
	public function listForSubject( string $subjectKey ): array {
		$assessments = $this->assessments->getBankBySubject( $subjectKey, array( 'status' => 'publish' ) );
		$variants = array();

		foreach ( $assessments as $assessment ) {
			if ( ! $assessment->kind->isStation() ) {
				continue;
			}

			$reason = $this->check( $assessment->id, $subjectKey );
			if ( null !== $reason ) {
				continue;
			}

			$format     = $this->formats->for( $assessment->kind );
			$variants[] = array(
				'id'               => $assessment->id,
				'title'            => $assessment->title,
				'kind'             => $assessment->kind->value,
				'direction'        => $format?->direction->value ?? '',
				'duration_minutes' => $format->durationMinutes ?? 0,
			);
		}

		return $variants;
	}
}
