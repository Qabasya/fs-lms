<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Log;

use Inc\Contracts\ClockInterface;
use Inc\Core\BaseController;
use Inc\DTO\Log\ContentReportLogInputDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\Log\ContentReportLogRepository;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ContentReportCallbacks
 *
 * AJAX кнопки «Нашли ошибку?» на страницах заданий и статей: сообщение читателя
 * пишется в журнал «Найденные ошибки». Публичный доступ (nopriv): только nonce
 * и лимит по IP — задания и статьи открыты гостям.
 *
 * @package Inc\Callbacks\Log
 */
class ContentReportCallbacks extends BaseController {

	use Sanitizer;
	use RequestContextProvider;

	private const MAX_LENGTH = 2000;

	public function __construct(
		private readonly ContentReportLogRepository $repository,
		private readonly PostManager                $posts,
		private readonly RateLimitService           $rateLimit,
		private readonly ClockInterface             $clock,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: сообщение об ошибке в задании или статье.
	 * Params: post_id (страница), task_id (задание-карточка в статье, опц.), message.
	 */
	public function ajaxReportContentError(): void {
		Nonce::ReportContentError->verify();

		$ctx = $this->requestContext();

		if ( ! $this->rateLimit->allowContentReport( $ctx->ip ) ) {
			$this->error( 'Слишком много сообщений. Попробуйте позже.' );
		}

		$message = mb_substr( trim( $this->sanitizeMultilineText( 'message' ) ), 0, self::MAX_LENGTH );
		if ( '' === $message ) {
			$this->error( 'Опишите найденную ошибку.' );
		}

		$postId = $this->sanitizeInt( 'post_id' );
		$page   = $this->posts->get( $postId );
		if ( null === $page || 'publish' !== $page->post_status || ! $this->isContentType( $page->post_type ) ) {
			$this->error( 'Страница не найдена.' );
		}

		$taskId = $this->sanitizeInt( 'task_id' ) ?: null;
		if ( null !== $taskId ) {
			$task = $this->posts->get( $taskId );
			if ( null === $task || ! PostTypeResolver::isTaskPostType( $task->post_type ) ) {
				$taskId = null;
			}
		}

		$this->repository->create( new ContentReportLogInputDTO(
			postId:    $postId,
			taskId:    $taskId,
			message:   $message,
			url:       (string) get_permalink( $postId ) ?: null,
			userId:    $ctx->actorUserId ?: null,
			actorIp:   $ctx->ip,
			createdAt: $this->clock->now( 'mysql', true ),
		) );

		$this->success();
	}

	private function isContentType( string $postType ): bool {
		return PostTypeResolver::isTaskPostType( $postType ) || PostTypeResolver::isArticlePostType( $postType );
	}
}
