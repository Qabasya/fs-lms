<?php
/**
 * Открытая запись гостя: выбор класса на странице записи без ссылки школы.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;

/**
 * Открытая запись гостя: на `/exam-signup/` без ссылки школы гость выбирает класс (9 — ОГЭ, 11 — ЕГЭ) и попадает на обычную форму записи.
 *
 * Принцип «одна форма»: у проведения с включённой открытой записью есть служебный источник ({@see ExamSourceDTO::PUBLIC_KEY}); выбор класса
 * открывает по нему ту же сессию приглашения, что и ссылка школы, и дальше работает прежний путь (сеанс, согласия, бронь, оплата).
 * Школьных отчётов по таким участникам нет ({@see ExamReportService::canInclude()}), данные остаются для статистики.
 */
class GuestPublicSignupService {

	/** Получает источники, проведения, ключи приглашений и сессии гостя. */
	public function __construct(
		private readonly ExamSourceRepository $sources,
		private readonly ExamEventRepository $events,
		private readonly ExamAccessTokenService $tokens,
		private readonly GuestSessionService $sessions,
		private readonly ExamTime $time,
	) {}

	/**
	 * Классы страницы выбора: у каждого — подпись экзамена, адрес перехода и признак «есть открытая запись».
	 *
	 * @return list<array{grade: int, label: string, available: bool, url: string}>
	 */
	public function options(): array {
		$options = array();
		foreach ( array( ExamDirection::Oge, ExamDirection::Ege ) as $direction ) {
			$grade     = $direction->grade();
			$options[] = array(
				'grade'     => $grade,
				'label'     => sprintf( '%d класс — %s', $grade, $direction->label() ),
				'available' => null !== $this->sourceFor( $grade ),
				'url'       => (string) add_query_arg( array( 'grade' => $grade ), PageRoutes::ExamSignup->url() ),
			);
		}

		return $options;
	}

	/**
	 * Открывает сессию приглашения по источнику открытой записи класса.
	 *
	 * @return string|null Значение куки приглашения; null — открытой записи на этот класс сейчас нет.
	 */
	public function openForGrade( int $grade ): ?string {
		$source = $this->sourceFor( $grade );
		if ( null === $source ) {
			return null;
		}

		$generation = $this->tokens->currentGeneration( ExamTokenPurpose::Invitation, $source->id );

		return $generation > 0 ? $this->sessions->openInvitation( $source->id, $generation ) : null;
	}

	/**
	 * Источник открытой записи класса: включён, проведение опубликовано, гостевая запись включена, окно записи открыто.
	 * Из нескольких проведений берётся созданное последним.
	 */
	private function sourceFor( int $grade ): ?ExamSourceDTO {
		$nowUtc = $this->time->nowUtc();

		foreach ( $this->sources->listActivePublic() as $source ) {
			if ( $source->grade !== $grade ) {
				continue;
			}

			$event = $this->events->find( $source->eventId );
			if ( null === $event || ExamEventStatus::Published->value !== $event->status || ! $event->guestRegistrationEnabled ) {
				continue;
			}
			if ( ( null !== $event->registrationOpensAt && $event->registrationOpensAt > $nowUtc )
				|| ( null !== $event->registrationClosesAt && $event->registrationClosesAt <= $nowUtc ) ) {
				continue;
			}

			return $source;
		}

		return null;
	}
}
