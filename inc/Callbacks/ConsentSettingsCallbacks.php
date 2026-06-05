<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class ConsentSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ConsentDefinitionsRepository $definitions,
	) {
		parent::__construct();
	}

	/**
	 * Создаёт новое согласие: WP-страница + запись в definitions.
	 */
	public function ajaxAddConsentDefinition(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$name = $this->requireText( 'name', error: 'Название обязательно.' );
		$key  = $this->requireKey( 'key', error: 'Ключ обязателен.' );

		$cleanKey = sanitize_key( $key );
		if ( $cleanKey !== $key || empty( $cleanKey ) ) {
			$this->error( 'Ключ должен содержать только строчные буквы, цифры и дефисы/подчёркивания.' );
		}

		if ( null !== $this->definitions->findByKey( $key ) ) {
			$this->error( "Согласие с ключом «{$key}» уже существует." );
		}

		$pageId = wp_insert_post( array(
			'post_title'   => $name,
			'post_name'    => 'lms-consent-' . $key,
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_content' => '',
		) );

		if ( is_wp_error( $pageId ) || ! $pageId ) {
			$this->error( 'Не удалось создать страницу согласия.' );
		}

		$this->definitions->save( $key, $name, (int) $pageId );

		$this->success( array(
			'key'      => $key,
			'name'     => $name,
			'page_id'  => $pageId,
			'edit_url' => get_edit_post_link( $pageId, 'raw' ),
		) );
	}

	/**
	 * Удаляет определение согласия (страница остаётся для истории).
	 */
	public function ajaxDeleteConsentDefinition(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$key = $this->requireKey( 'key', error: 'Ключ обязателен.' );

		if ( null === $this->definitions->findByKey( $key ) ) {
			$this->error( "Согласие «{$key}» не найдено." );
		}

		$this->definitions->delete( $key );
		$this->success( array( 'key' => $key ) );
	}

	/**
	 * Ищет версию согласия по sha256-хешу среди текущей версии и WP-ревизий.
	 */
	public function ajaxLookupConsentByHash(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$hash    = sanitize_text_field( wp_unslash( $_POST['hash'] ?? '' ) );
		$typeKey = $this->sanitizeKey( 'type_key' );

		if ( empty( $hash ) ) {
			$this->error( 'Хеш не указан.' );
		}

		$defs = ! empty( $typeKey ) && $this->definitions->findByKey( $typeKey )
			? array( $typeKey => $this->definitions->findByKey( $typeKey ) )
			: $this->definitions->readAll();

		foreach ( $defs as $key => $def ) {
			$pageId = (int) ( $def['page_id'] ?? 0 );
			if ( $pageId <= 0 ) {
				continue;
			}

			$page = get_post( $pageId );
			if ( ! $page ) {
				continue;
			}

			if ( hash( 'sha256', $page->post_content ) === $hash ) {
				$this->success( array(
					'found'   => true,
					'key'     => $key,
					'name'    => $def['name'] ?? $key,
					'content' => wp_kses_post( $page->post_content ),
					'version' => 'Текущая версия',
					'date'    => wp_date( 'd.m.Y H:i', strtotime( $page->post_modified ) ),
				) );
			}

			foreach ( wp_get_post_revisions( $pageId, array( 'order' => 'DESC' ) ) as $rev ) {
				if ( hash( 'sha256', $rev->post_content ) === $hash ) {
					$this->success( array(
						'found'   => true,
						'key'     => $key,
						'name'    => $def['name'] ?? $key,
						'content' => wp_kses_post( $rev->post_content ),
						'version' => 'Ревизия от ' . wp_date( 'd.m.Y H:i', strtotime( $rev->post_date ) ),
						'date'    => wp_date( 'd.m.Y H:i', strtotime( $rev->post_date ) ),
					) );
				}
			}
		}

		$this->success( array( 'found' => false ) );
	}
}
