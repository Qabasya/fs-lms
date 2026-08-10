<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Article;

use Inc\Core\BaseController;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\ArticleSlugService;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class SlugCallbacks
 *
 * Автогенерация слага статьи и его заморозка при первой публикации.
 *
 * ### Когда слаг пересобирается
 *
 * Пока статья не опубликована — на каждом сохранении из редактора: номер
 * задания живёт в терме таксономии, а он обязателен только при публикации
 * ({@see \Inc\Services\Subject\ArticlePublishValidator}), и на первом
 * сохранении его, как правило, ещё нет. В момент первой публикации адрес
 * замораживается и больше не меняется никогда.
 *
 * ### Как отсекаются чужие пути
 *
 * Не перечислением нежелательных сценариев, а одним положительным сигналом:
 * `tax_input` в данных поста есть только у сохранений из формы редактора
 * (обычной, быстрой, массовой). Автосохранения, смена статуса, корзина,
 * восстановление, перенос предмета пакетом ({@see \Inc\Services\Subject\Bundle\PostRestorer}
 * кладёт `post_name` как есть) и создание черновика из конструктора урока его
 * не шлют — и слага не касаются.
 *
 * @package Inc\Callbacks\Article
 */
class SlugCallbacks extends BaseController {

	/**
	 * Статусы, при которых адрес статьи уже опубликован и трогать его нельзя.
	 * Фолбэк для статей, изданных до появления мета-замка.
	 */
	private const PUBLISHED_STATUSES = array( 'publish', 'future', 'private', 'fs_archived' );

	/** Статусы, на которых генерировать нечего: черновик формы, корзина, вложение. */
	private const SKIP_STATUSES = array( 'auto-draft', 'trash', 'inherit' );

	/**
	 * @param ArticleSlugService $slugs Генератор слага.
	 * @param PostManager        $posts Менеджер записей WordPress.
	 */
	public function __construct(
		private readonly ArticleSlugService $slugs,
		private readonly PostManager        $posts,
	) {
		parent::__construct();
	}

	/**
	 * Подставляет слаг статьи. Хук `wp_insert_post_data`.
	 *
	 * Работает с `$postarr`, а не с `$_POST`: ядро отдаёт сюда те же данные,
	 * но они доезжают и под WP-CLI, и при программной вставке с `tax_input`.
	 *
	 * @param array<string, mixed> $data    Очищенные данные записи.
	 * @param array<string, mixed> $postarr Данные записи до очистки полей поста.
	 *
	 * @return array<string, mixed>
	 */
	public function generateSlug( array $data, array $postarr ): array {
		$post_type = (string) ( $data['post_type'] ?? '' );
		$post_id   = (int) ( $postarr['ID'] ?? 0 );

		if ( ! $this->shouldGenerate( $data, $postarr, $post_type, $post_id ) ) {
			return $data;
		}

		$subject_key = PostTypeResolver::subjectFromArticlePostType( $post_type );
		$taxonomy    = PostTypeResolver::getTaskTaxonomy( $subject_key );

		$data['post_name'] = $this->slugs->build(
			$post_type,
			$post_id,
			$this->slugs->resolveTaskNumber( $postarr['tax_input'][ $taxonomy ] ?? null, $subject_key )
		);

		return $data;
	}

	/**
	 * Замораживает слаг первой публикацией. Хук `transition_post_status`.
	 *
	 * Замок метой, а не проверкой статуса: статус пробивают два штатных пути
	 * ядра — снятие с публикации в черновик и восстановление из корзины
	 * (`wp_untrash_post()` возвращает запись именно в `draft`).
	 *
	 * @param string   $new_status Новый статус.
	 * @param string   $old_status Прежний статус.
	 * @param \WP_Post $post       Запись.
	 *
	 * @return void
	 */
	public function lockOnPublish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! PostTypeResolver::isArticlePostType( (string) $post->post_type ) ) {
			return;
		}

		if ( ! in_array( $new_status, self::PUBLISHED_STATUSES, true ) ) {
			return;
		}

		$this->posts->updateMeta( (int) $post->ID, PostMetaName::ArticleSlugLocked->value, 1 );
	}

	/**
	 * Нужно ли пересобирать слаг этой записи.
	 *
	 * @param array<string, mixed> $data      Очищенные данные записи.
	 * @param array<string, mixed> $postarr   Данные записи до очистки полей поста.
	 * @param string               $post_type Тип записи.
	 * @param int                  $post_id   ID записи (0 — создаётся новая).
	 *
	 * @return bool
	 */
	private function shouldGenerate( array $data, array $postarr, string $post_type, int $post_id ): bool {
		// Ревизии и автосейвы приходят с post_type = 'revision' и сюда не проходят.
		if ( ! PostTypeResolver::isArticlePostType( $post_type ) ) {
			return false;
		}

		// Автосохранение своего черновика идёт полноценным update: сигнала
		// формы в нём нет, но константа надёжнее и документирует намерение.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( in_array( (string) ( $data['post_status'] ?? '' ), self::SKIP_STATUSES, true ) ) {
			return false;
		}

		if ( ! isset( $postarr['tax_input'] ) || ! is_array( $postarr['tax_input'] ) ) {
			return false;
		}

		return ! $this->isFrozen( $post_id );
	}

	/**
	 * Заморожен ли адрес статьи.
	 *
	 * @param int $post_id ID статьи (0 — создаётся новая).
	 *
	 * @return bool
	 */
	private function isFrozen( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( '' !== (string) $this->posts->getMeta( $post_id, PostMetaName::ArticleSlugLocked->value ) ) {
			return true;
		}

		// Статьи, опубликованные до появления замка: меты нет, но адрес уже живёт.
		// На момент этого фильтра в БД ещё лежит прежний статус записи.
		$post = $this->posts->get( $post_id );

		return $post instanceof \WP_Post
			&& in_array( (string) $post->post_status, self::PUBLISHED_STATUSES, true );
	}
}