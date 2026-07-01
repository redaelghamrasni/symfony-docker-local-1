<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final class ArticleNotFoundListener implements EventSubscriberInterface
{
    public function __construct(
        private Environment $twig
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 100],  // ← Priorité TRÈS élevée
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        
        if (!$exception instanceof NotFoundHttpException) {
            return;
        }
        
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        
        if (!preg_match('#^/article/\d+#', $path)) {
            return;
        }
        
        preg_match('/\/article\/(\d+)/', $path, $matches);
        $articleId = $matches[1] ?? 'inconnu';
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>Article introuvable</title>
            <style>
                body { font-family: Arial; text-align: center; padding: 100px; background: #f5f5f5; }
                h1 { color: #dc3545; font-size: 48px; }
            </style>
        </head>
        <body>
            <h1>🚫 Article introuvable</h1>
            <p style="font-size: 20px;">URL demandée : ' . htmlspecialchars($path) . '</p>
            <a href="/articles" style="background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">
                Retour aux articles
            </a>
        </body>
        </html>';
        
        $response = new Response($html, Response::HTTP_NOT_FOUND);
        $event->setResponse($response);
        
        // ✨ CRUCIAL : Empêche les autres listeners de s'exécuter après
        $event->stopPropagation();
    }
}