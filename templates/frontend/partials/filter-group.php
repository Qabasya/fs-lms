<?php
/**
 * Группа фильтров сайдбара — общая для тренажёра и каталога учебника.
 *
 * Группа собрана FilterGroupService::group(). Пришедшие в URL фильтры
 * раскрывают секцию, отмечают опции и выводят бейдж; JS-компонент секции —
 * `components/filter-section.js`. Недоступные под текущий срез опции остаются
 * в разметке скрытыми: их возвращает JS при снятии фильтра.
 *
 * @var array<string, mixed> $group Группа фильтров.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

$group_active = (int) ( $group['active'] ?? 0 );
?>
<div class="filter-sec js-filter-sec"
	data-section="<?php echo esc_attr( $group['taxonomy'] ); ?>"
	<?php echo ! empty( $group['is_type'] ) ? 'data-is-type="1"' : ''; ?>
	<?php echo empty( $group['available'] ) ? 'hidden' : ''; ?>>
	<button class="filter-sec-head" aria-expanded="<?php echo $group_active ? 'true' : 'false'; ?>">
		<span class="filter-sec-title"><?php echo esc_html( $group['name'] ); ?></span>
		<span class="filter-sec-right">
			<?php if ( $group_active ) : ?><span class="filter-sec-badge"><?php echo esc_html( (string) $group_active ); ?></span><?php endif; ?>
			<span class="filter-sec-summary" <?php echo $group_active ? 'hidden' : ''; ?>><?php echo esc_html( $group['summary'] ); ?></span>
			<span class="filter-sec-chev<?php echo $group_active ? ' is-open' : ''; ?>" aria-hidden="true">
				<?php echo Icon::ChevronRight->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</span>
	</button>
	<div class="filter-sec-body" <?php echo $group_active ? '' : 'hidden'; ?>>
		<div class="filter-options">
			<?php foreach ( $group['terms'] as $term ) : ?>
				<button class="filter-option js-filter-option<?php echo ! empty( $term['selected'] ) ? ' is-active' : ''; ?>"
					data-filter="<?php echo esc_attr( $group['taxonomy'] ); ?>"
					data-value="<?php echo esc_attr( $term['slug'] ); ?>"
					<?php echo empty( $term['available'] ) ? 'hidden' : ''; ?>>
					<span class="filter-option-label"><?php echo esc_html( $term['name'] ); ?></span>
					<span class="filter-option-count"><?php echo esc_html( (string) $term['count'] ); ?></span>
					<span class="filter-option-check" aria-hidden="true">
						<?php // 10px: бокс галочки 14px с рамкой 1.5 даёт 11px внутри — иконка 14 в него не помещалась. ?>
						<?php echo Icon::Check->svg( 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
				</button>
			<?php endforeach; ?>
		</div>
	</div>
</div>
