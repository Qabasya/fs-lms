<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\ExamPayloadFilter;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Shared\ThemeCompatService;

/**
 * Class AssessmentPageController
 *
 * Подменяет шаблон для CPT {key}_assessments на фронтенде.
 *
 * @package Inc\Controllers\Pages
 */
class AssessmentPageController extends BaseController implements ServiceInterface {

	/**
	 * WP filter: выбор шаблона-рендерера плеера экзамена (T7.19).
	 * Модули регистрируют свой скин через:
	 *   add_filter('fs_lms_assessment_renderer', fn($tpl, $kind, $subject) => 'путь/к/скину.php', 10, 3)
	 */
	public const RENDERER_FILTER = 'fs_lms_assessment_renderer';

	public function __construct(
		private readonly AssessmentManager           $assessments,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly PersonRepository            $personRepo,
		private readonly ExamPayloadFilter           $payloadFilter,
		private readonly ClockInterface              $clock,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
	}

	public function loadTemplate( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$post = get_post();
		if ( ! $post || ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			return $template;
		}

		$assessment = $this->assessments->get( $post->ID );
		if ( ! $assessment ) {
			return $template;
		}

		$userId = get_current_user_id();
		$person = $userId ? $this->personRepo->findByWpUserId( $userId ) : null;

		$activeAttempt = null;
		if ( $person ) {
			$activeAttempt = $this->attemptRepo->findActive( $person->id, $assessment->id );
		}

		$now = $this->clock->now();

		$defaultTemplate = $this->path( 'templates/frontend/assessment/attempt.php' );

		// T7.19: модули могут зарегистрировать собственный рендерер через этот фильтр.
		$template = (string) apply_filters(
			self::RENDERER_FILTER,
			$defaultTemplate,
			$assessment->kind->value,
			$assessment->subjectKey
		);

		if ( ! file_exists( $template ) ) {
			$template = $defaultTemplate;
		}

		ThemeCompatService::header();
		include $template;
		ThemeCompatService::footer();
		exit;
	}
}
