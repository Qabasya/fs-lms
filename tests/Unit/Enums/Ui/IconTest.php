<?php

declare( strict_types=1 );

namespace Unit\Enums\Ui;

use Inc\Enums\Ui\Icon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Единый источник PHP-иконок: каждая отдаёт корректный SVG нужного размера. */
class IconTest extends TestCase {

	/** @return array<string, array{Icon}> */
	public static function allIcons(): array {
		$cases = array();
		foreach ( Icon::cases() as $icon ) {
			$cases[ $icon->name ] = array( $icon );
		}

		return $cases;
	}

	#[DataProvider( 'allIcons' )]
	public function test_svg_is_well_formed_xml_with_the_requested_size( Icon $icon ): void {
		$svg = new \DOMDocument();

		self::assertTrue( $svg->loadXML( $icon->svg( 24 ) ), $icon->name . ': разметка не разбирается как XML' );
		self::assertSame( 'svg', $svg->documentElement->nodeName );
		self::assertSame( '24', $svg->documentElement->getAttribute( 'width' ) );
		self::assertSame( '24', $svg->documentElement->getAttribute( 'height' ) );
		self::assertSame( '0 0 20 20', $svg->documentElement->getAttribute( 'viewBox' ), 'все иконки на общей сетке 20' );
	}

	#[DataProvider( 'allIcons' )]
	public function test_default_size_is_used_when_none_given( Icon $icon ): void {
		$svg = new \DOMDocument();
		$svg->loadXML( $icon->svg() );

		self::assertGreaterThan( 0, (int) $svg->documentElement->getAttribute( 'width' ) );
	}

	#[DataProvider( 'allIcons' )]
	public function test_icons_take_their_colour_from_the_parent( Icon $icon ): void {
		if ( Icon::BrandMark === $icon ) {
			$this->markTestSkipped( 'Логотип рисуется белым намеренно.' );
		}

		self::assertStringNotContainsString( 'style=', $icon->svg() );
		self::assertDoesNotMatchRegularExpression( '/(?:stroke|fill)="#/', $icon->svg(), $icon->name . ': цвет задан жёстко, а должен быть currentColor' );
	}

	public function test_player_action_icons_exist(): void {
		self::assertSame( 'phone', Icon::Phone->value );
		self::assertSame( 'message', Icon::Message->value );
		self::assertSame( 'logout', Icon::Logout->value );
	}
}
