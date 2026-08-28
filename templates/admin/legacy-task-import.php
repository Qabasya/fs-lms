<?php
/**
 * Скрытая страница разового переноса заданий со старой версии сайта.
 * Доступна только по прямому адресу admin.php?page=fs_lms_legacy_task_import
 * (аналогично Boilerplate Manager) — в боковом меню не отображается.
 *
 * @var \Inc\DTO\Subject\SubjectDTO[] $subjects Активные предметы.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Перенос заданий со старой версии сайта</h1>
		</div>
		<p class="fs-page-header__desc">
			Разовый инструмент: читает <code>.docs/legacy_tasks_import.json</code> и создаёт
			черновики в банке заданий выбранного предмета — номер и шаблон присваиваются
			так же, как при обычном создании задания. Термины авторов/года/сложности должны
			существовать заранее, иначе будут созданы автоматически с указанным названием.
		</p>
	</div>

	<hr class="wp-header-end">

	<?php if ( empty( $subjects ) ) : ?>
		<div class="notice notice-warning"><p>Нет ни одного активного предмета. Сначала создайте предмет.</p></div>
		<?php return; ?>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tbody>
		<tr>
			<th scope="row"><label for="fs-legacy-import-subject">Предмет</label></th>
			<td>
				<select id="fs-legacy-import-subject">
					<?php foreach ( $subjects as $subject ) : ?>
						<option value="<?php echo esc_attr( $subject->key ); ?>">
							<?php echo esc_html( $subject->name ); ?> (<?php echo esc_html( $subject->key ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="fs-legacy-import-author-tax">Таксономия автора</label></th>
			<td>
				<input type="text" id="fs-legacy-import-author-tax" class="regular-text" placeholder="{ключ}_author">
				<p class="description">Пусто — будет использован слаг «{ключ_предмета}_author».</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="fs-legacy-import-year-tax">Таксономия года</label></th>
			<td>
				<input type="text" id="fs-legacy-import-year-tax" class="regular-text" placeholder="{ключ}_year">
				<p class="description">Пусто — будет использован слаг «{ключ_предмета}_year».</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="fs-legacy-import-level-tax">Таксономия сложности</label></th>
			<td>
				<input type="text" id="fs-legacy-import-level-tax" class="regular-text" placeholder="{ключ}_level">
				<p class="description">Пусто — будет использован слаг «{ключ_предмета}_level».</p>
			</td>
		</tr>
		</tbody>
	</table>

	<p class="fs-legacy-import__controls">
		<button type="button" id="fs-legacy-import-start" class="button button-primary">Начать перенос</button>
		<span id="fs-legacy-import-status" class="fs-legacy-import__status"></span>
	</p>

	<progress id="fs-legacy-import-progress" class="fs-legacy-import__progress" value="0" max="100" hidden></progress>

	<div id="fs-legacy-import-report"></div>
</div>
