<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\ArticlePublishValidator;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Services\Template\TemplateResolver;
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
	 * @param TaskPublishValidator    $validator        Валидатор заданий перед публикацией
	 * @param ArticlePublishValidator $articleValidator Валидатор статей перед публикацией
	 * @param TaskPublishGuard        $guard            Общий протокол блокировки публикации
	 * @param PostManager             $posts            Доступ к сохранённой мете задания
	 * @param TermManager             $terms            Доступ к привязанным терминам
	 * @param TaxonomyRepository      $taxonomies       Таксономии предмета (для сборки состояния)
	 * @param TemplateResolver        $templateResolver Резолвер шаблона задания (как в метабоксе)
	 */
	public function __construct(
		private readonly TaskPublishValidator    $validator,
		private readonly ArticlePublishValidator $articleValidator,
		private readonly TaskPublishGuard        $guard,
		private readonly PostManager             $posts,
		private readonly TermManager             $terms,
		private readonly TaxonomyRepository      $taxonomies,
		private readonly TemplateResolver        $templateResolver,
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

		// Статьи (суффикс '_articles') — свой набор проверок, см. validateArticle()
		if ( PostTypeResolver::isArticlePostType( $postType ) ) {
			return $this->validateArticle( $data, (int) ( $postarr['ID'] ?? 0 ), $postType );
		}

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
				$hasMetaForm = isset( $_POST[ PostMetaName::Meta->value ] );

				// Программная вставка (импорт пакета, рестор): формы нет, поста ещё
				// нет — мета и термины будут записаны сразу после insert. Валидация
				// по пустому состоянию откатывала бы такие записи в черновик.
				if ( $postId <= 0 && ! $hasMetaForm ) {
					return null;
				}

				// Первичная вставка из модалки (post_id=0): терминов ни в форме, ни
				// в БД ещё нет — проверять таксономии не по чему.
				$blockingError = ( $postId > 0 || isset( $_POST['tax_input'] ) )
					? $this->validator->getBlockingError( $postType, $this->effectiveTaxInput( $postId, $postType ) )
					: null;

				return $blockingError
					?? $this->validator->getSoftError(
						$this->effectiveMeta( $postId ),
						$this->effectiveTemplateId( $postId )
					);
			}
		);
	}

	/**
	 * Блокировка публикации статьи: номер задания и обязательные для статей таксономии.
	 *
	 * @param array<string, mixed> $data     Очищенные данные поста
	 * @param int                  $postId   ID статьи (0 — создаётся новая)
	 * @param string               $postType CPT статей
	 *
	 * @return array<string, mixed>
	 */
	private function validateArticle( array $data, int $postId, string $postType ): array {
		return $this->guard->enforce(
			$data,
			'fs_lms_publish_error_',
			'Укажите название статьи.',
			function () use ( $postType, $postId ): ?string {
				// Программная вставка (импорт пакета, рестор): формы нет, поста ещё нет —
				// термины запишутся сразу после insert. Проверка по пустому состоянию
				// откатывала бы такие статьи в черновик.
				if ( $postId <= 0 && ! isset( $_POST['tax_input'] ) ) {
					return null;
				}

				return $this->articleValidator->getBlockingError(
					$postType,
					$this->effectiveArticleTaxInput( $postId, $postType )
				);
			}
		);
	}

	/**
	 * Термины статьи: из формы редактора, а при её отсутствии — уже сохранённые.
	 *
	 * Читаются только проверяемые таксономии: номер задания и обязательные для
	 * статей пользовательские (по флагу «Использовать в статьях»).
	 *
	 * @param int    $postId   ID статьи (0 — создаётся новая).
	 * @param string $postType CPT статей.
	 *
	 * @return array<string, mixed> Карта [слаг таксономии => привязки].
	 */
	private function effectiveArticleTaxInput( int $postId, string $postType ): array {
		if ( isset( $_POST['tax_input'] ) ) {
			return $this->unslashArray( 'tax_input' );
		}

		if ( $postId <= 0 ) {
			return array();
		}

		$subjectKey = PostTypeResolver::subjectFromArticlePostType( $postType );
		$slugs      = array( PostTypeResolver::getTaskTaxonomy( $subjectKey ) );

		foreach ( $this->articleValidator->requiredForArticles( $subjectKey ) as $tax ) {
			$slugs[] = $tax->slug;
		}

		return $this->storedTerms( $postId, $slugs );
	}

	/**
	 * Сохранённые привязки поста по списку таксономий.
	 *
	 * @param int      $postId ID записи.
	 * @param string[] $slugs  Слаги таксономий.
	 *
	 * @return array<string, int[]> Карта [слаг таксономии => ID терминов].
	 */
	private function storedTerms( int $postId, array $slugs ): array {
		$stored = array();

		foreach ( $slugs as $slug ) {
			$terms = $this->terms->getPostTerms( $postId, $slug );
			if ( ! empty( $terms ) ) {
				$stored[ $slug ] = array_map( static fn( $term ) => (int) $term->term_id, $terms );
			}
		}

		return $stored;
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
		$slugs      = array_map(
			static fn( $tax ): string => $tax->slug,
			$this->taxonomies->getBySubject( $subjectKey )
		);

		return $this->storedTerms( $postId, $slugs );
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
	 * Шаблон задания: из формы редактора, а при её отсутствии — резолвером,
	 * тем же, что рендерит метабокс (TemplateResolver): привязка шаблона к
	 * терму «номер задания» приоритетнее постметы. Чтение одной лишь постметы
	 * здесь ломало публикацию: у задания со связкой 19-21 меты нет, валидатор
	 * сваливался на «Стандартный» и требовал чужое «Условие задания».
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

		// Модалка inline-редактора (TaskContentCallbacks) шлёт шаблон параметром
		// `template`: при первичной вставке (post_id=0) это единственный источник.
		$fromModal = $this->sanitizeKey( 'template' );
		if ( '' !== $fromModal ) {
			return $fromModal;
		}

		$post = $postId > 0 ? $this->posts->get( $postId ) : null;

		return null !== $post ? $this->templateResolver->resolveId( $post ) : '';
	}

	/**
	 * Вызывается на хуке 'admin_notices' на экране редактирования задания или статьи.
	 * Предупреждает, если обязательная таксономия не содержит термов.
	 *
	 * @return void
	 */
	public function showEmptyRequiredTaxNotice(): void {
		$screen    = get_current_screen();
		$isArticle = $screen && PostTypeResolver::isArticlePostType( $screen->post_type );

		// Показываем ошибку валидации публикации, если она была отложена
		$this->guard->renderDeferredError(
			'fs_lms_publish_error_',
			$isArticle
				? __( 'Невозможно опубликовать статью', 'fs-lms' )
				: __( 'Невозможно опубликовать задание', 'fs-lms' )
		);

		if ( ! $screen ) {
			return;
		}

		// Пустые обязательные таксономии: у задания — все обязательные предмета,
		// у статьи — только помеченные «Использовать в статьях».
		if ( $isArticle ) {
			$subjectKey = PostTypeResolver::subjectFromArticlePostType( $screen->post_type );
			$emptyTaxes = $this->articleValidator->findEmptyRequired( $subjectKey );
			$contentWord = 'статью';
		} elseif ( PostTypeResolver::isTaskPostType( $screen->post_type ) ) {
			$subjectKey = PostTypeResolver::subjectFromTaskPostType( $screen->post_type );
			$emptyTaxes = $this->validator->findEmptyRequired( $subjectKey );
			$contentWord = 'задание';
		} else {
			return;
		}

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
				'<div class="notice notice-warning"><p><strong>Внимание:</strong> Обязательная таксономия «%s» не содержит термов — %s нельзя будет опубликовать.%s</p></div>',
				esc_html( $tax->name ),
				esc_html( $contentWord ),
				$canManage ? $link : esc_html( $link )
			);
		}
	}
}