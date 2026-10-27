<?php

declare( strict_types=1 );

namespace Unit\Controllers\Builders;

use Inc\Shared\Traits\AuthorFilter;
use Inc\Shared\Traits\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Селект «Автор» таблиц заданий, банка и предметных заданий: один, пока авторов меньше двух — не показывается.
 */
class AuthorFilterTraitTest extends TestCase {

	private object $filters;

	protected function setUp(): void {
		$this->filters = new class {
			use Sanitizer;
			use AuthorFilter;

			public function select( array $posts ): ?array {
				return $this->authorSelectFor( $posts );
			}
		};
		$_GET = array();
		$GLOBALS['_fs_test_users_by'] = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['_fs_test_users_by'] );
	}

	private function post( int $author ): object {
		return (object) array( 'post_author' => $author );
	}

	private function user( int $id, string $name ): void {
		$user               = new \WP_User();
		$user->ID           = $id;
		$user->display_name = $name;

		$GLOBALS['_fs_test_users_by']['id'][ (string) $id ] = $user;
	}

	public function test_hidden_with_fewer_than_two_authors(): void {
		self::assertNull( $this->filters->select( array() ) );
		self::assertNull( $this->filters->select( array( $this->post( 3 ), $this->post( 3 ) ) ) );
	}

	public function test_lists_distinct_authors_with_selected_one(): void {
		$this->user( 3, 'Анна' );
		$this->user( 4, 'Борис' );
		$_GET['author'] = '4';

		$select = $this->filters->select( array( $this->post( 3 ), $this->post( 4 ), $this->post( 3 ) ) );

		self::assertSame( array( 3 => 'Анна', 4 => 'Борис' ), $select['options'] );
		self::assertSame( '4', $select['selected'] );
		self::assertSame( 'author', $select['name'] );
		self::assertSame( 'Все авторы', $select['all_label'] );
	}

	public function test_skips_unknown_users(): void {
		$this->user( 3, 'Анна' );

		$select = $this->filters->select( array( $this->post( 3 ), $this->post( 9 ) ) );

		self::assertSame( array( 3 => 'Анна' ), $select['options'] );
	}
}
