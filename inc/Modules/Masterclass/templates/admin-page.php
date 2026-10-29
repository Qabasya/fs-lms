<?php
/**
 * Страница «FS LMS → Мастер-классы»: список мастер-классов с воронкой и действиями, форма создания и правки.
 * Вкладка «Журнал» приходит в этапе 6 (пока неактивна).
 *
 * @var list<array<string, mixed>>   $rows      Строки списка (`MasterclassAdminController::listView()`)
 * @var int                          $total     Всего мастер-классов
 * @var int                          $page      Текущая страница
 * @var int                          $pages     Всего страниц
 * @var list<array{id: int, name: string}> $teachers Преподаватели для формы
 * @var array<string, string>        $subjects  Предметы с банком уроков: ключ → название
 * @var array<string, mixed>         $defaults  Окно и размер пачки по умолчанию
 * @var string                       $list_url  Адрес страницы модуля
 * @var int                          $chunk_max Предел аккаунтов в одном запросе
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Inc\Modules\Masterclass\Enums\MasterclassStatus;
?>
<div class="wrap" id="fs-masterclass-page">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Мастер-классы</h1>
		</div>

		<p class="fs-page-header__desc">
			Занятия во внешних школах: временные аккаунты для детей, флаеры с логином и паролем, воронка до заявки.
		</p>
	</div>

	<nav class="nav-tab-wrapper" aria-label="Разделы">
		<a class="nav-tab nav-tab-active" href="<?php echo esc_url( $list_url ); ?>">Мастер-классы</a>
		<span class="nav-tab nav-tab-disabled" aria-disabled="true" title="Появится позже">Журнал</span>
	</nav>

	<hr class="wp-header-end">

	<p>
		<button type="button" class="button button-primary" id="fs-masterclass-new">Создать мастер-класс</button>
	</p>

	<div id="fs-masterclass-notice" class="notice" hidden>
		<p id="fs-masterclass-notice-text"></p>
	</div>

	<?php require __DIR__ . '/admin-form.php'; ?>

	<div class="fs-card fs-card--flat" id="fs-masterclass-panel" hidden>
		<div class="fs-card__body">
			<p id="fs-masterclass-panel-title"></p>
			<p>
				<input type="number" id="fs-masterclass-panel-number" class="small-text" min="1" value="1">
				<span id="fs-masterclass-panel-hint"></span>
			</p>
			<p>
				<button type="button" class="button button-primary" id="fs-masterclass-panel-ok">Выполнить</button>
				<button type="button" class="button" id="fs-masterclass-panel-cancel">Отмена</button>
			</p>
		</div>
	</div>

	<?php if ( array() === $rows ) : ?>
		<p class="description">Мастер-классов пока нет. Нажмите «Создать мастер-класс».</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped" id="fs-masterclass-table">
			<thead>
				<tr>
					<th scope="col">Школа</th>
					<th scope="col">Окно (местное время)</th>
					<th scope="col">Предмет</th>
					<th scope="col">Преподаватель</th>
					<th scope="col">Состояние</th>
					<th scope="col">Воронка</th>
					<th scope="col">Действия</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $funnel = $row['funnel']; ?>
					<tr data-mk-row
						data-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
						data-school="<?php echo esc_attr( $row['school'] ); ?>"
						data-subject="<?php echo esc_attr( $row['subject_key'] ); ?>"
						data-lesson="<?php echo esc_attr( (string) $row['lesson_id'] ); ?>"
						data-teacher="<?php echo esc_attr( (string) $row['teacher_id'] ); ?>"
						data-date="<?php echo esc_attr( $row['date'] ); ?>"
						data-start="<?php echo esc_attr( $row['start'] ); ?>"
						data-end="<?php echo esc_attr( $row['end'] ); ?>">
						<td><strong><?php echo esc_html( $row['school'] ); ?></strong></td>
						<td><?php echo esc_html( $row['window_label'] ); ?></td>
						<td><?php echo esc_html( $row['subject_name'] ); ?></td>
						<td><?php echo esc_html( $row['teacher_name'] ); ?></td>
						<td><?php echo esc_html( $row['status_label'] ); ?></td>
						<td>
							Создано <?php echo esc_html( (string) $funnel->created ); ?> ·
							входили <?php echo esc_html( (string) $funnel->loggedIn ); ?> ·
							дома <?php echo esc_html( (string) $funnel->atHome ); ?> ·
							связались <?php echo esc_html( (string) $funnel->contacted ); ?> ·
							заявок <?php echo esc_html( (string) $funnel->applied ); ?> ·
							зачислено <?php echo esc_html( (string) $funnel->enrolled ); ?>
						</td>
						<td>
							<?php if ( MasterclassStatus::Purged === $row['status'] ) : ?>
								<a href="<?php echo esc_url( $row['accounts_url'] ); ?>">Аккаунты</a>
							<?php else : ?>
								<?php if ( null !== $row['player_url'] ) : ?>
									<a href="<?php echo esc_url( $row['player_url'] ); ?>" target="_blank" rel="noopener">Открыть в плеере</a> |
								<?php endif; ?>
								<a href="<?php echo esc_url( $row['accounts_url'] ); ?>">Аккаунты</a>
								<?php if ( $row['editable'] ) : ?>
									| <button type="button" class="button-link" data-mk-action="add">Докинуть</button>
									| <a href="<?php echo esc_url( $row['flyers_url'] ); ?>" target="_blank" rel="noopener">Печать флаеров</a>
									| <button type="button" class="button-link" data-mk-action="unlock">Снять блокировки</button>
									| <button type="button" class="button-link" data-mk-action="extend">Продлить</button>
									| <button type="button" class="button-link" data-mk-action="edit">Изменить</button>
									| <button type="button" class="button-link button-link-delete" data-mk-action="delete">Удалить</button>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="tablenav-pages">
				Страница <?php echo esc_html( (string) $page ); ?> из <?php echo esc_html( (string) $pages ); ?>
				<?php if ( $page > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'paged' => $page - 1 ), $list_url ) ); ?>">← Назад</a>
				<?php endif; ?>
				<?php if ( $page < $pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'paged' => $page + 1 ), $list_url ) ); ?>">Вперёд →</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
