<?php

declare( strict_types=1 );

use Inc\Enums\ConsentType;
use Inc\Enums\PageRoutes;

defined( 'ABSPATH' ) || exit;

$consent_meta = (array) get_option( 'fs_lms_consent_page_meta', array() );
$current_hash = (string) ( $consent_meta['hash'] ?? '' );
$updated_at   = (string) ( $consent_meta['updated_at'] ?? '' );

$consent_page = get_page_by_path( PageRoutes::ConsentPage->value );

$revisions = array();
if ( $consent_page ) {
	$raw_revisions = wp_get_post_revisions( $consent_page->ID, array( 'order' => 'DESC' ) );
	foreach ( $raw_revisions as $rev ) {
		$revisions[] = array(
			'date'    => wp_date( 'd.m.Y H:i', strtotime( $rev->post_date ) ),
			'hash'    => hash( 'sha256', $rev->post_content ),
			'post_id' => $rev->ID,
		);
	}
}
?>

<div id="tab-consents" class="tab-pane active">

	<div class="header-row">
		<h1 class="wp-heading-inline">Согласия</h1>
	</div>

	<p class="description">
		Все типы согласий используют единый документ — страницу WordPress со слагом
		<code><?php echo esc_html( PageRoutes::ConsentPage->value ); ?></code>.
		Текст иммутабелен: новые версии создаются сохранением страницы в редакторе.
	</p>

	<?php if ( ! $consent_page ) : ?>
		<div class="notice notice-error inline" style="margin: 16px 0;">
			<p>Страница согласия не найдена. Переактивируйте плагин или создайте страницу со слагом
				<code><?php echo esc_html( PageRoutes::ConsentPage->value ); ?></code>.</p>
		</div>
	<?php else : ?>

		<!-- Текущая версия -->
		<dl class="fs-consent-tab__meta">
			<div>
				<dt>Текущий хеш (sha256)</dt>
				<dd>
					<?php if ( $current_hash ) : ?>
						<?php echo esc_html( $current_hash ); ?>
					<?php else : ?>
						<em>Не вычислен — сохраните страницу согласия в редакторе WordPress.</em>
					<?php endif; ?>
				</dd>
			</div>
			<div>
				<dt>Дата последнего обновления</dt>
				<dd>
					<?php echo $updated_at ? esc_html( wp_date( 'd.m.Y H:i', strtotime( $updated_at ) ) ) : '—'; ?>
				</dd>
			</div>
			<div>
				<dt>Редактировать документ</dt>
				<dd>
					<a href="<?php echo esc_url( get_edit_post_link( $consent_page->ID ) ); ?>" target="_blank">
						Открыть в редакторе
						<span class="dashicons dashicons-external" style="font-size:14px; vertical-align:middle;"></span>
					</a>
				</dd>
			</div>
		</dl>

		<!-- Ссылки по типам согласия -->
		<h2>Типы согласий</h2>
		<table class="wp-list-table widefat fixed striped fs-table" style="max-width: 860px; margin-bottom: 24px;">
			<thead>
			<tr>
				<th>Тип</th>
				<th>Ключ</th>
				<th>Ссылка (текущая версия)</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( ConsentType::cases() as $type ) :
				$consent_url = $current_hash
					? home_url( '/lms/consent/' . $type->value . '/' . $current_hash . '/' )
					: '';
				?>
				<tr>
					<td><?php echo esc_html( $type->label() ); ?></td>
					<td><code><?php echo esc_html( $type->value ); ?></code></td>
					<td>
						<?php if ( $consent_url ) : ?>
							<a href="<?php echo esc_url( $consent_url ); ?>" target="_blank">
								Просмотреть
								<span class="dashicons dashicons-external" style="font-size:13px; vertical-align:middle;"></span>
							</a>
						<?php else : ?>
							<em style="color: #646970;">Хеш не вычислен</em>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<!-- История версий (WP-ревизии) -->
		<h2>История версий</h2>

		<?php if ( empty( $revisions ) ) : ?>
			<p class="description">Ревизии не найдены. История появится после следующего сохранения страницы.</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fs-table" style="max-width: 860px; margin-bottom: 24px;">
				<thead>
				<tr>
					<th class="tw-30">Дата</th>
					<th>Хеш (sha256)</th>
					<th class="tw-15">Просмотр</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $revisions as $rev ) :
					$first_type = ConsentType::cases()[0];
					$rev_url    = home_url( '/lms/consent/' . $first_type->value . '/' . $rev['hash'] . '/' );
					?>
					<tr>
						<td><?php echo esc_html( $rev['date'] ); ?></td>
						<td><code style="font-size:11px; word-break:break-all;"><?php echo esc_html( $rev['hash'] ); ?></code></td>
						<td>
							<a href="<?php echo esc_url( $rev_url ); ?>" target="_blank">
								<span class="dashicons dashicons-external"></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- Поиск по хешу -->
		<h2>Восстановить по хешу</h2>
		<p class="description">
			Введите sha256-хеш из записи согласия, чтобы найти и просмотреть соответствующий текст.
		</p>
		<div class="fs-consent-tab__lookup">
			<input
				type="text"
				id="js-consent-hash-input"
				class="regular-text"
				placeholder="sha256-хеш (64 символа)"
				style="font-family: monospace; font-size: 12px;"
			>
			<button type="button" id="js-consent-hash-lookup" class="button">
				Найти
			</button>
		</div>
		<div id="js-consent-lookup-result" class="fs-consent-tab__lookup-result" style="display:none;"></div>

	<?php endif; ?>

</div>
