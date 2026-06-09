<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ConsentSettingsCallbacks
 *
 * AJAX-обработчики для управления определениями согласий.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Создание определения согласия** — создание WP-страницы + записи в репозитории.
 * 2. **Удаление определения согласия** — удаление записи из репозитория (страница остаётся).
 * 3. **Поиск согласия по хешу** — проверка, какой тип и версия согласия соответствуют переданному хешу.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с определениями согласий ConsentDefinitionsRepository,
 * а создание страниц — WordPress-функциям wp_insert_post().
 * Используется в административной панели для управления согласиями.
 */
class ConsentSettingsCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), error(), success()
	use Sanitizer;   // Трейт с методами requireText(), sanitizeKey()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param ConsentDefinitionsRepository $definitions Репозиторий определений согласий
	 */
	public function __construct(
		private readonly ConsentDefinitionsRepository $definitions,
	) {
		parent::__construct();
	}

	/**
	 * Создаёт новое определение согласия.
	 * Создаёт WP-страницу (черновик) и сохраняет метаданные в репозиторий.
	 *
	 * @return void
	 */
	public function ajaxAddConsentDefinition(): void {
		// Проверка прав доступа (требуется админ)
		$this->authorize( Nonce::Manager, Capability::Admin );

		// Валидация входных данных
		$name = $this->requireText( 'name', error: 'Название обязательно.' );
		$key  = $this->requireKey( 'key', error: 'Ключ обязателен.' );

		// sanitize_key() — очистка ключа (только буквы, цифры, дефисы, подчёркивания)
		$cleanKey = sanitize_key( $key );
		if ( $cleanKey !== $key || empty( $cleanKey ) ) {
			$this->error( 'Ключ должен содержать только строчные буквы, цифры и дефисы/подчёркивания.' );
		}

		// Проверка уникальности ключа
		if ( null !== $this->definitions->findByKey( $key ) ) {
			$this->error( "Согласие с ключом «{$key}» уже существует." );
		}

		// wp_insert_post() — создание страницы в WordPress
		$pageId = wp_insert_post( array(
			'post_title'   => $name,
			'post_name'    => 'lms-consent-' . $key,  // slug страницы
			'post_status'  => 'draft',                // Черновик (до публикации)
			'post_type'    => 'page',
			'post_content' => '',
		) );

		// is_wp_error() — проверка на ошибку WordPress
		if ( is_wp_error( $pageId ) || ! $pageId ) {
			$this->error( 'Не удалось создать страницу согласия.' );
		}

		// Сохранение метаданных в репозиторий (wp_options)
		$this->definitions->save( $key, $name, (int) $pageId );

		$this->success( array(
			'key'      => $key,
			'name'     => $name,
			'page_id'  => $pageId,
			// get_edit_post_link() — ссылка на редактирование страницы
			'edit_url' => get_edit_post_link( $pageId, 'raw' ),
		) );
	}

	/**
	 * Удаляет определение согласия.
	 * Страница остаётся в системе для сохранения истории согласий.
	 *
	 * @return void
	 */
	public function ajaxDeleteConsentDefinition(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$key = $this->requireKey( 'key', error: 'Ключ обязателен.' );

		// Проверка существования
		if ( null === $this->definitions->findByKey( $key ) ) {
			$this->error( "Согласие «{$key}» не найдено." );
		}

		// Удаление только из репозитория (страница остаётся для истории)
		$this->definitions->delete( $key );
		$this->success( array( 'key' => $key ) );
	}

	/**
	 * Ищет версию согласия по SHA-256 хешу.
	 * Проверяет текущую версию страницы и все её ревизии.
	 *
	 * @return void
	 */
	public function ajaxLookupConsentByHash(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		// sanitize_text_field() — очистка текстового поля
		$hash    = sanitize_text_field( wp_unslash( $_POST['hash'] ?? '' ) );
		$typeKey = $this->sanitizeKey( 'type_key' );

		if ( empty( $hash ) ) {
			$this->error( 'Хеш не указан.' );
		}

		// Если указан конкретный тип согласия — проверяем только его
		$defs = ! empty( $typeKey ) && $this->definitions->findByKey( $typeKey )
			? array( $typeKey => $this->definitions->findByKey( $typeKey ) )
			: $this->definitions->readAll();

		foreach ( $defs as $key => $def ) {
			$pageId = (int) ( $def['page_id'] ?? 0 );
			if ( $pageId <= 0 ) {
				continue;
			}

			// get_post() — получение объекта страницы
			$page = get_post( $pageId );
			if ( ! $page ) {
				continue;
			}

			// Проверка текущей версии контента
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

			// wp_get_post_revisions() — получение всех ревизий страницы
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