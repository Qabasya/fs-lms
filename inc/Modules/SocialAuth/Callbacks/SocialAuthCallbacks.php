<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Callbacks;

use Inc\Core\BaseController;
use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Modules\SocialAuth\Repositories\SocialAuthSettingsRepository;
use Inc\Shared\Traits\TemplateRenderer;

class SocialAuthCallbacks extends BaseController {

	use TemplateRenderer;

	public function __construct( private readonly SocialAuthSettingsRepository $settings_repo ) {
		parent::__construct();
	}

	public function getEnabledProviders(): array {
		$providers = array();

		foreach ( AuthProvider::cases() as $provider ) {
			if ( ! $this->settings_repo->isProviderEnabled( $provider->value ) ) {
				continue;
			}

			$providers[] = array(
				'url'   => home_url( '/lms-auth/' . $provider->configKey() ),
				'id'    => $provider->configKey(),
				'label' => $provider->label(),
			);
		}

		return $providers;
	}
}
