<?php

declare( strict_types=1 );

// Экранирование и медиа-функции WP — в неймспейсе сервиса: глобальные заглушки bootstrap
// возвращают текст как есть, а здесь важно проверить именно экранирование и выбор размера.
namespace Inc\Modules\ArticleBlocks\Services {
	if ( ! function_exists( 'Inc\Modules\ArticleBlocks\Services\esc_html' ) ) {
		function esc_html( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'Inc\Modules\ArticleBlocks\Services\esc_attr' ) ) {
		function esc_attr( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'Inc\Modules\ArticleBlocks\Services\wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string {
			return strip_tags( $text );
		}
	}
	if ( ! function_exists( 'Inc\Modules\ArticleBlocks\Services\wp_attachment_is_image' ) ) {
		function wp_attachment_is_image( int $id ): bool {
			return in_array( $id, $GLOBALS['_fs_test_images'] ?? array(), true );
		}
	}
	if ( ! function_exists( 'Inc\Modules\ArticleBlocks\Services\wp_get_attachment_image' ) ) {
		function wp_get_attachment_image( int $id, string|array $size ): string {
			$GLOBALS['_fs_test_image_size'] = $size;
			return '<img src="/img-' . $id . '.png" alt="">';
		}
	}
	if ( ! function_exists( 'Inc\Modules\ArticleBlocks\Services\wp_get_registered_image_subsizes' ) ) {
		function wp_get_registered_image_subsizes(): array {
			return array(
				'medium' => array( 'width' => 300, 'height' => 300 ),
				'large'  => array( 'width' => 1024, 'height' => 1024 ),
			);
		}
	}
}

namespace Unit\Modules\ArticleBlocks {

	use Inc\Modules\ArticleBlocks\Enums\ArticleBlock;
	use Inc\Modules\ArticleBlocks\Services\ArticleBlockRenderer;
	use Inc\Modules\ArticleBlocks\Services\BlockValueCodec;
	use Inc\Modules\ArticleBlocks\Services\ImageSizeOptions;
	use Inc\Modules\ArticleBlocks\Services\TableTextParser;
	use Inc\Modules\ArticleBlocks\Services\TaskSuggestions;
	use PHPUnit\Framework\TestCase;

	/**
	 * Разметка блоков статьи: семантические теги без обёрток, всё содержимое экранировано.
	 */
	class ArticleBlockRendererTest extends TestCase {

		private ArticleBlockRenderer $renderer;
		private BlockValueCodec $codec;
		private TaskSuggestions&\PHPUnit\Framework\MockObject\Stub $tasks;

		protected function setUp(): void {
			parent::setUp();

			$this->codec    = new BlockValueCodec();
			$this->tasks    = $this->createStub( TaskSuggestions::class );
			$this->renderer = new ArticleBlockRenderer( $this->codec, new TableTextParser(), new ImageSizeOptions(), $this->tasks );

			$GLOBALS['_fs_test_images']     = array( 42 );
			$GLOBALS['_fs_test_image_size'] = null;
		}

		// ── Код ──────────────────────────────────────────────────────────

		public function test_code_block_is_escaped_listing_with_hook_class(): void {
			$html = $this->renderer->render( ArticleBlock::Code, array(
				'code' => $this->codec->encodeText( "if a < b:\n    print(\"<b>\")\n\n" ),
				'lang' => 'Python',
			) );

			$this->assertSame(
				'<pre><code class="js-code" data-lang="Python">if a &lt; b:' . "\n" . '    print(&quot;&lt;b&gt;&quot;)</code></pre>',
				$html
			);
		}

		public function test_code_block_falls_back_to_python_for_unknown_language(): void {
			$html = $this->renderer->render( ArticleBlock::Code, array(
				'code' => $this->codec->encodeText( 'x = 1' ),
				'lang' => '"><script>',
			) );

			$this->assertStringContainsString( 'data-lang="Python"', $html );
		}

		public function test_empty_code_renders_nothing(): void {
			$this->assertSame( '', $this->renderer->render( ArticleBlock::Code, '' ) );
			$this->assertSame( '', $this->renderer->render( ArticleBlock::Code, array( 'code' => $this->codec->encodeText( " \n " ) ) ) );
		}

		// ── Таблица ──────────────────────────────────────────────────────

