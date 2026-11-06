<?php
/**
 * Настройки рейтинга и статистика кастомизации главной страницы.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$home_layout_statistics = $load_home_layout_statistics();
?>
<div id="tab-home-layout" class="tab-pane active">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Кастомизация</h2>
		</div>
		<p class="fs-page-header__desc">Предпочтения пользователей на главной личного кабинета.</p>
	</div>

	<div class="fs-lms-config">

		<div class="fs-card fs-card--flat">
			<div class="fs-card__header"><h2 class="fs-card__title">Порядок секций</h2></div>
			<div class="fs-card__body">
				<p class="fs-card__desc">Сохранённых раскладок: <strong><?php echo esc_html( (string) $home_layout_statistics['users'] ); ?></strong>. Пользователи без сохранённых настроек не учитываются.</p>
				<table class="wp-list-table widefat striped fs-table">
					<thead><tr><th scope="col">Секция</th><th scope="col">Первая позиция</th><th scope="col">Вторая позиция</th><th scope="col">Третья позиция</th></tr></thead>
					<tbody>
					<?php foreach ( $home_layout_statistics['sections'] as $section ) : ?>
						<tr><td class="column-title"><strong><?php echo esc_html( $section['label'] ); ?></strong></td>
							<?php foreach ( array( 'первом', 'втором', 'третьем' ) as $index => $position ) : ?>
								<td><?php echo esc_html( sprintf( 'На %s месте у %d пользователей', $position, $section['positions'][ $index ] ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="fs-card fs-card--flat">
			<div class="fs-card__header"><h2 class="fs-card__title">Выбранные плитки</h2></div>
			<div class="fs-card__body">
				<p class="fs-card__desc">Считается выбор в настройках, а не просмотры. Временно скрытые плитки учитываются. Автоматический набор не считается явным выбором; его используют <?php echo esc_html( (string) $home_layout_statistics['automatic'] ); ?> пользователей с сохранённой раскладкой.</p>
				<table class="wp-list-table widefat striped fs-table">
					<thead><tr><th scope="col">Плитка</th><th scope="col">Использование</th></tr></thead>
					<tbody>
					<?php foreach ( $home_layout_statistics['tiles'] as $tile ) : ?>
						<tr><td class="column-title"><strong><?php echo esc_html( $tile['label'] ); ?></strong></td><td><?php echo esc_html( sprintf( 'Используется у %d пользователей', $tile['users'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
