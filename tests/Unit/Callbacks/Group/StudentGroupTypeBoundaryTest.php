<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Group;

use Inc\Callbacks\Group\StudentGroupCallbacks;
use Inc\Enums\Course\GroupType;
use PHPUnit\Framework\TestCase;

class StudentGroupTypeBoundaryTest extends TestCase {
	private array $post;

	protected function setUp(): void {
		$this->post = $_POST;
	}

	protected function tearDown(): void {
		$_POST = $this->post;
	}

	private function parse( array $request, ?object $group = null ): GroupType {
		$_POST = $request;
		$callback = ( new \ReflectionClass( StudentGroupCallbacks::class ) )->newInstanceWithoutConstructor();
		return ( new \ReflectionMethod( StudentGroupCallbacks::class, 'submittedGroupType' ) )->invoke( $callback, $group );
	}

	public function test_new_form_round_trips_every_explicit_type(): void {
		foreach ( GroupType::cases() as $type ) {
			self::assertSame( $type, $this->parse( array( 'group_type' => $type->value ) ) );
		}
	}

	public function test_legacy_transport_is_not_silently_ignored(): void {
		self::assertSame( GroupType::Shared, $this->parse( array( 'access_mode' => 'scheduled', 'is_shared' => '1' ) ) );
		self::assertSame( GroupType::Individual, $this->parse( array( 'access_mode' => 'individual', 'is_shared' => '0' ) ) );
	}

	public function test_omitted_type_preserves_existing_legacy_combination(): void {
		self::assertSame( GroupType::LegacyOpenShared, $this->parse( array(), (object) array( 'access_mode' => 'open', 'is_shared' => 1 ) ) );
	}

	public function test_invalid_new_type_is_rejected_instead_of_defaulting(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->parse( array( 'group_type' => 'wrong' ) );
	}

	public function test_invalid_old_mode_is_rejected_instead_of_defaulting(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->parse( array( 'access_mode' => 'wrong' ) );
	}
}
