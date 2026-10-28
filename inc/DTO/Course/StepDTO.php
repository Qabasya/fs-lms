<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Course\StepType;

/**
 * Class StepDTO
 *
 * Один шаг последовательности урока/работы/контрольной (Courses.md → ★).
 * Хранится в meta-массиве `steps[]` сущности. `key` стабилен (переживает реордер;
 * к нему привязаны прогресс и гейт) и генерируется в сервисе при добавлении шага —
 * не в DTO (DTO без WP-вызовов и побочных эффектов).
 *
 * @package Inc\DTO\Course
 */
readonly class StepDTO {

	/**
	 * @param string               $key     Стабильный идентификатор шага.
	 * @param StepType             $type    Тип шага.
	 * @param array<string, mixed> $payload Поля по типу:
	 *   text:       { content }
	 *   video:      { url }
	 *   broadcast:  { title, stream_url }
	 *   task:       { ref, source }   source: subject|bank
	 *   work:       { ref }
	 *   assessment: { ref }
	 * @param string               $workType Тип работы шага `work` (practice|independent|homework|survey): '' — не задан.
	 *   Живёт вне payload: нужен у шага без ссылки (новая работа получает название и ограничение шаблонов по типу).
	 */
	public function __construct(
		public string   $key,
		public StepType $type,
		public array    $payload,
		public string   $workType = '',
	) {}

	public static function fromArray( array $data ): self {
		$payload = $data['payload'] ?? array();

		return new self(
			key    : (string) ( $data['key'] ?? '' ),
			type   : StepType::fromValueOrDefault( (string) ( $data['type'] ?? '' ) ),
			payload: is_array( $payload ) ? $payload : array(),
			workType: (string) ( $data['work_type'] ?? '' ),
		);
	}

	public function toArray(): array {
		$data = array(
			'key'     => $this->key,
			'type'    => $this->type->value,
			'payload' => $this->payload,
		);
		if ( '' !== $this->workType ) {
			$data['work_type'] = $this->workType;
		}

		return $data;
	}

	/**
	 * Десериализует meta-массив `steps[]` в список DTO (нечитаемые элементы отбрасываются).
	 *
	 * @param array<int, mixed> $rows
	 *
	 * @return self[]
	 */
	public static function fromList( array $rows ): array {
		$steps = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$step = self::fromArray( $row );
			// Шаг «Трансляция» в уроке больше не хранится (его строит плеер): остатки в
			// старых данных молча пропускаем, пока их не удалила миграция.
			if ( $step->type->isAuthorable() ) {
				$steps[] = $step;
			}
		}

		return $steps;
	}

	/**
	 * Сериализует список DTO в meta-массив `steps[]`.
	 *
	 * @param self[] $steps
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function toList( array $steps ): array {
		return array_map( static fn( self $step ): array => $step->toArray(), $steps );
	}
}
