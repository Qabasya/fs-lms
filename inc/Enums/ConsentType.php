<?php

namespace Inc\Enums;

enum ConsentType: string {
	/** Согласие на обработку персональных данных */
	case PdProcessing = 'pd_processing';

	/** Согласие на обработку персональных данных ребёнка */
	case PdChildProcessing = 'pd_child_processing';

	/** Согласие на передачу персональных данных */
	case PdTransfer = 'pd_transfer';

	/** Согласие на маркетинговые рассылки */
	case Marketing = 'marketing';

	/**
	 * Возвращает относительный путь к файлу шаблона согласия для конкретной версии.
	 *
	 * @param string $version Версия шаблона (например, 'v1', 'v2')
	 *
	 * @return string
	 */
	public function templateFile( string $version ): string {
		return match ( $this ) {
			self::PdProcessing      => "templates/consents/{$version}/pd_processing.html",
			self::PdChildProcessing => "templates/consents/{$version}/pd_child_processing.html",
			self::PdTransfer        => "templates/consents/{$version}/pd_transfer.html",
			self::Marketing         => "templates/consents/{$version}/marketing.html",
		};
	}
}
