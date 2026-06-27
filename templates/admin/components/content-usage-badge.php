<?php
/**
 * Колонка «Используется» в списке банка контента.
 *
 * @var array<int, array{id: int, title: string, type: string}> $consumers
 * @var string $post_type    post_type текущей записи (для URL фильтра)
 * @var string $filter_param GET-параметр фильтра (fs_lesson_usage / fs_work_usage / fs_assessment_usage)
 */

declare( strict_types=1 );

if ( empty( $consumers ) ) {
	echo '&mdash;';
	return;
}

$parts = array();
foreach ( $consumers as $consumer ) {
	if ( '' !== $filter_param ) {
		$url     = admin_url( 'edit.php?post_type=' . rawurlencode( $post_type ) . '&' . $filter_param . '=' . (int) $consumer['id'] );
		$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $consumer['title'] ) . '</a>';
	} else {
		$parts[] = esc_html( $consumer['title'] );
	}
}

echo implode( ', ', $parts );
