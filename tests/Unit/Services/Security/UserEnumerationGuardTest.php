<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use Inc\Services\Security\UserEnumerationGuard;
use PHPUnit\Framework\TestCase;
use WP_Error;

class UserEnumerationGuardTest extends TestCase {

	private UserEnumerationGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new UserEnumerationGuard();
	}

	public function test_guest_is_denied_users_routes(): void {
		foreach ( array( '/wp/v2/users', '/wp/v2/users/1', '/wp/v2/users/me', '/WP/V2/Users' ) as $route ) {
			$result = $this->guard->restPreDispatch( null, $route, false );

			self::assertInstanceOf( WP_Error::class, $result, $route );
			self::assertSame( 'rest_user_cannot_view', $result->get_error_code(), $route );
		}
	}

	public function test_logged_in_user_passes(): void {
		self::assertNull( $this->guard->restPreDispatch( null, '/wp/v2/users/me', true ) );
	}

	public function test_other_routes_are_not_affected(): void {
		foreach ( array( '/wp/v2/posts', '/wp/v2/usersettings', '/fs-lms/v1/users', '/' ) as $route ) {
			self::assertNull( $this->guard->restPreDispatch( null, $route, false ), $route );
		}
	}

	public function test_sitemap_users_provider_is_removed(): void {
		self::assertFalse( $this->guard->filterSitemapProvider( 'provider', 'users' ) );
		self::assertSame( 'provider', $this->guard->filterSitemapProvider( 'provider', 'posts' ) );
	}

	public function test_oembed_author_fields_are_stripped(): void {
		$data = $this->guard->stripOembedAuthor( array( 'title' => 't', 'author_name' => 'admin', 'author_url' => 'u' ) );

		self::assertSame( array( 'title' => 't' ), $data );
	}
}
