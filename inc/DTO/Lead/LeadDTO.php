<?php

declare( strict_types=1 );

namespace Inc\DTO\Lead;

use Inc\Enums\Lead\LeadRejectReason;
use Inc\Enums\Lead\LeadVerdict;

/**
 * Строка таблицы заявок с сайта. Имя и телефон — зашифрованные blob'ы:
 * расшифровывает вызывающий код (шаблон вкладки), а не DTO.
 */
readonly class LeadDTO {

	public function __construct(
		public int               $id,
		public LeadVerdict       $verdict,
		public ?LeadRejectReason $reason,
		public string            $nameEnc,
		public string            $phoneEnc,
		public string            $formId,
		public string            $pageUrl,
		public string            $ip,
		public string            $subnet,
		public string            $userAgent,
		public int               $fillSeconds,
		public string            $captcha,
		public bool              $captchaChallenge,
		public bool              $mobile,
		public ?bool             $mailSent,
		public string            $receivedAt,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromArray( array $row ): self {
		return new self(
			id:               (int) ( $row['id'] ?? 0 ),
			verdict:          LeadVerdict::tryFrom( (string) ( $row['verdict'] ?? '' ) ) ?? LeadVerdict::Rejected,
			reason:           LeadRejectReason::tryFrom( (string) ( $row['reason'] ?? '' ) ),
			nameEnc:          (string) ( $row['name_enc'] ?? '' ),
			phoneEnc:         (string) ( $row['phone_enc'] ?? '' ),
			formId:           (string) ( $row['form_id'] ?? '' ),
			pageUrl:          (string) ( $row['page_url'] ?? '' ),
			ip:               (string) ( $row['ip'] ?? '' ),
			subnet:           (string) ( $row['subnet'] ?? '' ),
			userAgent:        (string) ( $row['user_agent'] ?? '' ),
			fillSeconds:      (int) ( $row['fill_seconds'] ?? 0 ),
			captcha:          (string) ( $row['captcha'] ?? '' ),
			captchaChallenge: ! empty( $row['captcha_challenge'] ),
			mobile:           ! empty( $row['is_mobile'] ),
			mailSent:         null === ( $row['mail_sent'] ?? null ) ? null : (bool) $row['mail_sent'],
			receivedAt:       (string) ( $row['received_at'] ?? '' ),
		);
	}
}
