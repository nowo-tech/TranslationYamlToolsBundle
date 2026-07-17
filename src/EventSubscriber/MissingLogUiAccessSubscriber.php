<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function sprintf;

/**
 * Requires a configured role for missing-log Web UI routes when enabled.
 */
final readonly class MissingLogUiAccessSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_translation_yaml_tools_missing_log_';

    public function __construct(
        private ?string $requiredRole,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
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
        if ($this->requiredRole === null || $this->requiredRole === '') {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if ($route === null || !str_starts_with((string) $route, self::ROUTE_PREFIX)) {
            return;
        }

        if (!$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            throw new AccessDeniedException(sprintf('Missing-log UI requires role "%s" but security.authorization_checker is unavailable.', $this->requiredRole));
        }

        if (!$this->authorizationChecker->isGranted($this->requiredRole)) {
            throw new AccessDeniedException(sprintf('Missing-log UI requires role "%s".', $this->requiredRole));
        }
    }
}
