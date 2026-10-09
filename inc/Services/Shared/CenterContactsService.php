<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

/**
 * Контакты центра для публичных страниц экзаменов (этап 11a.6.3).
 *
 * Значения отдаёт тема через фильтр {@see self::FILTER} (ядро тему не знает); пока тема не подписана или вернула пустое значение,
 * работают запасные тексты из настроек плагина ({@see PluginConfig::centerContactsFallback()}).
 */
readonly class CenterContactsService {

	public const FILTER = 'fs_lms_center_contacts';

	public function __construct(
		private PluginConfig $config,
	) {}

	/**
	 * @return array{phone: string, email: string, hours: string, city: string, street: string}
	 */
	public function get(): array {
		$fallback = $this->config->centerContactsFallback();
		$defaults = array(
			'phone'  => $fallback['phone'],
			'email'  => $fallback['email'],
			'hours'  => $fallback['hours'],
			'city'   => '',
			'street' => $fallback['address'],
		);

		$filtered = apply_filters( self::FILTER, $defaults );
		$filtered = is_array( $filtered ) ? $filtered : array();

		$result = array();
		foreach ( $defaults as $key => $fallbackValue ) {
			$value          = trim( (string) ( $filtered[ $key ] ?? '' ) );
			$result[ $key ] = '' !== $value ? $value : $fallbackValue;
		}

		return $result;
	}

	/**
	 * Адрес без номера кабинета: «Калининград, ул. Черняховского, д. 6». Номер кабинета берётся из сеанса, не отсюда.
	 */
	public function addressWithoutRoom(): string {
		$contacts = $this->get();
		$street   = trim( (string) preg_split( '/,?\s*каб\./u', $contacts['street'] )[0] );

		return implode( ', ', array_filter( array( $contacts['city'], $street ), static fn ( string $part ): bool => '' !== $part ) );
	}
}
