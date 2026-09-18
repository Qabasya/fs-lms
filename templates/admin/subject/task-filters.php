<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Фильтры над нативной таблицей заданий предмета (хук restrict_manage_posts).
 *
 * Данные собирает {@see \Inc\Controllers\Builders\SubjectTaskListFilters}.
 *
 * @var array<int, array{name: string, options: array<string,string>, selected: string, all_label: string}> $taxonomies
 * @var array{name: string, options: array<int,string>, selected: string, all_label: string}|null $author
 */

require_once __DIR__ . '/../components/UI/ui_renderers.php';

foreach ( $taxonomies as $taxonomy ) {
	render_fs_select( $taxonomy );
}

if ( null !== $author ) {
	render_fs_select( $author );
}
