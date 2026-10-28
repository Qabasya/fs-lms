<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Enums;

/**
 * Вид клика в окне «Связаться» плеера (приходит с фронта в поле `kind`).
 *
 * Значение приходит из запроса и попадает в журнал — менять нельзя; неизвестное отбрасывает {@see self::fromRequest()}.
 */
enum MasterclassContactKind: string {

	/** Окно «Связаться» открыто. */
	case Open = 'open';

	/** Нажат телефон. */
	case Phone = 'phone';

	/** Нажата ссылка на мессенджер. */
	case Messenger = 'messenger';

	/** Нажато «Заполнить заявку». */
	case Apply = 'apply';

	/** Вид из запроса или `null`, если значение неизвестно. */
	public static function fromRequest( string $value ): ?self {
		return self::tryFrom( $value );
	}

	public function label(): string {
		return match ( $this ) {
			self::Open      => 'Открыл окно',
			self::Phone     => 'Нажал телефон',
			self::Messenger => 'Нажал мессенджер',
			self::Apply     => 'Нажал «Заполнить заявку»',
		};
	}
}
