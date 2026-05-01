<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;

class AuthCallbacks extends BaseController
{
    // === TEST === //
    public function renderAuthTestPage(): string {
        $settings = get_option( 'fs_lms_auth_settings', [] );
        $output = '<div class="lms-auth-test-wrapper" style="padding: 20px; border: 1px solid #ccc;">';
        $output .= '<h3>Тест авторизации</h3>';

        $providers = [
            'google' => 'Google',
            'vk'     => 'ВКонтакте',
            'github' => 'GitHub'
        ];

        foreach ( $providers as $id => $name ) {
            // Проверяем, включен ли провайдер в настройках
            if ( ! empty( $settings["{$id}_enabled"] ) ) {
                $login_url = home_url( "/lms-auth/login?provider={$id}" );
                $output .= sprintf(
                    '<p><a href="%s" class="button auth-btn-%s" style="display:inline-block; padding:10px 20px; background:#0073aa; color:#fff; text-decoration:none; border-radius:4px; margin-bottom:5px;">Войти через %s</a></p>',
                    esc_url( $login_url ),
                    esc_attr( $id ),
                    esc_html( $name )
                );
            }
        }

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $output .= '<hr><p style="color: green;">Вы сейчас авторизованы как: <strong>' . $user->display_name . '</strong></p>';
            $output .= '<p><a href="' . wp_logout_url( get_permalink() ) . '">Выйти</a></p>';
        }

        $output .= '</div>';

        return $output;
    }

}