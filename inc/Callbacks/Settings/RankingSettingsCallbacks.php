<?php
/**
 * Ranking settings AJAX transport.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Settings\RankingSettingsService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/** Saves only the ranking settings controlled by the customization tab. */
class RankingSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** Receives validated settings persistence. */
	public function __construct( private readonly RankingSettingsService $settings ) {
		parent::__construct();
	}

	/** Validates authorization and reports invalid numeric inputs explicitly. */
	public function ajaxSaveRankingSettings(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );
		try {
			$this->settings->save( $this->unslashArray( 'settings' ) );
		} catch ( \InvalidArgumentException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}
		$this->success( array( 'message' => 'Настройки рейтинга сохранены.' ) );
	}
}
