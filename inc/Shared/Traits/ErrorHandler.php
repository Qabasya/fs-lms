<?php

namespace Inc\Shared\Traits;

use Inc\Enums\Capability;
use Throwable;
use Inc\Enums\AuthProvider;

trait ErrorHandler
{
    /**
     * Отправка ошибки с автоматическим определением формата ответа.
     *
     * @param string $code Код ошибки для машинной обработки
     * @param string $message Сообщение для пользователя (безопасное)
     * @param int $status HTTP-статус
     * @param Capability|null $required_capability Проверка прав перед отправкой (опционально)
     * @return never
     */
    protected function sendError(
        string      $code,
        string      $message,
        int         $status = 400,
        ?Capability $required_capability = null
    ): void
    {
        // Проверка прав, если указана
        if ($required_capability && !current_user_can($required_capability->value)) {
            $status = 403;
            $message = 'Доступ запрещён';
        }

        // Логирование для разработчиков (не попадает в UI)
        $this->logErrorInternal($code, $message, $status);

        // AJAX-ответ
        if (wp_doing_ajax()) {
            wp_send_json_error(
                ['code' => $code, 'message' => $message],
                $status
            );
        }

        // Обычный HTML-ответ
        wp_die(
            esc_html($message),
            'LMS Error',
            ['response' => $status, 'back_link' => true]
        );
    }

    /**
     * Публичный метод для логирования исключений с контекстом.
     */
    protected function logException(Throwable $e, array $context = []): void
    {
        $this->logErrorInternal(
            'exception',
            $e->getMessage(),
            500,
            array_merge($context, [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null,
            ])
        );
    }

    /**
     * Удобный метод для логирования ошибок авторизации.
     */
    protected function logAuthError(Throwable $e, AuthProvider $provider): void
    {
        $this->logException($e, [
            'provider' => $provider->value,
            'component' => 'auth',
        ]);
    }

    /**
     * Внутренний метод логирования.
     * Не вызывает завершения скрипта — только запись в лог.
     */
    private function logErrorInternal(
        string $code,
        string $message,
        int    $status,
        array  $context = []
    ): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_data = array_merge([
            'timestamp' => current_time('mysql'),
            'code' => $code,
            'status' => $status,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], $context);

        // Формируем читаемое сообщение
        $log_message = sprintf(
            '[LMS] %s: %s | Context: %s',
            strtoupper($code),
            $message,
            json_encode($log_data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        // Запись в стандартный лог WordPress
        error_log($log_message);

    }
}