<?php
/**
 * Оболочка гостевой страницы результата: общий разбор и контакты центра.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Services\Shared\CenterContactsService;

/** Оболочка гостевой страницы результата: общий разбор и контакты центра. */
class GuestResultViewService {

	/** Добавляет контакты центра к общему разбору, сохраняя его независимость от гостевого маршрута. */
	public function __construct(
		private readonly ExamReviewViewService $review,
		private readonly CenterContactsService $contacts,
	) {}

	/**
	 * Добавляет контакты центра к общему разбору участия для гостевой страницы.
	 *
	 * @return array<string, mixed>|null
	 */
	public function build( int $participationId ): ?array {
		$data = $this->review->build( $participationId );

		return null !== $data ? $data + array( 'contacts' => $this->contacts->get() ) : null;
	}
}
