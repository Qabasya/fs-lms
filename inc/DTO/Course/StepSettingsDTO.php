<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Subject\TaskTemplate;

/**
 * Class StepSettingsDTO
 *
 * Настройки решения задачи на шаге урока.
 * Отделены от содержания задачи: хранятся в StepDTO.payload['settings'].
 *
 * Правила перемешивания форсируются по типу задачи:
 *   - ordering → shuffle всегда true (иначе ответ виден)
 *   - fill      → shuffle всегда false
 *   - прочие    → значение из настройки
 *
 * @package Inc\DTO\Course
 */
readonly class StepSettingsDTO {

	/**
	 * @param int  $maxAttempts     0 = без ограничений (по умолчанию).
	 * @param bool $shuffle         Перемешивать варианты / одну колонку сопоставления.
	 * @param int  $hintAfterErrors 0 = подсказка доступна сразу (по умолчанию).
	 */
	public function __construct(
		public int  $maxAttempts     = 0,
		public bool $shuffle         = false,
		public int  $hintAfterErrors = 0,
	) {}

	public static function fromArray( array $data ): self {
		return new self(
			maxAttempts    : max( 0, (int) ( $data['max_attempts'] ?? 0 ) ),
			shuffle        : (bool) ( $data['shuffle'] ?? false ),
			hintAfterErrors: max( 0, (int) ( $data['hint_after_errors'] ?? 0 ) ),
		);
	}

	public function toArray(): array {
		return array(
			'max_attempts'      => $this->maxAttempts,
			'shuffle'           => $this->shuffle,
			'hint_after_errors' => $this->hintAfterErrors,
		);
	}

	/**
	 * Возвращает копию с форсированными правилами перемешивания по типу задачи.
	 * Вызывается при сохранении шага и при отдаче настроек ученику.
	 */
	public function withTypeDefaults( TaskTemplate $template ): self {
		$shuffle = match ( $template ) {
			TaskTemplate::Ordering => true,
			TaskTemplate::Fill     => false,
			default                => $this->shuffle,
		};

		if ( $shuffle === $this->shuffle ) {
			return $this;
		}

		return new self(
			maxAttempts    : $this->maxAttempts,
			shuffle        : $shuffle,
			hintAfterErrors: $this->hintAfterErrors,
		);
	}
}
