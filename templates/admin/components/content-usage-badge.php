<?php
/**
 * Колонка «Используется» в списке банка контента.
 *
 * Режим путей (задачи/задания): plain text + CSS-тултип хлебных крошек.
 * @var array<int, array{display: string, tooltip: string}> $paths
 *
 * Режим потребителей (работы/уроки): ссылки с фильтром.
 * @var array<int, array{id: int, title: string, type: string}> $consumers
 * @var string $post_type
 * @var string $filter_param
 */

declare( strict_types=1 );

if ( ! empty( $paths ) ) {
	$parts = array();
	foreach ( $paths as $path ) {
		$has_tip = $path['display'] !== $path['tooltip'];
		$url     = $path['url'] ?? '';
		$label   = esc_html( $path['display'] );

		if ( '' !== $url ) {
			$tip_attrs = $has_tip
				? ' class="fs-tip" data-tooltip="' . esc_attr( $path['tooltip'] ) . '"'
				: '';
			$parts[] = '<a href="' . esc_url( $url ) . '"' . $tip_attrs . '>' . $label . '</a>';
		} elseif ( $has_tip ) {
			$parts[] = '<span class="fs-tip" data-tooltip="' . esc_attr( $path['tooltip'] ) . '">' . $label . '</span>';
		} else {
			$parts[] = $label;
		}
	}
	echo implode( ', ', $parts );
	return;
}

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
