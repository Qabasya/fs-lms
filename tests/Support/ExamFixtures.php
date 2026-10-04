<?php

declare( strict_types=1 );

namespace Tests\Support;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSessionDTO;

/**
 * Фикстуры проведения и сеанса для тестов сервисов экзаменов: строки в том виде, в каком их отдаёт `$wpdb`
 * (все значения — строки), с точечной подменой полей.
 */
trait ExamFixtures {

	/** @param array<string, mixed> $override */
	private function examEvent( array $override = array() ): ExamEventDTO {
		return ExamEventDTO::fromArray( array_merge( array(
			'id'                         => '3',
			'subject_key'                => 'inf_ege',
			'title'                      => 'Пробный ЕГЭ',
			'description'                => null,
			'owner_user_id'              => '10',
			'status'                     => 'draft',
			'period_from'                => '2026-03-10',
			'period_to'                  => '2026-03-12',
			'registration_opens_at'      => '2026-03-01 07:00:00',
			'registration_closes_at'     => '2026-03-10 07:00:00',
			'guest_registration_enabled' => '0',
			'default_assessment_id'      => null,
			'variant_snapshot'           => null,
			'cancel_reason'              => null,
			'published_at'               => null,
			'completed_at'               => null,
			'cancelled_at'               => null,
			'version'                    => '1',
			'created_at'                 => '2026-02-20 10:00:00',
			'updated_at'                 => '2026-02-20 10:00:00',
		), $override ) );
	}

	/** @param array<string, mixed> $override */
	private function examSession( array $override = array() ): ExamSessionDTO {
		return ExamSessionDTO::fromArray( array_merge( array(
			'id'                  => '7',
			'event_id'            => '3',
			'assessment_id'       => '500',
			'scheduled_at'        => '2026-03-10 07:00:00',
			'planned_end_at'      => '2026-03-10 10:55:00',
			'room_id'             => '2',
			'capacity'            => '12',
			'occupied_count'      => '0',
			'responsible_user_id' => '10',
			'status'              => 'open',
			'first_started_at'    => null,
			'cancel_reason'       => null,
			'version'             => '1',
			'created_at'          => '2026-02-20 10:00:00',
			'updated_at'          => '2026-02-20 10:00:00',
		), $override ) );
	}

	/** Заявка гостя: бронь действует (`hold`, `is_held = 1`). @param array<string, mixed> $override */
	private function examGuestApplication( array $override = array() ): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array_merge( array(
			'id'               => '9',
			'event_id'         => '3',
			'session_id'       => '7',
			'source_id'        => '14',
			'participant_id'   => null,
			'identity_hash'    => str_repeat( 'a', 64 ),
			'active_slot'      => '1',
			'state'            => 'hold',
			'is_held'          => '1',
			'hold_expires_at'  => '2026-03-10 07:20:00',
			'request_key'      => 'req-1',
			'draft_enc'        => null,
			'source_snapshot'  => null,
			'consent_refs'     => null,
			'participation_id' => null,
			'registration_id'  => null,
			'ip_hash'          => null,
			'created_by_user_id' => null,
			'version'          => '1',
			'created_at'       => '2026-03-10 07:00:00',
			'updated_at'       => '2026-03-10 07:00:00',
		), $override ) );
	}
}
