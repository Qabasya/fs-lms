<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

/** Пакетное чтение опубликованных заданий по возрастающему ID. */
class TaskFileNormalizationRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/** @param string[] $types @return int[] */
	public function publishedIds( array $types, int $afterId, int $limit ): array {
		if ( ! $types ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql = 'SELECT ID FROM %i WHERE post_type IN (' . $placeholders . ') AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d';
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( $sql, $this->wpdb->posts, ...array_merge( $types, array( 'publish', $afterId, $limit ) ) ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Не удалось получить список опубликованных заданий.' );
		}
		return array_map( 'intval', $rows );
	}
}
