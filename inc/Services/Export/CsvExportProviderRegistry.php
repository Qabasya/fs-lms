<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\Enums\ExportTarget;
use RuntimeException;

/**
 * Реестр провайдеров CSV-экспорта.
 *
 * Маппинг ExportTarget → CsvExportProviderInterface.
 * Добавить новый экспорт = register() + новый провайдер. Оркестратор не трогается (OCP).
 */
class CsvExportProviderRegistry {

	/** @var array<string, CsvExportProviderInterface> */
	private array $providers = array();

	public function register( ExportTarget $target, CsvExportProviderInterface $provider ): void {
		$this->providers[ $target->value ] = $provider;
	}

	public function resolve( ExportTarget $target ): CsvExportProviderInterface {
		$provider = $this->providers[ $target->value ] ?? null;
		if ( null === $provider ) {
			throw new RuntimeException( "Нет провайдера для ExportTarget::{$target->name}" );
		}
		return $provider;
	}

	public function has( ExportTarget $target ): bool {
		return isset( $this->providers[ $target->value ] );
	}
}
