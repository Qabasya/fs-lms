<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

use Inc\Enums\Settings\TableName;

/**
 * Единый реестр каналов логирования.
 *
 * Источник правды для канала: ключ (value), человекочитаемая метка (label)
 * и таблица хранения (tableName). Потребители:
 *  - вкладки страницы «Журналы» — templates/admin/logs.php (заголовок из label());
 *  - репозитории логов — берут свою таблицу через tableName().
 *
 * Добавление нового канала = одна новая case + строки в label()/tableName().
 */
enum LogChannel: string {
	case EntityAudit     = 'entity_audit';
	case EnrollmentAudit = 'enrollment_audit';
	case PiiAccess       = 'pii_access';
	case Export          = 'export';
	case DataChange      = 'data_change';
	case ConsentChange   = 'consent_change';
	case Email           = 'email';
	case Auth            = 'auth';
	case LearningEvents  = 'learning_events';

	/** Короткая метка канала — заголовок вкладки на странице «Журналы». */
	public function label(): string {
		return match ( $this ) {
			self::EntityAudit     => 'Действия',
			self::EnrollmentAudit => 'Зачисления',
			self::PiiAccess       => 'Доступ к ПД',
			self::Export          => 'Экспорт',
			self::DataChange      => 'Изменения данных',
			self::ConsentChange   => 'Согласия',
			self::Email           => 'Письма',
			self::Auth            => 'Аутентификация',
			self::LearningEvents  => 'События обучения',
		};
	}

	/** Таблица БД, в которой хранится журнал канала. */
	public function tableName(): TableName {
		return match ( $this ) {
			self::EntityAudit     => TableName::EntityAuditLog,
			self::EnrollmentAudit => TableName::AuditLog,
			self::PiiAccess       => TableName::PiiAccessLog,
			self::Export          => TableName::ExportLog,
			self::DataChange      => TableName::DataChangeLog,
			self::ConsentChange   => TableName::ConsentChangeLog,
			self::Email           => TableName::EmailLog,
			self::Auth            => TableName::AuthLog,
			self::LearningEvents  => TableName::LearningEvents,
		};
	}

	/**
	 * Показывать ли канал отдельной вкладкой на админ-странице «Журналы».
	 * LearningEvents — лента событий на фронте/в кокпите, в админ-журналы не выводится.
	 */
	public function inAdminLogs(): bool {
		return self::LearningEvents !== $this;
	}

	/**
	 * Привязка канала к вкладке страницы «Журналы»: id вкладки (URL / active_tab)
	 * и имя партиала в templates/admin/components/tabs/logs-tabs/.
	 * Осмысленно только при inAdminLogs() === true.
	 *
	 * @return array{id: string, partial: string}
	 */
	public function adminTab(): array {
		return match ( $this ) {
			self::EntityAudit     => array( 'id' => 'tab-0', 'partial' => 'logs-0-entity-audit' ),
			self::EnrollmentAudit => array( 'id' => 'tab-1', 'partial' => 'logs-1-audit' ),
			self::PiiAccess       => array( 'id' => 'tab-2', 'partial' => 'logs-2-pii' ),
			self::Export          => array( 'id' => 'tab-3', 'partial' => 'logs-3-export' ),
			self::DataChange      => array( 'id' => 'tab-4', 'partial' => 'logs-4-data-change' ),
			self::ConsentChange   => array( 'id' => 'tab-5', 'partial' => 'logs-5-consent-change' ),
			self::Email           => array( 'id' => 'tab-6', 'partial' => 'logs-6-email' ),
			self::Auth            => array( 'id' => 'tab-8', 'partial' => 'logs-8-auth' ),
			self::LearningEvents  => array( 'id' => '', 'partial' => '' ),
		};
	}

	/**
	 * Канал по id вкладки админ-страницы «Журналы» (обратное к adminTab()['id']).
	 *
	 * @return self|null null — если id не соответствует ни одному отображаемому каналу.
	 */
	public static function fromAdminTabId( string $tabId ): ?self {
		foreach ( self::cases() as $channel ) {
			if ( $channel->inAdminLogs() && $channel->adminTab()['id'] === $tabId ) {
				return $channel;
			}
		}

		return null;
	}
}
