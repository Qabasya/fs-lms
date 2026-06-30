<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;

/**
 * Class TeacherProfileView
 *
 * Витрина преподавателя: его инструменты — Главная, Журнал, КТП.
 * Группы препода рендерятся в сайдбаре отдельным блоком (фронт).
 *
 * @package Inc\Services\Profile
 */
final class TeacherProfileView implements ProfileViewInterface {

	public function build( ProfileContext $context ): array {
		return array(
			'nav'     => array(
				array( 'key' => 'dashboard', 'label' => 'Главная' ),
				array( 'key' => 'journal',   'label' => 'Журнал' ),
				array( 'key' => 'ktp',       'label' => 'КТП и расписание' ),
			),
			'screens' => array( 'dashboard', 'journal', 'ktp' ),
		);
	}
}
