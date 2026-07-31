<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class SubjectValidationCallbacks
 *
 * Коллбеки для валидации заданий перед публикацией и отображения предупреждений в админ-панели.
 *
 * @package Inc\Callbacks\Subject
 *
 * ### Основные обязанности:
 *
 * 1. **Валидация перед публикацией** — проверка наличия обязательных таксономий и мета-полей
 *    перед публикацией задания. Блокирует публикацию при ошибках.
 * 2. **Предупреждение на экране редактирования** — отображение уведомления, если обязательная
 *    таксономия не содержит термов (чтобы автор мог заранее их создать).
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику валидации TaskPublishValidator.
 * Используется в SubjectController для подключения к хукам 'wp_insert_post_data'
 * и 'admin_notices'.
 */
class SubjectValidationCallbacks extends BaseController {

	use Sanitizer;

	/**
	 * Конструктор коллбеков.
	 *
	 * @param TaskPublishValidator $validator  Валидатор заданий перед публикацией
	 * @param TaskPublishGuard     $guard      Общий протокол блокировки публикации
	 * @param PostManager          $posts      Доступ к сохранённой мете задания
	 * @param TermManager          $terms      Доступ к привязанным терминам
	 * @param TaxonomyRepository   $taxonomies Таксономии предмета (для сборки состояния)
	 */
	public function __construct(
		private readonly TaskPublishValidator $validator,
		private readonly TaskPublishGuard     $guard,
		private readonly PostManager          $posts,
		private readonly TermManager          $terms,
		private readonly TaxonomyRepository   $taxonomies,
	) {
		parent::__construct();
	}

	/**
	 * Вызывается на хуке 'wp_insert_post_data'. Блокирует публикацию,
	 * если отсутствуют обязательные таксономии или мета-поля.
	 *
	 * @param array $data    Очищенные данные поста
	 * @param array $postarr Неочищенные данные из $_POST
	 *
	 * @return array
	 */
	public function validateRequiredTaxonomies( array $data, array $postarr ): array {
		$postType = $data['post_type'] ?? '';

		// Только для типов постов заданий (суффикс '_tasks')
		if ( ! PostTypeResolver::isTaskPostType( $postType ) ) {
			return $data;
		}

		$postId = (int) ( $postarr['ID'] ?? 0 );

		return $this->guard->enforce(
			$data,
			'fs_lms_publish_error_',
			'Укажите название задания.',
			function () use ( $postType, $postId ) {
				return $this->validator->getBlockingError( $postType, $this->effectiveTaxInput( $postId, $postType ) )
					?? $this->validator->getSoftError(
						$this->effectiveMeta( $postId ),
						$this->effectiveTemplateId( $postId )
					);
			}
		);
	}

	/**
	 * Термины задания: из формы редактора, а при её отсутствии — уже сохранённые.
	 *
	 * Быстрое и массовое редактирование, а также программные `wp_update_post()`
	 * данных метабоксов не шлют. Без этого фолбэка проверка видела пустой ввод и
	 * откатывала в черновик задание, у которого на самом деле всё заполнено.
	 *
	 * @param int    $postId   ID задания (0 — создаётся новое).
	 * @param string $postType CPT заданий.
	 *
	 * @return array<string, mixed> Карта [слаг таксономии => привязки].
	 */
	private function effectiveTaxInput( int $postId, string $postType ): array {
		if ( isset( $_POST['tax_input'] ) ) {
			return $this->unslashArray( 'tax_input' );
		}

		if ( $postId <= 0 ) {
			return array();
		}

		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $postType );
		$stored     = array();

		foreach ( $this->taxonomies->getBySubject( $subjectKey ) as $tax ) {
			$terms = $this->terms->getPostTerms( $postId, $tax->slug );
			if ( ! empty( $terms ) ) {
				$stored[ $tax->slug ] = array_map( static fn( $term ) => (int) $term->term_id, $terms );
			}
		}

		return $stored;
	}

	/**
	 * Мета задания: из формы редактора, а при её отсутствии — сохранённая.
	 *
	 * @param int $postId ID задания.
	 *
	 * @return array<string, mixed>
	 */
	private function effectiveMeta( int $postId ): array {
		if ( isset( $_POST[ PostMetaName::Meta->value ] ) ) {
			return $this->unslashArray( PostMetaName::Meta->value );
		}

		if ( $postId <= 0 ) {
			return array();
		}

		$meta = $this->posts->getMeta( $postId, PostMetaName::Meta->value );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Шаблон задания: из формы редактора, а при её отсутствии — сохранённый.
	 *
	 * Пустая строка уводит реестр шаблонов на «Стандартный», поля которого
	 * к реальному заданию отношения не имеют, — поэтому фолбэк обязателен.
	 *
	 * @param int $postId ID задания.
	 *
	 * @return string
	 */
	private function effectiveTemplateId( int $postId ): string {
		$fromForm = $this->sanitizeKey( PostMetaName::TemplateType->value );
		if ( '' !== $fromForm ) {
			return $fromForm;
		}

		$stored = $postId > 0
			? $this->posts->getMeta( $postId, PostMetaName::TemplateType->value )
			: '';

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Вызывается на хуке 'admin_notices' на экране редактирования задания.
	 * Предупреждает, если обязательная таксономия не содержит термов.
	 *
	 * @return void
	 */
	public function showEmptyRequiredTaxNotice(): void {
		$screen = get_current_screen();

		// Показываем ошибку валидации публикации, если она была отложена
		$this->guard->renderDeferredError( 'fs_lms_publish_error_', __( 'Невозможно опубликовать задание', 'fs-lms' ) );

		if ( ! $screen || ! PostTypeResolver::isTaskPostType( $screen->post_type ) ) {
			return;
		}

		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $screen->post_type );
		// Находим обязательные таксономии, в которых нет ни одного терма
		$emptyTaxes = $this->validator->findEmptyRequired( $subjectKey );

		if ( empty( $emptyTaxes ) ) {
			return;
		}

		foreach ( $emptyTaxes as $tax ) {
			// Право на управление терминами таксономий (WP-капабилити рубрик)
			$canManage = current_user_can( Capability::ManageTerms->value );

			if ( $canManage ) {
				$link = sprintf(
					' <a href="%s">Добавить термы &rarr;</a>',
					esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax->slug ) )
				);
			} else {
				$link = ' Обратитесь к администратору.';
			}

			printf(
				'<div class="notice notice-warning"><p><strong>Внимание:</strong> Обязательная таксономия «%s» не содержит термов — задание нельзя будет опубликовать.%s</p></div>',
				esc_html( $tax->name ),
				$canManage ? $link : esc_html( $link )
			);
		}
	}
}