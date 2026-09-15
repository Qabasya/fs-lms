<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

use Inc\Modules\ArticleBlocks\Enums\ArticleBlock;

/**
 * Class ArticleBlockRenderer
 *
 * Разметка блоков статьи.
 *
 * @package Inc\Modules\ArticleBlocks\Services
 *
 * ### Только семантические теги, без обёрток
 *
 * Страница статьи (`ArticleContentService`) сама оформляет `<pre>`, `<table>`, `<figure>` и
 * H2/H3 и разворачивает пустые `<div>`-обёртки — классы на них всё равно пропали бы. Поэтому
 * блоки отдают голую разметку, а внешний вид задают стили статьи. `js-code` у кода проставлен
 * сразу: подсветка работает и там, где пост-обработки статьи нет.
 */
final readonly class ArticleBlockRenderer {

	/**
	 * Языки плашки редактора кода. Подсветка в `code-block.js` — только Python:
	 * для остальных язык меняет подпись, а не правила подсветки.
	 */
	public const LANGUAGES = array( 'Python', 'C++', 'C#', 'Java', 'Pascal', 'JavaScript', 'SQL', 'Текст' );

	/** Максимальная ширина картинки из поля «Ширина, px». */
	private const MAX_IMAGE_WIDTH = 2400;

	public function __construct(
		private BlockValueCodec $codec,
		private TableTextParser $tables,
		private ImageSizeOptions $sizes,
		private TaskSuggestions $tasks,
	) {}

	/**
	 * Разметка блока по атрибутам шорткода.
	 *
	 * @param ArticleBlock $block Блок
	 * @param mixed        $atts  Атрибуты из add_shortcode() ('' — атрибутов нет)
	 *
	 * @return string '' — блок пуст
	 */
	public function render( ArticleBlock $block, mixed $atts ): string {
		$atts = is_array( $atts ) ? array_map( 'strval', $atts ) : array();

		return match ( $block ) {
			ArticleBlock::Code    => $this->code( $atts ),
			ArticleBlock::Table   => $this->table( $atts ),
			ArticleBlock::Image   => $this->image( $atts ),
			ArticleBlock::Heading => $this->heading( $atts ),
			ArticleBlock::Task    => $this->task( $atts ),
		};
	}

	/**
	 * @param array<string, string> $atts `code`, `lang`
	 */
	private function code( array $atts ): string {
		// Хвост из пустых строк редактор кода всё равно срезает — пустой листинг не выводим вовсе.
		$code = rtrim( $this->codec->decodeText( $atts['code'] ?? '' ) );

		if ( '' === trim( $code ) ) {
			return '';
		}

		$lang = in_array( $atts['lang'] ?? '', self::LANGUAGES, true ) ? $atts['lang'] : self::LANGUAGES[0];

		return '<pre><code class="js-code" data-lang="' . esc_attr( $lang ) . '">' . esc_html( $code ) . '</code></pre>';
	}

	/**
	 * @param array<string, string> $atts `rows`, `no_header` ('yes'), `caption`
	 */
	private function table( array $atts ): string {
		$rows = $this->tables->parse( $this->codec->decodeText( $atts['rows'] ?? '' ) );

		if ( array() === $rows ) {
			return '';
		}

		// Галочка «без заголовка», а не «с заголовком»: снятую галочку WPBakery в шорткод не пишет,
		// и включённую по умолчанию галочку было бы не отличить от снятой.
		$hasHeader = 'yes' !== ( $atts['no_header'] ?? '' );
		$html      = '<table>';

		if ( $hasHeader ) {
			$html .= '<thead>' . $this->row( array_shift( $rows ), 'th' ) . '</thead>';
		}

		if ( array() !== $rows ) {
			$html .= '<tbody>' . implode( '', array_map( fn( array $row ): string => $this->row( $row, 'td' ), $rows ) ) . '</tbody>';
		}

		$html .= '</table>';

		return $this->withCaption( $html, $atts['caption'] ?? '' );
	}

	/**
	 * @param array<string, string> $atts `image`, `size`, `width`, `caption`
	 */
	private function image( array $atts ): string {
		$attachmentId = absint( $atts['image'] ?? 0 );

		if ( 0 === $attachmentId || ! wp_attachment_is_image( $attachmentId ) ) {
			return '';
		}

		$width = min( absint( $atts['width'] ?? 0 ), self::MAX_IMAGE_WIDTH );

		// Ширина задана — просим WP подобрать превью под неё: высота считается пропорционально
		// (0 — без ограничения), в атрибуты width/height попадут уже пересчитанные значения.
		$size = $width > 0 ? array( $width, 0 ) : $this->sizes->resolve( $atts['size'] ?? '' );
		$img  = wp_get_attachment_image( $attachmentId, $size );

		if ( '' === $img ) {
			return '';
		}

		return $this->withCaption( $img, $atts['caption'] ?? '' );
	}

	/**
	 * @param array<string, string> $atts `text`, `level` (h2|h3)
	 */
	private function heading( array $atts ): string {
		$text = trim( $this->codec->decodeAttr( $atts['text'] ?? '' ) );

		if ( '' === $text ) {
			return '';
		}

		$level = 'h3' === ( $atts['level'] ?? '' ) ? 'h3' : 'h2';

		return "<{$level}>" . esc_html( $text ) . "</{$level}>";
	}

	/**
	 * Абзац из одной ссылки на задание — страница статьи сама заменяет его карточкой.
	 *
	 * Карточку здесь не собираем: её разметка, условие и раскрытие уже живут в пост-обработке статьи,
	 * а `data-id` она читает раньше href. Снятое с публикации задание не выводим вовсе — иначе
	 * на странице осталась бы ссылка в никуда.
	 *
	 * @param array<string, string> $atts `task` (ID задания)
	 */
	private function task( array $atts ): string {
		$post = $this->tasks->publishedTask( absint( $atts['task'] ?? 0 ) );

		if ( null === $post ) {
			return '';
		}

		return '<p><a href="' . esc_url( (string) get_permalink( $post ) ) . '" data-id="' . $post->ID . '">'
			. esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) . '</a></p>';
	}

	/**
	 * Строка таблицы.
	 *
	 * @param array<int, string> $cells Ячейки
	 * @param string             $tag   th|td
	 */
	private function row( array $cells, string $tag ): string {
		$html = '';

		foreach ( $cells as $cell ) {
			// `x` в ячейке — моноширинный фрагмент: имена переменных и формулы в таблицах статей.
			$content = (string) preg_replace( '/`([^`]+)`/u', '<code>$1</code>', esc_html( $cell ) );
			$html   .= "<{$tag}>{$content}</{$tag}>";
		}

		return "<tr>{$html}</tr>";
	}

	/**
	 * Оборачивает блок в `<figure>` с подписью; без подписи — только для картинки.
	 *
	 * @param string $html    Разметка блока
	 * @param string $caption Значение атрибута подписи
	 */
	private function withCaption( string $html, string $caption ): string {
		$caption = trim( $this->codec->decodeAttr( $caption ) );
		$isTable = str_starts_with( $html, '<table' );

		if ( '' === $caption ) {
			return $isTable ? $html : '<figure>' . $html . '</figure>';
		}

		return '<figure>' . $html . '<figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
	}
}
