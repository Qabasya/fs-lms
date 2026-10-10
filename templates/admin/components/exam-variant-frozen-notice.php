<?php
/**
 * Admin-notice: правка варианта экзамена отклонена, вариант заморожен (этап 13.2).
 *
 * @var string $reason Причина отказа.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice notice-error">
	<p><?php echo esc_html( $reason ); ?></p>
</div>
