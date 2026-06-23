<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Services;

use Hybridauth\Hybridauth;
use Inc\DTO\Person\UserDTO;
use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Enums\Access\UserRole;
use Inc\Repositories\OptionsRepositories\UserRepository;

class AuthService {

	private ?Hybridauth $hybridauth = null;

	public function __construct(
		private readonly UserRepository $user_repo
	) {}

	public function processUserFromSocialProfile( AuthProvider $provider, object $profile ): ?UserDTO {
		$user = $this->user_repo->getBySocialId( $provider->value, (string) $profile->identifier );

		if ( ! $user && ! empty( $profile->email ) ) {
			$user = $this->user_repo->getByEmail( $profile->email );

			if ( $user ) {
				$this->user_repo->updateMeta(
					$user->id,
					array(
						"fs_social_{$provider->value}_id" => $profile->identifier,
					)
				);
			}
		}

		if ( ! $user ) {
			$user = $this->registerSocialUser( $provider, $profile );
		}

		if ( $user && ! empty( $profile->photoURL ) ) {
			$this->user_repo->updateMeta(
				$user->id,
				array(
					'fs_avatar_url' => $profile->photoURL,
				)
			);
		}

		if ( $user ) {
			$this->login( $user );
		}

		return $user;
	}

	public function login( UserDTO $user ): void {
		wp_set_current_user( $user->id );
		wp_set_auth_cookie( $user->id, true );
		do_action( 'wp_login', $user->email, get_userdata( $user->id ) );
	}

	private function registerSocialUser( AuthProvider $provider, object $profile ): ?UserDTO {
		return $this->user_repo->create(
			array(
				'user_login'   => $this->generateUniqueLogin( $profile->displayName ?: $profile->firstName ),
				'user_email'   => $profile->email ?: '',
				'display_name' => $profile->displayName ?: $profile->firstName,
				'role'         => UserRole::Student->value,
				'meta'         => array(
					"fs_social_{$provider->value}_id" => $profile->identifier,
					'fs_social_provider'              => $provider->value,
					'fs_avatar_url'                   => $profile->photoURL,
				),
			)
		);
	}

	private function generateUniqueLogin( string $baseName ): string {
		$login = sanitize_user( $baseName, true );

		return $login . '_' . bin2hex( random_bytes( 2 ) );
	}
}
