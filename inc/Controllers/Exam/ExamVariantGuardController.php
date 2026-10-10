<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\TransientKey;
use Inc\Managers\Wp\TransientManager;
use Inc\Services\Exam\ExamVariantGuard;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Хуки неизменяемости варианта экзамена (этап 13.2): мета работы и заданий, поля поста, корзина, удаление поста и вложения.
 *
 * Правило одно: изменяющая операция над замороженным объектом отклоняется, чтение не затрагивается. Решает {@see ExamVariantGuard};
 * здесь — только перехват. Метаданные перехватываются общими фильтрами `*_post_metadata`: так закрыты метабокс, модалка задания, конструктор
 * работы, импорт и перенос предмета разом, без правки каждого пути. Причина показывается нотисом после редиректа.
 */
class ExamVariantGuardController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	/** Сколько живёт причина отказа — ровно на один редирект. */
	private const NOTICE_TTL = 30;

	public function __construct(
		private readonly ExamVariantGuard $guard,
		private readonly TransientManager $transients,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'update_post_metadata', array( $this, 'guardMeta' ), 1, 3 );
		add_filter( 'add_post_metadata', array( $this, 'guardMeta' ), 1, 3 );
		add_filter( 'delete_post_metadata', array( $this, 'guardMeta' ), 1, 3 );
		add_filter( 'wp_insert_post_data', array( $this, 'guardPostData' ), 1, 2 );
		add_filter( 'pre_trash_post', array( $this, 'guardTrash' ), 5, 2 );
		add_filter( 'pre_delete_post', array( $this, 'guardDelete' ), 5, 2 );
		add_filter( 'pre_delete_attachment', array( $this, 'guardAttachment' ), 5, 2 );
		add_action( 'admin_notices', array( $this, 'renderNotice' ) );
	}

	/**
	 * @param mixed  $check    null — штатный путь; не null — операция отменена.
	 * @param int    $objectId Пост.
	 * @param string $metaKey  Ключ меты.
	 *
	 * @return mixed
	 */
	public function guardMeta( $check, $objectId, $metaKey ) {
		if ( null !== $check || ! in_array( (string) $metaKey, ExamVariantGuard::GUARDED_META, true ) || ! $this->guard->isPostFrozen( (int) $objectId ) ) {
			return $check;
		}

		$this->remember( (int) $objectId );

		return false;
	}

	/**
	 * Сохранение замороженного поста возвращает прежние значения полей: название, текст условия, статус, адрес.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 *
	 * @return array<string, mixed>
	 */
	public function guardPostData( array $data, array $postarr ): array {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id <= 0 || ! $this->guard->isPostFrozen( $id ) ) {
			return $data;
		}

		$current = get_post( $id );
		if ( ! $current instanceof \WP_Post ) {
			return $data;
		}

		$changed = false;
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
			if ( isset( $data[ $field ] ) && (string) $data[ $field ] !== (string) $current->{$field} ) {
				$data[ $field ] = $current->{$field};
				$changed        = true;
			}
		}
		if ( $changed ) {
			$this->remember( $id );
		}

		return $data;
	}

	/** @param bool|null $check @param \WP_Post $post @return bool|null */
	public function guardTrash( $check, $post ) {
		return $post instanceof \WP_Post && $this->guard->isPostFrozen( $post->ID ) ? $this->block( $post->ID ) : $check;
	}

	/** @param \WP_Post|false|null $check @param \WP_Post $post @return \WP_Post|false|null */
	public function guardDelete( $check, $post ) {
		return $post instanceof \WP_Post && $this->guard->isPostFrozen( $post->ID ) ? $this->block( $post->ID ) : $check;
	}

	/** @param \WP_Post|false|null $check @param \WP_Post $post @return \WP_Post|false|null */
	public function guardAttachment( $check, $post ) {
		return $post instanceof \WP_Post && $this->guard->isAttachmentFrozen( $post->ID ) ? $this->block( $post->ID ) : $check;
	}

	public function renderNotice(): void {
		$reason = $this->transients->take( TransientKey::ExamVariantBlocked, get_current_user_id() );
		if ( is_string( $reason ) && '' !== $reason ) {
			$this->render( 'admin/components/exam-variant-frozen-notice', array( 'reason' => $reason ) );
		}
	}

	/** @return false */
	private function block( int $postId ) {
		$this->remember( $postId );

		return false;
	}

	private function remember( int $postId ): void {
		$this->transients->set( TransientKey::ExamVariantBlocked, get_current_user_id(), $this->guard->reason( $postId ), self::NOTICE_TTL );
	}
}
