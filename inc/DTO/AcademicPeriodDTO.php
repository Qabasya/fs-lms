<?php

declare(strict_types=1);

namespace Inc\DTO;

/**
 * Class AcademicPeriodDTO
 *
 * Data Transfer Object для передачи данных об учебном периоде (год/семестр).
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — обеспечивает строгую типизацию данных учебного периода.
 * 2. **Фабричные методы** — преобразование из массива в DTO и обратно.
 *
 * ### Архитектурная роль:
 *
 * Используется для передачи данных об учебных периодах между слоями:
 * - Из AcademicPeriodRepository в AcademicPeriodService
 * - Из AcademicPeriodService в AcademicPeriodCallbacks
 */
readonly class AcademicPeriodDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $id         Уникальный идентификатор периода (например, '2025_2026')
	 * @param string $name       Отображаемое название периода (например, '2025-2026 учебный год')
	 * @param string $start_date Дата начала периода (Y-m-d)
	 * @param string $end_date   Дата окончания периода (Y-m-d)
	 * @param bool   $is_current Флаг текущего периода (только один может быть активным)
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $start_date,
		public string $end_date,
		public bool $is_current = false,
	) {}

	/**
	 * Создаёт DTO из массива данных.
	 *
	 * @param array<string, mixed> $data Массив с ключами 'id', 'name', 'start_date', 'end_date', 'is_current'
	 *
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id:         (string) ( $data['id'] ?? '' ),
			name:       (string) ( $data['name'] ?? '' ),
			start_date: (string) ( $data['start_date'] ?? '' ),
			end_date:   (string) ( $data['end_date'] ?? '' ),
			is_current: (bool) ( $data['is_current'] ?? false ),
		);
	}

	/**
	 * Преобразует DTO в массив для сохранения в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'name'       => $this->name,
			'start_date' => $this->start_date,
			'end_date'   => $this->end_date,
			'is_current' => $this->is_current,
		);
	}
}
