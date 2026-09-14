import { BaseValidator } from './BaseValidator.js';

/**
 * Пароль, который ученик задаёт себе в заявке.
 * Зеркало серверного правила Inc\Services\Security\CredentialsPolicy::PASSWORD_PATTERN.
 */
export class PasswordValidator extends BaseValidator {
    checkCustom(value, input) {
        const passwordRegex = /^[A-Za-z0-9_%*?!№#@]+$/u;

        // Проверяем значение как есть, без trim: пробел по краям сервер не примет.
        if (!passwordRegex.test(input.value)) {
            return 'Разрешены латиница, цифры и символы _ % * ? ! № # @';
        }

        return null;
    }
}
