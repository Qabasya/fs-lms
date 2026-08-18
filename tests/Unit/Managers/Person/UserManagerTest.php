<?php

declare( strict_types=1 );

namespace Unit\Managers\Person;

use FakeWpdb;
use Inc\Managers\Person\UserManager;
use PHPUnit\Framework\TestCase;

/**
 * changeLogin() — регрессия: wp_update_user()/wp_insert_user() ядра WP игнорируют
 * user_login при обновлении существующего пользователя, поэтому логин меняется
 * прямым UPDATE таблицы (см. докблок метода).
 */
class UserManagerTest extends TestCase {

	private UserManager $manager;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_userdata']   = [];
		$GLOBALS['_fs_test_users_by']   = [];
		$GLOBALS['wpdb']                = new FakeWpdb();
		$GLOBALS['wpdb']->users         = 'wp_users';
		$this->manager                  = new UserManager();
	}

	private function seedUser( int $id, string $login ): void {
		$user             = new \WP_User();
		$user->ID         = $id;
		$user->user_login = $login;
		$GLOBALS['_fs_test_userdata'][ $id ]                 = $user;
		$GLOBALS['_fs_test_users_by']['login'][ $login ]     = $user;
	}

	public function test_change_login_updates_user_login_and_nicename(): void {
		$this->seedUser( 5, 'old_login' );

		$this->manager->changeLogin( 5, 'new_login' );

		self::assertCount( 1, $GLOBALS['wpdb']->updates );
		self::assertSame( 'new_login', $GLOBALS['wpdb']->updates[0]['data']['user_login'] );
		self::assertSame( 'new_login', $GLOBALS['wpdb']->updates[0]['data']['user_nicename'] );
		self::assertSame( [ 'ID' => 5 ], $GLOBALS['wpdb']->updates[0]['where'] );
	}

	public function test_change_login_throws_when_user_not_found(): void {
		$this->expectException( \RuntimeException::class );
		$this->manager->changeLogin( 999, 'new_login' );
	}

	public function test_change_login_throws_when_login_taken_by_another_user(): void {
		$this->seedUser( 5, 'old_login' );
		$this->seedUser( 7, 'taken_login' );

		$this->expectException( \RuntimeException::class );
		$this->manager->changeLogin( 5, 'taken_login' );
	}

	public function test_change_login_allows_setting_own_current_login(): void {
		$this->seedUser( 5, 'same_login' );

		$this->manager->changeLogin( 5, 'same_login' );

		self::assertCount( 1, $GLOBALS['wpdb']->updates );
	}
}
