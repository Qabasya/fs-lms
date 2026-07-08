<?php

declare( strict_types=1 );

namespace Inc\Enums\Assessment;

enum AssessmentKind: string {

	case Control     = 'control';
	case Ege         = 'ege';
	case EgeComputer = 'ege_computer';

	public function label(): string {
		return match ( $this ) {
			self::Control     => 'Контрольная',
			self::Ege         => 'ЕГЭ',
			self::EgeComputer => 'Компьютерный ЕГЭ',
		};
	}

	/** Активная попытка блокирует весь контент ученика. */
	public function locksContent(): bool {
		return true;
	}

	/** Правильные ответы ученику не показываются после сдачи. */
	public function hidesAnswers(): bool {
		return true;
	}

	/** Каждое задание имеет собственный балл (task_points); для Control вес = 1 имплицитно. */
	public function usesWeightedScore(): bool {
		return match ( $this ) {
			self::Ege, self::EgeComputer => true,
			default                      => false,
		};
	}

	/**
	 * Бинарное оценивание «верно/неверно»: каждое задание весит ровно 1 балл
	 * (max = 1), частичный балл и критерии игнорируются (D16.1). Только для
	 * Control; ЕГЭ/КЕГЭ используют взвешенный балл (см. {@see usesWeightedScore()}).
	 */
	public function binaryScoring(): bool {
		return match ( $this ) {
			self::Control => true,
			default       => false,
		};
	}

	/** Первичный балл переводится во вторичный по score_map работы. */
	public function needsSecondaryScore(): bool {
		return match ( $this ) {
			self::Ege, self::EgeComputer => true,
			default                      => false,
		};
	}

	/** Составные шаблоны (ThreeInOne) разворачиваются в отдельно оцениваемые элементы. */
	public function expandsComposites(): bool {
		return match ( $this ) {
			self::Ege, self::EgeComputer => true,
			default                      => false,
		};
	}

	/** Показывает мягкое предупреждение при неполном покрытии {key}_task_number. */
	public function needsCompletenessCheck(): bool {
		return match ( $this ) {
			self::Ege, self::EgeComputer => true,
			default                      => false,
		};
	}

	/** В плеере показываются только условия и поля ответа; решения и task_code исключаются. */
	public function answersOnly(): bool {
		return true;
	}

	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Control;
	}

	/** @return array<int, string> Значения видов с повзадачным баллом (ЕГЭ). */
	public static function weightedScoreValues(): array {
		return array_values( array_map(
			static fn( self $case ) => $case->value,
			array_filter( self::cases(), static fn( self $case ) => $case->usesWeightedScore() )
		) );
	}

	/** @return array<int, array{value: string, label: string}> */
	public static function options(): array {
		return array_map(
			static fn( self $case ) => [ 'value' => $case->value, 'label' => $case->label() ],
			self::cases()
		);
	}
}
