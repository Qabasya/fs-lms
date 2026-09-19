<?php

declare( strict_types=1 );

namespace Unit\Managers\Wp;

use Inc\Managers\Wp\PostManager;
use PHPUnit\Framework\TestCase;

/**
 * Ядро снимает слэши со значения меты (`update_metadata()` → `wp_unslash()`),
 * а в менеджер оно приходит уже без них. Без `wp_slash()` второй unslash
 * срезал `\` из LaTeX шага «Лекция» и условий (`\cdot` → `cdot`).
 */
class PostManagerMetaSlashTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
	}

	public function test_backslashes_survive_nested_meta(): void {
		$manager = new PostManager();
		$value   = array(
			'steps' => array(
				array( 'payload' => array( 'content' => '<p>$a \cdot b = \frac{1}{2}$</p>' ) ),
			),
			'task_code' => "print('\\n')",
			'count'     => 3,
		);

		$manager->updateMeta( 10, 'fs_lms_meta', $value );

		self::assertSame( $value, $manager->getMeta( 10, 'fs_lms_meta' ) );
	}
}
