<?php
/**
 * Public explanation of an unavailable private link. Never renders a key or personal data.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

use Inc\Services\Shared\ThemeCompatService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = $message ?? (string) get_query_var( 'fs_lms_link_message', '' );
ThemeCompatService::header();
?>
<main class="fs-lms-join-page fs-page-wrapper">
	<section class="fs-join-card" aria-labelledby="fs-link-notice-title">
		<h1 id="fs-link-notice-title" class="fs-join-card__title"><?php esc_html_e( 'Ссылка недоступна', 'fs-lms' ); ?></h1>
		<p class="fs-join-card__subtitle"><?php echo esc_html( $message ); ?></p>
	</section>
</main>
<?php ThemeCompatService::footer(); ?>
