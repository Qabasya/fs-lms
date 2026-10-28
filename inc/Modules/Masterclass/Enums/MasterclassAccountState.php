<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Enums;

/**
 * Состояние временного аккаунта мастер-класса (`masterclass_accounts.state`).
 *
 * Значение хранится в БД — менять нельзя без data-миграции.
 */
enum MasterclassAccountState: string {

	/** Выдан, заявки нет. */
	case Active = 'active';

	/** Ребёнок подал заявку со страницы `/apply/`, она привязана к аккаунту. */
	case Applied = 'applied';

	/** Заявка принята и ученик зачислен: аккаунт стал настоящим и модулю больше не принадлежит. */
	case Enrolled = 'enrolled';

	public function label(): string {
		return match ( $this ) {
			self::Active   => 'Выдан',
			self::Applied  => 'Подал заявку',
			self::Enrolled => 'Зачислен',
		};
	}
}
