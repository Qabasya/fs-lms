<?php

declare( strict_types=1 );

namespace Inc\Core\Assets;

use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\ArticlePublishValidator;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

/**
 * Class AdminLocalizations
 *
 * Реестр window-переменных админки: какие данные на каком экране локализуются
 * к админскому бандлу (fs_lms_vars, fs_lms_task_data, fs_lms_lesson_vars и др.).
 *
 * Выделен из Core\Enqueue (Т14.4): только сборка данных, сами вызовы
 * wp_localize_script делает AdminAssets.
 *
 * @package Inc\Core\Assets
 */
class AdminLocalizations {

	public function __construct(
		private readonly TaxonomyRepository      $taxonomy_repository,
		private readonly TemplateRegistry        $templateRegistry,
		private readonly ArticlePublishValidator $articleValidator,
	) {}

	/**
	 * Реестр window-переменных админки: имя → данные (null — на этом экране не нужна).
	 *
	 * @param AdminScreenContext $ctx Признаки текущего экрана
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	public function registry( AdminScreenContext $ctx ): array {
		return array(
			'fs_lms_lesson_vars'       => $ctx->lesson ? $this->lessonVars( $ctx ) : null,
			// На экране работ нужен task-modal для создания задания.
			'fs_lms_task_data'         => $this->taskDataVars( $ctx ),
			'fs_lms_task_editor_vars'  => $ctx->needsTaskEditor() ? $this->taskEditorVars() : null,
			// Экран статьи: обязательные для статей таксономии — для клиентского гарда публикации.
			'fs_lms_article_data'      => $ctx->article ? $this->articleDataVars( $ctx ) : null,
			'fs_lms_applications_vars' => 'fs_lms_userlist' === $ctx->page ? $this->applicationsVars() : null,
			// Глобальные переменные — на всех страницах админки плагина.
			'fs_lms_vars'              => $this->globalAdminVars(),
		);
	}

	/**
	 * Переменные экрана CPT статей.
	 *
	 * Список таксономий, без которых статью нельзя опубликовать: номер задания
	 * плюс обязательные таксономии предмета с флагом «Использовать в статьях».
	 * Серверная проверка — {@see \Inc\Services\Subject\ArticlePublishValidator};
	 * здесь тот же набор отдаётся клиентскому гарду, чтобы автор увидел ошибку
	 * до отправки формы.
	 *
	 * @param AdminScreenContext $ctx Признаки экрана
	 *
	 * @return array<string, mixed>
	 */
	private function articleDataVars( AdminScreenContext $ctx ): array {
		$subjectKey = PostTypeResolver::subjectFromArticlePostType( $ctx->postType );

		$taxonomies = array(
			array(
				'slug' => PostTypeResolver::getTaskTaxonomy( $subjectKey ),
				'name' => 'Номер задания',
			),
		);

		foreach ( $this->articleValidator->requiredForArticles( $subjectKey ) as $dto ) {
			$taxonomies[] = array(
				'slug' => $dto->slug,
				'name' => $dto->name,
			);
		}

		return array(
			'subject_key'         => $subjectKey,
			'required_taxonomies' => $taxonomies,
		);
	}

	/**
	 * Переменные экрана CPT уроков.
	 *
	 * @param AdminScreenContext $ctx Признаки экрана
	 *
	 * @return array<string, mixed>
	 */
	private function lessonVars( AdminScreenContext $ctx ): array {
		return array(
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'subject_key' => PostTypeResolver::subjectFromLessonPostType( $ctx->postType ),
			'nonces'      => array(
				'authorLesson' => Nonce::AuthorLesson->create(),
			),
		);
	}

