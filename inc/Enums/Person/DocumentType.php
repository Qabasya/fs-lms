<?php

declare(strict_types=1);

namespace Inc\Enums\Person;

enum DocumentType: string {
	/** Внутренний паспорт гражданина */
	case Pass = 'pass';

	/** Свидетельство о рождении (<14 лет) */
	case BirthCertificate = 'birth_certificate';

	/** Заграничный паспорт */
	case ForeignPass = 'foreign_pass';

	/**
	 * Возвращает человекочитаемое название типа документа.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pass      => 'Паспорт',
			self::BirthCertificate => 'Свидетельство о рождении',
			self::ForeignPass  => 'Иностранный паспорт',
		};
	}
}
