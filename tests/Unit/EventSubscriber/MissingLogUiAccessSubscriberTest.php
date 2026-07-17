<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(MissingLogUiAccessSubscriber::class)]
final class MissingLogUiAccessSubscriberTest extends TestCase
{
    public function testSkipsWhenRoleNotConfigured(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        $subscriber = new MissingLogUiAccessSubscriber(null, $checker);
        $event      = $this->controllerEvent('nowo_translation_yaml_tools_missing_log_index');

        $subscriber->onKernelController($event);
    }

    public function testGetSubscribedEvents(): void
    {
        self::assertArrayHasKey(KernelEvents::CONTROLLER, MissingLogUiAccessSubscriber::getSubscribedEvents());
        self::assertSame(['onKernelController', 0], MissingLogUiAccessSubscriber::getSubscribedEvents()[KernelEvents::CONTROLLER]);
    }

    public function testDeniesWhenAuthorizationCheckerMissing(): void
    {
        $subscriber = new MissingLogUiAccessSubscriber('ROLE_ADMIN');
        $event      = $this->controllerEvent('nowo_translation_yaml_tools_missing_log_index');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('security.authorization_checker is unavailable');

        $subscriber->onKernelController($event);
    }

    public function testSkipsForUnrelatedRoutes(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        $subscriber = new MissingLogUiAccessSubscriber('ROLE_ADMIN', $checker);
        $subscriber->onKernelController($this->controllerEvent('demo_home'));
    }

    public function testAllowsMissingLogRouteWhenGranted(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $subscriber = new MissingLogUiAccessSubscriber('ROLE_ADMIN', $checker);
        $subscriber->onKernelController($this->controllerEvent('nowo_translation_yaml_tools_missing_log_index'));
    }

    public function testDeniesMissingLogRouteWhenNotGranted(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $subscriber = new MissingLogUiAccessSubscriber('ROLE_ADMIN', $checker);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Missing-log UI requires role "ROLE_ADMIN".');

        $subscriber->onKernelController($this->controllerEvent('nowo_translation_yaml_tools_missing_log_mark_added'));
    }

    private function controllerEvent(string $route): ControllerEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
