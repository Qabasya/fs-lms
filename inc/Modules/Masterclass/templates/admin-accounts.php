<?php
/**
 * Аккаунты одного мастер-класса (решение 24): номер, логин, состояние, первый вход, «дома», «связался», заявка.
 * Серверный рендер без AJAX. Паролей и персональных данных нет — пароли только на флаерах.
 *
 * @var string|null                 $error        Сообщение, если мастер-класса нет
 * @var string                      $back_url     Адрес списка мастер-классов
 * @var bool                        $with_deleted Показаны ли удалённые
 * @var int                         $page         Текущая страница
 * @var int                         $pages        Всего страниц
 * @var int                         $total        Всего строк
 * @var list<array<string, mixed>>  $rows         Строки (`MasterclassAccountService::page()`)
 * @var string                      $school       Школа
 * @var string                      $date         Дата мастер-класса (местная, д.м.Г)
 * @var bool                        $purged       Мастер-класс вычищен
 * @var string                      $toggle_url   Адрес переключателя «Показать удалённые»
 * @var string|null                 $prev_url     Предыдущая страница
 * @var string|null                 $next_url     Следующая страница
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** Строка таблицы: пустое время — прочерк. */
$mk_time = static function ( mixed $value ): string {
	return is_string( $value ) && '' !== $value ? $value : '—';
};
?>
<div class="wrap" id="fs-masterclass-accounts">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Аккаунты мастер-класса</h1>
		</div>
	</div>

	<hr class="wp-header-end">

	<p><a href="<?php echo esc_url( $back_url ); ?>">← К мастер-классам</a></p>

	<?php if ( null !== $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php else : ?>
		<h2><?php echo esc_html( $school ); ?> · <?php echo esc_html( $date ); ?></h2>

		<p>
			<a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $with_deleted ? 'Скрыть удалённые' : 'Показать удалённые'; ?></a>
			· Всего: <?php echo esc_html( (string) $total ); ?>
		</p>

		<?php if ( array() === $rows ) : ?>
			<p class="description">
				<?php echo $purged ? 'Мастер-класс вычищен: аккаунтов не осталось.' : 'Аккаунтов нет.'; ?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th scope="col">№</th>
						<th scope="col">Логин</th>
						<th scope="col">Состояние</th>
						<th scope="col">Первый вход</th>
						<th scope="col">Дома</th>
						<th scope="col">Связался</th>
						<th scope="col">Заявка</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['number'] ); ?></td>
							<td><?php echo esc_html( (string) $row['login'] ); ?></td>
							<td>
								<?php echo esc_html( (string) $row['state'] ); ?>
								<?php if ( null !== $row['deletedLocal'] ) : ?>
									<br><small>Удалён <?php echo esc_html( (string) $row['deletedLocal'] ); ?>: <?php echo esc_html( (string) ( $row['deleteReason'] ?? '—' ) ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $mk_time( $row['firstLoginLocal'] ) ); ?></td>
							<td><?php echo esc_html( $mk_time( $row['homeLocal'] ) ); ?></td>
							<td><?php echo esc_html( $mk_time( $row['contactLocal'] ) ); ?></td>
							<td><?php echo $row['hasApplication'] ? 'да' : 'нет'; ?></td>
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
	<?php endif; ?>
</div>
