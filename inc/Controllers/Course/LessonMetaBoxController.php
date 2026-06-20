<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Course\StepDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\TidiesCoreMetaBoxes;

/**
 * Class LessonMetaBoxController
 *
 * Монтирует конструктор шагов (step-builder, T1.5.4) на все CPT {key}_lessons.
 * Сами шаги сохраняются через AJAX (AjaxHook::SaveLessonSteps), поэтому save_post
 * здесь не используется — метабокс только рендерит точку монтажа с инлайн-JSON шагов.
 *
 * @package Inc\Controllers
 */
class LessonMetaBoxController extends BaseController implements ServiceInterface {

	use TidiesCoreMetaBoxes;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly LessonManager     $lessons,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		// Приоритет 100 — после регистрации ядром коробок «Автор»/«Изображение»/«Атрибуты».
		add_action( 'add_meta_boxes', array( $this, 'tidyLessonMetaBoxes' ), 100 );
	}

	/**
	 * Чистит экран урока от лишних коробок ядра и уносит «Автор» в правый сайдбар.
	 *
	 * @param string $post_type Тип записи текущего экрана.
	 */
	public function tidyLessonMetaBoxes( string $post_type ): void {
		if ( PostTypeResolver::isLessonPostType( $post_type ) ) {
			$this->tidyCoreMetaBoxes( $post_type );
		}
	}

	public function handleAddMetaBoxes(): void {
		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		$lesson_post_types = array_map(
			static fn( $subject ) => PostTypeResolver::lessons( $subject->key ),
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_lesson_metabox',
			'Конструктор урока',
			array( $this, 'renderMetaboxContent' ),
			$lesson_post_types
		)->register();
	}

	public function renderMetaboxContent( \WP_Post $post ): void {
		$lesson  = $this->lessons->get( $post->ID );
		$subject = PostTypeResolver::subjectFromLessonPostType( $post->post_type );
		$steps   = null !== $lesson ? StepDTO::toList( $lesson->steps ) : array();

		foreach ( $steps as &$step ) {
			$ref_id = (int) ( $step['payload']['ref'] ?? $step['payload']['article_id'] ?? 0 );
			if ( $ref_id > 0 ) {
				$step['_title'] = get_the_title( $ref_id );
			}
		}
		unset( $step );

		$json = wp_json_encode(
			$steps,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		echo '<div class="fs-lms-step-builder" '
			. 'data-lesson-id="' . esc_attr( (string) $post->ID ) . '" '
			. 'data-subject="' . esc_attr( $subject ) . '" '
			. 'data-level="lesson">';
		echo '<script type="application/json" class="fs-sb-data">' . ( $json ?: '[]' ) . '</script>';
		echo '</div>';
	}
}
