<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class CheckResultDTO
 *
 * Результат авто-проверки ответа ученика на задание.
 *
 * @package Inc\DTO\Task
 */
readonly class CheckResultDTO {

	/**
	 * @param bool    $isCorrect    Верно ли в целом (score === maxScore для всех типов, кроме Triple).
	 * @param float   $score        Набранный балл.
	 * @param float   $maxScore     Максимальный балл за задачу.
	 * @param array   $itemFeedback Детализация по элементам (пропускам, парам и т.д.) — опционально.
	 *                              Формат: [ index => bool|null ].
	 */
	public function __construct(
		public bool  $isCorrect,
		public float $score,
		public float $maxScore,
		public array $itemFeedback = array(),
	) {}

	public static function correct( float $max = 1.0 ): self {
		return new self( true, $max, $max );
	}

	public static function incorrect( float $max = 1.0 ): self {
		return new self( false, 0.0, $max );
	}

	/** Частичный балл; isCorrect = true только если score >= maxScore. */
	public static function partial( float $score, float $max, array $itemFeedback = array() ): self {
		return new self( $score >= $max, max( 0.0, min( $score, $max ) ), $max, $itemFeedback );
	}
}
