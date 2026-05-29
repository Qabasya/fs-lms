<?php
/**
 * Шаблон публичной страницы просмотра согласия на обработку ПД.
 *
 * Используется ConsentController для маршрута /lms/consent/{type}/{version}.
 * Данные передаются через set_query_var() перед подменой шаблона.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Services\ThemeCompatService;

$consent_text    = get_query_var( 'fs_consent_text', '' );
$consent_version = get_query_var( 'fs_consent_version', '' );

ThemeCompatService::header();
?>

<main class="fs-lms-consent-page">
	<div class="fs-lms-consent-page__container">

		<div class="fs-lms-consent-page__meta">
			<?php if ( $consent_version ) : ?>
				<span class="fs-lms-consent-page__version">
					<?php echo esc_html( $consent_version ); ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="fs-lms-consent-page__content">
			<?php echo wp_kses_post( $consent_text ); ?>
		</div>

	</div>
</main>

<?php ThemeCompatService::footer(); ?>
