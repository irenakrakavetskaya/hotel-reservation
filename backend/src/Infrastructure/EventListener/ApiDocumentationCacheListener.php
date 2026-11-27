<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ApiDocumentationCacheListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (in_array($event->getRequest()->attributes->get('_route'), ['api_doc_ui', 'api_doc_json'], true)) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
            $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
        }
    }
}
