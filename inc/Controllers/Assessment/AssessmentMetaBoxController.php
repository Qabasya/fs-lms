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
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;
use Inc\Shared\Traits\TidiesCoreMetaBoxes;

/**
 * Class AssessmentMetaBoxController
 *
 * Регистрирует, рендерит и сохраняет метабокс контрольной для всех CPT {key}_assessments.
 *
 * @package Inc\Controllers
 */
class AssessmentMetaBoxController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	use Authorizer, Sanitizer, TidiesCoreMetaBoxes;

	/** Префикс транзиента предупреждения о неукомплектованной КЕГЭ (см. {@see resolveCompletenessError()}). */
	private const COMPLETENESS_WARNING_PREFIX = 'fs_lms_assessment_completeness_warning_';

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
		add_action( 'admin_notices', array( $this, 'showCompletenessWarning' ) );
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

		// Ранний хук; фактический сейв c нонсом в handleAssessmentSave().
		$postedMeta = $this->unslashArray( PostMetaName::Meta->value );
		$rawKind    = $this->sanitizeKeyValue( $postedMeta['kind'] ?? '' );
		$postedKind = '' !== $rawKind ? AssessmentKind::tryFrom( $rawKind ) : null;
		$kind       = $postedKind ?? $assessment->kind;

		if ( ! $kind->needsCompletenessCheck() ) {
			return null;
		}

		$result = $this->completeness->validate( $assessment, $assessment->subjectKey );
		if ( $result->isStrictlyComplete() ) {
			return null;
		}

		// Тестовое окружение (WP_DEBUG): КЕГЭ разрешаем публиковать неукомплектованной —
		// жёлтое предупреждение вместо блокировки, чтобы быстро проверять вёрстку без
		// набора полного комплекта номеров заданий.
		if ( $this->allowsIncompletePublish( $kind ) ) {
			$this->guard->warn( self::COMPLETENESS_WARNING_PREFIX, 'Работа не укомплектована — ' . $result->summary() . '.' );
			return null;
		}

		return 'Работа не укомплектована — ' . $result->summary() . '.';
	}

	/** Только тестовое окружение и только станции ЕГЭ/ОГЭ — см. {@see resolveCompletenessError()}. */
	private function allowsIncompletePublish( AssessmentKind $kind ): bool {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && $kind->isStation();
	}

	/** Выводит отложенную ошибку публикации контрольной на экране редактирования. */
	public function showPublishError(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! PostTypeResolver::isAssessmentPostType( $screen->post_type ) ) {
			return;
		}
		$this->guard->renderDeferredError( 'fs_lms_assessment_publish_error_', __( 'Невозможно опубликовать контрольную', 'fs-lms' ) );
	}

	/** Выводит отложенное предупреждение о неукомплектованной публикации (тестовое окружение). */
	public function showCompletenessWarning(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! PostTypeResolver::isAssessmentPostType( $screen->post_type ) ) {
			return;
		}
		$this->guard->renderDeferredWarning( self::COMPLETENESS_WARNING_PREFIX, __( 'Опубликовано неукомплектованным (тестовое окружение)', 'fs-lms' ) );
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
		$this->render( 'admin/metaboxes/fields-wrapper', array(
			'wrapper_class' => 'fs-lms-assessment-settings',
			'post'          => $post,
			'template'      => $this->template,
			'values'        => $this->postManager->taskMeta( $post->ID ),
		) );
	}

	public function renderBuilderContent( \WP_Post $post ): void {
		// Тот же гейт, что открывает станцию вхолостую по прямой ссылке
		// (AssessmentAccessPolicy::canPreview()) — прямой путь из конструктора,
		// без необходимости знать пермалинк контрольной или её текущий статус.
		printf(
			'<p class="fs-lms-assessment-preview"><a class="button" href="%s" target="_blank" rel="noopener">Предпросмотр ↗</a></p>',
			esc_url( (string) get_preview_post_link( $post ) )
		);

		$subject     = PostTypeResolver::subjectFromAssessmentPostType( $post->post_type );
		$assessment  = $this->assessmentManager->get( $post->ID );
		$task_ids     = null !== $assessment ? $assessment->taskIds : array();
		$task_points  = null !== $assessment ? $assessment->taskPoints : array();
		$task_numbers = null !== $assessment ? $assessment->taskNumbers : array();

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
		$json         = wp_json_encode( $steps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$points_json  = wp_json_encode( $task_points, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$numbers_json = wp_json_encode( $task_numbers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		// Число позиций зависит от ВИДА экзамена, не только от предмета: ЕГЭ по
		// информатике — все термы таксономии номеров (обычно 27), ОГЭ — фиксированные
		// 16 (см. .docs/Tasks.md §2 и Inc\Modules\EgeComputer\Config\OgeScaleConfig —
		// авторитетный источник числа 16, здесь не импортируется: ядро не знает о
		// модулях). Таксономия для ОГЭ ни при чём — позиции 13.1/13.2 не могут быть
		// термами (см. докблок OgeCriteriaConfig). Карта kind => slots, а не одно
		// число — раньше ОГЭ ошибочно наследовал число слотов ЕГЭ.
		$ege_slots_by_kind = array(
			AssessmentKind::EgeComputer->value => (int) wp_count_terms( array(
				'taxonomy'   => $subject . '_task_number',
				'hide_empty' => false,
			) ),
			AssessmentKind::OgeComputer->value => 16,
		);
		$ege_slots_json = wp_json_encode( $ege_slots_by_kind );

		$ege_kinds_json = wp_json_encode( AssessmentKind::weightedScoreValues() );

		// Тот же тестовый гейт, что и на сервере (см. resolveCompletenessError()) — не
		// дизейблим кнопку «Опубликовать» на клиенте для неукомплектованного КЕГЭ, чтобы
		// клиентский гейт (D16.5) не блокировал то, что серверный уже разрешает.
		$allow_incomplete_kinds = array_values( array_filter(
			array_map( static fn( AssessmentKind $kind ) => $kind->value, AssessmentKind::cases() ),
			fn( string $value ) => $this->allowsIncompletePublish( AssessmentKind::from( $value ) )
		) );
		$allow_incomplete_json = wp_json_encode( $allow_incomplete_kinds );

		$this->render( 'admin/metaboxes/builder-shell', array(
			'root_class' => 'fs-lms-assessment-builder',
			'data'       => array(
				'assessment-id'         => $post->ID,
				'subject'               => $subject,
				'ege-slots'             => $ege_slots_json ?: '{}',
				'ege-kinds'             => $ege_kinds_json ?: '[]',
				'allow-incomplete-kinds' => $allow_incomplete_json ?: '[]',
				'task-points'           => $points_json ?: '{}',
				'task-numbers'          => $numbers_json ?: '{}',
			),
			'json'       => (string) $json,
		) );
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

		$data = $this->unslashArray( PostMetaName::Meta->value );

		// Станции (ЕГЭ/ОГЭ) имитируют реальный экзамен — время, лимит попыток,
		// таблица перевода баллов и вступительный текст больше не редактируются
		// автором конкретной работы, приходят из module-level StationExamConfig
		// (см. AssessmentManager::STATION_SETTINGS_FILTER, .docs/Tasks.md §3.2).
		// Даже если что-то из этого пришло в $_POST — не сохраняем.
		$kind = sanitize_key( $data['kind'] ?? '' );
		if ( AssessmentKind::fromValueOrDefault( $kind )->isStation() ) {
			foreach ( [ 'time_limit_minutes', 'max_attempts', 'pass_score', 'score_map', 'intro_html' ] as $stationField ) {
				unset( $data[ $stationField ] );
			}
		}

		$this->metaBoxManager->saveFieldsMerge(
			$post_id,
			PostMetaName::Meta->value,
			$data,
			$this->template->get_fields()
		);

		// Плоский ключ для фильтрации в list table.
		if ( '' !== $kind ) {
			$this->postManager->updateMeta( $post_id, PostMetaName::AssessmentKind->value, $kind );
		}
	}
}
