<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class StudentGroupDTO
 *
 * Data Transfer Object для передачи данных о студенческой группе.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — обеспечивает строгую типизацию данных группы.
 * 2. **Фабричные методы** — создание DTO из массива и обратное преобразование.
 *
 * ### Архитектурная роль:
 *
 * Используется для изоляции данных и их валидации между репозиторием, сервисом и представлением.
 */
readonly class StudentGroupDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $id         Уникальный идентификатор группы (автогенерируемый слаг)
	 * @param string $title      Название группы
	 * @param string $period_id  ID академического периода, к которому привязана группа
	 * @param string $subject_id ID предмета, к которому относится группа
	 * @param int    $teacher_id ID пользователя WordPress (преподавателя)
	 */
	public function __construct(
		public string $id,
		public string $title,
		public string $period_id,
		public string $subject_id,
		public int    $teacher_id,
		public array  $schedule = [],
	) {
	}

	/**
	 * Преобразует DTO в плоский массив для сохранения в wp_options.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'title'      => $this->title,
			'period_id'  => $this->period_id,
			'subject_id' => $this->subject_id,
			'teacher_id' => $this->teacher_id,
			'schedule'   => $this->schedule,
		);
	}

	/**
	 * Создаёт экземпляр DTO из массива данных.
	 *
	 * @param array<string, mixed> $data Массив данных из базы или формы
	 *
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id:         (string) ( $data['id'] ?? '' ),
			title:      (string) ( $data['title'] ?? '' ),
			period_id:  (string) ( $data['period_id'] ?? '' ),
			subject_id: (string) ( $data['subject_id'] ?? '' ),
			teacher_id: (int) ( $data['teacher_id'] ?? 0 ),
			schedule:   (array) ( $data['schedule'] ?? [] ),
		);
	}
}