	/**
	 * Данные модалки создания задания — на экранах заданий, работ и страницах предмета.
	 *
	 * @param AdminScreenContext $ctx Признаки экрана
	 *
	 * @return array<string, mixed>|null
	 */
	private function taskDataVars( AdminScreenContext $ctx ): ?array {
		$subjectKey = match ( true ) {
			$ctx->task            => PostTypeResolver::subjectFromTaskPostType( $ctx->postType ),
			$ctx->work            => PostTypeResolver::subjectFromWorkPostType( $ctx->postType ),
			$ctx->isSubjectPage() => $ctx->subjectPageKey(),
			default               => '',
		};

		if ( '' === $subjectKey ) {
			return null;
		}

		return array(
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'security'            => Nonce::TaskCreation->create(),
			'subject_key'         => $subjectKey,
			'post_type'           => $ctx->task ? $ctx->postType : PostTypeResolver::tasks( $subjectKey ),
			// Гард публикации (RequiredTaxGuard) вешается на #publish текущего экрана и ищет
			// tax_input[slug] — такие поля есть только на экране самого задания. На экранах
			// работ/уроков/курсов/страницы предмета этот блок нужен лишь для модалки создания
			// задания, поэтому список обязательных таксономий здесь не отдаём.
			'required_taxonomies' => $ctx->task ? $this->getRequiredTaxonomies( $subjectKey ) : array(),
		);
	}

	/**
	 * Переменные inline-редактора задач (Phase F, Этап 6).
	 *
	 * @return array<string, mixed>
	 */
	private function taskEditorVars(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'schema'   => $this->templateRegistry->allEditorSchemas(),
			'nonces'   => array(
				'taskContent' => Nonce::TaskContent->create(),
			),
			'actions'  => array(
				'saveTaskContent'   => AjaxHook::SaveTaskContent->jsAction(),
				'getTaskEditorForm' => AjaxHook::GetTaskEditorForm->jsAction(),
			),
		);
	}

	/**
	 * Переменные таблицы заявок.
	 *
	 * @return array<string, mixed>
	 */
	private function applicationsVars(): array {
		return array(
			'nonces' => array(
				'trash'                  => Nonce::TrashApplication->create(),
				'edit'                   => Nonce::EditApplication->create(),
				'review'                 => Nonce::ReviewApplication->create(),
				'enroll'                 => Nonce::Enroll->create(),
				'manager'                => Nonce::Manager->create(),
				'revealPii'              => Nonce::RevealPii->create(),
				'updatePerson'           => Nonce::UpdatePerson->create(),
				'deletePii'              => Nonce::RequestPiiDeletion->create(),
				'restoreFromArchive'     => Nonce::RestoreFromArchive->create(),
				'selectExistingParent'   => Nonce::SelectExistingParent->create(),
				'removeParentAssignment' => Nonce::RemoveParentAssignment->create(),
			),
		);
	}

	/**
	 * Глобальные переменные всех страниц админки плагина.
	 *
	 * @return array<string, mixed>
	 */
	private function globalAdminVars(): array {
		return array(
			'ajaxurl'          => admin_url( 'admin-ajax.php' ),
			'nonces'           => array(
				'subject'           => Nonce::Subject->create(),
				'subjectBundle'     => Nonce::SubjectBundle->create(),
				'manager'           => Nonce::Manager->create(),
				'expulsion'         => Nonce::Expulsion->create(),
				'deleteGroup'       => Nonce::DeleteGroup->create(),
				'deletePeriod'      => Nonce::DeletePeriod->create(),
				'hardDeleteStudent' => Nonce::HardDeleteStudent->create(),
				'config'            => Nonce::Config->create(),
				'authorLesson'      => Nonce::AuthorLesson->create(),
				'authorWork'        => Nonce::AuthorWork->create(),
				'authorAssessment'  => Nonce::AuthorAssessment->create(),
				'authorCourse'      => Nonce::AuthorCourse->create(),
				'room'              => Nonce::Room->create(),
			),
			'ajax_actions'     => AjaxHook::toJsArray(),
			// Фаза 5, D3/D4: URL preview-плеера курса (кнопка «Просмотр» в конструкторе).
			'coursePreviewUrl' => PageRoutes::CoursePreview->url(),
		);
	}

	/**
	 * Возвращает список обязательных таксономий для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array
	 */
	private function getRequiredTaxonomies( string $subject_key ): array {
		return array_values(
			array_map(
				fn( $dto ) => array(
					'slug' => $dto->slug,
					'name' => $dto->name,
				),
				array_filter(
					$this->taxonomy_repository->getBySubject( $subject_key ),
					fn( $dto ) => $dto->is_required
				)
			)
		);
	}
}
