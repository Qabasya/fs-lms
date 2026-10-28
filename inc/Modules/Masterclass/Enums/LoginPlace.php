<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Enums;

/**
 * Где ребёнок вошёл под временным аккаунтом (классификация входа, `MasterclassLoginTracker::classify()`).
 *
 * Значение не хранится в БД, но попадает в контекст журнала — менять нельзя.
 */
enum LoginPlace: string {

	/** До начала окна мастер-класса: проверка флаера заранее. */
	case BeforeStart = 'before_start';

	/** В окне мастер-класса: ребёнок в школе. */
	case OnSite = 'on_site';

	/** После окна или повторно позже `home_timeout_minutes`: ребёнок дома. */
	case AtHome = 'at_home';

	public function label(): string {
		return match ( $this ) {
			self::BeforeStart => 'До начала',
			self::OnSite      => 'На занятии',
			self::AtHome      => 'Дома',
		};
	}
}
