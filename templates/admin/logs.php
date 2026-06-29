<?php

/**
 * @var string          $active_tab
 * @var int             $per_page
 * @var AuditLogDTO[]   $audit_rows
 * @var int             $audit_total
 * @var int             $audit_page
 * @var array           $audit_filters
 * @var AuditAction[]   $audit_actions
 * @var PiiAccessLogDTO[] $pii_rows
 * @var int             $pii_total
 * @var int             $pii_page
 * @var array           $pii_filters
 */

use Inc\Enums\Log\LogChannel;

// Вкладки строятся циклом из единого реестра каналов: показываются каналы с
// inAdminLogs() === true; id вкладки, партиал и заголовок (label) берутся из LogChannel.
// Добавить вкладку = добавить case в LogChannel + строку в adminTab().
$tabs = array();
foreach ( LogChannel::cases() as $channel ) {
	if ( ! $channel->inAdminLogs() ) {
		continue;
	}
	$tab = $channel->adminTab();
	$tabs[ $tab['id'] ] = array(
		'channel' => $channel,
		'file'    => '/components/tabs/logs-tabs/' . $tab['partial'] . '.php',
	);
}

$page_slug = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>

<div class="wrap">
	<h1>Журналы</h1>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_id => $tab ) : ?>
			<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=<?php echo esc_attr( $tab_id ); ?>"
				class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab['channel']->label() ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<div class="tab-content">
		<?php
		if ( isset( $tabs[ $active_tab ] ) ) {
			$file_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . $tabs[ $active_tab ]['file'];
			if ( file_exists( $file_path ) ) {
				include $file_path;
			}
		}
		?>
	</div>
</div>
