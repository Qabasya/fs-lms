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
use Inc\Services\Task\TaskBundleService;
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

	/**
	 * WP filter: доменная ошибка публикации от модулей (`?string` — текст ошибки,
	 * `null` — всё в порядке). Ядро о модулях не знает: публичные экзамены, например,
	 * требуют год. Аргументы: `$error`, `int $postId`, `array $postedMeta`.
	 */
	public const PUBLISH_ERROR_FILTER = 'fs_lms_assessment_publish_error';

	/** Префикс транзиента предупреждения о неукомплектованной КЕГЭ (см. {@see resolveCompletenessError()}). */
	private const COMPLETENESS_WARNING_PREFIX = 'fs_lms_assessment_completeness_warning_';

	/**
	 * Поля метабокса «Настройки контрольной» — видим только для `AssessmentKind::Control`
	 * (`! kind->isStation()`), см. .docs/Tasks.md «тип экзамена — отдельный метабокс».
	 */
	private const SETTINGS_FIELD_IDS = array( 'time_limit_minutes', 'max_attempts', 'pass_score', 'intro_html' );

	public function __construct(
		private readonly SubjectRepository  $subjects,
		private readonly MetaBoxRegistrar   $registrar,
		private readonly MetaBoxManager     $metaBoxManager,
		private readonly AssessmentTemplate $template,
		private readonly PostManager           $postManager,
		private readonly AssessmentManager     $assessmentManager,
		private readonly TaskPublishGuard      $guard,
		private readonly EgeCompletenessChecker $completeness,
		private readonly TaskBundleService      $bundles,
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
			fn(): ?string => $this->resolveCompletenessError( $postId ) ?? $this->resolveModuleError( $postId )
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

	/** Ошибка публикации от модулей ({@see self::PUBLISH_ERROR_FILTER}); работает по присланной форме. */
	private function resolveModuleError( int $postId ): ?string {
		if ( $postId <= 0 ) {
			return null;
		}

		$error = apply_filters( self::PUBLISH_ERROR_FILTER, null, $postId, $this->unslashArray( PostMetaName::Meta->value ) );

		return is_string( $error ) && '' !== $error ? $error : null;
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

		// Порядок регистрации = порядок на экране (все 'high', WP сохраняет очередь
		// внутри одного приоритета): тип экзамена — первым, от него зависит второй
		// (settings — только Control; для станций ЕГЭ/ОГЭ эти поля приходят из
		// StationExamConfig, см. handleAssessmentSave()).
		$this->registrar->add(
			'fs_lms_assessment_kind',
			'Тип экзамена',
			array( $this, 'renderKindContent' ),
			$assessment_post_types
		)->register();

		$this->registrar->add(
			'fs_lms_assessment_settings',
			'Настройки контрольной',
			array( $this, 'renderSettingsContent' ),
			$assessment_post_types
		)->register();

		$this->registrar->add(
			'fs_lms_assessment_station',
			'Экраны и доступ',
			array( $this, 'renderStationContent' ),
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

	/**
	 * «Тип экзамена» — только поле `kind`, единственный держатель nonce'а формы
	 * (остальные два метабокса ниже его не дублируют — один nonce на форму).
	 */
	public function renderKindContent( \WP_Post $post ): void {
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );
		$this->render( 'admin/metaboxes/fields-subset', array(
			'wrapper_class' => 'fs-lms-assessment-kind',
			'post'          => $post,
			'template'      => $this->template,
			'values'        => $this->postManager->taskMeta( $post->ID ),
			'field_ids'     => array( 'kind' ),
		) );
	}

	/**
	 * «Настройки контрольной» — весь контейнер видим только для `AssessmentKind::Control`
	 * (JS: `assessment-builder.js::toggleKindFields()`); для станций (ЕГЭ/ОГЭ) эти четыре
	 * поля всё равно не редактируются — приходят из `StationExamConfig`.
	 */
	public function renderSettingsContent( \WP_Post $post ): void {
		$this->render( 'admin/metaboxes/fields-subset', array(
			'wrapper_class' => 'fs-lms-assessment-settings',
			'post'          => $post,
			'template'      => $this->template,
			'values'        => $this->postManager->taskMeta( $post->ID ),
			'field_ids'     => self::SETTINGS_FIELD_IDS,
		) );
	}

	/**
	 * «Экраны и доступ» — только для станции ЕГЭ (JS: `assessment-builder.js::toggleKindFields()`
	 * скрывает весь бокс для остальных видов). Состав — флажок ядра плюс поля модулей
	 * ({@see AssessmentTemplate::FIELDS_FILTER}).
	 */
	public function renderStationContent( \WP_Post $post ): void {
		$this->render( 'admin/metaboxes/fields-subset', array(
			'wrapper_class' => 'fs-lms-assessment-station',
			'post'          => $post,
			'template'      => $this->template,
			'values'        => $this->postManager->taskMeta( $post->ID ),
			'field_ids'     => $this->template->stationFieldIds(),
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
		$task_ids    = null !== $assessment ? $assessment->taskIds : array();
		$task_points = null !== $assessment ? $assessment->taskPoints : array();

		// Число позиций зависит от ВИДА экзамена, не только от предмета: ЕГЭ по
		// информатике — все термы таксономии номеров (обычно 27), ОГЭ — фиксированные
		// 16 (см. .docs/Tasks.md §2 и Inc\Modules\EgeComputer\Config\OgeScaleConfig —
		// авторитетный источник числа 16, здесь не импортируется: ядро не знает о
		// модулях). Таксономия для ОГЭ ни при чём — позиции 13-16 резолвятся
		// ручным номером на самом экзамене, а не термом (см. докблок OgeCriteriaConfig;
		// «13» — один пост с двумя условиями на выбор, AlternativeConditionsTemplate).
		// Карта kind => slots, а не одно число — раньше ОГЭ ошибочно наследовал
		// число слотов ЕГЭ.
		$ege_slots_by_kind = array(
			AssessmentKind::EgeComputer->value => (int) wp_count_terms( array(
				'taxonomy'   => $subject . '_task_number',
				'hide_empty' => false,
			) ),
			AssessmentKind::OgeComputer->value => 16,
		);

		// Раскладка по позициям: сохранённая; иначе (экзамен сохранён до её появления)
		// задания встают на позицию своего номера, а связка 19-21 разворачивается в три слота.
		$slot_total = null !== $assessment ? (int) ( $ege_slots_by_kind[ $assessment->kind->value ] ?? 0 ) : 0;
		$layout     = $this->assessmentManager->slotLayout( $post->ID );
		if ( array() === $layout ) {
			$layout = $slot_total > 0
				? $this->layoutByPosition( $task_ids, $assessment->taskNumbers, $subject, $slot_total )
				: $task_ids;
		}

		$steps = array();
		foreach ( $layout as $i => $id ) {
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
			),
			'json'       => (string) $json,
		) );
	}

	/**
	 * Раскладка заданий по позициям экзамена (слот i = номер i+1) для экзамена, у которого
	 * сохранённой раскладки ещё нет. Задание встаёт на позицию своего номера (терм
	 * `{subject}_task_number` либо ручной номер банковского задания); связка 19-21
	 * (parent) заменяется тремя детьми на их номерах — раньше в слоте оставался parent,
	 * и №20/№21 не появлялись. Не нашедшее места — в первый свободный слот.
	 *
	 * @param int[]                $taskIds     Плотный список заданий экзамена
	 * @param array<int, string>   $taskNumbers Снапшот номеров банковских заданий (task_id => номер)
	 * @param string               $subject     Ключ предмета
	 * @param int                  $total       Число позиций экзамена
	 *
	 * @return int[] Раскладка длиной не меньше $total; 0 — пустая позиция
	 */
	private function layoutByPosition( array $taskIds, array $taskNumbers, string $subject, int $total ): array {
		// Связки → дети с их номерами (позиции 19/20/21).
		$entries = array();
		foreach ( $taskIds as $id ) {
			$id       = (int) $id;
			$children = $this->bundles->childrenSummary( $id );
			if ( array() === $children ) {
				$entries[] = array( 'id' => $id, 'number' => $this->positionOf( $id, $taskNumbers, $subject ) );
				continue;
			}
			foreach ( $children as $child ) {
				$entries[] = array( 'id' => (int) $child['id'], 'number' => (int) $child['number'] );
			}
		}

		$layout   = array_fill( 0, $total, 0 );
		$leftover = array();
		foreach ( $entries as $entry ) {
			$index = $entry['number'] - 1;
			if ( $index >= 0 && $index < $total && 0 === $layout[ $index ] ) {
				$layout[ $index ] = $entry['id'];
			} else {
				$leftover[] = $entry['id'];
			}
		}

		foreach ( $leftover as $id ) {
			$free = array_search( 0, $layout, true );
			if ( false === $free ) {
				$layout[] = $id;
			} else {
				$layout[ $free ] = $id;
			}
		}

		return $layout;
	}

	/** Номер позиции задания: терм таксономии номеров, иначе ручной номер банковского; 0 — неизвестен. */
	private function positionOf( int $taskId, array $taskNumbers, string $subject ): int {
		$terms = wp_get_post_terms( $taskId, $subject . '_task_number', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return (int) $terms[0];
		}

		return (int) ( $taskNumbers[ $taskId ] ?? 0 );
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

		// Станции (ЕГЭ/ОГЭ) имитируют реальный экзамен — время, лимит попыток и
		// вступительный текст больше не редактируются автором конкретной работы,
		// приходят из module-level StationExamConfig (см.
		// AssessmentManager::STATION_SETTINGS_FILTER, .docs/Tasks.md §3.2). Даже если
		// что-то из этого пришло в $_POST — не сохраняем.
		$kind           = sanitize_key( $data['kind'] ?? '' );
		$assessmentKind = AssessmentKind::fromValueOrDefault( $kind );
		if ( $assessmentKind->isStation() ) {
			foreach ( [ 'time_limit_minutes', 'max_attempts', 'pass_score', 'intro_html' ] as $stationField ) {
				unset( $data[ $stationField ] );
			}
		}

		// score_map (перевод первичного балла во вторичный, SecondaryScoreService) —
		// для станций (ЕГЭ/ОГЭ) шкала берётся из module-level StationExamConfig
		// (KegeScaleConfig/OgeScaleConfig) и безусловно переопределяет значение из
		// меты при каждом чтении (EgeComputerModule::applyStationSettings()); у
		// Control поле не читается вовсе. Значит мета-версия мертва в обоих
		// случаях — никогда не сохраняем её, даже если пришла в $_POST.
		unset( $data['score_map'] );

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
