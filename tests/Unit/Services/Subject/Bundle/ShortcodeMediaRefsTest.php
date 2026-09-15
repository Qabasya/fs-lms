<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Services\Subject\Bundle\ShortcodeMediaRefs;
use PHPUnit\Framework\TestCase;

/**
 * ID вложений в атрибутах шорткодов (картинки статей из WPBakery).
 */
class ShortcodeMediaRefsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'][ ShortcodeMediaRefs::FILTER ] );
		parent::tearDown();
	}

	public function test_extracts_single_image_and_gallery_lists(): void {
		$content = '[vc_row][vc_column][vc_single_image image="143" img_size="large"]'
			. '[vc_column_text]Текст[/vc_column_text][vc_gallery type="flexslider" images="150, 151,143"][/vc_column][/vc_row]';

		$this->assertSame( array( 143, 150, 151 ), ( new ShortcodeMediaRefs() )->extractIds( $content ) );
	}

	public function test_ignores_unknown_shortcodes_and_foreign_attributes(): void {
		$content = '[vc_column_text image="5"]x[/vc_column_text]'
			. '[vc_single_image_extra image="6"]'
			. '[vc_single_image data-image="7" custom_image="8" source="external_link"]';

		$this->assertSame( array(), ( new ShortcodeMediaRefs() )->extractIds( $content ) );
	}

	public function test_module_can_add_its_shortcode_through_filter(): void {
		$GLOBALS['_fs_test_filter_returns'][ ShortcodeMediaRefs::FILTER ] = array(
			'vc_single_image'  => array( 'image' ),
			'fs_article_image' => array( 'image' ),
		);

		$ids = ( new ShortcodeMediaRefs() )->extractIds( '[fs_article_image image="42" size="large"]' );

		$this->assertSame( array( 42 ), $ids );
	}

	public function test_rewrites_ids_once_and_keeps_unmapped(): void {
		$content = '[vc_single_image image="143"][vc_images_carousel images="143,201,999"]';

		// 143→201 и 201→305: 143 не должен «доехать» до 305.
		$rewritten = ( new ShortcodeMediaRefs() )->rewrite( $content, array( 143 => 201, 201 => 305 ) );

		$this->assertSame( '[vc_single_image image="201"][vc_images_carousel images="201,305,999"]', $rewritten );
	}

	public function test_rewrite_leaves_text_outside_shortcodes_untouched(): void {
		$content = '<p>image="143"</p>[vc_single_image image="143"]';

		$rewritten = ( new ShortcodeMediaRefs() )->rewrite( $content, array( 143 => 700 ) );

		$this->assertSame( '<p>image="143"</p>[vc_single_image image="700"]', $rewritten );
	}
}
