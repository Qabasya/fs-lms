<?php
/**
 * Карточка фильтров сайдбара — общая для тренажёра и каталога учебника.
 *
 * На узком экране карточка встаёт над списком и сворачивается под кнопку
 * «Фильтры» (`components/filters-toggle.js`): раскрытые группы заняли бы
 * весь первый экран. На десктопе кнопка скрыта, тело видно всегда.
 *
 * @var array<int, array<string, mixed>> $filters_groups   Группы FilterGroupService.
 * @var bool                             $filters_selected Есть ли выбранные фильтры (кнопка «Сбросить»).
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;
?>
<section class="side-card filters-side">
	<div class="filters-side-head">
		<span class="filters-side-title">Фильтры</span>
		<button type="button" class="filters-side-toggle js-filters-toggle" aria-expanded="false" aria-controls="fs-filters-body">
			<span>Фильтры</span>
			<span class="filters-side-toggle-chev" aria-hidden="true">
				<?php echo Icon::ChevronDown->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</button>
		<button class="filters-side-clear js-filters-clear" <?php echo $filters_selected ? '' : 'disabled'; ?>>Сбросить</button>
	</div>

	<div class="filters-side-body" id="fs-filters-body">
		<?php foreach ( $filters_groups as $group ) : ?>
			<?php include __DIR__ . '/filter-group.php'; ?>
		<?php endforeach; ?>
	</div>
</section>
