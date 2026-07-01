<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ErrorController extends AbstractController
{
    #[Route('/error/{status_code}', name: 'error_catcher')]
    public function error(FlattenException $exception): Response
    {
        $statusCode = $exception->getStatusCode();

        return $this->render("bundles/TwigBundle/Exception/error{$statusCode}.html.twig", [
            'status_code' => $statusCode,
            'status_text' => Response::$statusTexts[$statusCode] ?? 'Unknown Error',
        ], new Response('', $statusCode));
    }
}
