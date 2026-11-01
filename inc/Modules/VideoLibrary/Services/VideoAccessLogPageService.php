<?php
/**
 * Video access log page data.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Managers\Person\UserManager;
use Inc\Modules\VideoLibrary\Repositories\VideoAccessLogRepository;
use Inc\Shared\Traits\Sanitizer;

/** Data for the module-owned tab on the shared logs page. */
class VideoAccessLogPageService {
	use Sanitizer;

	public const TAB = 'tab-video-access';

	/** Receives the collaborators for this module operation. */
	public function __construct(
		private readonly VideoAccessLogRepository $repository,
		private readonly UserManager $users,
	) {}

	/** Load the selected page and its user names. */
	public function build(): array {
		$filters = array(
			'wp_user_id'      => $this->sanitizeGetInt( 'wp_user_id' ),
			'group_lesson_id' => $this->sanitizeGetInt( 'group_lesson_id' ),
			'recording'       => $this->sanitizeGetText( 'recording' ),
		);
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$date   = $this->sanitizeGetText( $key );
			$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( false !== $parsed && $parsed->format( 'Y-m-d' ) === $date ) {
				$filters[ $key ] = $date;
			}
		}
		$orderby = $this->sanitizeGetKey( 'orderby' );
		$orderby = in_array( $orderby, array( 'id', 'last_at', 'watch_seconds', 'requests_count' ), true ) ? $orderby : 'id';
		$order   = 'asc' === $this->sanitizeGetKey( 'order' ) ? 'ASC' : 'DESC';
		$filters = array_filter( $filters );
		$page    = $this->repository->page( $filters, max( 1, $this->sanitizeGetInt( 'paged' ) ), 50, $orderby, $order );
		return array(
			'video_log' => $page + array(
				'filters' => $filters,
				'summary' => $this->repository->summary( $filters ),
				'orderby' => $orderby,
				'order'   => strtolower( $order ),
				'names'   => $this->users->displayNames( array_column( $page['rows'], 'wp_user_id' ) ),
			),
		);
	}
}
