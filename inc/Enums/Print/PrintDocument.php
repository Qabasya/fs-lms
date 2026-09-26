<?php

declare( strict_types=1 );

namespace Inc\Enums\Print;

use Inc\Enums\Log\AuditAction;

/**
 * Enum PrintDocument
 *
 * Формы документов «Центра печати». Значение кейса — одновременно имя
 * шаблона `templates/documents/{value}.{extension()}` и ключ формы в запросе.
 * DOCX — текст с полями `{{…}}`, PDF — готовая форма с полями ввода.
 * Форма без шаблона видна на странице, но сформировать её нельзя.
 *
 * @package Inc\Enums\Print
 */
enum PrintDocument: string {
	case Contract     = 'contract';
	case Consent      = 'consent';
	case TaxDeduction = 'tax_deduction';

	/**
	 * Название формы в выпадающем списке.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Contract     => 'Договор',
			self::Consent      => 'Согласие на обработку ПД',
			self::TaxDeduction => 'Справка на вычет',
		};
	}

	/**
	 * Текст кнопки формирования.
	 */
	public function buttonLabel(): string {
		return match ( $this ) {
			self::Contract     => 'Сформировать договор',
			self::Consent      => 'Сформировать согласие',
			self::TaxDeduction => 'Сформировать справку',
		};
	}

	/**
	 * Действие в журнале «Зачисления» при формировании документа.
	 */
	public function auditAction(): AuditAction {
		return match ( $this ) {
			self::Contract     => AuditAction::ContractPrinted,
			self::Consent      => AuditAction::ConsentPrinted,
			self::TaxDeduction => AuditAction::TaxDeductionPrinted,
		};
	}

	/**
	 * Формат шаблона и готового файла.
	 */
	public function extension(): string {
		return self::TaxDeduction === $this ? 'pdf' : 'docx';
	}

	public function mimeType(): string {
		return 'pdf' === $this->extension()
			? 'application/pdf'
			: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	}

	/**
	 * Форме нужны данные, которых нет в системе (номер справки, год, сумма) —
	 * их вводят на странице перед формированием.
	 */
	public function needsInput(): bool {
		return self::TaxDeduction === $this;
	}

	/**
	 * Начало имени скачиваемого файла.
	 */
	public function filePrefix(): string {
		return match ( $this ) {
			self::Contract     => 'Договор',
			self::Consent      => 'Согласие на обработку ПД',
			self::TaxDeduction => 'Справка на вычет',
		};
	}
}
