<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum AuthProvider: string {
    case GOOGLE    = 'Google';
    case VKONTAKTE = 'VK';
    case GITHUB    = 'Github';

    /**
     * Возвращает человекочитаемое название для админки
     */
    public function label(): string {
        return match( $this ) {
            self::GOOGLE    => 'Google Auth',
            self::VKONTAKTE => 'ВКонтакте',
            self::GITHUB    => 'Github',
        };
    }
    public static function fromRequest(string $value): ?self
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'google' => self::GOOGLE,
            'vk', 'vkontakte', 'vk.com' => self::VKONTAKTE,
            'github', 'git hub' => self::GITHUB,
            default => null,
        };
    }

    public function configKey(): string
    {
        return match ($this) {
            self::GOOGLE => 'google',
            self::VKONTAKTE => 'vk',
            self::GITHUB => 'github',
        };
    }
}