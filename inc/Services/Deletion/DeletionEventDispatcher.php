<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

class DeletionEventDispatcher {

	/** @var array<string, callable[]> */
	private array $listeners = [];

	public function listen( string $eventClass, callable $handler ): void {
		$this->listeners[ $eventClass ][] = $handler;
	}

	public function dispatch( DeletionEventInterface $event ): void {
		foreach ( $this->listeners[ $event::class ] ?? [] as $handler ) {
			$handler( $event );
		}
	}
}
