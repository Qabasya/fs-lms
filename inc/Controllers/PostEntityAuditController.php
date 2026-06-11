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
 * Ловит WP-события жизненного цикла CPT-заданий и статей → dispatch в шину логирования.
 *
 * Используется для задач/статей, которые редактируются через стандартный WP-редактор
 * (создание, обновление, удаление). Для прочих сущностей dispatch идёт из CRUD-колбэков.
 */
class PostEntityAuditController implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function register(): void {
		add_action( 'save_post',          array( $this, 'onSavePost' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'onDeletePost' ), 10, 1 );
	}

	public function onSavePost( int $postId, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}

		[ $event, $entityType ] = $this->resolvePostContext( $post->post_type, $update );
		if ( null === $event ) {
			return;
		}

		$this->logEvents->dispatch(
			$event,
			new EntityChangedEvent( get_current_user_id(), $update ? OperationType::Update : OperationType::Create, $entityType, $postId )
		);
	}

	public function onDeletePost( int $postId ): void {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		[ , $entityType ] = $this->resolvePostContext( $post->post_type, true );
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

	/** @return array{LogEvent|null, EntityType|null} */
	private function resolvePostContext( string $postType, bool $update ): array {
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
