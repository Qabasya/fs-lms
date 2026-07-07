<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

/**
 * Class EgeCompletenessResult
 *
 * Вердикт строгой проверки укомплектованности ЕГЭ-работы (D16.2): биекция
 * задание ↔ номер таксономии `{key}_task_number` (ровно одно задание на каждый
 * номер, все номера покрыты, без дублей и «сирот»).
 *
 * @package Inc\DTO\Assessment
 */
readonly class EgeCompletenessResult {

	/**
	 * @param string[] $missing       Номера-термы без назначенного задания.
	 * @param string[] $duplicated    Номера, покрытые более чем одним заданием.
	 * @param int[]    $orphans       task_id без номера (или с номером вне таксономии).
	 * @param int      $expectedCount Число термов таксономии (ожидаемое число заданий).
	 * @param int      $actualCount   Фактическое число заданий в работе.
	 */
	public function __construct(
		public array $missing,
		public array $duplicated,
		public array $orphans,
		public int   $expectedCount,
		public int   $actualCount,
	) {}

	/** Строгая укомплектованность: биекция 1:1 без пропусков, дублей и сирот. */
	public function isStrictlyComplete(): bool {
		return empty( $this->missing )
			&& empty( $this->duplicated )
			&& empty( $this->orphans )
			&& $this->expectedCount > 0
			&& $this->actualCount === $this->expectedCount;
	}

	/**
	 * Человекочитаемая сводка проблем для admin-notice / ответа API.
	 * Пустая строка, если работа укомплектована.
	 */
	public function summary(): string {
		if ( $this->isStrictlyComplete() ) {
			return '';
		}

		$parts = array();
		if ( ! empty( $this->missing ) ) {
			$parts[] = 'не заполнены номера: ' . implode( ', ', $this->missing );
		}
		if ( ! empty( $this->duplicated ) ) {
			$parts[] = 'дублируются номера: ' . implode( ', ', $this->duplicated );
		}
		if ( ! empty( $this->orphans ) ) {
			$parts[] = 'заданий без номера: ' . count( $this->orphans );
		}
		if ( $this->expectedCount <= 0 ) {
			$parts[] = 'у предмета не заданы номера заданий';
		}

		return implode( '; ', $parts );
	}
}