		public function test_table_with_header_row_and_inline_code(): void {
			$html = $this->renderer->render( ArticleBlock::Table, array(
				'rows' => $this->codec->encodeText( "Функция\tПример\nСумма\t`=СУММ(A1:A3)`\n<b>\t2" ),
			) );

			$this->assertSame(
				'<table><thead><tr><th>Функция</th><th>Пример</th></tr></thead>'
				. '<tbody><tr><td>Сумма</td><td><code>=СУММ(A1:A3)</code></td></tr><tr><td>&lt;b&gt;</td><td>2</td></tr></tbody></table>',
				$html
			);
		}

		public function test_table_without_header_and_with_caption(): void {
			$html = $this->renderer->render( ArticleBlock::Table, array(
				'rows'      => $this->codec->encodeText( "1\t2" ),
				'no_header' => 'yes',
				'caption'   => 'Таблица ``истинности``',
			) );

			$this->assertSame(
				'<figure><table><tbody><tr><td>1</td><td>2</td></tr></tbody></table><figcaption>Таблица &quot;истинности&quot;</figcaption></figure>',
				$html
			);
		}

		// ── Изображение ──────────────────────────────────────────────────

		public function test_image_uses_registered_size_and_caption(): void {
			$html = $this->renderer->render( ArticleBlock::Image, array(
				'image'   => '42',
				'size'    => 'medium',
				'caption' => 'Схема',
			) );

			$this->assertSame( '<figure><img src="/img-42.png" alt=""><figcaption>Схема</figcaption></figure>', $html );
			$this->assertSame( 'medium', $GLOBALS['_fs_test_image_size'] );
		}

		public function test_image_width_overrides_size_and_unknown_size_falls_back(): void {
			$this->renderer->render( ArticleBlock::Image, array( 'image' => '42', 'width' => '640' ) );
			$this->assertSame( array( 640, 0 ), $GLOBALS['_fs_test_image_size'] );

			$this->renderer->render( ArticleBlock::Image, array( 'image' => '42', 'size' => 'huge' ) );
			$this->assertSame( ImageSizeOptions::DEFAULT, $GLOBALS['_fs_test_image_size'] );
		}

		public function test_image_that_is_not_an_attachment_renders_nothing(): void {
			$this->assertSame( '', $this->renderer->render( ArticleBlock::Image, array( 'image' => '7' ) ) );
		}

		// ── Заголовок ────────────────────────────────────────────────────

		public function test_heading_levels(): void {
			// WPBakery хранит «[» и «]» в атрибуте как `{` и `}`.
			$this->assertSame( '<h2>Циклы [for]</h2>', $this->renderer->render( ArticleBlock::Heading, array( 'text' => 'Циклы `{`for`}`' ) ) );
			$this->assertSame( '<h3>&lt;Шаг&gt;</h3>', $this->renderer->render( ArticleBlock::Heading, array( 'text' => '<Шаг>', 'level' => 'h3' ) ) );
			$this->assertSame( '<h2>x</h2>', $this->renderer->render( ArticleBlock::Heading, array( 'text' => 'x', 'level' => 'h1' ) ) );
			$this->assertSame( '', $this->renderer->render( ArticleBlock::Heading, array( 'text' => '  ' ) ) );
		}

		// ── Задание ──────────────────────────────────────────────────────

		public function test_task_block_is_link_paragraph_for_card_post_processing(): void {
			$post             = new \WP_Post();
			$post->ID         = 7;
			$post->post_title = '№ 5001. <b>Вариант</b> & ко';

			$GLOBALS['_fs_test_posts'][7] = $post;

			$this->tasks->method( 'publishedTask' )->willReturn( $post );

			$this->assertSame(
				'<p><a href="http://example.com/?p=7" data-id="7">№ 5001. Вариант &amp; ко</a></p>',
				$this->renderer->render( ArticleBlock::Task, array( 'task' => '7' ) )
			);

			unset( $GLOBALS['_fs_test_posts'][7] );
		}

		public function test_task_block_is_empty_for_unpublished_or_missing_task(): void {
			$this->tasks->method( 'publishedTask' )->willReturn( null );

			$this->assertSame( '', $this->renderer->render( ArticleBlock::Task, array( 'task' => '7' ) ) );
			$this->assertSame( '', $this->renderer->render( ArticleBlock::Task, '' ) );
		}
	}
}
