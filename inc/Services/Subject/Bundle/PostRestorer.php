<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Shared\SafeHtml;
use Inc\Enums\Wp\PostMetaName;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;

/**
 * Class PostRestorer
 *
 * Создаёт запись на целевом сайте из представления, снятого {@see PostCollector}.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Зачем выделено (Этап 1)
 *
 * Обратная половина той же одной операции: вставить пост, разложить мету,
 * привязать термины. Одинакова для всех семи разделов пакета.
 *
 * ### Санитизация
 *
 * Вход — файл, загруженный пользователем, поэтому поля поста чистятся теми же
 * функциями, что и в обычном импорте. Мета не проходит `wp_kses`: там лежат
 * структуры конструктора (шаги, ссылки, настройки оценивания), а не HTML для
 * вывода — ломать их нельзя. HTML-поля внутри меты рендерятся через штатные
 * шаблоны полей, которые экранируют вывод сами.
 */
class PostRestorer {

	/**
	 * Конструктор.
	 *
	 * @param PostManager $posts Менеджер записей
	 * @param TermManager $terms Менеджер терминов
	 */
	public function __construct(
		private readonly PostManager $posts,
		private readonly TermManager $terms,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Создаёт запись и возвращает её новый ID.
	 *
	 * @param string              $postType CPT целевой записи
	 * @param array               $data     Представление записи из манифеста
	 * @param ImportedEntitiesCollector $created  Журнал созданного (для отката)
	 *
	 * @return int Новый ID; 0 — вставка не удалась
	 */
	public function restore( string $postType, array $data, ImportedEntitiesCollector $created ): int {
		// post_name переносится как есть: для заданий слаг — это номер задания
		// (см. PostManager::getPostsByTerm), и восстановление его из заголовка
		// сломало бы и ЧПУ, и отображение номера.
		$postId = $this->posts->insert( array(
			'post_type'    => sanitize_key( $postType ),
			'post_title'   => sanitize_text_field( (string) ( $data['post_title'] ?? '' ) ),
			'post_name'    => sanitize_title( (string) ( $data['post_name'] ?? '' ) ),
			'post_content' => SafeHtml::post( (string) ( $data['post_content'] ?? '' ) ),
			'post_excerpt' => sanitize_text_field( (string) ( $data['post_excerpt'] ?? '' ) ),
			'post_status'  => sanitize_key( (string) ( $data['post_status'] ?? 'publish' ) ),
			'post_date'    => sanitize_text_field( (string) ( $data['post_date'] ?? '' ) ),
			'menu_order'   => absint( $data['menu_order'] ?? 0 ),
		) );

		if ( $postId <= 0 ) {
			return 0;
		}

		$created->addPost( $postId );

		$meta = (array) ( $data['meta'] ?? array() );
		// A copied course must be explicitly reviewed and enabled on its destination.
		unset( $meta[ PostMetaName::CourseServiceGroupId->value ] );
		if ( PostTypeResolver::isCoursePostType( $postType ) ) {
			$meta[ PostMetaName::CourseSelfPacedEnabled->value ] = false;
			$meta[ PostMetaName::CourseServiceGroupId->value ] = 0;
			$audience = $meta[ PostMetaName::CourseAudienceSubjectKeys->value ] ?? array();
			$meta[ PostMetaName::CourseAudienceSubjectKeys->value ] = array_values( array_unique( array_filter(
				is_array( $audience ) ? $audience : array(),
				fn ( mixed $key ): bool => is_string( $key ) && null !== $this->subjects->getByKey( $key ),
			) ) );
		}

		foreach ( $meta as $metaKey => $metaValue ) {
			$this->posts->updateMeta( $postId, sanitize_key( (string) $metaKey ), $metaValue );
		}

		foreach ( (array) ( $data['terms'] ?? array() ) as $taxonomy => $slugs ) {
			$this->terms->setPostTerms( $postId, (array) $slugs, sanitize_title( (string) $taxonomy ) );
		}

		return $postId;
	}
}
