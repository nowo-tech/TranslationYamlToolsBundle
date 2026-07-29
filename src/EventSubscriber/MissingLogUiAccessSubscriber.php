<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_object;
use function sprintf;

/**
 * Enforces missing-log Web UI access via MissingLogUiAccessCheckerInterface (REQ-UI-002).
 */
final readonly class MissingLogUiAccessSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_translation_yaml_tools_missing_log_';

    public function __construct(
        private MissingLogUiAccessCheckerInterface $accessChecker,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if ($route === null || !str_starts_with((string) $route, self::ROUTE_PREFIX)) {
            return;
        }

        $token = $this->tokenStorage?->getToken();
        $user  = $token?->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw new AccessDeniedException(sprintf('Missing-log UI requires an authenticated user allowed by %s.', MissingLogUiAccessCheckerInterface::class));
        }
    }
}
