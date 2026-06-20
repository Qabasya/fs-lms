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
use Inc\Services\PostTypeResolver;
use Inc\Services\ThemeCompatService;

/**
 * Class AssessmentPageController
 *
 * Подменяет шаблон для CPT {key}_assessments на фронтенде.
 *
 * @package Inc\Controllers\Pages
 */
class AssessmentPageController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly AssessmentManager           $assessments,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly PersonRepository            $personRepo,
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

		ThemeCompatService::header();
		include $this->path( 'templates/frontend/assessment/attempt.php' );
		ThemeCompatService::footer();
		exit;
	}
}
