<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class TaskPublishGuard
 *
 * Единый протокол блокировки публикации контента-задания: пропуск автосейва →
 * проверка статуса → проверка заголовка → доменная проверка → откат в `draft`
 * с отложенным сообщением (транзиент), которое затем выводится в `admin_notices`.
 *
 * Применимость (по типу поста) и доменную проверку решает вызывающий: тип поста
 * проверяется до вызова {@see enforce()}, а конкретная ошибка передаётся через
 * `$resolveError`. Так одна и та же механика обслуживает и задания (`{key}_tasks`),
 * и задачи банка (`fs_lms_problems`), различающиеся лишь сообщениями и проверками.
 *
 * @package Inc\Services\Task
 */
class TaskPublishGuard {

	private const TTL = 60;

	/**
	 * Применяет протокол к данным поста из хука `wp_insert_post_data`.
	 *
	 * @param array<string, mixed> $data            Очищенные данные поста.
	 * @param string               $transientPrefix Префикс ключа транзиента (user id добавляется сам).
	 * @param string               $emptyTitleError Сообщение при пустом заголовке.
	 * @param callable             $resolveError    fn(): ?string — доменная ошибка; null = ошибок нет.
	 *
	 * @return array<string, mixed> Данные поста, при ошибке — со статусом `draft`.
	 */
	public function enforce( array $data, string $transientPrefix, string $emptyTitleError, callable $resolveError ): array {
		if ( ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		if ( '' === trim( (string) ( $data['post_title'] ?? '' ) ) ) {
			return $this->forceDraft( $data, $transientPrefix, $emptyTitleError );
		}

		$error = $resolveError();
		if ( null !== $error ) {
			return $this->forceDraft( $data, $transientPrefix, $error );
		}

		return $data;
	}

	/**
	 * Выводит отложенную ошибку публикации в `admin_notices` и очищает транзиент.
	 *
	 * @param string $transientPrefix Тот же префикс, что и в {@see enforce()}.
	 * @param string $heading         Заголовок уведомления (будет экранирован).
	 */
	public function renderDeferredError( string $transientPrefix, string $heading ): void {
		$key   = $transientPrefix . get_current_user_id();
		$error = get_transient( $key );
		if ( ! $error ) {
			return;
		}

		delete_transient( $key );
		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>%s:</strong> %s</p></div>',
			esc_html( $heading ),
			esc_html( (string) $error )
		);
	}

	/**
	 * Откатывает статус в `draft` и сохраняет сообщение в транзиент пользователя.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<string, mixed>
	 */
	private function forceDraft( array $data, string $transientPrefix, string $message ): array {
		$data['post_status'] = 'draft';
		set_transient( $transientPrefix . get_current_user_id(), $message, self::TTL );

		return $data;
	}
}
