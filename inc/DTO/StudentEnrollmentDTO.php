<?php

declare(strict_types=1);

namespace Inc\DTO;

/**
 * Class StudentEnrollmentDTO
 *
 * Data Transfer Object для передачи данных о привязке ученика к учебному периоду.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — обеспечивает строгую типизацию данных о зачислении ученика.
 * 2. **Фабричные методы** — преобразование из массива в DTO и обратно.
 * 3. **Уникальный ключ** — генерация составного ключа для хранения в репозитории.
 *
 * ### Архитектурная роль:
 *
 * Используется для передачи данных о том, в каком классе и группе учится ученик
 * в конкретном учебном периоде. Применяется в StudentPeriodMatrixRepository
 * и AcademicPeriodService.
 */
readonly class StudentEnrollmentDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int    $student_id ID ученика
	 * @param string $period_id  ID учебного периода
	 * @param int    $class_num  Номер класса (например, 10, 11)
	 * @param string $group_id   ID группы ученика (например, 'phys_a')
	 */
	public function __construct(
		public int    $student_id,
		public string $period_id,
		public int    $class_num = 0,
		public string $group_id  = '',
	) {}

	/**
	 * Создаёт DTO из массива данных.
	 *
	 * @param array<string, mixed> $data Массив с ключами 'student_id', 'period_id', 'class_num', 'group_id'
	 *
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			student_id: (int)    ( $data['student_id'] ?? 0 ),
			period_id:  (string) ( $data['period_id'] ?? '' ),
			class_num:  (int)    ( $data['class_num'] ?? 0 ),
			group_id:   (string) ( $data['group_id'] ?? '' ),
		);
	}

	/**
	 * Преобразует DTO в массив для сохранения в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'student_id' => $this->student_id,
			'period_id'  => $this->period_id,
			'class_num'  => $this->class_num,
			'group_id'   => $this->group_id,
		);
	}

	/**
	 * Генерирует уникальный ключ для хранения в опции.
	 * Формат: usr_{student_id}_{period_id}
	 *
	 * @return string
	 */
	public function storageKey(): string {
		return "usr_{$this->student_id}_{$this->period_id}";
	}
}