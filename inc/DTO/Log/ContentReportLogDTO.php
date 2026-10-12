<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Запись журнала «Найденные ошибки» (fs_lms_content_reports).
 *
 * @package Inc\DTO\Log
 */
readonly class ContentReportLogDTO {

	/**
	 * @param int         $id        ID записи
	 * @param int         $postId    Страница (задание или статья), с которой отправлено
	 * @param int|null    $taskId    Задание, к которому относится сообщение (карточка в статье)
	 * @param string      $message   Текст читателя
	 * @param string|null $url       Адрес страницы
	 * @param int|null    $userId    Пользователь WP (null — гость)
	 * @param string      $actorIp   IP
	 * @param string      $createdAt Дата (UTC)
	 */
	public function __construct(
		public int     $id,
		public int     $postId,
		public ?int    $taskId,
		public string  $message,
		public ?string $url,
		public ?int    $userId,
		public string  $actorIp,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:        (int) $row['id'],
			postId:    (int) ( $row['post_id'] ?? 0 ),
			taskId:    ! empty( $row['task_id'] ) ? (int) $row['task_id'] : null,
			message:   (string) ( $row['message'] ?? '' ),
			url:       isset( $row['url'] ) ? (string) $row['url'] : null,
			userId:    ! empty( $row['user_id'] ) ? (int) $row['user_id'] : null,
			actorIp:   (string) ( $row['actor_ip'] ?? '' ),
			createdAt: (string) $row['created_at'],
		);
	}
}
