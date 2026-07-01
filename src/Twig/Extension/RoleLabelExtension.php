<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class RoleLabelExtension extends AbstractExtension
{
    private const LABELS = [
        'ROLE_USER'  => 'User',
        'ROLE_ADMIN' => 'Administrator',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('role_label', $this->roleLabel(...)),
        ];
    }

    public function roleLabel(string $role): string
    {
        return self::LABELS[$role] ?? ucwords(strtolower(str_replace(['ROLE_', '_'], ['', ' '], $role)));
    }
}
