<?php

declare( strict_types=1 );

namespace Inc\Enums\Lead;

/**
 * Исход проверки заявки с лид-формы сайта.
 */
enum LeadVerdict: string {

	case Accepted = 'accepted';
	case Rejected = 'rejected';

	public function label(): string {
		return match ( $this ) {
			self::Accepted => 'Принята',
			self::Rejected => 'Отклонена',
		};
	}
}
