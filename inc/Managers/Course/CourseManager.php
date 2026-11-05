<?php

declare( strict_types=1 );

namespace Inc\Managers\Course;

use Inc\Managers\Wp\PostManager;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class CourseManager
 *
 * CRUD курсов: пост + meta через PostManager.
 *
 * @package Inc\Managers
 */
class CourseManager {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	public function create( string $subjectKey, CourseDTO $dto ): int {
		$id = $this->posts->insert(
			array(
				'post_title'   => $dto->title,
				'post_content' => $dto->descriptionHtml,
				'post_type'    => PostTypeResolver::courses( $subjectKey ),
				'post_status'  => 'draft',
				'post_author'  => $dto->authorId ?: get_current_user_id(),
			)
		);

		if ( $id > 0 ) {
			$this->saveMeta( $id, $dto );
			$this->saveAccess( $id, false, $dto->audienceSubjectKeys, 0 );
		}

		return $id;
	}

	public function update( int $courseId, CourseDTO $dto ): bool {
		$updated = $this->posts->update(
			$courseId,
			array(
				'post_title'   => $dto->title,
				'post_content' => $dto->descriptionHtml,
			)
		);

		if ( ! $updated ) {
			return false;
		}

		$this->saveMeta( $courseId, $dto );
		return true;
	}

	public function get( int $courseId ): ?CourseDTO {
		$post = $this->posts->get( $courseId );
		if ( null === $post || ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return null;
		}

		$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
		return CourseDTO::fromPost( $post, is_array( $meta ) ? $meta : array(), $this->accessSettings( $post->ID ) );
	}

	/**
	 * Курсы банка предмета.
	 *
	 * @param string $subjectKey
	 * @param array  $args Опции выборки (PostManager::search): status, author, search, orderby, order, limit.
	 * @return CourseDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		$posts = $this->posts->search( PostTypeResolver::courses( $subjectKey ), $args );

		return array_map(
			function ( \WP_Post $post ): CourseDTO {
				$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
				return CourseDTO::fromPost( $post, is_array( $meta ) ? $meta : array(), $this->accessSettings( $post->ID ) );
			},
			$posts
		);
	}

	public function delete( int $courseId ): bool {
		$this->posts->delete( $courseId );
		return true;
	}

	private function saveMeta( int $courseId, CourseDTO $dto ): void {
		$this->posts->updateMeta(
			$courseId,
			PostMetaName::Meta->value,
			array(
				'modules' => ModuleDTO::toList( $dto->modules ),
			)
		);
	}

	/** Access is separate from the replaceable module payload. */
	public function accessSettings( int $courseId ): array {
		$audience = $this->posts->getMeta( $courseId, PostMetaName::CourseAudienceSubjectKeys->value );
		return array(
			'self_paced_enabled'    => (bool) $this->posts->getMeta( $courseId, PostMetaName::CourseSelfPacedEnabled->value ),
			'audience_subject_keys' => is_array( $audience ) ? $audience : array(),
			'service_group_id'      => (int) $this->posts->getMeta( $courseId, PostMetaName::CourseServiceGroupId->value ),
		);
	}

	/** Persist and verify separate access metadata without replacing the module payload. */
	public function saveAccess( int $courseId, bool $enabled, array $audience, int $groupId ): void {
		$values = array(
			PostMetaName::CourseSelfPacedEnabled->value    => $enabled ? 1 : 0,
			PostMetaName::CourseAudienceSubjectKeys->value => array_values( $audience ),
			PostMetaName::CourseServiceGroupId->value      => $groupId,
		);
		foreach ( $values as $key => $value ) {
			$this->posts->updateMeta( $courseId, $key, $value );
			$stored = $this->posts->getMeta( $courseId, $key );
			if ( is_int( $value ) ? (int) $stored !== $value : $stored !== $value ) {
				throw new \RuntimeException( 'Не удалось сохранить настройки доступа курса.' );
			}
		}
	}

	/**
	 * URL аватара курса — его обложки (Конструктор курса → миниатюра в шапке).
	 *
	 * @return string '' — не задан (или курса нет)
	 */
	public function avatarUrl( int $courseId ): string {
		return $courseId > 0 ? $this->posts->getThumbnailUrl( $courseId, 'thumbnail' ) : '';
	}
}
