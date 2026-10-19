<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Controllers;

use Inc\Modules\VideoLibrary\Callbacks\VideoLibraryCallbacks;
use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\VideoLibrary\Services\RecordingAlertService;
use Inc\Modules\VideoLibrary\Services\S3UrlSigner;

/**
 * Class VideoLibraryController
 *
 * Рантайм-хуки модуля (только при включённом флаге):
 *  - подписчик generic-фильтра ядра `fs_lms_recording_url` — превращает стабильный указатель
 *    `s3://{bucket}/{key}` из `group_lessons.recording_url` во временную presigned-ссылку
 *    (регистрируется только при заполненных S3-константах — graceful absence §4.5);
 *  - AJAX ручной привязки unmatched-записей (V9). Имена действий — константами
 *    (вне core AjaxHook — изоляция §4.6).
 *
 * @package Inc\Modules\VideoLibrary\Controllers
 */
class VideoLibraryController {

	public const LESSONS_ACTION = 'fs_lms_video_lessons';
	public const ATTACH_ACTION  = 'fs_lms_video_attach';
	public const DETACH_ACTION  = 'fs_lms_video_detach';

	/** Алёрт «занятие прошло, записи нет» + ручная вставка ссылки (З3). */
	public const PENDING_ACTION = 'fs_lms_video_pending';
	public const SET_URL_ACTION = 'fs_lms_video_set_url';

	/** Привязка записи преподавателем из шага «Трансляция» плеера. */
	public const TEACHER_ATTACH_ACTION = 'fs_lms_video_teacher_attach';

	public function __construct(
		private readonly VideoLibraryCallbacks $callbacks,
		private readonly S3UrlSigner           $signer,
		private readonly VideoLibraryConfig    $config,
		private readonly RecordingAlertService $alerts,
	) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::LESSONS_ACTION, array( $this->callbacks, 'ajaxLessons' ) );
		add_action( 'wp_ajax_' . self::ATTACH_ACTION, array( $this->callbacks, 'ajaxAttach' ) );
		add_action( 'wp_ajax_' . self::DETACH_ACTION, array( $this->callbacks, 'ajaxDetach' ) );
		add_action( 'wp_ajax_' . self::PENDING_ACTION, array( $this->callbacks, 'ajaxPendingRecordings' ) );
		add_action( 'wp_ajax_' . self::SET_URL_ACTION, array( $this->callbacks, 'ajaxSetLessonRecordingUrl' ) );

		add_action( 'wp_ajax_' . self::TEACHER_ATTACH_ACTION, array( $this->callbacks, 'ajaxTeacherAttach' ) );
		// Шов для плеера: ядро спрашивает, какие записи можно привязать занятию, и о модуле не знает.
		add_filter( 'fs_lms_recording_candidates', array( $this, 'filterRecordingCandidates' ), 10, 2 );

		// Без S3-ключей указатель s3://… не подписать — фильтр не регистрируем,
		// guard в StepContentRenderer скроет запись из плеера.
		if ( $this->config->s3Ready() ) {
			add_filter( 'fs_lms_recording_url', array( $this, 'filterRecordingUrl' ), 10, 2 );
		}
	}

	/**
	 * Подходящие занятию непривязанные записи для выбора преподавателем в шаге «Трансляция».
	 *
	 * @param mixed $picker Прежнее значение фильтра (null — модуль не отвечал)
	 *
	 * @return array{candidates: array<int, array{id: int, label: string}>, action: string, nonce: string}|null
	 */
	public function filterRecordingCandidates( mixed $picker, mixed $groupLesson = null ): mixed {
		if ( ! $groupLesson instanceof GroupLessonDTO ) {
			return $picker;
		}

		$candidates = array();
		foreach ( $this->alerts->candidatesFor( $groupLesson ) as $recording ) {
			$candidates[] = array(
				'id'    => $recording->id,
				'label' => trim( (string) $recording->recordedAt . ' · ' . basename( $recording->s3Key ) ),
			);
		}

		return array(
			'candidates' => $candidates,
			'action'     => self::TEACHER_ATTACH_ACTION,
			'nonce'      => Nonce::SaveSchedule->create(),
		);
	}

	/**
	 * `s3://{bucket}/{key}` → presigned https; прочие значения (прямые URL, null) — как есть.
	 */
	public function filterRecordingUrl( ?string $url, mixed $groupLesson = null ): ?string {
		if ( null === $url || ! preg_match( '#^s3://([^/]+)/(.+)$#', $url, $m ) ) {
			return $url;
		}

		return $this->signer->presign( $m[1], $m[2] );
	}
}
