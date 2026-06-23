<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Services\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX-обработчики для создания и сохранения содержимого задач из редактора.
 * Обслуживает хук SaveTaskContent (Phase F, Этап 6).
 */
class TaskContentCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TemplateRegistry $templateRegistry,
		private readonly MetaBoxManager   $metaBoxManager,
	) {}

	/**
	 * Создаёт новую задачу или обновляет существующую со всеми полями.
	 * POST: subject_key, template, title, data (JSON), post_id? (0 = создать новую).
	 */
	public function ajaxSaveTaskContent(): void {
		$this->authorize( Nonce::TaskContent, Capability::ManageLMSAssignments );

		$subjectKey = $this->requireKey( 'subject_key' );
		$templateId = $this->requireKey( 'template' );
		$title      = $this->requireText( 'title' );
		$postId     = (int) ( $_POST['post_id'] ?? 0 );
		$rawData    = $this->sanitizeText( 'data' );

		$data = json_decode( $rawData, true );
		if ( ! is_array( $data ) ) {
			$this->error( 'Неверный формат данных' );
			return;
		}

		$template = $this->templateRegistry->get( $templateId );
		if ( ! $template ) {
			$this->error( 'Неизвестный шаблон задания' );
			return;
		}

		$postType = PostTypeResolver::tasks( $subjectKey );

		if ( $postId > 0 ) {
			wp_update_post( [ 'ID' => $postId, 'post_title' => $title ] );
		} else {
			$result = wp_insert_post( [
				'post_type'   => $postType,
				'post_title'  => $title,
				'post_status' => 'publish',
			] );

			if ( is_wp_error( $result ) || ! $result ) {
				$this->error( 'Не удалось создать задание' );
				return;
			}

			$postId = $result;
			update_post_meta( $postId, PostMetaName::TemplateType->value, $templateId );
		}

		$this->metaBoxManager->saveFields(
			$postId,
			PostMetaName::Meta->value,
			$data,
			$template->get_fields()
		);

		$this->success( [
			'id'       => $postId,
			'title'    => get_the_title( $postId ),
			'template' => $templateId,
			'edit_url' => get_edit_post_link( $postId, '' ),
		] );
	}
}
