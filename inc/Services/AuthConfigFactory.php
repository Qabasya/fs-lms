<?php

namespace Inc\Services;

use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;

class AuthConfigFactory
{
    public function __construct(
        private readonly SettingsRepository $settings_repo
    ) {}

    public function make(?AuthProvider $provider = null): array
    {
        return [
            'callback'   => $this->buildCallback($provider),
            'debug_mode' => $this->isDebugMode(),
            'debug_file' => $this->getDebugFile(),
            'providers'  => $this->buildProvidersConfig(),
        ];
    }

    private function buildCallback(?AuthProvider $provider): string
    {
        $base = home_url('/lms-auth/callback');

        if (!$provider) {
            return $base;
        }

        return add_query_arg(
            'provider',
            strtolower($provider->value),
            $base
        );
    }

    private function isDebugMode(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    private function getDebugFile(): string
    {
        return WP_CONTENT_DIR . '/hybridauth.log';
    }

    private function buildProvidersConfig(): array
    {
        $settings = $this->settings_repo->readAll();
        $result = [];

        foreach (AuthProvider::cases() as $provider) {
            $key = $provider->configKey();

            $result[$provider->hybridauthKey()] = $this->buildProvider($key, $settings);
        }

        return $result;
    }

    private function buildProvider(string $key, array $settings): array
    {
        return [
            'enabled' => !empty($settings[$key . '_enabled']),
            'keys' => [
                'id'     => $settings[$key . '_id'] ?? '',
                'secret' => $settings[$key . '_secret'] ?? '',
            ],
        ];
    }
}