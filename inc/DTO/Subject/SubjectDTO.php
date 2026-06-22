<?php

declare( strict_types=1 );

namespace Inc\DTO\Subject;

/**
 * Class SubjectDTO
 *
 * Data Transfer Object для передачи данных о предмете.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — обеспечивает строгую типизацию данных предмета.
 * 2. **Инкапсуляция данных** — объединяет ключ и название предмета в один объект.
 * 3. **Фабричные методы** — преобразование из массива в DTO и обратно.
 *
 * ### Архитектурная роль:
 *
 * Используется для передачи данных между слоями:
 * - Из SubjectRepository в SubjectController
 * - Из SubjectController в представление (шаблон)
 */
readonly class SubjectDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param string $key  Уникальный идентификатор предмета (slug), например 'math' или 'physics'
	 * @param string $name Отображаемое название предмета, например 'Математика' или 'Физика'
	 */
	public function __construct(
		public string $key,
		public string $name,
		public bool   $archived = false,
	) {}

	/**
	 * Создаёт DTO из массива.
	 *
	 * @param array<string, mixed> $data Массив с ключами 'key', 'name', опц. 'archived'
	 *
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			key:      (string) ( $data['key'] ?? '' ),
			name:     (string) ( $data['name'] ?? '' ),
			archived: (bool) ( $data['archived'] ?? false ),
		);
	}

	/**
	 * Преобразует DTO в массив.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'      => $this->key,
			'name'     => $this->name,
			'archived' => $this->archived,
		);
	}
}