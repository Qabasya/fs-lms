<?php
/**
 * Фаза момента начала экзамена для ученика и гостя: до старта (далеко / скоро), идёт, завершён.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/**
 * Фаза момента начала экзамена для ученика и гостя: от неё зависят тексты оповещений.
 * Порог «скоро» — {@see self::SOON_SECONDS}; зеркало на клиенте — `src/js/common/exam-start-phase.js`.
 */
enum ExamStartPhase: string {

	/** Секунд до начала, начиная с которых экзамен «скоро начнётся». */
	public const SOON_SECONDS = 300;

	case Far     = 'far';
	case Soon    = 'soon';
	case Running = 'running';
	case Ended   = 'ended';

	/**
	 * Определяет фазу по секундам до начала и до конца окна сеанса (отрицательные считаются прошедшими).
	 */
	public static function of( int $secondsToStart, int $secondsToEnd ): self {
		if ( $secondsToEnd <= 0 ) {
			return self::Ended;
		}
		if ( $secondsToStart <= 0 ) {
			return self::Running;
		}

		return $secondsToStart <= self::SOON_SECONDS ? self::Soon : self::Far;
	}
}
