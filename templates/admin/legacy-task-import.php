<?php
/**
 * Скрытая страница разового переноса заданий со старой версии сайта.
 * Доступна только по прямому адресу admin.php?page=fs_lms_legacy_task_import
 * (аналогично Boilerplate Manager) — в боковом меню не отображается.
 *
 * @var \Inc\DTO\Subject\SubjectDTO[]                                      $subjects   Активные предметы.
 * @var array<string, array<int, array{slug: string, name: string}>>     $taxonomies Таксономии по ключу предмета.
 * @var int                                                              $batch_size Записей в одном AJAX-запросе.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap fs-lms-import">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Перенос заданий со старой версии сайта</h1>
		</div>
		<p class="fs-page-header__desc">
			Разовый инструмент: читает выбранный файл <code>legacy_tasks_import.json</code> и создаёт
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

	<?php require __DIR__ . '/partials/legacy-import-form.php'; ?>
</div>
