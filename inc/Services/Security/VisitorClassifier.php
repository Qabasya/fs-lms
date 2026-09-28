<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

/**
 * Class VisitorClassifier
 *
 * Кто открыл ссылку — по User-Agent, для журналов.
 *
 * @package Inc\Services\Security
 *
 * Ссылка, отправленная в мессенджер или письмо, открывается ещё до человека:
 * Telegram, WhatsApp, VK, почтовые сервисы запрашивают страницу своими серверами
 * ради превью и проверки на вредоносность — отсюда «открытия» с чужих IP.
 * Такие визиты JS не выполняют, поэтому событий формы после них не бывает.
 * Определение эвристическое: UA можно подделать, для журнала этого достаточно.
 */
readonly class VisitorClassifier {

	/** Подстрока UA (без учёта регистра) → сервис. Порядок важен: частные — раньше общих. */
	private const PREVIEW_BOTS = array(
		'TelegramBot'         => 'Telegram',
		'WhatsApp'            => 'WhatsApp',
		'vkShare'             => 'ВКонтакте',
		'Viber'               => 'Viber',
		'facebookexternalhit' => 'Facebook',
		'Twitterbot'          => 'X (Twitter)',
		'Slackbot'            => 'Slack',
		'Discordbot'          => 'Discord',
		'SkypeUriPreview'     => 'Skype',
		'Mail.RU_Bot'         => 'Mail.ru',
		'YandexBot'           => 'Яндекс',
		'YandexMessenger'     => 'Яндекс Мессенджер',
		'Yandex'              => 'Яндекс',
		'Googlebot'           => 'Google',
		'Google-'             => 'Google',
		'bingbot'             => 'Bing',
		'curl/'               => 'curl',
		'python-requests'     => 'скрипт (python)',
		'HeadlessChrome'      => 'headless-браузер',
		'bot'                 => 'бот',
		'crawler'             => 'бот',
		'spider'              => 'бот',
		'preview'             => 'превью',
	);

	/**
	 * Сервис превью/бот, открывший ссылку; null — похоже на человека.
	 *
	 * @param string $userAgent User-Agent запроса
	 */
	public function previewBot( string $userAgent ): ?string {
		if ( '' === trim( $userAgent ) ) {
			return 'без User-Agent';
		}

		foreach ( self::PREVIEW_BOTS as $needle => $name ) {
			if ( false !== stripos( $userAgent, $needle ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Короткое описание устройства и браузера: «Android, Яндекс Браузер».
	 *
	 * @param string $userAgent User-Agent запроса
	 */
	public function device( string $userAgent ): string {
		$platform = match ( true ) {
			(bool) preg_match( '/iPhone|iPad|iPod/i', $userAgent ) => 'iOS',
			(bool) preg_match( '/Android/i', $userAgent )          => 'Android',
			(bool) preg_match( '/Windows/i', $userAgent )          => 'Windows',
			(bool) preg_match( '/Macintosh|Mac OS X/i', $userAgent ) => 'Mac',
			(bool) preg_match( '/Linux/i', $userAgent )            => 'Linux',
			default                                                => 'неизвестная ОС',
		};

		$browser = match ( true ) {
			(bool) preg_match( '/Telegram/i', $userAgent )       => 'встроенный браузер Telegram',
			(bool) preg_match( '/WhatsApp/i', $userAgent )       => 'встроенный браузер WhatsApp',
			(bool) preg_match( '/VKClient|vkontakte/i', $userAgent ) => 'встроенный браузер VK',
			(bool) preg_match( '/YaBrowser|YaApp|YaSearchBrowser/i', $userAgent ) => 'Яндекс Браузер',
			(bool) preg_match( '/MailRuSputnik|MRCHROME|mail\.ru/i', $userAgent ) => 'браузер Mail.ru',
			(bool) preg_match( '/Edg\//', $userAgent )           => 'Edge',
			(bool) preg_match( '/OPR\/|Opera/', $userAgent )     => 'Opera',
			(bool) preg_match( '/SamsungBrowser/', $userAgent )  => 'Samsung Internet',
			(bool) preg_match( '/Firefox|FxiOS/', $userAgent )   => 'Firefox',
			(bool) preg_match( '/CriOS|Chrome/', $userAgent )    => 'Chrome',
			(bool) preg_match( '/Safari/', $userAgent )          => 'Safari',
			default                                              => 'неизвестный браузер',
		};

		return $platform . ', ' . $browser;
	}
}
