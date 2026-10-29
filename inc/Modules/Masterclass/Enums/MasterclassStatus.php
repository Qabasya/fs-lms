<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Enums;

/**
 * Состояние мастер-класса в списке страницы модуля.
 *
 * В БД не хранится: выводится из колонок и времени (`MasterclassDTO::status()`), как и заморозка.
 */
enum MasterclassStatus: string {

	/** Окно ещё не началось. */
	case Waiting = 'waiting';

	/** Идёт окно мастер-класса. */
	case Running = 'running';

	/** Окно закончилось, доступ ещё открыт — дети допроходят дома. */
	case OpenAtHome = 'open_at_home';

	/** Наступил срок заморозки: урок закрыт. */
	case Frozen = 'frozen';

	/** «Удалить» нажато, тик вычищает аккаунты и группу. */
	case Deleting = 'deleting';

	/** Аккаунты и группа вычищены, осталась строка для воронки. */
	case Purged = 'purged';

	public function label(): string {
		return match ( $this ) {
			self::Waiting    => 'ждёт',
			self::Running    => 'идёт',
			self::OpenAtHome => 'открыт дома',
			self::Frozen     => 'заморожен',
			self::Deleting   => 'удаляется',
			self::Purged     => 'вычищен',
		};
	}
}
