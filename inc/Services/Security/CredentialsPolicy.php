<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use DomainException;

/**
 * Class CredentialsPolicy
 *
 * Правила логина и пароля, которые ученик задаёт себе сам (форма заявки) и которые
 * администратор правит в заявке до зачисления.
 *
 * @package Inc\Services\Security
 *
 * ### Зеркало на клиенте:
 *
 * - логин — `LatinOnlyValidator` (`data-validate="latinOnly"`), длина — minlength/maxlength поля;
 * - пароль — `PasswordValidator` (`data-validate="password"`).
 *
 * Меняя состав символов здесь, меняйте его и в JS: клиентская проверка — только подсказка,
 * решает сервер.
 */
readonly class CredentialsPolicy {

	/** Логин: латиница, цифры, подчёркивание, 3–20 символов. */
	private const LOGIN_PATTERN = '/^[A-Za-z0-9_]{3,20}$/';

	/** Пароль: латиница, цифры и `_ % * ? ! № # @`, 3–16 символов (`u` — `№` многобайтный). */
	private const PASSWORD_PATTERN = '/^[A-Za-z0-9_%*?!№#@]{3,16}$/u';

	public const LOGIN_ERROR    = 'Логин: латиница, цифры и символ подчёркивания, от 3 до 20 символов.';
	public const PASSWORD_ERROR = 'Пароль: латиница, цифры и символы _ % * ? ! № # @, от 3 до 16 символов.';

	/**
	 * @param string $login Логин как пришёл из формы
	 *
	 * @throws DomainException Логин не соответствует правилу
	 */
	public function assertLogin( string $login ): void {
		if ( 1 !== preg_match( self::LOGIN_PATTERN, $login ) ) {
			throw new DomainException( self::LOGIN_ERROR );
		}
	}

	/**
	 * @param string $password Пароль без санитизации (только wp_unslash)
	 *
	 * @throws DomainException Пароль не соответствует правилу
	 */
	public function assertPassword( string $password ): void {
		if ( 1 !== preg_match( self::PASSWORD_PATTERN, $password ) ) {
			throw new DomainException( self::PASSWORD_ERROR );
		}
	}
}
