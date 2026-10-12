<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Вставка записи в журнал «Найденные ошибки» (fs_lms_content_reports).
 *
 * @package Inc\DTO\Log
 */
readonly class ContentReportLogInputDTO {

	public function __construct(
		public int     $postId,
		public ?int    $taskId,
		public string  $message,
		public ?string $url,
		public ?int    $userId,
		public string  $actorIp,
		public string  $createdAt,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'post_id'    => $this->postId,
			'task_id'    => $this->taskId,
			'message'    => $this->message,
			'url'        => null !== $this->url ? mb_substr( $this->url, 0, 500 ) : null,
			'user_id'    => $this->userId,
			'actor_ip'   => $this->actorIp,
			'created_at' => $this->createdAt,
		);
	}
}
