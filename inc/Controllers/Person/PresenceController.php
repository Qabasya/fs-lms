<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Person\PresenceService;

/**
 * Class PresenceController
 *
 * Хуки отметки «преподаватель в системе» — логика в {@see PresenceService}.
 *
 * @package Inc\Controllers\Person
 */
class PresenceController implements ServiceInterface {

	public function __construct(
		private readonly PresenceService $presence,
	) {}

	public function register(): void {
		add_action( 'init', array( $this->presence, 'touchCurrentUser' ) );
		add_action( 'wp_login', array( $this->presence, 'onLogin' ), 10, 2 );
	}
}
