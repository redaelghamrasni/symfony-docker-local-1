<?php

namespace App\Service;

final class GreetingService
{
    public function greet(string $name, string $lang = 'en'): string
    {
        if ($lang === 'fr') {
            return 'Bonjour' . $name;
        } 
        else {
            return 'Hello' . $name;
        }
    }
}