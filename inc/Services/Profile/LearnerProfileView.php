<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Profile\LearnerScreen;

/**
 * Class LearnerProfileView
 *
 * Витрина учащегося — обслуживает И ученика, И родителя.
 * Состав экранов одинаков; различие несёт {@see ProfileContext}:
 * ученик — свои данные с правом записи, родитель — данные ребёнка только для чтения.
 *
 * @package Inc\Services\Profile
 */
final class LearnerProfileView implements ProfileViewInterface {

	public function build( ProfileContext $context ): array {
		// Ключи экранов с префиксом роли: student-* / parent-* (виден в адресе кабинета).
		$forParent = UserRole::FSParent === $context->role;

		// «Рейтинг» — только ученику: родителю места ребёнка среди других учеников не показываем.
		$screens = LearnerScreen::cases();
		if ( $forParent ) {
			$screens = array_values( array_filter( $screens, static fn( LearnerScreen $screen ): bool => LearnerScreen::Ranking !== $screen ) );
		}

		return array(
			'nav'     => array_map(
				static fn( LearnerScreen $screen ): array => array( 'key' => $screen->key( $forParent ), 'label' => $screen->label() ),
				$screens
			),
			'screens' => array_map(
				static fn( LearnerScreen $screen ): string => $screen->key( $forParent ),
				$screens
			),
		);
	}
}
