<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Enums;

enum AuthProvider: string {
	case Google    = 'Google';
	case Vkontakte = 'VK';
	case Github    = 'Github';

	public function label(): string {
		return match ( $this ) {
			self::Google    => 'Google',
			self::Vkontakte => 'VK',
			self::Github    => 'Github',
		};
	}

	public static function fromRequest( string $value ): ?self {
		$value = strtolower( trim( $value ) );

		return match ( $value ) {
			'google'                    => self::Google,
			'vk', 'vkontakte', 'vk.com' => self::Vkontakte,
			'github', 'git hub'         => self::Github,
			default                     => null,
		};
	}

	public function configKey(): string {
		return match ( $this ) {
			self::Google    => 'google',
			self::Vkontakte => 'vk',
			self::Github    => 'github',
		};
	}

	public function hybridauthKey(): string {
		return match ( $this ) {
			self::Google    => 'Google',
			self::Vkontakte => 'Vkontakte',
			self::Github    => 'GitHub',
		};
	}
}
