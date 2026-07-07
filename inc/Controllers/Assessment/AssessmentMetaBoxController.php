<?php

declare( strict_types=1 );

namespace Inc\Controllers\Assessment;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\AssessmentTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\TidiesCoreMetaBoxes;

/**
 * Class AssessmentMetaBoxController
 *
 * Регистрирует, рендерит и сохраняет метабокс контрольной для всех CPT {key}_assessments.
 *
 * @package Inc\Controllers
 */
class AssessmentMetaBoxController extends BaseController implements ServiceInterface {

	use Authorizer, TidiesCoreMetaBoxes;

	public function __construct(
		private readonly SubjectRepository  $subjects,
		private readonly MetaBoxRegistrar   $registrar,
		private readonly MetaBoxManager     $metaBoxManager,
		private readonly AssessmentTemplate $template,
		private readonly PostManager           $postManager,
		private readonly AssessmentManager     $assessmentManager,
		private readonly TaskPublishGuard      $guard,
		private readonly EgeCompletenessChecker $completeness,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'handleTidyMetaBoxes' ), 20 );
		add_action( 'save_post', array( $this, 'handleAssessmentSave' ) );
		// #10: не даём опубликовать контрольную без названия (откат в draft + notice).
		add_filter( 'wp_insert_post_data', array( $this, 'validateAssessmentTitle' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'showPublishError' ) );
	}

	/**
	 * Блокирует публикацию контрольной без названия (`wp_insert_post_data`).
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 *
	 * @return array<string, mixed>
	 */
	public function validateAssessmentTitle( array $data, array $postarr ): array {
		if ( ! PostTypeResolver::isAssessmentPostType( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$postId = (int) ( $postarr['ID'] ?? 0 );

		return $this->guard->enforce(
			$data,
			'fs_lms_assessment_publish_error_',
			'Укажите название контрольной.',
			fn(): ?string => $this->resolveCompletenessError( $postId )
		);
	}

	/**
	 * Доменная ошибка публикации (D16.3.а): для ЕГЭ/КЕГЭ запрещаем публиковать
	 * неукомплектованную работу (строгая биекция задание↔номер). Тип берётся из
	 * присланной формы (может меняться в этом же запросе) с фолбэком на сохранённый;
	 * состав задач — из уже сохранённого meta (степ-лист автосейвится по AJAX).
	 */
	private function resolveCompletenessError( int $postId ): ?string {
		if ( $postId <= 0 ) {
			return null;
		}

		$assessment = $this->assessmentManager->get( $postId );
		if ( null === $assessment ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ранний хук; фактический сейв c нонсом в handleAssessmentSave().
		$rawKind    = isset( $_POST['fs_lms_meta']['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['fs_lms_meta']['kind'] ) ) : '';
		$postedKind = '' !== $rawKind ? AssessmentKind::tryFrom( $rawKind ) : null;
		$kind       = $postedKind ?? $assessment->kind;

		if ( ! $kind->needsCompletenessCheck() ) {
			return null;
		}

		$result = $this->completeness->validate( $assessment, $assessment->subjectKey );
		if ( $result->isStrictlyComplete() ) {
			return null;
		}

		return 'Работа не укомплектована — ' . $result->summary() . '.';
	}

	/** Выводит отложенную ошибку публикации контрольной на экране редактирования. */
	public function showPublishError(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! PostTypeResolver::isAssessmentPostType( $screen->post_type ) ) {
			return;
		}
		$this->guard->renderDeferredError( 'fs_lms_assessment_publish_error_', __( 'Невозможно опубликовать контрольную', 'fs-lms' ) );
	}

	public function handleAddMetaBoxes(): void {
		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		$assessment_post_types = array_map(
			static fn( $subject ) => PostTypeResolver::assessments( $subject->key ),
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_assessment_settings',
			'Настройки контрольной',
			array( $this, 'renderSettingsContent' ),
			$assessment_post_types
		)->register();

		$this->registrar->add(
			'fs_lms_assessment_builder',
			'Конструктор контрольной',
			array( $this, 'renderBuilderContent' ),
			$assessment_post_types
		)->register();
	}

	public function handleTidyMetaBoxes(): void {
		$screen = get_current_screen();
		if ( $screen && PostTypeResolver::isAssessmentPostType( $screen->post_type ) ) {
			$this->tidyCoreMetaBoxes( $screen->post_type );
		}
	}

	public function renderSettingsContent( \WP_Post $post ): void {
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );
		echo '<div class="fs-lms-assessment-settings">';
		$this->template->render( $post );
		echo '</div>';
	}

	public function renderBuilderContent( \WP_Post $post ): void {
		$subject     = PostTypeResolver::subjectFromAssessmentPostType( $post->post_type );
		$assessment  = $this->assessmentManager->get( $post->ID );
		$task_ids    = null !== $assessment ? $assessment->taskIds : array();
		$task_points = null !== $assessment ? $assessment->taskPoints : array();

		$steps = array();
		foreach ( $task_ids as $i => $id ) {
			$id      = (int) $id;
			$steps[] = array(
				'key'     => 'slot_' . $i,
				'type'    => 'task',
				'payload' => array( 'ref' => $id > 0 ? $id : 0 ),
				'_title'  => $id > 0 ? get_the_title( $id ) : '',
			);
		}
		$json        = wp_json_encode( $steps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$points_json = wp_json_encode( $task_points, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		$ege_slots = (int) wp_count_terms( array(
			'taxonomy'   => $subject . '_task_number',
			'hide_empty' => false,
		) );

		$ege_kinds_json = wp_json_encode( AssessmentKind::weightedScoreValues() );

		echo '<div class="fs-sb-wrap">';
		echo '<div class="fs-lms-assessment-builder" '
			. 'data-assessment-id="' . esc_attr( (string) $post->ID ) . '" '
			. 'data-subject="' . esc_attr( $subject ) . '" '
			. 'data-ege-slots="' . esc_attr( (string) $ege_slots ) . '" '
			. 'data-ege-kinds="' . esc_attr( $ege_kinds_json ?: '[]' ) . '" '
			. 'data-task-points="' . esc_attr( $points_json ?: '{}' ) . '">';
		echo '<script type="application/json" class="fs-sb-data">' . ( $json ?: '[]' ) . '</script>';
		echo '</div>';
		echo '</div>';
	}

	public function handleAssessmentSave( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			return;
		}

		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		$raw_data = wp_unslash( $_POST[ PostMetaName::Meta->value ] ?? array() );

		$data = is_array( $raw_data ) ? $raw_data : array();

		$this->metaBoxManager->saveFieldsMerge(
			$post_id,
			PostMetaName::Meta->value,
			$data,
			$this->template->get_fields()
		);

		// Плоский ключ для фильтрации в list table.
		$kind = sanitize_key( $data['kind'] ?? '' );
		if ( '' !== $kind ) {
			$this->postManager->updateMeta( $post_id, PostMetaName::AssessmentKind->value, $kind );
		}
	}
}
