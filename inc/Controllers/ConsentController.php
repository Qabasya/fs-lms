<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\ConsentType;
use Inc\Services\ConsentService;
use RuntimeException;
use WP_Post;

/**
 * Class ConsentController
 *
 * Контроллер публичной страницы просмотра согласия на обработку ПД.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Rewrite rule** — регистрирует маршрут /lms/consent/{type}/{version}.
 * 2. **Query vars** — объявляет переменные fs_consent_type и fs_consent_version.
 * 3. **Template include** — подменяет шаблон темы на templates/frontend/consent-page.php.
 *
 * ### URL-формат:
 *
 * /lms/consent/pd_child_processing/v1
 *  └── type:    значение ConsentType (pd_child_processing)
 *  └── version: папка в templates/consents/ (v1, v2, ...)
 *
 * ### 404:
 *
 * Если тип не существует в ConsentType или версия отсутствует в файловой системе —
 * возвращается 404 через штатный механизм WordPress.
 */
class ConsentController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * @param ConsentService $consentService Сервис согласий
	 */
	public function __construct(
		private readonly ConsentService $consentService,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует хуки контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init',            array( $this, 'addRewriteRule' ) );
		add_filter( 'query_vars',      array( $this, 'addQueryVars' ) );
		add_filter( 'template_include', array( $this, 'loadConsentTemplate' ) );
		add_action( 'save_post',       array( $this, 'handleConsentPageSave' ), 10, 2 );
	}

	/**
	 * @param int     $postId ID сохраняемого поста
	 * @param WP_Post $post   Объект поста
	 *
	 * @return void
	 */
	public function handleConsentPageSave( int $postId, WP_Post $post ): void {
		$this->consentService->onConsentPageSaved( $post );
	}

	/**
	 * Регистрирует rewrite rule для маршрута /lms/consent/{type}/{version}.
	 *
	 * @return void
	 */
	public function addRewriteRule(): void {
		add_rewrite_rule(
			'^lms/consent/([^/]+)/([^/]+)/?$',
			'index.php?fs_consent_type=$matches[1]&fs_consent_version=$matches[2]',
			'top'
		);
	}

	/**
	 * Объявляет query vars плагина, чтобы WordPress не отфильтровал их.
	 *
	 * @param string[] $vars Зарегистрированные переменные запроса
	 *
	 * @return string[]
	 */
	public function addQueryVars( array $vars ): array {
		$vars[] = 'fs_consent_type';
		$vars[] = 'fs_consent_version';

		return $vars;
	}

	/**
	 * Подменяет шаблон темы на шаблон страницы согласия.
	 *
	 * Срабатывает только если оба query var заполнены. Валидирует тип согласия
	 * через ConsentType::tryFrom() и версию через ConsentService::getDocumentText().
	 * При любой ошибке возвращает 404.
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string Путь к шаблону плагина или 404-шаблону темы
	 */
	public function loadConsentTemplate( string $template ): string {
		$typeSlug = get_query_var( 'fs_consent_type' );
		$version  = get_query_var( 'fs_consent_version' );

		if ( empty( $typeSlug ) || empty( $version ) ) {
			return $template;
		}

		$type = ConsentType::tryFrom( $typeSlug );

		if ( null === $type ) {
			return $this->serve404();
		}

		try {
			$text = $this->consentService->getDocumentText( $type, $version );
		} catch ( RuntimeException $e ) {
			return $this->serve404();
		}

		$pluginTemplate = $this->path( 'templates/frontend/consent-page.php' );

		if ( ! file_exists( $pluginTemplate ) ) {
			return $template;
		}

		set_query_var( 'fs_consent_text',    $text );
		set_query_var( 'fs_consent_version', $version );

		return $pluginTemplate;
	}

	/**
	 * Устанавливает статус 404 и возвращает 404-шаблон темы.
	 *
	 * @return string
	 */
	private function serve404(): string {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		return get_404_template();
	}
}
