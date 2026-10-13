<?php

declare( strict_types=1 );

namespace Inc\Services\Lead;

use Inc\DTO\Lead\LeadInputDTO;
use Inc\Enums\Lead\LeadRejectReason;
use Inc\Enums\Lead\LeadVerdict;
use Inc\Repositories\WPDBRepositories\LeadRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class LeadService
 *
 * Приём заявок с лид-форм сайта: тема отдаёт сырой массив хуком
 * `fs_lms_theme_lead_submitted`, сервис проверяет его, шифрует имя и телефон
 * и складывает в `fs_lms_leads`. Тему и её классы не знает — только формат массива.
 *
 * @package Inc\Services\Lead
 */
class LeadService {

	public function __construct(
		private readonly LeadRepository  $leads,
		private readonly PiiCryptoService $crypto,
	) {}

	/**
	 * @param array<string, mixed> $lead Поля заявки (см. `fs_lms_theme_dispatch_lead()` в теме).
	 *
	 * @return int ID записи; 0 — заявка не распознана или пуста.
	 */
	public function record( array $lead ): int {
		$verdict = LeadVerdict::tryFrom( (string) ( $lead['verdict'] ?? '' ) );
		$name    = trim( (string) ( $lead['name'] ?? '' ) );
		$phone   = trim( (string) ( $lead['phone'] ?? '' ) );

		if ( null === $verdict || '' === $name || '' === $phone ) {
			return 0;
		}

		$reason = LeadRejectReason::tryFrom( (string) ( $lead['reason'] ?? '' ) );
		$ip     = $this->clip( (string) ( $lead['ip'] ?? '' ), 45 );
		$sentAt = (int) ( $lead['received_at'] ?? 0 );
		$mail   = $lead['mail_sent'] ?? null;

		return $this->leads->create( new LeadInputDTO(
			verdict:          $verdict->value,
			reason:           LeadVerdict::Rejected === $verdict ? ( $reason?->value ?? '' ) : '',
			nameEnc:          $this->crypto->encrypt( $this->clip( $name, 200 ) ),
			phoneEnc:         $this->crypto->encrypt( $this->clip( $phone, 50 ) ),
			formId:           $this->clip( (string) ( $lead['form_id'] ?? '' ), 30 ),
			pageUrl:          $this->clip( (string) ( $lead['page_url'] ?? '' ), 500 ),
			ip:               $ip,
			subnet:           $this->subnetOf( $ip ),
			userAgent:        $this->clip( (string) ( $lead['user_agent'] ?? '' ), 500 ),
			fillSeconds:      max( 0, (int) ( $lead['fill_seconds'] ?? 0 ) ),
			captcha:          $this->clip( (string) ( $lead['captcha'] ?? '' ), 12 ),
			captchaChallenge: ! empty( $lead['captcha_challenge'] ),
			mobile:           ! empty( $lead['mobile'] ),
			mailSent:         null === $mail ? null : (bool) $mail,
			receivedAt:       gmdate( 'Y-m-d H:i:s', $sentAt > 0 ? $sentAt : time() ),
		) );
	}

	/**
	 * Подсеть для группировки заявок: IPv4 → `a.b.c.0/24`, IPv6 → первые 64 бита.
	 * Пусто, если адрес не распознан.
	 */
	public function subnetOf( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- мусорный IP — штатный случай

		if ( false === $packed ) {
			return '';
		}

		if ( 4 === strlen( $packed ) ) {
			return sprintf( '%d.%d.%d.0/24', ord( $packed[0] ), ord( $packed[1] ), ord( $packed[2] ) );
		}

		$head = array_values( unpack( 'n4', substr( $packed, 0, 8 ) ) );

		return sprintf( '%x:%x:%x:%x::/64', ...$head );
	}

	private function clip( string $value, int $max ): string {
		return mb_substr( $value, 0, $max );
	}
}
