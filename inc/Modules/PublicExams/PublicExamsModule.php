<?php

declare( strict_types=1 );

namespace Inc\Modules\PublicExams;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Assessment\AssessmentMetaBoxController;
use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Controllers\Pages\SubjectLandingController;
use Inc\Core\Assets\BundleLoader;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Wp\SubjectPageType;
use Inc\MetaBoxes\Fields\CheckboxField;
use Inc\MetaBoxes\Templates\AssessmentTemplate;
use Inc\Modules\PublicExams\Callbacks\PublicResultCallbacks;
use Inc\Modules\PublicExams\Config\PublicExamsConfig;
use Inc\Modules\PublicExams\Fields\ExamYearField;
use Inc\Modules\PublicExams\Services\PublicExamCatalog;
use Inc\Modules\PublicExams\Services\PublicExamPolicy;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\SubjectPagesService;

/**
 * Class PublicExamsModule
 *
 * Опциональный модуль — публичные экзамены: ЕГЭ (Компьютер), который автор открыл
 * всем. Гость решает его без авторизации, в конце получает лист с ответами и
 * баллами; в БД ничего не пишется (ответы живут в браузере, лист считает сервер
 * по присланным ответам — тот же путь, что у предпросмотра автора).
 * Ядро о модуле не знает: связь только через фильтры.
 *
 * Выключение:
 *  1) константа FS_LMS_PUBLIC_EXAMS = false в wp-config.php;
 *  2) выключенный модуль EgeComputer;
 *  3) удаление каталога `inc/Modules/PublicExams/` + строки в `Init::getServices()`.
 *
 * @package Inc\Modules\PublicExams
 */
class PublicExamsModule implements ServiceInterface {

	/** Разовый добор страниц «Экзамены» для предметов, заведённых до включения модуля. */
	private const PAGES_OPTION = 'fs_lms_public_exams_pages';
	private const PAGES_DONE   = '1';

	public function __construct(
		private readonly PublicExamsConfig     $config,
		private readonly PublicExamPolicy      $policy,
		private readonly PublicExamCatalog     $catalog,
		private readonly PublicResultCallbacks $callbacks,
		private readonly SubjectPagesService   $subjectPages,
		private readonly SubjectRepository     $subjects,
	) {}

	public function register(): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		// Конструктор экзамена: «Публичный» и «Год» (ядро о них не знает).
		add_filter( AssessmentTemplate::FIELDS_FILTER, array( $this, 'addFields' ) );
		add_filter( AssessmentMetaBoxController::PUBLISH_ERROR_FILTER, array( $this, 'requireYear' ), 10, 3 );

		// Страница экзамена: открыта всем, «Завершить» ведёт в раздел «Экзамены».
		add_filter( AssessmentPageController::PUBLIC_ACCESS_FILTER, array( $this, 'grantAccess' ), 10, 2 );
		add_filter( AssessmentPageController::PUBLIC_BACK_URL_FILTER, array( $this, 'backUrl' ), 10, 2 );

		// Лист ответов для гостей (nopriv) — и имя экшена для JS станции.
		add_action( 'wp_ajax_' . PublicResultCallbacks::ACTION, array( $this->callbacks, 'ajaxPublicResult' ) );
		add_action( 'wp_ajax_nopriv_' . PublicResultCallbacks::ACTION, array( $this->callbacks, 'ajaxPublicResult' ) );
		add_filter( BundleLoader::KEGE_PUBLIC_RESULT_FILTER, static fn(): string => PublicResultCallbacks::ACTION );

		// Раздел лендинга предмета `/{key}/exams/`.
		add_filter( SubjectPagesService::EXAMS_FILTER, '__return_true' );
		add_filter( SubjectLandingController::EXAMS_GROUPS_FILTER, array( $this, 'examGroups' ), 10, 2 );
		add_action( 'init', array( $this, 'ensurePages' ), 30 );
	}

	/**
	 * @param array<string, array{label: string, object: object}> $fields
	 *
	 * @return array<string, array{label: string, object: object}>
	 */
	public function addFields( array $fields ): array {
		$fields[ PublicExamPolicy::META_PUBLIC ] = array(
			'label'  => 'Публичный экзамен (доступен без авторизации на сайте и в курсе)',
			'object' => new CheckboxField(),
		);
		$fields[ PublicExamPolicy::META_YEAR ]   = array(
			'label'  => 'Год экзамена (публичные экзамены на сайте группируются по годам)',
			'object' => new ExamYearField( $this->catalog ),
		);

		return $fields;
	}

	/**
	 * Публичный экзамен без года на сайте не показать — не даём его опубликовать.
	 *
	 * @param mixed                $error  Ошибка от предыдущих обработчиков
	 * @param int                  $postId ID экзамена
	 * @param array<string, mixed> $posted Присланная форма (`fs_lms_meta`)
	 */
	public function requireYear( mixed $error, int $postId, array $posted ): ?string {
		if ( is_string( $error ) && '' !== $error ) {
			return $error;
		}

		if ( '1' !== (string) ( $posted[ PublicExamPolicy::META_PUBLIC ] ?? '' ) ) {
			return null;
		}

		// Публичным бывает только ЕГЭ (Компьютер): у остальных видов флажок скрыт и не действует.
		if ( AssessmentKind::EgeComputer->value !== sanitize_key( (string) ( $posted['kind'] ?? '' ) ) ) {
			return null;
		}

		return 1 === preg_match( '/^\d{4}$/', trim( (string) ( $posted[ PublicExamPolicy::META_YEAR ] ?? '' ) ) )
			? null
			: 'Укажите год публичного экзамена (4 цифры) — по нему экзамены группируются на сайте.';
	}

	public function grantAccess( bool $open, AssessmentDTO $assessment ): bool {
		return $open || $this->policy->isPublic( $assessment );
	}

	public function backUrl( string $default, AssessmentDTO $assessment ): string {
		$url = $this->subjectPages->url( $assessment->subjectKey, SubjectPageType::Exams );

		return '' !== $url ? $url : $default;
	}

	/**
	 * @param array<int|string, mixed> $groups
	 *
	 * @return array<string, array<int, array{title: string, url: string}>>
	 */
	public function examGroups( array $groups, string $subjectKey ): array {
		return $this->catalog->groups( $subjectKey );
	}

	/**
	 * Разовый добор страниц раздела «Экзамены» для предметов, заведённых раньше;
	 * новые предметы получают её при создании (фильтр {@see SubjectPagesService::EXAMS_FILTER}).
	 */
	public function ensurePages(): void {
		if ( self::PAGES_DONE === get_option( self::PAGES_OPTION ) ) {
			return;
		}

		foreach ( $this->subjects->readAll() as $subject ) {
			if ( $subject->hasBank ) {
				$this->subjectPages->ensureForSubject( $subject );
			}
		}

		update_option( self::PAGES_OPTION, self::PAGES_DONE, false );
	}
}
