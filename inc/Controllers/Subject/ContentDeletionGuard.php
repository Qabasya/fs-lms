<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subject;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Services\Course\ContentLifecycleService;
use Inc\Services\Course\ContentUsageService;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class ContentDeletionGuard
 *
 * Запрещает физическое удаление (trash/force-delete) контента банков при наличии
 * ссылок (usage > 0). Вместо удаления — «В архив». Orphan удаляется штатно.
 *
 * @package Inc\Controllers
 */
class ContentDeletionGuard extends BaseController implements ServiceInterface {

	use Sanitizer;
	use TemplateRenderer;

	private const ARCHIVE_ACTION   = 'fs_lms_archive_content';
	private const UNARCHIVE_ACTION = 'fs_lms_unarchive_content';

	public function __construct(
		private readonly ContentUsageService     $usage,
		private readonly ContentLifecycleService $lifecycle,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'pre_trash_post', array( $this, 'guardTrash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'guardDelete' ), 10, 3 );
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_action( 'admin_action_' . self::ARCHIVE_ACTION, array( $this, 'handleArchive' ) );
		add_action( 'admin_action_' . self::UNARCHIVE_ACTION, array( $this, 'handleUnarchive' ) );
		add_action( 'admin_notices', array( $this, 'maybeRenderBlockedNotice' ) );
		add_filter( 'manage_posts_columns', array( $this, 'addUsageColumn' ), 10, 2 );
		add_action( 'manage_posts_custom_column', array( $this, 'renderUsageColumn' ), 10, 2 );
	}

	/**
	 * Добавляет колонку «Используется» в списки банков (бейдж T1.26).
	 *
	 * @param array<string, string> $columns
	 * @param string                $post_type
	 * @return array<string, string>
	 */
	public function addUsageColumn( array $columns, string $post_type ): array {
		if ( PostTypeResolver::isBankPostType( $post_type ) ) {
			$columns['fs_lms_usage'] = __( 'Используется', 'fs-lms' );
		}

		return $columns;
	}

	/**
	 * Рендерит счётчик использований в колонке списка банка.
	 *
	 * @param string $column
	 * @param int    $post_id
	 * @return void
	 */
	public function renderUsageColumn( string $column, int $post_id ): void {
		if ( 'fs_lms_usage' !== $column ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$kind  = ContentUsageService::kindOf( $post->post_type );
		$count = '' === $kind ? 0 : $this->usage->usageCount( $kind, $post_id );

		$this->render( 'admin/components/content-usage-badge', array( 'count' => $count ) );
	}

	/**
	 * @param bool|null $check
	 * @param \WP_Post  $post
	 * @return bool|null false блокирует; null — штатный путь.
	 */
	public function guardTrash( ?bool $check, \WP_Post $post ): ?bool {
		return $this->isBlocked( $post ) ? false : $check;
	}

	/**
	 * @param \WP_Post|false|null $check
	 * @param \WP_Post            $post
	 * @param bool                $force_delete
	 * @return \WP_Post|false|null false блокирует; исходное значение — штатный путь.
	 */
	public function guardDelete( $check, \WP_Post $post, bool $force_delete ) {
		return $this->isBlocked( $post ) ? false : $check;
	}

	/**
	 * Подменяет действие «Удалить» на «В архив» для референсного контента.
	 *
	 * @param array<string, string> $actions
	 * @param \WP_Post               $post
	 * @return array<string, string>
	 */
	public function rowActions( array $actions, \WP_Post $post ): array {
		if ( ! PostTypeResolver::isBankPostType( $post->post_type ) ) {
			return $actions;
		}

		if ( ContentLifecycleService::STATUS_ARCHIVED === $post->post_status ) {
			$actions['fs_lms_unarchive'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->actionUrl( self::UNARCHIVE_ACTION, $post->ID ) ),
				esc_html__( 'Вернуть из архива', 'fs-lms' )
			);
		}

		if ( $this->isReferenced( $post ) ) {
			unset( $actions['trash'], $actions['delete'] );
			$actions['fs_lms_archive'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->actionUrl( self::ARCHIVE_ACTION, $post->ID ) ),
				esc_html__( 'В архив', 'fs-lms' )
			);
		}

		return $actions;
	}

	public function handleArchive(): void {
		$post_id = $this->validatedActionPost( self::ARCHIVE_ACTION );
		$this->lifecycle->archive( $post_id );
		$this->redirectBack( $post_id );
	}

	public function handleUnarchive(): void {
		$post_id = $this->validatedActionPost( self::UNARCHIVE_ACTION );
		$this->lifecycle->unarchive( $post_id );
		$this->redirectBack( $post_id );
	}

	public function maybeRenderBlockedNotice(): void {
		if ( empty( $_GET['fs_lms_delete_blocked'] ) ) {
			return;
		}

		$this->render(
			'admin/components/content-delete-blocked-notice',
			array( 'count' => $this->sanitizeInt( $_GET['fs_lms_delete_blocked'] ) )
		);
	}

	/**
	 * Заблокировано ли удаление (для pre_trash/pre_delete): банк + есть ссылки.
	 *
	 * @param \WP_Post $post
	 * @return bool
	 */
	private function isBlocked( \WP_Post $post ): bool {
		if ( ! $this->isReferenced( $post ) ) {
			return false;
		}

		set_transient(
			'fs_lms_delete_blocked_' . get_current_user_id(),
			$this->usage->usageCount( ContentUsageService::kindOf( $post->post_type ), $post->ID ),
			30
		);

		return true;
	}

	/**
	 * @param \WP_Post $post
	 * @return bool
	 */
	private function isReferenced( \WP_Post $post ): bool {
		$kind = ContentUsageService::kindOf( $post->post_type );
		if ( '' === $kind ) {
			return false;
		}

		return $this->usage->usageCount( $kind, $post->ID ) > 0;
	}

	private function actionUrl( string $action, int $post_id ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?action=' . $action . '&post=' . $post_id ),
			$action . '_' . $post_id
		);
	}

	private function validatedActionPost( string $action ): int {
		$post_id = $this->sanitizeInt( $_GET['post'] ?? 0 );

		if ( ! current_user_can( Capability::ManageLMSAssignments->value ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'fs-lms' ) );
		}
		check_admin_referer( $action . '_' . $post_id );

		return $post_id;
	}

	private function redirectBack( int $post_id ): void {
		$post     = get_post( $post_id );
		$fallback = $post instanceof \WP_Post
			? admin_url( 'edit.php?post_type=' . $post->post_type )
			: admin_url();

		wp_safe_redirect( wp_get_referer() ?: $fallback );
		exit;
	}
}
