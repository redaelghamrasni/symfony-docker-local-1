<?php

namespace App\Traits;

trait FlashMessageTrait
{
    public function addFlashMessage(string $type, string $message): void
    {
        $this->addFlash($type, $message);
    }

    public function addSuccessMessage(string $message): void
    {
        $this->addFlash('success', $message);
    }

    public function addErrorMessage(string $message): void
    {
        $this->addFlash('error', $message);
    }

    public function addInfoMessage(string $message): void
    {
        $this->addFlash('info', $message);

    }

    public function addWarningMessage(string $message): void
    {
        $this->addFlash('warning', $message);
    }
}