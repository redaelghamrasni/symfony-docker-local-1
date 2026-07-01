<?php

namespace App\EventListener;

use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class EntityNotFoundListener implements EventSubscriberInterface
{
    public function __construct(
        private ContainerInterface $container,
        private Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 100],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $message = $exception->getMessage();

        $shouldHandle = false;

        // Catch Doctrine EntityNotFoundException
        if ($exception instanceof EntityNotFoundException) {
            $shouldHandle = true;
        }

        // Catch EntityValueResolver errors (from ArgumentResolver)
        if (str_contains($message, 'object not found by') && str_contains($message, 'EntityValueResolver')) {
            $shouldHandle = true;
        }

        if ($shouldHandle) {
            $statusCode = 404;
            $html = $this->twig->render('bundles/TwigBundle/Exception/error404.html.twig', [
                'status_code' => $statusCode,
                'status_text' => Response::$statusTexts[$statusCode] ?? 'Not Found',
            ]);

            $response = new Response($html, $statusCode);
            $event->setResponse($response);
        }
    }
}


