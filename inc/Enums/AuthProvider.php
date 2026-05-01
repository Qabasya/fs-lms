<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum AuthProvider: string {
    case GOOGLE    = 'Google';
    case VK = 'VK';
    case GITHUB    = 'GitHub';

    /**
     * Возвращает человекочитаемое название для админки
     */
    public function label(): string {
        return match( $this ) {
            self::GOOGLE    => 'Google Auth',
            self::VK => 'ВКонтакте',
            self::GITHUB    => 'GitHub',
        };
    }
}