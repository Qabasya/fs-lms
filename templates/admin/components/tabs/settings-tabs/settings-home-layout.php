<?php
/**
 * Настройки рейтинга и статистика кастомизации главной страницы.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Inc\Enums\Course\WorkType;

$home_layout_statistics = $load_home_layout_statistics();
$ranking_settings       = $config['ranking'];
$ranking_fields         = WorkType::options() + array(
	'assessment'               => 'Экзамены и контрольные',
	'attendance_bonus'         => 'Бонус за серию посещений',
	'attendance_streak_length' => 'Занятий подряд для бонуса',
);
?>
<div id="tab-home-layout" class="tab-pane active">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Кастомизация</h2>
		</div>
		<p class="fs-page-header__desc">Баллы рейтинга и предпочтения пользователей на главной личного кабинета.</p>
	</div>

	<div class="fs-lms-config">
		<form id="fs-ranking-settings-form" class="fs-card fs-card--flat">
			<div class="fs-card__header"><h2 class="fs-card__title">Рейтинг</h2></div>
			<div class="fs-card__body">
				<p class="fs-card__desc">Баллы за одну решённую задачу, включая повторные решения. Ноль отключает начисление за этот источник. Изменения пересчитывают рейтинг текущего триместра.</p>
				<?php foreach ( $ranking_fields as $key => $label ) : ?>
					<?php $is_streak = 'attendance_streak_length' === $key; ?>
					<div class="fs-field">
						<label for="fs-ranking-<?php echo esc_attr( $key ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
						<div class="fs-field__control">
							<input type="number" id="fs-ranking-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" data-ranking-field class="small-text" required
								min="<?php echo $is_streak ? '2' : '0'; ?>" max="<?php echo $is_streak ? '100' : '1000'; ?>" step="<?php echo $is_streak ? '1' : '0.01'; ?>"
								value="<?php echo esc_attr( (string) $ranking_settings[ $key ] ); ?>" />
						</div>
						<?php if ( 'attendance_bonus' === $key ) : ?>
							<p class="fs-field__desc">Начисляется за каждые N занятий подряд без пропуска. Например, при серии из 6 занятий и N = 3 бонус начисляется дважды.</p>
						<?php elseif ( $is_streak ) : ?>
							<p class="fs-field__desc">От 2 до 100 занятий. Пропуск начинает новую серию; при нулевом бонусе посещаемость не добавляет баллы.</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="fs-card__footer">
				<button type="submit" class="button button-primary js-ranking-save">Сохранить</button>
				<span class="fs-config-status js-ranking-status" role="status" aria-live="polite"></span>
			</div>
		</form>

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
