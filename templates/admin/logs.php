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

$tabs = array(
	'tab-1' => array(
		'title' => 'Журнал действий',
		'file'  => '/components/tabs/logs-tabs/logs-1-audit.php',
	),
	'tab-2' => array(
		'title' => 'Журнал доступа к ПД',
		'file'  => '/components/tabs/logs-tabs/logs-2-pii.php',
	),
);

$page_slug = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>

<div class="wrap">
	<h1>Журналы</h1>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_id => $tab ) : ?>
			<a href="?page=<?php echo esc_attr( $page_slug ); ?>&tab=<?php echo esc_attr( $tab_id ); ?>"
				class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab['title'] ); ?>
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
