<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$definitions = (array) get_option( 'fs_lms_consent_definitions', array() );
?>

<div id="tab-consents" class="tab-pane active">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Согласия</h2>
			<div class="fs-page-header__actions">
				<button type="button" class="page-title-action js-open-consent-modal">
					Добавить согласие
				</button>
			</div>
		</div>
		<p class="fs-page-header__desc">
			Каждое согласие — отдельная страница WordPress. Текст редактируется через стандартный редактор.
			История версий хранится в ревизиях страницы; каждая версия идентифицируется по sha256-хешу.
			Перед использованием в формах заявок создайте согласие с ключом <code>pd_processing</code> и опубликуйте его.
		</p>
	</div>

	<?php if ( empty( $definitions ) ) : ?>

		<div class="notice notice-info inline fs-table__no-items">
			<p>Согласия не созданы.</p>
			<button type="button" class="page-title-action js-open-consent-modal">
				Добавить первое согласие
			</button>
		</div>

	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table fs-consents-table" id="js-consents-table">
			<thead>
			<tr>
				<th class="column-primary tw-3"></th>
				<th>Название</th>
				<th class="tw-15">Ключ</th>
				<th class="tw-25">Текущий хеш</th>
				<th class="tw-20">Действия</th>
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
				<tr class="js-consent-toggle" data-key="<?php echo esc_attr( $key ); ?>">
					<td>
						<span class="dashicons dashicons-arrow-right-alt2 accordion-arrow"></span>
					</td>
					<td>
						<strong><?php echo esc_html( $def['name'] ?? $key ); ?></strong>
						<?php if ( ! $isPublished ) : ?>
							<span class="consent-draft">(черновик)</span>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $key ); ?></code></td>
					<td>
						<?php if ( $currentHash ) : ?>
							<code class="fs-code-sm"><?php echo esc_html( substr( $currentHash, 0, 32 ) . '…' ); ?></code>
						<?php else : ?>
							<em>Страница пустая</em>
						<?php endif; ?>
					</td>
					<td onclick="event.stopPropagation();">
						<div class="row-actions visible">
							<?php if ( $viewUrl ) : ?>
								<span class="view">
									<a href="<?php echo esc_url( $viewUrl ); ?>" target="_blank">Просмотреть</a>
								</span>
							<?php endif; ?>
							<?php if ( $pageId ) : ?>
								<?php if ( $viewUrl ) : ?> | <?php endif; ?>
								<span class="edit">
									<a href="<?php echo esc_url( get_edit_post_link( $pageId ) ); ?>" target="_blank">Изменить</a>
								</span> |
							<?php endif; ?>
							<span class="trash">
								<a href="#"
									class="js-delete-consent submitdelete"
									data-key="<?php echo esc_attr( $key ); ?>"
									data-name="<?php echo esc_attr( $def['name'] ?? $key ); ?>">Удалить</a>
							</span>
						</div>
					</td>
				</tr>

				<!-- Аккордеон: история версий -->
				<tr class="consent-accordion-row hidden" id="consent-accordion-<?php echo esc_attr( $key ); ?>">
					<td colspan="5">
						<div class="consent-accordion-content">
							<?php if ( ! $page ) : ?>
								<p class="description consent-error">Страница не найдена (page_id=<?php echo $pageId; ?>).</p>
							<?php elseif ( empty( $page->post_content ) && empty( $revisions ) ) : ?>
								<p class="description">Текст согласия ещё не добавлен. <a href="<?php echo esc_url( get_edit_post_link( $pageId ) ); ?>" target="_blank">Открыть редактор</a></p>
							<?php else : ?>
								<p class="consent-history-title">История версий</p>
								<table class="wp-list-table widefat fixed striped max-tw-50">
									<thead>
									<tr>
										<th class="tw-20">Дата</th>
										<th>Хеш (sha256)</th>
										<th class="tw-10">Ссылка</th>
									</tr>
									</thead>
									<tbody>
									<?php
									// Текущая версия первой строкой
									if ( $currentHash ) :
										$viewCurrentUrl = home_url( '/lms/consent/' . rawurlencode( $key ) . '/' . $currentHash . '/' );
										?>
										<tr class="consent-version-current">
											<td>
												<strong><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $page->post_modified ) ) ); ?></strong>
												<span class="consent-version-badge">текущая</span>
											</td>
											<td><code class="fs-code-sm"><?php echo esc_html( $currentHash ); ?></code></td>
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
											<td><code class="fs-code-sm"><?php echo esc_html( $revHash ); ?></code></td>
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
