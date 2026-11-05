<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Modules\VideoLibrary\Repositories\VideoInventoryRepository;
use Inc\Shared\Traits\TransactionRunner;

/** Согласованная очистка указателей и привязок всех записей. */
class VideoRecordingResetService {
	use TransactionRunner;

	public function __construct( private readonly VideoInventoryRepository $inventory ) {}

	public function reset( bool $dryRun ): array {
		if ( $dryRun ) {
			return $this->inventory->clearRecordings( true );
		}
		return $this->inTransaction( fn (): array => $this->inventory->clearRecordings( false ) );
	}
}
