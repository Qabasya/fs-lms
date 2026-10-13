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
	 */
	public function __construct(
		public string   $key,
		public StepType $type,
		public array    $payload,
	) {}

	public static function fromArray( array $data ): self {
		$payload = $data['payload'] ?? array();

		return new self(
			key    : (string) ( $data['key'] ?? '' ),
			type   : StepType::fromValueOrDefault( (string) ( $data['type'] ?? '' ) ),
			payload: is_array( $payload ) ? $payload : array(),
		);
	}

	public function toArray(): array {
		return array(
			'key'     => $this->key,
			'type'    => $this->type->value,
			'payload' => $this->payload,
		);
	}

	/**
	 * Ссылки из HTML (уникальные, отсортированные): у лекции-дубликата автор меняет
	 * именно их. Зеркало `reviewLinks()` в step-editor.js.
	 *
	 * @return string[]
	 */
	public static function linksOf( string $html ): array {
		$found = preg_match_all( '#https?://[^\s"\'<>]+#i', str_replace( '&amp;', '&', $html ), $matches );
		$links = false === $found ? array() : array_values( array_unique( $matches[0] ) );
		sort( $links );

		return $links;
	}

	/**
	 * Копия шага с меткой «дубликат — контент не изменён». Трансляцию не метим: её
	 * содержимое не меняется никогда. У лекции со ссылками метка помнит исходный набор
	 * ссылок — снимается только когда он изменился, а не от любой правки текста.
	 */
	public function markedForReview(): self {
		if ( StepType::Broadcast === $this->type ) {
			return $this;
		}

		$extra = array( 'needs_review' => true );

		if ( StepType::Text === $this->type ) {
			$links = self::linksOf( (string) ( $this->payload['content'] ?? '' ) );
			if ( array() !== $links ) {
				$extra['review_links'] = $links;
			}
		}

		return new self( $this->key, $this->type, array_merge( $this->payload, $extra ) );
	}

	/**
	 * Метка «не изменён» ещё действует: у лекции с запомненными ссылками — пока набор
	 * ссылок прежний, у остальных шагов — пока метку не сняла правка.
	 */
	public function isUnreviewed(): bool {
		if ( empty( $this->payload['needs_review'] ) ) {
			return false;
		}

		$origin = $this->payload['review_links'] ?? null;
		if ( StepType::Text === $this->type && is_array( $origin ) ) {
			return self::linksOf( (string) ( $this->payload['content'] ?? '' ) ) === array_values( $origin );
		}

		return true;
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
