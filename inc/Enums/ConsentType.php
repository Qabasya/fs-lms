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
	 * Возвращает человекочитаемое название типа согласия для UI.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::PdProcessing      => 'Обработка персональных данных',
			self::PdChildProcessing => 'Обработка ПД ребёнка (от лица представителя)',
			self::PdTransfer        => 'Передача ПД третьим лицам',
			self::Marketing         => 'Маркетинговые рассылки',
		};
	}
}
