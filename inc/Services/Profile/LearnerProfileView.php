<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;

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
		return array(
			'nav'     => array(
				array( 'key' => 'learner-home',       'label' => 'Главная' ),
				array( 'key' => 'learner-lessons',    'label' => 'Мои курсы' ),
				array( 'key' => 'learner-grades',     'label' => 'Мои оценки' ),
				array( 'key' => 'learner-attendance', 'label' => 'Посещаемость' ),
			),
			'screens' => array( 'learner-home', 'learner-lessons', 'learner-grades', 'learner-attendance' ),
		);
	}
}
