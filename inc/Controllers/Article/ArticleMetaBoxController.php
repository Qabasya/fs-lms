<?php

declare( strict_types=1 );

namespace Inc\Controllers\Article;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class ArticleMetaBoxController
 *
 * Метабокс «Краткое описание» для всех CPT {key}_articles: одна строка-аннотация,
 * которую учебник показывает в карточке статьи (и которая идёт в тег description).
 *
 * @package Inc\Controllers\Article
 *
 * Описание хранится плоской постметой ({@see PostMetaName::ArticleDescription}),
 * а не внутри `fs_lms_meta`: у статьи нет шаблона полей, и плоский ключ позволяет
 * читать аннотацию списком без разбора массива меты.
 */
class ArticleMetaBoxController extends BaseController implements ServiceInterface {

	use Authorizer;
	use Sanitizer;
	use TemplateRenderer;

	/** Предел длины описания в символах — и в разметке, и при сохранении. */
	public const MAX_LENGTH = 120;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly PostManager       $posts,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'save_post', array( $this, 'handleArticleSave' ) );
	}

	/**
	 * Ставит метабокс на CPT статей всех предметов с банком.
	 *
	 * @return void
	 */
	public function handleAddMetaBoxes(): void {
		$articlePostTypes = array();

		foreach ( $this->subjects->readAll() as $subject ) {
			// Предмет без собственного банка не регистрирует CPT статей (Эпик 18).
			if ( ! ( $subject->hasBank ?? true ) ) {
				continue;
			}
			$articlePostTypes[] = PostTypeResolver::articles( $subject->key );
		}

		if ( empty( $articlePostTypes ) ) {
			return;
		}

		$this->registrar->add(
			'fs_lms_article_description',
			'Краткое описание',
			array( $this, 'renderDescriptionContent' ),
			$articlePostTypes,
			array( 'context' => 'side', 'priority' => 'default' )
		)->register();
	}

	/**
	 * Рендерит поле описания.
	 *
	 * @param \WP_Post $post Редактируемая статья
	 *
	 * @return void
	 */
	public function renderDescriptionContent( \WP_Post $post ): void {
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		$value = $this->posts->getMeta( $post->ID, PostMetaName::ArticleDescription->value );

		$this->render( 'admin/metaboxes/article-description', array(
			'field_name' => PostMetaName::ArticleDescription->value,
			'value'      => is_string( $value ) ? $value : '',
			'max_length' => self::MAX_LENGTH,
		) );
	}

	/**
	 * Сохраняет описание, обрезая его до допустимой длины.
	 *
	 * @param int $post_id ID сохраняемой записи
	 *
	 * @return void
	 */
	public function handleArticleSave( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = $this->posts->get( $post_id );
		if ( null === $post || ! PostTypeResolver::isArticlePostType( $post->post_type ) ) {
			return;
		}

		$field = PostMetaName::ArticleDescription->value;

		// Быстрое/массовое редактирование поля не шлёт — сохранённое описание не трогаем.
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		$this->posts->updateMeta( $post_id, $field, $this->normalize( $this->sanitizeText( $field ) ) );
	}

	/**
	 * Приводит описание к хранимому виду: одна строка не длиннее MAX_LENGTH символов.
	 *
	 * Обрезка на сервере обязательна: maxlength в разметке снимается инструментами
	 * разработчика и не действует при программном сохранении.
	 *
	 * @param string $value Введённое описание
	 *
	 * @return string
	 */
	private function normalize( string $value ): string {
		$value = trim( $value );

		// mb_substr() — обрезка по символам, а не по байтам (кириллица — 2 байта)
		return mb_substr( $value, 0, self::MAX_LENGTH, 'UTF-8' );
	}
}
