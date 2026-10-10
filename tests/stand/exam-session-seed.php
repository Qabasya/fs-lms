<?php
/**
 * Стенд сеанса на N гостей (этап 13.1.4): проведение STAND, сеанс «сейчас», N допущенных гостей с записью и сессией входа.
 *
 * Запуск (внутри контейнера WordPress): `wp eval-file tests/stand/exam-session-seed.php <N> <assessment_id>`.
 * Печатает JSON `{event_id, session_id, assessment_id, task_ids, participants: [{participation_id, registration_id, cookie}]}`.
 * Чистит `wp fs-lms exam stand-clean` (проведения `STAND …`). Только dev.
 */

$count        = max( 1, (int) ( $args[0] ?? 50 ) );
$assessmentId = (int) ( $args[1] ?? 0 );

$c = new \Inc\Core\Container();
$c->bind( \Inc\Contracts\ClockInterface::class, \Inc\Services\Shared\WpClock::class );
$c->bind( \Inc\Contracts\LogEventDispatcherInterface::class, \Inc\Services\Log\LogEventDispatcher::class );

$time           = $c->get( \Inc\Services\Exam\ExamTime::class );
$events         = $c->get( \Inc\Repositories\WPDBRepositories\ExamEventRepository::class );
$sessions       = $c->get( \Inc\Repositories\WPDBRepositories\ExamSessionRepository::class );
$participants   = $c->get( \Inc\Repositories\WPDBRepositories\ExamParticipantRepository::class );
$participations = $c->get( \Inc\Repositories\WPDBRepositories\ExamParticipationRepository::class );
$registrations  = $c->get( \Inc\Repositories\WPDBRepositories\ExamRegistrationRepository::class );
$guestSessions  = $c->get( \Inc\Services\Exam\GuestSessionService::class );
$assessments    = $c->get( \Inc\Managers\Assessment\AssessmentManager::class );

$assessment = $assessments->get( $assessmentId );
if ( null === $assessment ) {
	fwrite( STDERR, "Вариант не найден\n" );
	exit( 1 );
}

$now   = $time->nowUtc();
$start = $time->addMinutes( $now, -2 );
$end   = $time->addMinutes( $start, 235 );

$eventId   = $events->insert( array( 'subject_key' => $assessment->subjectKey, 'title' => 'STAND session ' . gmdate( 'H:i:s' ), 'owner_user_id' => 1, 'status' => 'published', 'period_from' => gmdate( 'Y-m-d' ), 'period_to' => gmdate( 'Y-m-d', time() + 86400 ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$sessionId = $sessions->insert( array( 'event_id' => $eventId, 'assessment_id' => $assessmentId, 'scheduled_at' => $start, 'planned_end_at' => $end, 'room_id' => 0, 'capacity' => $count, 'occupied_count' => $count, 'responsible_user_id' => 1, 'status' => 'open', 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );

$out = array();
for ( $i = 1; $i <= $count; $i++ ) {
	$pid = $participants->insert( array( 'school_name' => 'STAND', 'created_at' => $now, 'updated_at' => $now ) );
	$pn  = $participations->insert( array( 'event_id' => $eventId, 'participant_id' => $pid, 'audience' => 'guest', 'transfer_allowed' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$reg = $registrations->insert( array( 'participation_id' => $pn, 'session_id' => $sessionId, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => $now ) );
	$participations->setActiveRegistration( $pn, $reg );
	$participations->setAdmission( $pn, $now, 1 );

	$token  = \Inc\DTO\Exam\ExamAccessTokenDTO::fromArray( array( 'id' => 0, 'purpose' => 'entry', 'target_id' => $pn, 'token_hash' => str_repeat( '0', 64 ), 'generation' => 1, 'issuer_user_id' => 1, 'created_at' => $now ) );
	$cookie = $guestSessions->openEntry( $token, $reg, $end );
	$out[]  = array( 'participation_id' => $pn, 'registration_id' => $reg, 'cookie' => $cookie );
}

// Сессии входа проверяют поколение ключа: заводим действующий ключ каждому участию.
global $wpdb;
$tokens = $wpdb->prefix . 'fs_lms_exam_access_tokens';
foreach ( $out as $row ) {
	$wpdb->insert( $tokens, array( 'purpose' => 'entry', 'target_id' => $row['participation_id'], 'token_hash' => hash( 'sha256', 'stand-' . $row['participation_id'] ), 'generation' => 1, 'issuer_user_id' => 1, 'created_at' => $now ) );
}

echo wp_json_encode( array(
	'event_id'      => $eventId,
	'session_id'    => $sessionId,
	'assessment_id' => $assessmentId,
	'permalink'     => (string) get_permalink( $assessmentId ),
	'task_ids'      => array_values( $assessment->taskIds ),
	'participants'  => $out,
) ), "\n";
