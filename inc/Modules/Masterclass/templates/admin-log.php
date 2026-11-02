<?php
/**
 * Вкладка «Журнал» страницы модуля: действия мастер-классов с фильтрами и пагинацией.
 * Паролей и персональных данных нет: журнал пишется без них (`MasterclassLogWriter`).
 *
 * @var list<array<string, mixed>>  $rows      Строки журнала (`MasterclassAdminController::logView()`)
 * @var int                         $total     Всего записей по фильтру
 * @var int                         $page      Текущая страница
 * @var int                         $pages     Всего страниц
 * @var list<array{value: string, label: string}> $actions Словарь действий для фильтра
 * @var array<string, mixed>        $filters   Текущие значения фильтров (местные даты)
 * @var string                      $list_url  Адрес списка мастер-классов
 * @var string                      $filter_url Адрес вкладки «Журнал»
 * @var string|null                 $prev_url  Предыдущая страница
 * @var string|null                 $next_url  Следующая страница
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap" id="fs-masterclass-log">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Мастер-классы</h1>
		</div>
	</div>

	<nav class="nav-tab-wrapper" aria-label="Разделы">
		<a class="nav-tab" href="<?php echo esc_url( $list_url ); ?>">Мастер-классы</a>
		<span class="nav-tab nav-tab-active">Журнал</span>
	</nav>

	<hr class="wp-header-end">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( \Inc\Modules\Masterclass\Controllers\MasterclassAdminController::PAGE_SLUG ); ?>">
		<input type="hidden" name="view" value="log">

		<p class="tablenav">
			<label>
				Действие
				<select name="mk_action">
					<option value="">Все</option>
					<?php foreach ( $actions as $action ) : ?>
						<option value="<?php echo esc_attr( $action['value'] ); ?>" <?php selected( $filters['action'], $action['value'] ); ?>>
							<?php echo esc_html( $action['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				Мастер-класс №
				<input type="number" name="mk_mid" class="small-text" min="1" value="<?php echo esc_attr( (string) $filters['masterclass_id'] ); ?>">
			</label>
			<label>
				Ученик №
				<input type="number" name="mk_acc" class="small-text" min="1" value="<?php echo esc_attr( (string) $filters['account_id'] ); ?>">
			</label>
			<label>
				От
				<input type="date" name="mk_from" value="<?php echo esc_attr( (string) $filters['date_from'] ); ?>">
			</label>
			<label>
				До
				<input type="date" name="mk_to" value="<?php echo esc_attr( (string) $filters['date_to'] ); ?>">
			</label>
			<button type="submit" class="button">Применить</button>
			<a class="button" href="<?php echo esc_url( $filter_url ); ?>">Сбросить</a>
		</p>
	</form>

	<?php if ( array() === $rows ) : ?>
		<p class="description">Записей журнала по этому фильтру нет.</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col">Время (местное)</th>
					<th scope="col">Действие</th>
					<th scope="col">Актор</th>
					<th scope="col">Мастер-класс</th>
					<th scope="col">Аккаунт</th>
					<th scope="col">IP</th>
					<th scope="col">Данные</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['time'] ); ?></td>
						<td>
							<?php echo esc_html( $row['action'] ); ?>
							<?php if ( $row['isFailure'] ) : ?>
								<br><small>не удалось</small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['actor'] ); ?></td>
						<td>
							<?php if ( null !== $row['masterclass_id'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => \Inc\Modules\Masterclass\Controllers\MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => $row['masterclass_id'] ), admin_url( 'admin.php' ) ) ); ?>">
									№ <?php echo esc_html( (string) $row['masterclass_id'] ); ?>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['account'] ); ?></td>
						<td><?php echo esc_html( $row['ip'] ); ?></td>
						<td>
							<?php if ( array() !== $row['context'] ) : ?>
								<?php foreach ( $row['context'] as $key => $value ) : ?>
									<small><?php echo esc_html( $key ); ?>: <?php echo esc_html( $value ); ?></small><br>
								<?php endforeach; ?>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="tablenav-pages">
				Страница <?php echo esc_html( (string) $page ); ?> из <?php echo esc_html( (string) $pages ); ?>
				<?php if ( null !== $prev_url ) : ?>
					<a href="<?php echo esc_url( $prev_url ); ?>">← Назад</a>
				<?php endif; ?>
				<?php if ( null !== $next_url ) : ?>
					<a href="<?php echo esc_url( $next_url ); ?>">Вперёд →</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
