<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX-обработчики inline-редактора задач (Phase F, Этап 6).
 *
 * Источник истины полей — PHP `Fields/*` (путь A): модалка запрашивает готовую
 * HTML-разметку полей шаблона ({@see ajaxGetTaskEditorForm}) и отправляет её
 * как `fs_lms_meta[...]` — тот же формат, что и нативный метабокс. Сохранение
 * идёт общим путём {@see MetaBoxManager::saveFields()}. JS поля не строит.
 */
class TaskContentCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TemplateRegistry $templateRegistry,
		private readonly MetaBoxManager   $metaBoxManager,
	) {}

	/**
	 * Возвращает HTML полей выбранного шаблона для модалки-редактора.
	 * POST: subject_key, template, post_id? (0 = новая задача → пустые поля).
	 */
	public function ajaxGetTaskEditorForm(): void {
		$this->authorize( Nonce::TaskContent, Capability::ManageLMSAssignments );

		$subjectKey = $this->requireKey( 'subject_key' );
		$templateId = $this->requireKey( 'template' );
		$postId     = (int) ( $_POST['post_id'] ?? 0 );

		$template = $this->templateRegistry->get( $templateId );
		if ( ! $template ) {
			$this->error( 'Неизвестный шаблон задания' );
			return;
		}

		$postType = PostTypeResolver::tasks( $subjectKey );

		if ( $postId > 0 ) {
			$post = get_post( $postId );
			if ( ! $post ) {
				$this->error( 'Задание не найдено' );
				return;
			}
		} else {
			// Поля task-шаблонов не используют $post — отдаём пустую болванку.
			$post = new \WP_Post( (object) array( 'ID' => 0, 'post_type' => $postType ) );
		}

		ob_start();
		$template->render( $post );
		$html = (string) ob_get_clean();

		$this->success( array( 'html' => $html ) );
	}

	/**
	 * Создаёт новую задачу или обновляет существующую со всеми полями.
	 * POST: subject_key, template, title, post_id? (0 = создать), fs_lms_meta[...] (поля шаблона).
	 */
	public function ajaxSaveTaskContent(): void {
		$this->authorize( Nonce::TaskContent, Capability::ManageLMSAssignments );

		$subjectKey = $this->requireKey( 'subject_key' );
		$templateId = $this->requireKey( 'template' );
		$title      = $this->requireText( 'title' );
		$postId     = (int) ( $_POST['post_id'] ?? 0 );
		$rawMeta    = (array) wp_unslash( $_POST[ PostMetaName::Meta->value ] ?? array() );

		$template = $this->templateRegistry->get( $templateId );
		if ( ! $template ) {
			$this->error( 'Неизвестный шаблон задания' );
			return;
		}

		$postType = PostTypeResolver::tasks( $subjectKey );

		if ( $postId > 0 ) {
			wp_update_post( array( 'ID' => $postId, 'post_title' => $title ) );
		} else {
			$result = wp_insert_post( array(
				'post_type'   => $postType,
				'post_title'  => $title,
				'post_status' => 'publish',
			) );

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
			$rawMeta,
			$template->get_fields()
		);

		$this->success( array(
			'id'       => $postId,
			'title'    => get_the_title( $postId ),
			'template' => $templateId,
			'edit_url' => get_edit_post_link( $postId, '' ),
		) );
	}
}
