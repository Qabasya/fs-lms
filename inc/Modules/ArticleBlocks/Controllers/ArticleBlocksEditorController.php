<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Controllers;

use Inc\Core\BaseController;
use Inc\Modules\ArticleBlocks\Builders\ElementMapBuilder;
use Inc\Modules\ArticleBlocks\Registrars\VcElementRegistrar;
use Inc\Modules\ArticleBlocks\Services\EditorFieldRenderer;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ArticleBlocksEditorController
 *
 * Редактор блоков: элементы в панели «Добавить элемент» WPBakery и кнопка «Код»
 * на панели «Текстового блока» статей. Подключается только при включённом модуле.
 *
 * @package Inc\Modules\ArticleBlocks\Controllers
 */
class ArticleBlocksEditorController extends BaseController {

	/** JS поля `fs_lms_encoded_text` (self-contained, вне core-бандла). */
	private const FIELD_SCRIPT = 'inc/Modules/ArticleBlocks/assets/editor-fields.js';

	/** Кнопка TinyMCE «Код» (команда WP_Code есть в редакторе WordPress, но на панель не выведена). */
	private const CODE_BUTTON = 'wp_code';

	public function __construct(
		private readonly ElementMapBuilder   $elements,
		private readonly VcElementRegistrar  $registrar,
		private readonly EditorFieldRenderer $fields,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Хук есть только у активного WPBakery — без него элементы просто не регистрируются.
		add_action( 'vc_before_init', array( $this, 'mapElements' ) );
		add_filter( 'mce_buttons', array( $this, 'addCodeButton' ) );
	}

	/**
	 * Регистрирует тип поля и элементы блоков.
	 *
	 * @return void
	 */
	public function mapElements(): void {
		$path = $this->path( self::FIELD_SCRIPT );
		$url  = add_query_arg( 'ver', file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version, $this->url( self::FIELD_SCRIPT ) );

		$this->registrar->addParamType( ElementMapBuilder::ENCODED_PARAM, array( $this->fields, 'render' ), $url );

		foreach ( $this->elements->elements() as $element ) {
			$this->registrar->mapElement( $element );
		}
	}

	/**
	 * Добавляет кнопку «Код» после «Курсива» — только на экране редактирования статьи.
	 *
	 * @param string[] $buttons Кнопки первой строки панели
	 *
	 * @return string[]
	 */
	public function addCodeButton( array $buttons ): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! PostTypeResolver::isArticlePostType( (string) $screen->post_type ) ) {
			return $buttons;
		}

		if ( in_array( self::CODE_BUTTON, $buttons, true ) ) {
			return $buttons;
		}

		$position = array_search( 'italic', $buttons, true );

		if ( false === $position ) {
			$buttons[] = self::CODE_BUTTON;
			return $buttons;
		}

		array_splice( $buttons, (int) $position + 1, 0, array( self::CODE_BUTTON ) );

		return $buttons;
	}
}
