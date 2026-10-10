<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Services\Shared\CenterContactsService;

/** Оболочка гостевой страницы результата: общий разбор и контакты центра. */
class GuestResultViewService {

	public function __construct(
		private readonly ExamReviewViewService $review,
		private readonly CenterContactsService $contacts,
	) {}

	/** @return array<string, mixed>|null */
	public function build( int $participationId ): ?array {
		$data = $this->review->build( $participationId );

		return null !== $data ? $data + array( 'contacts' => $this->contacts->get() ) : null;
	}
}
