<?php

namespace Inc\Services;

use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;
use Inc\Shared\Traits\Sanitizer;

class ProviderResolver
{
    use Sanitizer;

    public function __construct(
        private readonly SettingsRepository $settings_repo
    )
    {
    }

    public function fromRequest(): ?AuthProvider
    {
        $provider = $this->sanitizeText('provider', 'GET');

        if ($provider === '') {
            return null;
        }

        return AuthProvider::fromRequest($provider);
    }

    public function fromCallback(): ?AuthProvider
    {
        $provider = $this->fromRequest();

        if ($provider) {
            return $provider;
        }

        if (!$this->sanitizeBool('code', 'GET') && !isset($_GET['code'])) {
            return null;
        }

        foreach (AuthProvider::cases() as $case) {
            if ($this->settings_repo->isProviderEnabled($case->value)) {
                return $case;
            }
        }

        return null;
    }
}