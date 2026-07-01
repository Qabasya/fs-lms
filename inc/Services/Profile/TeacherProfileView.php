<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;

/**
 * Class TeacherProfileView
 *
 * Витрина преподавателя: его инструменты — Главная, Журнал, КТП.
 * Группы препода рендерятся в сайдбаре отдельным блоком (фронт).
 * Офис (`FSOffice`) дополнительно получает экран «Замены» (Эпики 5+9).
 *
 * @package Inc\Services\Profile
 */
final class TeacherProfileView implements ProfileViewInterface {

	public function build( ProfileContext $context ): array {
		$nav = array(
			array( 'key' => 'dashboard', 'label' => 'Главная' ),
			array( 'key' => 'groups',    'label' => 'Группы' ),
			array( 'key' => 'journal',   'label' => 'Журнал' ),
			array( 'key' => 'summary',   'label' => 'Сводка по ученику' ),
			array( 'key' => 'ktp',       'label' => 'КТП и расписание' ),
		);
		$screens = array( 'dashboard', 'groups', 'journal', 'summary', 'ktp' );

		// Замены (кабинет + педагог) — офисный инструмент, препод не видит.
		if ( UserRole::FSOffice === $context->role ) {
			$nav[]     = array( 'key' => 'substitutions', 'label' => 'Замены' );
			$screens[] = 'substitutions';
		}

		return array( 'nav' => $nav, 'screens' => $screens );
	}
}
