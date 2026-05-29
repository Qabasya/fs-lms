<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\ApplicationCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class ApplicationController
 *
 * Контроллер публичной формы зачисления (/lms/apply, /lms/join/{code}).
 *
 * @package Inc\Controllers
 */
class ApplicationController extends AjaxController {

	public function __construct(
		private readonly ApplicationCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'init',             array( $this, 'addRewriteRules' ) );
		add_filter( 'query_vars',       array( $this, 'addQueryVars' ) );
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
		$this->registerAjaxHooks();
	}

	protected function publicAjaxActions(): array {
		return array(
			array( AjaxHook::SendOtpCode,        $this->callbacks ),
			array( AjaxHook::CreateApplication,  $this->callbacks ),
			array( AjaxHook::SubmitParentData,    $this->callbacks ),
		);
	}

	public function addRewriteRules(): void {
		add_rewrite_rule(
			'^lms/apply/?$',
			'index.php?fs_lms_page=apply',
			'top'
		);
		add_rewrite_rule(
			'^lms/join/([A-Z0-9\-]+)/?$',
			'index.php?fs_lms_page=join&fs_lms_join_code=$matches[1]',
			'top'
		);
	}

	public function addQueryVars( array $vars ): array {
		$vars[] = 'fs_lms_page';
		$vars[] = 'fs_lms_join_code';

		return $vars;
	}

	public function loadTemplate( string $template ): string {
		$page = get_query_var( 'fs_lms_page' );

		if ( 'apply' === $page ) {
			$path = $this->path( 'templates/public/apply.php' );

			return file_exists( $path ) ? $path : $template;
		}

		if ( 'join' === $page ) {
			$path = $this->path( 'templates/public/join.php' );

			return file_exists( $path ) ? $path : $template;
		}

		return $template;
	}
}