<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;

/**
 * Class LogPageRegistry
 *
 * Реестр провайдеров вкладок страницы «Журналы»: канал → поставщик данных.
 *
 * @package Inc\Services\Log\Pages
 *
 * Заменяет цепочку elseif в коллбэке страницы. Добавление канала = новый
 * провайдер + аргумент конструктора; коллбэк при этом не меняется.
 * Неизвестная вкладка данных не даёт — шаблон отрисует только сами вкладки.
 */
class LogPageRegistry {

	/** @var array<string, LogPageProviderInterface> Провайдеры по значению канала */
	private array $providers;

	public function __construct(
		EntityAuditLogPageProvider     $entityAudit,
		EnrollmentAuditLogPageProvider $enrollmentAudit,
		PiiAccessLogPageProvider       $piiAccess,
		ExportLogPageProvider          $export,
		DataChangeLogPageProvider      $dataChange,
		ConsentChangeLogPageProvider   $consentChange,
		EmailLogPageProvider           $email,
		AuthLogPageProvider            $auth,
	) {
		$this->providers = array();

		foreach ( array( $entityAudit, $enrollmentAudit, $piiAccess, $export, $dataChange, $consentChange, $email, $auth ) as $provider ) {
			$this->providers[ $provider->channel()->value ] = $provider;
		}
	}

	/**
	 * Данные вкладки для шаблона.
	 *
	 * @param LogChannel|null $channel Канал активной вкладки (null — вкладка неизвестна)
	 * @param LogPageQueryDTO $query   Общий контекст страницы
	 *
	 * @return array<string, mixed> Переменные шаблона (пусто для неизвестной вкладки)
	 */
	public function data( ?LogChannel $channel, LogPageQueryDTO $query ): array {
		if ( null === $channel ) {
			return array();
		}

		return isset( $this->providers[ $channel->value ] )
			? $this->providers[ $channel->value ]->data( $query )
			: array();
	}
}
