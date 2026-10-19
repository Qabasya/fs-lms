<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Services\Subject\PostTypeResolver;

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
		// Правка без смены статуса (publish → publish) в transition_post_status не видна — её ловит wp_after_insert_post.
		add_action( 'wp_after_insert_post', array( $this, 'onAfterInsertPost' ), 20, 4 );
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
			$this->logEvents->dispatch(
				$this->eventFor( $entityType, OperationType::Delete ),
				new EntityChangedEvent( get_current_user_id(), OperationType::Delete, $entityType, $post->ID, $post->post_title )
			);
			return;
		}

		if ( $oldStatus === 'auto-draft' || $oldStatus === 'new' ) {
			$this->logEvents->dispatch(
				$this->eventFor( $entityType, OperationType::Create ),
				new EntityChangedEvent( get_current_user_id(), OperationType::Create, $entityType, $post->ID )
			);
			return;
		}

		$this->logEvents->dispatch(
			$this->eventFor( $entityType, OperationType::Update ),
			new EntityChangedEvent( get_current_user_id(), OperationType::Update, $entityType, $post->ID, $post->post_title )
		);
	}

	/**
	 * Правка существующей записи без смены статуса: сохранение опубликованного задания, задачи банка или статьи.
	 * Смену статуса (в том числе в корзину) уже записал {@see self::onTransitionPostStatus()}, поэтому здесь — только
	 * случаи, когда статус до и после совпадает. Автосохранения и ревизии не пишутся.
	 *
	 * @param int           $postId     ID записи
	 * @param \WP_Post      $post       Запись после сохранения
	 * @param bool          $update     true — запись уже существовала
	 * @param \WP_Post|null $postBefore Запись до сохранения (null при создании)
	 *
	 * @return void
	 */
	public function onAfterInsertPost( int $postId, \WP_Post $post, bool $update, ?\WP_Post $postBefore ): void {
		if ( ! $update || null === $postBefore || $postBefore->post_status !== $post->post_status ) {
			return;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$entityType = $this->resolveEntityType( $post->post_type );
		if ( null === $entityType ) {
			return;
		}

		$this->logEvents->dispatch(
			$this->eventFor( $entityType, OperationType::Update ),
			new EntityChangedEvent( get_current_user_id(), OperationType::Update, $entityType, $postId, $post->post_title )
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

		$this->logEvents->dispatch(
			$this->eventFor( $entityType, OperationType::Delete ),
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
		if ( PostTypeResolver::isProblemPostType( $postType ) ) {
			return EntityType::Problem;
		}
		if ( str_ends_with( $postType, '_articles' ) ) {
			return EntityType::Article;
		}
		return null;
	}

	/**
	 * Событие шины по типу сущности и операции.
	 *
	 * @param EntityType    $entityType Задание, задача банка или статья
	 * @param OperationType $operation  Создание, изменение или удаление
	 *
	 * @return LogEvent
	 */
	private function eventFor( EntityType $entityType, OperationType $operation ): LogEvent {
		return match ( $entityType ) {
			EntityType::Task    => match ( $operation ) {
				OperationType::Create => LogEvent::TaskCreated,
				OperationType::Delete => LogEvent::TaskDeleted,
				default               => LogEvent::TaskUpdated,
			},
			EntityType::Problem => match ( $operation ) {
				OperationType::Create => LogEvent::ProblemCreated,
				OperationType::Delete => LogEvent::ProblemDeleted,
				default               => LogEvent::ProblemUpdated,
			},
			default             => match ( $operation ) {
				OperationType::Create => LogEvent::ArticleCreated,
				OperationType::Delete => LogEvent::ArticleDeleted,
				default               => LogEvent::ArticleUpdated,
			},
		};
	}
}
