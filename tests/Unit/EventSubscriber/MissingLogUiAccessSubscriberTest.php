<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\EventSubscriber;

use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(MissingLogUiAccessSubscriber::class)]
final class MissingLogUiAccessSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        self::assertArrayHasKey(KernelEvents::CONTROLLER, MissingLogUiAccessSubscriber::getSubscribedEvents());
        self::assertSame(['onKernelController', 0], MissingLogUiAccessSubscriber::getSubscribedEvents()[KernelEvents::CONTROLLER]);
    }

    public function testSkipsForUnrelatedRoutes(): void
    {
        $checker = $this->createMock(MissingLogUiAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $subscriber = new MissingLogUiAccessSubscriber($checker);
        $subscriber->onKernelController($this->controllerEvent('demo_home'));
    }

    public function testAllowsMissingLogRouteWhenCheckerPasses(): void
    {
        $user    = $this->createMock(UserInterface::class);
        $checker = $this->createMock(MissingLogUiAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->with($user)->willReturn(true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $subscriber = new MissingLogUiAccessSubscriber($checker, $storage);
        $subscriber->onKernelController($this->controllerEvent('nowo_translation_yaml_tools_missing_log_index'));
    }

    public function testDeniesAnonymousUser(): void
    {
        $checker = $this->createMock(MissingLogUiAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn(null);

        $subscriber = new MissingLogUiAccessSubscriber($checker, $storage);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('MissingLogUiAccessCheckerInterface');

        $subscriber->onKernelController($this->controllerEvent('nowo_translation_yaml_tools_missing_log_index'));
    }

    public function testDeniesWhenCheckerRejects(): void
    {
        $user    = $this->createMock(UserInterface::class);
        $checker = $this->createMock(MissingLogUiAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->with($user)->willReturn(false);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $subscriber = new MissingLogUiAccessSubscriber($checker, $storage);

        $this->expectException(AccessDeniedException::class);

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
