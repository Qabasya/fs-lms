<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\DTO\Log\LoginDiagnosticsDTO;
use Inc\DTO\Person\LoginAttemptDTO;
use Inc\Enums\Auth\LoginFailReason;
use Inc\Managers\Person\UserManager;
use WP_Error;
use WP_User;

/**
 * Class LoginDiagnosticsService
 *
 * Разбирает неудачный вход для журнала аутентификации: почему отказано и что
 * именно ввёл пользователь.
 *
 * @package Inc\Services\Security
 *
 * ### Что попадает в подробности:
 *
 * логин как набран (если sanitize_user его изменил), чем найден аккаунт (логин/email),
 * ID и роли, длина пароля, кириллица в пароле, форма входа, пришёл ли токен капчи,
 * код ошибки WP. **Пароль не сохраняется ни в каком виде.**
 *
 * ### Проверка «пароль почти верный»:
 *
 * При неверном пароле существующего аккаунта введённый пароль проверяется ещё в
 * трёх вариантах — с инвертированным регистром (Caps Lock), с другим регистром первой
 * буквы (автозаглавная на телефоне) и в английской раскладке. Совпадение ничего не
 * открывает — вход всё равно отклонён, — только уточняет причину в журнале. Цена —
 * до трёх лишних проверок хеша, и только на неудачах, которые и так ограничены
 * {@see RateLimitService} (3 попытки за 15 минут).
 */
readonly class LoginDiagnosticsService {

	/** ЙЦУКЕН → QWERTY: только буквы, пунктуация в раскладках неоднозначна. */
	private const LAYOUT_MAP = array(
		'й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't', 'н' => 'y', 'г' => 'u',
		'ш' => 'i', 'щ' => 'o', 'з' => 'p', 'х' => '[', 'ъ' => ']', 'ф' => 'a', 'ы' => 's',
		'в' => 'd', 'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k', 'д' => 'l',
		'ж' => ';', 'э' => "'", 'я' => 'z', 'ч' => 'x', 'с' => 'c', 'м' => 'v', 'и' => 'b',
		'т' => 'n', 'ь' => 'm', 'б' => ',', 'ю' => '.', 'ё' => '`',
		'Й' => 'Q', 'Ц' => 'W', 'У' => 'E', 'К' => 'R', 'Е' => 'T', 'Н' => 'Y', 'Г' => 'U',
		'Ш' => 'I', 'Щ' => 'O', 'З' => 'P', 'Х' => '{', 'Ъ' => '}', 'Ф' => 'A', 'Ы' => 'S',
		'В' => 'D', 'А' => 'F', 'П' => 'G', 'Р' => 'H', 'О' => 'J', 'Л' => 'K', 'Д' => 'L',
		'Ж' => ':', 'Э' => '"', 'Я' => 'Z', 'Ч' => 'X', 'С' => 'C', 'М' => 'V', 'И' => 'B',
		'Т' => 'N', 'Ь' => 'M', 'Б' => '<', 'Ю' => '>', 'Ё' => '~',
	);

	public function __construct(
		private UserManager $users,
	) {}

	/**
	 * @param LoginAttemptDTO $attempt  Что пришло из формы
	 * @param string          $username Логин после sanitize_user (аргумент wp_login_failed)
	 * @param WP_Error|null   $error    Причина отказа от WP / LoginGuardService
	 *
	 * @return LoginDiagnosticsDTO
	 */
	public function diagnose( LoginAttemptDTO $attempt, string $username, ?WP_Error $error ): LoginDiagnosticsDTO {
		$code     = null !== $error ? (string) $error->get_error_code() : '';
		$password = trim( $attempt->password ); // WP проверяет пароль после trim()
		$rawLogin = trim( $attempt->login );

		[ $user, $foundBy ] = $this->findUser( '' !== $username ? $username : $rawLogin );

		$details = array(
			'error_code'        => $code,
			'form'              => $attempt->fromSignInPage ? 'sign_in' : 'wp_login',
			'captcha_token'     => '' !== $attempt->captchaToken,
			'account'           => $foundBy,
			'password_length'   => mb_strlen( $password ),
			'password_cyrillic' => $this->hasCyrillic( $password ),
		);

		if ( '' !== $rawLogin && $rawLogin !== $username ) {
			$details['login_raw'] = $rawLogin;
		}

		if ( null !== $user ) {
			$details['user_id'] = $user->ID;
			$details['roles']   = array_values( $user->roles );
		}

		return new LoginDiagnosticsDTO( $this->reason( $code, $attempt, $user, $rawLogin, $password ), $details );
	}

	private function reason( string $code, LoginAttemptDTO $attempt, ?WP_User $user, string $rawLogin, string $password ): LoginFailReason {
		return match ( $code ) {
			LoginGuardService::CODE_CAPTCHA => '' === $attempt->captchaToken
				? LoginFailReason::CaptchaMissing
				: LoginFailReason::CaptchaRejected,
			LoginGuardService::CODE_LOCKED  => LoginFailReason::Locked,
			'invalid_username', 'invalid_email' => $this->hasCyrillic( $rawLogin )
				&& null !== $this->findUser( $this->toLatinLayout( $rawLogin ) )[0]
				? LoginFailReason::LoginLayout
				: LoginFailReason::UnknownLogin,
			'incorrect_password' => null !== $user
				? $this->nearMissReason( $user, $password )
				: LoginFailReason::WrongPassword,
			default => LoginFailReason::Other,
		};
	}

	private function nearMissReason( WP_User $user, string $password ): LoginFailReason {
		if ( '' === $password ) {
			return LoginFailReason::WrongPassword;
		}

		$swapped = $this->swapCase( $password );
		if ( $swapped !== $password && $this->users->checkPassword( $user, $swapped ) ) {
			return LoginFailReason::CapsLock;
		}

		$first = $this->flipFirstLetter( $password );
		if ( $first !== $password && $this->users->checkPassword( $user, $first ) ) {
			return LoginFailReason::FirstLetterCase;
		}

		if ( $this->hasCyrillic( $password ) && $this->users->checkPassword( $user, $this->toLatinLayout( $password ) ) ) {
			return LoginFailReason::PasswordLayout;
		}

		return LoginFailReason::WrongPassword;
	}

	/**
	 * @param string $login Логин или email
	 *
	 * @return array{0: WP_User|null, 1: string} Пользователь и чем найден: login | email | none
	 */
	private function findUser( string $login ): array {
		if ( '' === $login ) {
			return array( null, 'none' );
		}

		$user = $this->users->findByLogin( $login );
		if ( null !== $user ) {
			return array( $user, 'login' );
		}

		$user = str_contains( $login, '@' ) ? $this->users->findByEmail( $login ) : null;

		return null !== $user ? array( $user, 'email' ) : array( null, 'none' );
	}

	private function hasCyrillic( string $value ): bool {
		return 1 === preg_match( '/\p{Cyrillic}/u', $value );
	}

	private function toLatinLayout( string $value ): string {
		return strtr( $value, self::LAYOUT_MAP );
	}

	private function swapCase( string $value ): string {
		$result = '';
		foreach ( mb_str_split( $value ) as $char ) {
			$upper   = mb_strtoupper( $char );
			$result .= $upper === $char ? mb_strtolower( $char ) : $upper;
		}

		return $result;
	}

	private function flipFirstLetter( string $value ): string {
		$first = mb_substr( $value, 0, 1 );
		$rest  = mb_substr( $value, 1 );
		$flip  = mb_strtoupper( $first ) === $first ? mb_strtolower( $first ) : mb_strtoupper( $first );

		return $flip . $rest;
	}
}
