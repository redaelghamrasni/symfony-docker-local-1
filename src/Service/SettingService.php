<?php

namespace App\Service;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

class SettingService
{
    public function __construct(
        private SettingRepository $repository,
        private EntityManagerInterface $em
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $setting = $this->repository->find($key);
        if ($setting === null || $setting->getValue() === null) {
            return $default;
        }
        return $setting->getValue();
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $val = $this->get($key);
        return $val !== null ? (float) $val : $default;
    }

    public function set(string $key, mixed $value, string $label = '', string $type = 'text'): void
    {
        $setting = $this->repository->find($key);
        if ($setting === null) {
            $setting = new Setting($key, $label ?: $key, $type);
            $this->em->persist($setting);
        }
        $setting->setValue($value !== null ? (string) $value : null);
        $this->em->flush();
    }

    /** @return Setting[] */
    public function all(): array
    {
        return $this->repository->findAll();
    }
}
