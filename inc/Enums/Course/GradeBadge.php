<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

use Inc\Enums\Assessment\AssessmentKind;

/**
 * Короткая метка типа работы в журнале/сводке (Эпик 10, решение D9):
 * СР / ПР / ДЗ / ОП / КР / ЭКЗ. Единый бейдж поверх {@see WorkType} и {@see AssessmentKind}.
 *
 * Маппинг: practice→ПР, independent→СР, homework→ДЗ, survey→ОП; control→КР, ege/ege_computer→ЭКЗ.
 *
 * @package Inc\Enums\Course
 */
enum GradeBadge: string {

	case Independent = 'independent'; // СР
	case Practice    = 'practice';    // ПР
	case Homework    = 'homework';    // ДЗ
	case Survey      = 'survey';      // ОП
	case Control     = 'control';     // КР
	case Exam        = 'exam';        // ЭКЗ

	/** Короткая метка для ячейки журнала. */
	public function badge(): string {
		return match ( $this ) {
			self::Independent => 'СР',
			self::Practice    => 'ПР',
			self::Homework    => 'ДЗ',
			self::Survey      => 'ОП',
			self::Control     => 'КР',
			self::Exam        => 'ЭКЗ',
		};
	}

	/** Полное название. */
	public function label(): string {
		return match ( $this ) {
			self::Independent => 'Самостоятельная',
			self::Practice    => 'Практическая',
			self::Homework    => 'Домашнее задание',
			self::Survey      => 'Опрос',
			self::Control     => 'Контрольная',
			self::Exam        => 'Экзамен',
		};
	}

	/** Из {@see WorkType} (сдачи работ). */
	public static function fromWorkType( WorkType $type ): self {
		return match ( $type ) {
			WorkType::Practice    => self::Practice,
			WorkType::Independent => self::Independent,
			WorkType::Homework    => self::Homework,
			WorkType::Survey      => self::Survey,
		};
	}

	/** Из {@see AssessmentKind} (контрольные/экзамены). */
	public static function fromAssessmentKind( AssessmentKind $kind ): self {
		return match ( $kind ) {
			AssessmentKind::Control                              => self::Control,
			AssessmentKind::EgeComputer, AssessmentKind::OgeComputer => self::Exam,
		};
	}
}
