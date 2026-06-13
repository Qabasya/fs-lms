<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

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
		add_action( 'transition_post_status', array( $this, 'onTransitionPostStatus' ), 20, 3 );
		// before_delete_post — только для прямого хард-делита (не из корзины)
		add_action( 'before_delete_post', array( $this, 'onDeletePost' ), 10, 2 );
	}

	/**
	 * Обработчик смены статуса поста.
	 * Логирует: создание (из auto-draft), обновление, перемещение в корзину.
	 *
	 * @param string   $newStatus Новый статус
	 * @param string   $oldStatus Старый статус
	 * @param \WP_Post $post      Объект поста
	 *
	 * @return void
	 */
	public function onTransitionPostStatus( string $newStatus, string $oldStatus, \WP_Post $post ): void {
		if ( $newStatus === 'auto-draft' || $oldStatus === $newStatus ) {
			return;
		}

		$entityType = $this->resolveEntityType( $post->post_type );
		if ( null === $entityType ) {
			return;
		}

		if ( $newStatus === 'trash' ) {
			$deleteEvent = PostTypeResolver::isTaskPostType( $post->post_type )
				? LogEvent::TaskDeleted
				: LogEvent::ArticleDeleted;
			$this->logEvents->dispatch(
				$deleteEvent,
				new EntityChangedEvent( get_current_user_id(), OperationType::Delete, $entityType, $post->ID, $post->post_title )
			);
			return;
		}

		if ( $oldStatus === 'auto-draft' || $oldStatus === 'new' ) {
			$createEvent = PostTypeResolver::isTaskPostType( $post->post_type )
				? LogEvent::TaskCreated
				: LogEvent::ArticleCreated;
			$this->logEvents->dispatch(
				$createEvent,
				new EntityChangedEvent( get_current_user_id(), OperationType::Create, $entityType, $post->ID )
			);
			return;
		}

		$updateEvent = PostTypeResolver::isTaskPostType( $post->post_type )
			? LogEvent::TaskUpdated
			: LogEvent::ArticleUpdated;
		$this->logEvents->dispatch(
			$updateEvent,
			new EntityChangedEvent( get_current_user_id(), OperationType::Update, $entityType, $post->ID )
		);
	}

	/**
	 * Обработчик постоянного удаления поста (хард-делит минуя корзину).
	 *
	 * @param int      $postId ID поста
	 * @param \WP_Post $post   Объект поста
	 *
	 * @return void
	 */
	public function onDeletePost( int $postId, \WP_Post $post ): void {
		// Пропускаем посты из корзины — Delete уже залогирован при трэшинге
		if ( 'trash' === $post->post_status ) {
			return;
		}

		$entityType = $this->resolveEntityType( $post->post_type );
		if ( null === $entityType ) {
			return;
		}

		$deleteEvent = PostTypeResolver::isTaskPostType( $post->post_type )
			? LogEvent::TaskDeleted
			: LogEvent::ArticleDeleted;

		$this->logEvents->dispatch(
			$deleteEvent,
			new EntityChangedEvent( get_current_user_id(), OperationType::Delete, $entityType, $postId, $post->post_title )
		);
	}

	/**
	 * Определяет тип сущности по типу поста.
	 *
	 * @param string $postType Тип поста
	 *
	 * @return EntityType|null
	 */
	private function resolveEntityType( string $postType ): ?EntityType {
		if ( PostTypeResolver::isTaskPostType( $postType ) ) {
			return EntityType::Task;
		}
		if ( str_ends_with( $postType, '_articles' ) ) {
			return EntityType::Article;
		}
		return null;
	}
}