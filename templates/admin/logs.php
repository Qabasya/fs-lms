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

use Inc\Enums\Access\Capability;
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
    <div class="fs-page-header">
        <div class="fs-page-header__content">
            <h1 class="fs-page-header__title">Журналы</h1>

        </div>

        <p class="fs-page-header__desc">
            Здесь можно просмотреть журналы действий администраторов и пользователей.
        </p>
    </div>

	<hr class="wp-header-end">

	<?php
	// Месячный архив тяжёлых журналов: скачать ZIP → очистить. Только для тех, кто вправе выгружать ПД.
	if ( current_user_can( Capability::ManageLmsPlatform->value ) && current_user_can( Capability::ExportPII->value ) ) :
		?>
		<div class="fs-log-archive" id="js-log-archive">
			<div class="fs-log-archive__head">
				<h2 class="fs-log-archive__title">Архив журналов</h2>
				<p class="fs-log-archive__hint">
					Раз в месяц: скачайте архив, затем очистите журналы. Остаётся прошлый месяц,
					события обучения очищаются целиком. Восстановление:
					<code>wp fs-lms logs import архив.zip</code>
				</p>
			</div>
			<ul class="fs-log-archive__list" data-archive-list>
				<li>Загрузка…</li>
			</ul>
			<p class="fs-log-archive__last" data-archive-last hidden></p>
			<div class="fs-log-archive__actions">
				<button type="button" class="button button-primary" data-archive-export>
					<span class="dashicons dashicons-download"></span> 1. Скачать архив
				</button>
				<button type="button" class="button" data-archive-purge disabled>
					<span class="dashicons dashicons-trash"></span> 2. Очистить журналы
				</button>
			</div>
		</div>
	<?php endif; ?>

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
