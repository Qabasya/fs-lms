<?php
/**
 * Доступ к назначениям промо-курсов.
 *
 * @package Inc\Repositories\WPDBRepositories\Promo
 */

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Promo;

use Inc\DTO\Promo\CourseOverride;
use Inc\Enums\Settings\TableName;
use Inc\Repositories\WPDBRepositories\AbstractExamRepository;
use Inc\Services\Promo\CourseAssignmentResolver;

/** Чтение фактического назначения без предоставления доступа к занятиям. */
class PromoCourseRepository extends AbstractExamRepository {

	/** Передаёт зависимости чтению назначений. */
	public function __construct( private readonly CourseAssignmentResolver $resolver, ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
	}

	/** Возвращает курс активной связи по серверному времени UTC либо null. */
	public function effectiveCourse( int $linkId, string $nowUtc ): ?int {
		$row = $this->readRow(
			$this->wpdb->prepare(
				'SELECT l.course_override, l.course_id AS individual_course_id, e.course_id AS event_course_id
			FROM %i l INNER JOIN %i e ON e.id = l.event_id INNER JOIN %i a ON a.id = l.account_id
			WHERE l.id = %d AND l.revoked_at IS NULL AND a.revoked_at IS NULL AND l.expires_at > %s AND a.expires_at > %s',
				TableName::PromoEventLinks->prefixed(),
				TableName::PromoEvents->prefixed(),
				TableName::PromoAccounts->prefixed(),
				$linkId,
				$nowUtc,
				$nowUtc
			)
		);
		if ( null === $row ) {
			return null;
		}

		$courseId      = null === $row['individual_course_id'] ? null : (int) $row['individual_course_id'];
		$eventCourseId = null === $row['event_course_id'] ? null : (int) $row['event_course_id'];
		$override      = 0 === (int) $row['course_override']
			? CourseOverride::inherit()
			: ( null === $courseId ? CourseOverride::revoke() : CourseOverride::assign( $courseId ) );

		return $this->resolver->resolve( $eventCourseId, $override );
	}
}
