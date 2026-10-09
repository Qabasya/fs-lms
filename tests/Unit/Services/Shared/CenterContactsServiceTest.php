<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Shared;

use Inc\Services\Shared\CenterContactsService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class CenterContactsServiceTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'][ CenterContactsService::FILTER ] );
		parent::tearDown();
	}

	private function service( array $fallback = array() ): CenterContactsService {
		$config = $this->createMock( PluginConfig::class );
		$config->method( 'centerContactsFallback' )->willReturn( $fallback + array( 'phone' => '+7 000', 'email' => 'f@x', 'hours' => 'пн-пт', 'address' => 'Запасной адрес' ) );

		return new CenterContactsService( $config );
	}

	public function test_filter_values_win_over_fallback(): void {
		$GLOBALS['_fs_test_filter_returns'][ CenterContactsService::FILTER ] = array( 'phone' => '+7 111', 'email' => 't@x', 'hours' => '9-18', 'city' => 'Калининград', 'street' => 'ул. Черняховского, д. 6, каб. 316' );

		$contacts = $this->service()->get();

		self::assertSame( '+7 111', $contacts['phone'] );
		self::assertSame( 'Калининград', $contacts['city'] );
	}

	public function test_empty_filter_value_uses_fallback(): void {
		$GLOBALS['_fs_test_filter_returns'][ CenterContactsService::FILTER ] = array( 'phone' => '', 'email' => '  ' );

		$contacts = $this->service()->get();

		self::assertSame( '+7 000', $contacts['phone'] );
		self::assertSame( 'f@x', $contacts['email'] );
	}

	public function test_address_is_cut_before_room(): void {
		$GLOBALS['_fs_test_filter_returns'][ CenterContactsService::FILTER ] = array( 'city' => 'Калининград', 'street' => 'ул. Черняховского, д. 6, каб. 316' );

		self::assertSame( 'Калининград, ул. Черняховского, д. 6', $this->service()->addressWithoutRoom() );
	}

	public function test_address_without_room_word_is_unchanged(): void {
		$GLOBALS['_fs_test_filter_returns'][ CenterContactsService::FILTER ] = array( 'city' => 'Калининград', 'street' => 'ул. Черняховского, д. 6' );

		self::assertSame( 'Калининград, ул. Черняховского, д. 6', $this->service()->addressWithoutRoom() );
	}
}
