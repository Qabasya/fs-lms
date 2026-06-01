import { BaseValidator } from './BaseValidator.js';

export class PhoneValidator extends BaseValidator {
    /**
     * Кастомная проверка специфики номера телефона
     * @param {string} value - Текущее значение инпута
     * @returns {string|null} - Текст ошибки или null, если всё ок
     */
    checkCustom(value) {
        // Очищаем от маски, оставляем только цифры
        const digits = value.replace(/\D/g, '');

        // 1. Проверяем на пустоту, если поле обязательное (required обрабатывается базовым классом,
        // но если начали вводить и бросили — отловим здесь)
        if (digits.length === 0) {
            return 'Поле обязательно для заполнения';
        }

        // 2. Проверяем длину чистых цифр для РФ номера (должно быть ровно 11)
        if (digits.length < 11) {
            return `Номер введен не полностью. Ожидается 11 цифр (введено: ${digits.length}).`;
        }

        // 3. Проверка регулярным выражением структуры маски
        const phoneRegex = /^\+7\(\d{3}\)-\d{3}-\d{2}-\d{2}$/;
        if (!phoneRegex.test(value)) {
            return 'Используйте формат: +7(999)-000-00-00';
        }

        return null;
    }
}