<?php

declare( strict_types=1 );

namespace Unit\Modules\ArticleBlocks;

use Inc\Modules\ArticleBlocks\Services\BlockValueCodec;
use PHPUnit\Framework\TestCase;

/**
 * Хранение кода и таблиц в атрибутах шорткодов.
 */
class BlockValueCodecTest extends TestCase {

	private const string CODE = "a = [1, \"x\"]\n    print(a)  # тест & <b>]";

	public function test_decodes_module_field_value(): void {
		$codec = new BlockValueCodec();

		// Так же кодирует editor-fields.js: base64 от UTF-8.
		$stored = BlockValueCodec::PREFIX . base64_encode( self::CODE );

		$this->assertSame( self::CODE, $codec->decodeText( $stored ) );
		$this->assertSame( $stored, $codec->encodeText( self::CODE ) );
	}

	public function test_decodes_wpbakery_textarea_safe_value(): void {
		$stored = '#E-8_' . base64_encode( rawurlencode( self::CODE ) );

		$this->assertSame( self::CODE, ( new BlockValueCodec() )->decodeText( $stored ) );
	}

	public function test_normalizes_windows_line_breaks(): void {
		$stored = BlockValueCodec::PREFIX . base64_encode( "a\r\nb\rc" );

		$this->assertSame( "a\nb\nc", ( new BlockValueCodec() )->decodeText( $stored ) );
	}

	public function test_broken_value_decodes_to_empty_string(): void {
		$codec = new BlockValueCodec();

		$this->assertSame( '', $codec->decodeText( BlockValueCodec::PREFIX . '%%%не base64' ) );
		$this->assertSame( '', $codec->decodeText( BlockValueCodec::PREFIX . base64_encode( "\xFF\xFE" ) ) );
	}

	public function test_decodes_wpbakery_escapes_in_plain_attribute(): void {
		$this->assertSame(
			'Массив [1, 2] и "кавычки"',
			( new BlockValueCodec() )->decodeAttr( 'Массив `{`1, 2`}` и ``кавычки``' )
		);
	}
}
