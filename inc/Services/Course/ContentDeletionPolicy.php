<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Wp\TransientKey;
use Inc\Services\Subject\ContentKindResolver;
use Inc\Managers\Wp\TransientManager;

/**
 * Class ContentDeletionPolicy
 *
 * Решает, можно ли удалить запись банка, и запоминает причину отказа для
 * показа админу после редиректа.
 *
 * @package Inc\Services\Course
 *
 * Отказ и объяснение разнесены по разным запросам (WP редиректит после
 * pre_trash/pre_delete), поэтому список «кто ссылается» кладётся в короткоживущий
 * транзиент. Ключ транзиента живёт только здесь — контроллер о нём не знает.
 */
readonly class ContentDeletionPolicy {

	/** Сколько живёт причина отказа — ровно на один редирект. */
	private const BLOCK_TTL = 30;

	/**
	 * @param ContentUsageService $usage      Подсчёт ссылок на запись
	 * @param TransientManager    $transients Хранилище причины отказа
	 */
	public function __construct(
		private ContentUsageService $usage,
		private TransientManager    $transients,
	) {}

	/**
	 * Ссылается ли на запись хоть один потребитель.
	 *
	 * @param \WP_Post $post Запись банка
	 */
	public function isReferenced( \WP_Post $post ): bool {
		$kind = ContentKindResolver::of( $post->post_type );

		return '' !== $kind && $this->usage->usageCount( $kind, $post->ID ) > 0;
	}

	/**
	 * Заблокировано ли удаление; при блокировке запоминает причину для нотиса.
	 *
	 * @param \WP_Post $post Запись банка
	 */
	public function blocksDeletion( \WP_Post $post ): bool {
		if ( ! $this->isReferenced( $post ) ) {
			return false;
		}

		$kind = ContentKindResolver::of( $post->post_type );
		$this->transients->set(
			TransientKey::DeleteBlocked,
			get_current_user_id(),
			array(
				'title'     => $post->post_title,
				'consumers' => $this->usage->usageList( $kind, $post->ID ),
			),
			self::BLOCK_TTL
		);

		return true;
	}

	/**
	 * Забирает причину последнего отказа (одноразово) — для нотиса в админке.
	 *
	 * @return array{title: string, consumers: array}|null
	 */
	public function takeBlockReason(): ?array {
		$blocked = $this->transients->take( TransientKey::DeleteBlocked, get_current_user_id() );
		if ( ! is_array( $blocked ) ) {
			return null;
		}

		return array(
			'title'     => (string) ( $blocked['title'] ?? '' ),
			'consumers' => (array) ( $blocked['consumers'] ?? array() ),
		);
	}

}
