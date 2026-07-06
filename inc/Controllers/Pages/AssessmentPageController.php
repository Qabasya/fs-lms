<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptResultService;
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

	/**
	 * WP filter: признак «страница прохождения контрольной на bare-шелле плеера»
	 * (Эпик 15, T15.1/T15.2) — по нему `Enqueue::enqueue_frontend_assets()`
	 * подключает изолированный бандл `assessment.min.css/js` вместо темы сайта.
	 * Взводится только для дефолтного рендерера (attempt.php); модульные скины
	 * (напр. EgeComputer) намеренно оставлены на старом ThemeCompatService-флоу —
	 * см. T15.9.
	 */
	public const ROUTE_FILTER = 'fs_lms_is_assessment_route';

	public function __construct(
		private readonly AssessmentManager           $assessments,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly PersonRepository            $personRepo,
		private readonly ExamPayloadFilter           $payloadFilter,
		private readonly ClockInterface              $clock,
		private readonly AssessmentAccessPolicy      $access,
		private readonly PostManager                 $posts,
		private readonly AttemptResultService        $resultService,
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

		// Гард доступа: контрольная остаётся publicly_queryable ради плеера, поэтому
		// доступ закрываем здесь. Гость → логин с возвратом на эту же ссылку;
		// аутентифицированный, но не имеющий доступа → 404 (не раскрываем наличие).
		$userId = get_current_user_id();
		if ( ! $userId ) {
			// Логин-URL внутренний и доверенный — редиректим как в LessonPlayerController.
			wp_redirect( wp_login_url( get_permalink( $post->ID ) ?: home_url( '/' ) ) );
			exit;
		}

		$person = $this->personRepo->findByWpUserId( $userId );
		if ( null === $person || ! $this->access->canAccess( $person->id, $assessment->id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return get_404_template();
		}

		// Остаётся открытой по пермалинку — запрещаем индексацию.
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$activeAttempt = $this->attemptRepo->findActive( $person->id, $assessment->id );

		$now = $this->clock->now();

		// T13.5 (Эпик 13, D16): per-task view-данные для шаблона.
		$taskViews = $this->buildTaskViews( $assessment->taskIds );

		// T13.7: если нет активной попытки — ищем последнюю завершённую для показа результата.
		$lastAttempt   = null;
		$resultPerTask = array();
		if ( ! $activeAttempt ) {
			$lastAttempt = $this->attemptRepo->findLastSubmitted( $person->id, $assessment->id );
			if ( $lastAttempt ) {
				$resultPerTask = $this->resultService->studentPerTask( $lastAttempt->id, $person->id );
			}
		}

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

		// T15.1/T15.9: дефолтный рендерер получает bare-шелл плеера (см. ROUTE_FILTER);
		// модульные скины (EgeComputer и т.п.) намеренно остаются на старом
		// ThemeCompatService-флоу — их визуальный порт в шелл плеера не входит в Эпик 15.
		if ( $defaultTemplate === $template ) {
			add_filter( self::ROUTE_FILTER, '__return_true' );

			$backUrl = $this->resolveBackUrl();

			include $this->path( 'templates/frontend/assessment/attempt-shell-header.php' );
			include $template;
			include $this->path( 'templates/frontend/assessment/attempt-shell-footer.php' );
			exit;
		}

		ThemeCompatService::header();
		include $template;
		ThemeCompatService::footer();
		exit;
	}

	/**
	 * Ссылка «Вернуться» в шапке bare-шелла (T15.7): возврат в исходный шаг
	 * плеера, если контрольная была открыта из него (`?from_gid=&from_gl=`,
	 * см. `partials/step-assessment.php`), иначе — на `/profile/`.
	 */
	private function resolveBackUrl(): string {
		$fromGid = isset( $_GET['from_gid'] ) ? absint( wp_unslash( $_GET['from_gid'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fromGl  = isset( $_GET['from_gl'] ) ? absint( wp_unslash( $_GET['from_gl'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $fromGid > 0 && $fromGl > 0 ) {
			return (string) add_query_arg(
				array(
					'gid' => $fromGid,
					'gl'  => $fromGl,
				),
				PageRoutes::GroupCockpit->url()
			);
		}

		return PageRoutes::UserProfile->url();
	}

	/**
	 * Per-task view-данные (T13.5): тип шаблона + материалы для «Развёрнутый ответ».
	 * Эталонные решения/критерии сюда НЕ попадают — на страницу ученика не отдаются.
	 *
	 * @param int[] $taskIds
	 * @return array<int, array{template: string, materials: array<int, array{url: string, name: string}>}>
	 */
	private function buildTaskViews( array $taskIds ): array {
		$views = array();
		foreach ( $taskIds as $taskId ) {
			$taskId   = (int) $taskId;
			$template = TaskTemplate::fromDatabase(
				(string) $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value )
			);

			$materials = array();
			if ( TaskTemplate::FileAnswer === $template ) {
				$meta = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
				$ids  = is_array( $meta ) ? ( $meta['task_materials']['attachment_ids'] ?? array() ) : array();
				foreach ( (array) $ids as $attachmentId ) {
					$attachmentId = (int) $attachmentId;
					$url          = $attachmentId ? wp_get_attachment_url( $attachmentId ) : '';
					if ( ! $url ) {
						continue;
					}
					$materials[] = array(
						'url'  => $url,
						'name' => get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}",
					);
				}
			}

			$views[ $taskId ] = array(
				'template'  => $template->value,
				'materials' => $materials,
			);
		}
		return $views;
	}
}
