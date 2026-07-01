<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettingRepository::class)]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', length: 100)]
    private string $key;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 50)]
    private string $type = 'text';

    public function __construct(string $key, string $label, string $type = 'text')
    {
        $this->key   = $key;
        $this->label = $label;
        $this->type  = $type;
    }

    public function getKey(): string { return $this->key; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): self { $this->value = $value; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
}
