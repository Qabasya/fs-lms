<?php

declare( strict_types=1 );

namespace Inc\Enums\Print;

use Inc\Enums\Log\AuditAction;

/**
 * Enum PrintDocument
 *
 * Формы документов «Центра печати». Значение кейса — одновременно имя
 * DOCX-шаблона в `templates/documents/{value}.docx` и ключ формы в запросе.
 * Форма без шаблона видна на странице, но сформировать её нельзя.
 *
 * @package Inc\Enums\Print
 */
enum PrintDocument: string {
	case Contract     = 'contract';
	case TaxDeduction = 'tax_deduction';

	/**
	 * Название формы в выпадающем списке.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Contract     => 'Договор',
			self::TaxDeduction => 'Справка на вычет',
		};
	}

	/**
	 * Текст кнопки формирования.
	 */
	public function buttonLabel(): string {
		return match ( $this ) {
			self::Contract     => 'Сформировать договор',
			self::TaxDeduction => 'Сформировать справку',
		};
	}

	/**
	 * Действие в журнале «Зачисления» при формировании документа.
	 */
	public function auditAction(): AuditAction {
		return match ( $this ) {
			self::Contract     => AuditAction::ContractPrinted,
			self::TaxDeduction => AuditAction::TaxDeductionPrinted,
		};
	}

	/**
	 * Начало имени скачиваемого файла.
	 */
	public function filePrefix(): string {
		return match ( $this ) {
			self::Contract     => 'Договор',
			self::TaxDeduction => 'Справка на вычет',
		};
	}
}
