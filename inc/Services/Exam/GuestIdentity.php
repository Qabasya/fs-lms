<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Services\Security\PiiCryptoService;

/**
 * Хеши личности гостя без состояния (этап 11a.1.1).
 *
 * **Личность = ФИО и телефон вместе:** двое детей одного родителя с общим телефоном дают разные хеши и не склеиваются.
 * Хеш только по телефону или только по ФИО — подсказка сотруднику о возможном дубле, а не основание для отказа.
 * Нормализация: нижний регистр, `ё` → `е`, один пробел между словами; телефон — только цифры, начальная «8» → «7».
 */
class GuestIdentity {

	public function __construct(
		private readonly PiiCryptoService $crypto,
	) {}

	public function normalizeName( string $last, string $first, string $middle = '' ): string {
		$joined = mb_strtolower( implode( ' ', array( $last, $first, $middle ) ) );
		$joined = str_replace( 'ё', 'е', $joined );

		return trim( (string) preg_replace( '/\s+/u', ' ', $joined ) );
	}

	/** Телефон только цифрами; российский «8…» приводится к «7…». */
	public function normalizePhone( string $phone ): string {
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			$digits = '7' . substr( $digits, 1 );
		}

		return $digits;
	}

	public function nameHash( string $last, string $first, string $middle = '' ): string {
		return $this->crypto->hash( 'name:' . $this->normalizeName( $last, $first, $middle ) );
	}

	public function phoneHash( string $phone ): string {
		return $this->crypto->hash( 'phone:' . $this->normalizePhone( $phone ) );
	}

	/** Хеш пары «ФИО + телефон» — ключ единственной активной заявки на проведение. */
	public function identityHash( string $last, string $first, string $middle, string $phone ): string {
		return $this->crypto->hash( 'identity:' . $this->normalizeName( $last, $first, $middle ) . '|' . $this->normalizePhone( $phone ) );
	}
}
