<?php

declare( strict_types=1 );

namespace Inc\DTO\Lead;

/**
 * Заявка лид-формы сайта, готовая к записи: ПД уже зашифрованы.
 */
readonly class LeadInputDTO {

	public function __construct(
		public string  $verdict,
		public string  $reason,
		public string  $nameEnc,
		public string  $phoneEnc,
		public string  $formId,
		public string  $pageUrl,
		public string  $ip,
		public string  $subnet,
		public string  $userAgent,
		public int     $fillSeconds,
		public string  $captcha,
		public bool    $captchaChallenge,
		public bool    $mobile,
		public ?bool   $mailSent,
		public string  $receivedAt,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'verdict'           => $this->verdict,
			'reason'            => $this->reason,
			'name_enc'          => $this->nameEnc,
			'phone_enc'         => $this->phoneEnc,
			'form_id'           => $this->formId,
			'page_url'          => $this->pageUrl,
			'ip'                => $this->ip,
			'subnet'            => $this->subnet,
			'user_agent'        => $this->userAgent,
			'fill_seconds'      => $this->fillSeconds,
			'captcha'           => $this->captcha,
			'captcha_challenge' => $this->captchaChallenge ? 1 : 0,
			'is_mobile'         => $this->mobile ? 1 : 0,
			'mail_sent'         => null === $this->mailSent ? null : ( $this->mailSent ? 1 : 0 ),
			'received_at'       => $this->receivedAt,
		);
	}
}
