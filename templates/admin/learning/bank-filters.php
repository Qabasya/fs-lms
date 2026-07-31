<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Выпадающие фильтры над нативной таблицей банка контента (хук restrict_manage_posts).
 *
 * @var array<int, array{name: string, options: array, selected: string, all_label: string}> $selects
 */

require_once __DIR__ . '/../components/UI/ui_renderers.php';

foreach ( $selects as $select ) {
	render_fs_select( $select );
}
