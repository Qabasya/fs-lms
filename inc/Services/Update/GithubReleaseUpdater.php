<?php

declare( strict_types=1 );

namespace Inc\Services\Update;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\TransientKey;
use Inc\Managers\Wp\TransientManager;
use Inc\Shared\PluginLogger;

/**
 * Class GithubReleaseUpdater
 *
 * Учит WP-ядро видеть обновления плагина из GitHub Releases (`Qabasya/fs-lms`,
 * публичный репозиторий, токен не нужен) — плагин не в каталоге wordpress.org,
 * поэтому штатная проверка обновлений ничего не находит без этого моста.
 *
 * ### Что делает
 *
 * - `pre_set_site_transient_update_plugins` — раз в цикл проверки WP (транзиент
 *   `update_plugins`, обычно раз в ~12ч, либо принудительно кнопкой «Проверить
 *   ещё раз» / `wp plugin update`) сверяет `tag_name` последнего релиза с
 *   `FS_LMS_VERSION`; если релиз новее — кладёт в транзиент ссылку на ZIP-ассет,
 *   и экран «Плагины» показывает стандартное «Доступно обновление».
 * - `plugins_api` — карточка «Просмотреть подробности»: описание и changelog
 *   берутся из тела GitHub Release как есть (без markdown-парсинга — сырой
 *   текст в `<pre>` плюс ссылка на страницу релиза).
 *
 * ### Что НЕ делает
 *
 * Полностью автоматический накат (`auto_update_plugin`) сознательно не включён —
 * только индикатор «Доступно обновление», обновление всегда по клику админа.
 *
 * ### Отказоустойчивость
 *
 * Сеть недоступна / GitHub API упал / рейт-лимит — тихий fail-open: плагин
 * продолжает работать на текущей версии, индикатор обновления просто не
 * появляется в этом цикле. Ответ API кэшируется транзиентом на несколько
 * часов — не бьём GitHub на каждый чих (неавторизованный лимит — 60 запросов/час
 * на IP).
 *
 * @package Inc\Services\Update
 */
class GithubReleaseUpdater implements ServiceInterface {

	/** Владелец/имя репозитория на GitHub. */
	private const REPO = 'Qabasya/fs-lms';

	/** Slug плагина в каталоге плагинов (папка + главный файл). */
	private const SLUG = 'fs-lms';

	/** Сколько держим ответ GitHub API в кэше (секунды). */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Суффикс транзиента — сущность одна на сайт, не нужен per-entity ключ. */
	private const CACHE_SUFFIX = 'latest';

	public function __construct(
		private readonly TransientManager $transients,
	) {}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkForUpdate' ) );
		add_filter( 'plugins_api', array( $this, 'pluginInformation' ), 10, 3 );
	}

	/**
	 * Прописывает обновление в стандартный транзиент WP, если на GitHub есть релиз новее.
	 *
	 * @param mixed $transient Значение из WP (обычно stdClass с полями checked/response).
	 *
	 * @return mixed Транзиент без изменений либо с добавленной записью нашего плагина.
	 */
	public function checkForUpdate( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->fetchLatestRelease();

		if ( null === $release || null === $release['zip_url'] ) {
			return $transient;
		}

		$currentVersion = defined( 'FS_LMS_VERSION' ) ? FS_LMS_VERSION : '0.0.0';

		if ( ! version_compare( $release['version'], $currentVersion, '>' ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->pluginFile() ] = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => self::SLUG,
			'plugin'      => $this->pluginFile(),
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $release['zip_url'],
		);

		return $transient;
	}

	/**
	 * Карточка «Просмотреть подробности» на экране «Плагины».
	 *
	 * @param mixed  $result Значение по умолчанию (false либо объект от другого фильтра).
	 * @param string $action Запрошенное действие ('query_plugins', 'plugin_information', …).
	 * @param object $args   Аргументы запроса, у 'plugin_information' есть ->slug.
	 *
	 * @return mixed
	 */
	public function pluginInformation( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$release = $this->fetchLatestRelease();

		if ( null === $release ) {
			return $result;
		}

		$changelog = sprintf(
			'<p><a href="%1$s" target="_blank" rel="noopener">%2$s</a></p><pre style="white-space:pre-wrap">%3$s</pre>',
			esc_url( $release['html_url'] ),
			esc_html__( 'Полный релиз на GitHub', 'fs-lms' ),
			esc_html( $release['body'] )
		);

		return (object) array(
			'name'          => 'FS LMS',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://future-step.ru/">FutureStep</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'sections'      => array(
				'description' => esc_html__( 'Плагин для управления заданиями ЕГЭ/ОГЭ.', 'fs-lms' ),
				'changelog'   => $changelog,
			),
			'download_link' => $release['zip_url'],
		);
	}

	/**
	 * Забирает последний релиз из кэша либо GitHub API. `null` — сеть/API недоступны
	 * или в релизе нет ожидаемого ZIP-ассета; вызывающий код обязан трактовать это
	 * как «обновлений нет», а не как ошибку.
	 *
	 * @return array{version: string, html_url: string, body: string, zip_url: ?string}|null
	 */
	private function fetchLatestRelease(): ?array {
		$cached = $this->transients->get( TransientKey::GithubRelease, self::CACHE_SUFFIX );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ),
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'FS-LMS-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			PluginLogger::warning( 'GITHUB_UPDATER', 'Запрос к GitHub Releases API не удался (fail-open)', array(
				'error' => $response->get_error_message(),
			) );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			PluginLogger::warning( 'GITHUB_UPDATER', 'Неожиданный ответ GitHub Releases API (fail-open)', array( 'code' => $code ) );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			PluginLogger::warning( 'GITHUB_UPDATER', 'Некорректное тело ответа GitHub Releases API (fail-open)' );
			return null;
		}

		$release = array(
			'version'  => ltrim( (string) $data['tag_name'], 'v' ),
			'html_url' => (string) ( $data['html_url'] ?? ( 'https://github.com/' . self::REPO . '/releases/latest' ) ),
			'body'     => (string) ( $data['body'] ?? '' ),
			'zip_url'  => $this->findZipAssetUrl( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() ),
		);

		$this->transients->set( TransientKey::GithubRelease, self::CACHE_SUFFIX, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Находит прямую ссылку на ZIP-ассет релиза (первый файл `.zip`).
	 *
	 * @param array<int, array<string, mixed>> $assets Секция 'assets' ответа GitHub API.
	 *
	 * @return string|null
	 */
	private function findZipAssetUrl( array $assets ): ?string {
		foreach ( $assets as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( str_ends_with( $name, '.zip' ) ) {
				return isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : null;
			}
		}

		return null;
	}

	/**
	 * Относительный путь главного файла плагина (`fs-lms/fs-lms.php`) — ключ,
	 * которым WP индексирует плагины в транзиенте обновлений. Собран из
	 * {@see self::SLUG}, а не через `plugin_basename( FS_LMS_PATH … )` — папка
	 * плагина и так фиксирована (совпадает со slug), а константа пути не нужна.
	 *
	 * @return string
	 */
	private function pluginFile(): string {
		return self::SLUG . '/fs-lms.php';
	}
}
