<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\OperationType;
use Inc\Services\PostTypeResolver;

/**
 * Class PostEntityAuditController
 *
 * Контроллер для логирования событий жизненного цикла CPT-заданий и статей.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Отслеживание создания/обновления постов** — перехват хука save_post.
 * 2. **Отслеживание удаления постов** — перехват хука before_delete_post.
 * 3. **Диспетчеризация событий** — отправка событий в шину логирования.
 *
 * ### Архитектурная роль:
 *
 * Используется для задач/статей, которые редактируются через стандартный WP-редактор
 * (создание, обновление, удаление). Для прочих сущностей (предметы, таксономии и т.д.)
 * диспетчеризация идёт из CRUD-коллбеков.
 *
 * ### Примечания:
 *
 * - Игнорирует ревизии и автосохранения.
 * - Определяет тип сущности (Task или Article) через PostTypeResolver.
 * - Для удаления передаёт старое название поста (post_title) для аудита.
 */
class PostEntityAuditController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Регистрирует все хуки контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'save_post' — хук, срабатывающий при сохранении поста
		add_action( 'save_post', array( $this, 'onSavePost' ), 20, 3 );
		// 'before_delete_post' — хук, срабатывающий перед удалением поста
		add_action( 'before_delete_post', array( $this, 'onDeletePost' ), 10, 1 );
	}

	/**
	 * Обработчик сохранения поста (создание или обновление).
	 *
	 * @param int      $postId ID поста
	 * @param \WP_Post $post   Объект поста
	 * @param bool     $update true — обновление, false — создание
	 *
	 * @return void
	 */
	public function onSavePost( int $postId, \WP_Post $post, bool $update ): void {
		// Пропускаем ревизии и автосохранения
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}

		[ $event, $entityType ] = $this->resolvePostContext( $post->post_type, $update );
		if ( null === $event ) {
			return;
		}

		// dispatch() — отправка события в шину логирования
		$this->logEvents->dispatch(
			$event,
			new EntityChangedEvent( get_current_user_id(), $update ? OperationType::Update : OperationType::Create, $entityType, $postId )
		);
	}

	/**
	 * Обработчик удаления поста.
	 *
	 * @param int $postId ID поста
	 *
	 * @return void
	 */
	public function onDeletePost( int $postId ): void {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		[ , $entityType ] = $this->resolvePostContext( $post->post_type, true );
		if ( null === $entityType ) {
			return;
		}

		// Определение события удаления (TaskDeleted или ArticleDeleted)
		$deleteEvent = PostTypeResolver::isTaskPostType( $post->post_type )
			? LogEvent::TaskDeleted
			: LogEvent::ArticleDeleted;

		// Передаём старое название поста (post_title) для аудита
		$this->logEvents->dispatch(
			$deleteEvent,
			new EntityChangedEvent( get_current_user_id(), OperationType::Delete, $entityType, $postId, $post->post_title )
		);
	}

	/**
	 * Определяет событие и тип сущности по типу поста.
	 *
	 * @param string $postType Тип поста
	 * @param bool   $update   true — обновление, false — создание
	 *
	 * @return array{LogEvent|null, EntityType|null}
	 */
	private function resolvePostContext( string $postType, bool $update ): array {
		// Задания: post_type вида {key}_tasks
		if ( PostTypeResolver::isTaskPostType( $postType ) ) {
			$event = $update ? LogEvent::TaskUpdated : LogEvent::TaskCreated;
			return array( $event, EntityType::Task );
		}

		// Статьи: post_type вида {key}_articles
		if ( str_ends_with( $postType, '_articles' ) ) {
			$event = $update ? LogEvent::ArticleUpdated : LogEvent::ArticleCreated;
			return array( $event, EntityType::Article );
		}

		return array( null, null );
	}
}