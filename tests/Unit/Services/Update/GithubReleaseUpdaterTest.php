<?php

declare( strict_types=1 );

namespace Unit\Services\Update;

use Inc\Managers\Wp\TransientManager;
use Inc\Services\Update\GithubReleaseUpdater;
use PHPUnit\Framework\TestCase;

class GithubReleaseUpdaterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_transients']   = array();
		$GLOBALS['_test_http_response'] = null;
		$GLOBALS['_test_http_last']     = null;
	}

	private function updater(): GithubReleaseUpdater {
		return new GithubReleaseUpdater( new TransientManager() );
	}

	private static function githubResponse( string $tag, array $assets, string $body = 'changelog body' ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'tag_name' => $tag,
				'html_url' => 'https://github.com/Qabasya/fs-lms/releases/tag/' . $tag,
				'body'     => $body,
				'assets'   => $assets,
			) ),
		);
	}

	private static function zipAsset( string $version ): array {
		return array( array(
			'name'                 => "fs-lms-{$version}.zip",
			'browser_download_url' => "https://github.com/Qabasya/fs-lms/releases/download/v{$version}/fs-lms-{$version}.zip",
		) );
	}

	public function test_check_for_update_ignores_non_object_transient(): void {
		$result = $this->updater()->checkForUpdate( 'not-an-object' );
		self::assertSame( 'not-an-object', $result );
	}

	public function test_check_for_update_adds_response_when_release_is_newer(): void {
		// FS_LMS_VERSION неопределена в тестах -> фолбэк '0.0.0', любой тег новее.
		$GLOBALS['_test_http_response'] = self::githubResponse( 'v1.0.0', self::zipAsset( '1.0.0' ) );

		$transient = (object) array( 'response' => array() );
		$result    = $this->updater()->checkForUpdate( $transient );

		self::assertArrayHasKey( 'fs-lms/fs-lms.php', $result->response );
		$entry = $result->response['fs-lms/fs-lms.php'];
		self::assertSame( '1.0.0', $entry->new_version );
		self::assertSame( 'fs-lms', $entry->slug );
		self::assertStringContainsString( 'fs-lms-1.0.0.zip', $entry->package );
	}

	public function test_check_for_update_skips_when_zip_asset_missing(): void {
		$GLOBALS['_test_http_response'] = self::githubResponse( 'v1.0.0', array() );

		$transient = (object) array( 'response' => array() );
		$result    = $this->updater()->checkForUpdate( $transient );

		self::assertSame( array(), $result->response );
	}

	public function test_check_for_update_fail_open_on_http_error(): void {
		$GLOBALS['_test_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );

		$transient = (object) array( 'response' => array() );
		$result    = $this->updater()->checkForUpdate( $transient );

		self::assertSame( array(), $result->response );
	}

	public function test_check_for_update_fail_open_on_malformed_body(): void {
		$GLOBALS['_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => 'not json' );

		$transient = (object) array( 'response' => array() );
		$result    = $this->updater()->checkForUpdate( $transient );

		self::assertSame( array(), $result->response );
	}

	public function test_check_for_update_caches_release_between_calls(): void {
		$GLOBALS['_test_http_response'] = self::githubResponse( 'v1.0.0', self::zipAsset( '1.0.0' ) );

		$updater = $this->updater();
		$updater->checkForUpdate( (object) array( 'response' => array() ) );

		// Меняем "ответ GitHub" — если бы кэш не сработал, второй вызов увидел бы v2.0.0.
		$GLOBALS['_test_http_response'] = self::githubResponse( 'v2.0.0', self::zipAsset( '2.0.0' ) );
		$result = $updater->checkForUpdate( (object) array( 'response' => array() ) );

		self::assertSame( '1.0.0', $result->response['fs-lms/fs-lms.php']->new_version );
	}

	public function test_plugin_information_ignores_other_actions_and_slugs(): void {
		$updater = $this->updater();

		self::assertFalse( $updater->pluginInformation( false, 'query_plugins', (object) array( 'slug' => 'fs-lms' ) ) );
		self::assertFalse( $updater->pluginInformation( false, 'plugin_information', (object) array( 'slug' => 'other-plugin' ) ) );
	}

	public function test_plugin_information_returns_release_details(): void {
		$GLOBALS['_test_http_response'] = self::githubResponse( 'v1.2.3', self::zipAsset( '1.2.3' ), 'Fixed bugs' );

		$result = $this->updater()->pluginInformation( false, 'plugin_information', (object) array( 'slug' => 'fs-lms' ) );

		self::assertSame( '1.2.3', $result->version );
		self::assertSame( 'fs-lms', $result->slug );
		self::assertStringContainsString( 'Fixed bugs', $result->sections['changelog'] );
		self::assertStringContainsString( 'fs-lms-1.2.3.zip', $result->download_link );
	}

	public function test_plugin_information_fail_open_returns_original_result(): void {
		$GLOBALS['_test_http_response'] = array( 'response' => array( 'code' => 404 ), 'body' => '' );

		$result = $this->updater()->pluginInformation( false, 'plugin_information', (object) array( 'slug' => 'fs-lms' ) );

		self::assertFalse( $result );
	}
}
