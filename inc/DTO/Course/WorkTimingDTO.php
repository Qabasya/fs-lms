<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class WorkTimingDTO
 *
 * Замер времени работы-шага от плеера: сколько прошло с открытия работы и
 * как давно ученик менял ответ на каждое задание. Всё — в секундах ДО сдачи:
 * плеер считает по своим часам, сервер откладывает от своего «сейчас», поэтому
 * расхождение часов ученика и сервера на результат не влияет.
 *
 * @package Inc\DTO\Course
 */
readonly class WorkTimingDTO {

	/**
	 * @param int|null        $elapsedSec    Секунд от открытия работы до сдачи; null — замера нет.
	 * @param array<int, int> $answeredAgoSec taskId → сколько секунд назад менялся ответ.
	 */
	public function __construct(
		public ?int  $elapsedSec = null,
		public array $answeredAgoSec = array(),
	) {}

	/** Момент последнего ответа на задание (локальное время WP) или null. */
	public function answeredAt( int $taskId, string $now ): ?string {
		$ago = $this->answeredAgoSec[ $taskId ] ?? null;
		if ( null === $ago ) {
			return null;
		}

		// Ответ не мог быть дан раньше открытия работы.
		if ( null !== $this->elapsedSec ) {
			$ago = min( $ago, $this->elapsedSec );
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - $ago );
	}

	/**
	 * Из сырого JSON плеера: `{"elapsed": 120, "answered": {"12": 30}}`.
	 * Отрицательные и нечисловые значения отбрасываются.
	 */
	public static function fromArray( array $raw ): self {
		$elapsed = isset( $raw['elapsed'] ) && is_numeric( $raw['elapsed'] ) && $raw['elapsed'] >= 0
			? (int) $raw['elapsed']
			: null;

		$answered = array();
		foreach ( (array) ( $raw['answered'] ?? array() ) as $taskId => $ago ) {
			if ( is_numeric( $ago ) && $ago >= 0 && (int) $taskId > 0 ) {
				$answered[ (int) $taskId ] = (int) $ago;
			}
		}

		return new self( $elapsed, $answered );
	}
}
