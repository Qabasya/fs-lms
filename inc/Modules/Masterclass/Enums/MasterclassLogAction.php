<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Enums;

/**
 * Действия журнала «Мастер-классы» (`masterclass_log.action`).
 *
 * Значение кейса хранится в БД; менять его нельзя без data-миграции — по нему фильтруется журнал
 * и читаются старые записи. Хука `do_action` нет: внутри модуля пишет `MasterclassLogWriter` напрямую.
 */
enum MasterclassLogAction: string {

	case MasterclassCreated = 'masterclass_created';
	case MasterclassUpdated = 'masterclass_updated';
	case MasterclassExtended = 'masterclass_extended';
	case DeleteRequested = 'delete_requested';
	case MasterclassPurged = 'masterclass_purged';
	case AccountsCreated = 'accounts_created';
	case AccountDeleted = 'account_deleted';
	case AccountDetached = 'account_detached';
	case FlyersPrinted = 'flyers_printed';
	case LoginBeforeStart = 'login_before_start';
	case LoginOnSite = 'login_on_site';
	case LoginAtHome = 'login_at_home';
	case Frozen = 'frozen';
	case ContactOpened = 'contact_opened';
	case ContactPhone = 'contact_phone';
	case ContactMessenger = 'contact_messenger';
	case ApplyClicked = 'apply_clicked';
	case ApplicationBound = 'application_bound';
	case ApplicationBindFailed = 'application_bind_failed';
	case Enrolled = 'enrolled';
	case LoginsUnlocked = 'logins_unlocked';

	/** Действие журнала для клика в окне «Связаться». */
	public static function fromContact( MasterclassContactKind $kind ): self {
		return match ( $kind ) {
			MasterclassContactKind::Open      => self::ContactOpened,
			MasterclassContactKind::Phone     => self::ContactPhone,
			MasterclassContactKind::Messenger => self::ContactMessenger,
			MasterclassContactKind::Apply     => self::ApplyClicked,
		};
	}

	public function label(): string {
		return match ( $this ) {
			self::MasterclassCreated     => 'Мастер-класс создан',
			self::MasterclassUpdated     => 'Мастер-класс изменён',
			self::MasterclassExtended    => 'Срок продлён',
			self::DeleteRequested        => 'Удаление запрошено',
			self::MasterclassPurged      => 'Мастер-класс вычищен',
			self::AccountsCreated        => 'Аккаунты созданы',
			self::AccountDeleted         => 'Аккаунт удалён',
			self::AccountDetached        => 'Аккаунт отвязан',
			self::FlyersPrinted          => 'Флаеры напечатаны',
			self::LoginBeforeStart       => 'Вход до начала',
			self::LoginOnSite            => 'Вход на занятии',
			self::LoginAtHome            => 'Вход дома',
			self::Frozen                 => 'Доступ заморожен',
			self::ContactOpened          => 'Открыл «Связаться»',
			self::ContactPhone           => 'Нажал телефон',
			self::ContactMessenger       => 'Нажал мессенджер',
			self::ApplyClicked           => 'Нажал «Заполнить заявку»',
			self::ApplicationBound       => 'Заявка привязана',
			self::ApplicationBindFailed  => 'Заявку не удалось привязать',
			self::Enrolled               => 'Зачислен',
			self::LoginsUnlocked         => 'Блокировки входа сняты',
		};
	}
}
