<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$definitions = (array) get_option( 'fs_lms_consent_definitions', array() );
?>

<div id="tab-consents" class="tab-pane active">

	<div class="header-row">
		<h1 class="wp-heading-inline">Согласия</h1>
		<div class="description-actions">
			<button type="button" class="page-title-action js-open-consent-modal">
				Добавить согласие
			</button>
		</div>
	</div>

	<p class="description">
		Каждое согласие — отдельная страница WordPress. Текст редактируется через стандартный редактор.
		История версий хранится в ревизиях страницы; каждая версия идентифицируется по sha256-хешу.
		Перед использованием в формах заявок создайте согласие с ключом <code>pd_processing</code> и опубликуйте его.
	</p>

	<?php if ( empty( $definitions ) ) : ?>

		<div class="notice notice-info inline fs-table__no-items">
			<p>Согласия не созданы.</p>
			<button type="button" class="page-title-action js-open-consent-modal">
				Добавить первое согласие
			</button>
		</div>

	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table" id="js-consents-table">
			<thead>
			<tr>
				<th class="column-primary" style="width:36px;"></th>
				<th>Название</th>
				<th style="width:160px">Ключ</th>
				<th style="width:280px">Текущий хеш</th>
				<th style="width:160px">Действия</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $definitions as $key => $def ) :
				$pageId      = (int) ( $def['page_id'] ?? 0 );
				$page        = $pageId ? get_post( $pageId ) : null;
				$currentHash = ( $page && $page->post_content ) ? hash( 'sha256', $page->post_content ) : null;
				$isPublished = $page && 'publish' === $page->post_status;
				$revisions   = $page ? wp_get_post_revisions( $page->ID, array( 'order' => 'DESC' ) ) : array();
				$viewUrl     = $currentHash ? home_url( '/lms/consent/' . rawurlencode( $key ) . '/' . $currentHash . '/' ) : '';
				?>

				<!-- Основная строка -->
				<tr class="js-consent-toggle" data-key="<?php echo esc_attr( $key ); ?>" style="cursor:pointer;">
					<td>
						<span class="dashicons dashicons-arrow-right-alt2 accordion-arrow"
							style="transition: transform .2s; color:#646970;"></span>
					</td>
					<td>
						<strong><?php echo esc_html( $def['name'] ?? $key ); ?></strong>
						<?php if ( ! $isPublished ) : ?>
							<span style="color:#d63638; font-size:11px; margin-left:4px;">(черновик)</span>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $key ); ?></code></td>
					<td>
						<?php if ( $currentHash ) : ?>
							<code style="font-size:11px;"><?php echo esc_html( substr( $currentHash, 0, 32 ) . '…' ); ?></code>
						<?php else : ?>
							<em style="color:#646970;">Страница пустая</em>
						<?php endif; ?>
					</td>
					<td>
						<div class="row-actions visible" style="display:flex; gap:8px; align-items:center;">
							<?php if ( $pageId ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $pageId ) ); ?>" target="_blank"
									title="Редактировать текст" onclick="event.stopPropagation();">
									<span class="dashicons dashicons-edit"></span>
								</a>
							<?php endif; ?>
							<?php if ( $viewUrl ) : ?>
								<a href="<?php echo esc_url( $viewUrl ); ?>" target="_blank"
									title="Просмотреть текущую версию" onclick="event.stopPropagation();">
									<span class="dashicons dashicons-visibility"></span>
								</a>
							<?php endif; ?>
							<a href="#" class="js-delete-consent submitdelete"
								data-key="<?php echo esc_attr( $key ); ?>"
								data-name="<?php echo esc_attr( $def['name'] ?? $key ); ?>"
								title="Удалить определение" onclick="event.stopPropagation();"
								style="color:#d63638;">
								<span class="dashicons dashicons-trash"></span>
							</a>
						</div>
					</td>
				</tr>

				<!-- Аккордеон: история версий -->
				<tr class="consent-accordion-row hidden" id="consent-accordion-<?php echo esc_attr( $key ); ?>">
					<td colspan="5" style="background:#f9f9f9; padding:0;">
						<div class="consent-accordion-content" style="padding:12px 16px 16px 48px;">
							<?php if ( ! $page ) : ?>
								<p class="description" style="color:#d63638;">Страница не найдена (page_id=<?php echo $pageId; ?>).</p>
							<?php elseif ( empty( $page->post_content ) && empty( $revisions ) ) : ?>
								<p class="description">Текст согласия ещё не добавлен. <a href="<?php echo esc_url( get_edit_post_link( $pageId ) ); ?>" target="_blank">Открыть редактор</a></p>
							<?php else : ?>
								<p style="margin:0 0 8px; font-weight:600; color:#3c434a;">История версий</p>
								<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
									<thead>
									<tr>
										<th style="width:140px">Дата</th>
										<th>Хеш (sha256)</th>
										<th style="width:80px">Ссылка</th>
									</tr>
									</thead>
									<tbody>
									<?php
									// Текущая версия первой строкой
									if ( $currentHash ) :
										$viewCurrentUrl = home_url( '/lms/consent/' . rawurlencode( $key ) . '/' . $currentHash . '/' );
										?>
										<tr style="background:#f0f6fc;">
											<td>
												<strong><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $page->post_modified ) ) ); ?></strong>
												<span style="font-size:10px; color:#2271b1; margin-left:4px;">текущая</span>
											</td>
											<td><code style="font-size:11px;"><?php echo esc_html( $currentHash ); ?></code></td>
											<td>
												<a href="<?php echo esc_url( $viewCurrentUrl ); ?>" target="_blank">
													<span class="dashicons dashicons-external"></span>
												</a>
											</td>
										</tr>
									<?php endif; ?>

									<?php foreach ( $revisions as $rev ) :
										$revHash = hash( 'sha256', $rev->post_content );
										if ( ! $rev->post_content ) continue;
										$revUrl = home_url( '/lms/consent/' . rawurlencode( $key ) . '/' . $revHash . '/' );
										?>
										<tr>
											<td><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $rev->post_date ) ) ); ?></td>
											<td><code style="font-size:11px;"><?php echo esc_html( $revHash ); ?></code></td>
											<td>
												<a href="<?php echo esc_url( $revUrl ); ?>" target="_blank">
													<span class="dashicons dashicons-external"></span>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</td>
				</tr>

			<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>

</div>
