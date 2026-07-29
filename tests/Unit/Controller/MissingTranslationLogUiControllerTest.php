<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Controller;

use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\MissingTranslationLogTestEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[CoversClass(MissingTranslationLogUiController::class)]
final class MissingTranslationLogUiControllerTest extends TestCase
{
    private function createRepository(): MissingTranslationLogRepository
    {
        return MissingTranslationLogTestEntityManagerFactory::createRepository();
    }

    public function testIndexRendersList(): void
    {
        $repo = $this->createMock(MissingTranslationLogRepository::class);
        $repo->method('findByStatus')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>ok</html>');

        $container = new Container();
        $container->set('twig', $twig);

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $response = $controller->index(Request::create('/?status=pending'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('ok', (string) $response->getContent());
    }

    public function testMarkAddedRedirectsOnSuccess(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'm',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $id = $repo->findByStatus(MissingTranslationLogStatus::Pending, 5)[0]->getId();
        self::assertNotNull($id);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static function (CsrfToken $token): bool {
            return $token->getId() === 'missing_log_mark_added' && $token->getValue() === 'good';
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/missing-log/');

        $request = Request::create('/' . $id . '/mark-added', 'POST', ['_token' => 'good']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $response = $controller->markAdded((int) $id, $request);
        self::assertSame(302, $response->getStatusCode());

        $fresh = $repo->findOneById((int) $id);
        self::assertNotNull($fresh);
        self::assertSame(MissingTranslationLogStatus::Added, $fresh->getStatus());
    }

    public function testMarkAddedThrowsWhenCsrfInvalid(): void
    {
        $repo = $this->createRepository();

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $this->createMock(UrlGeneratorInterface::class));
        $container->set('request_stack', new RequestStack());

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $request = Request::create('/1/mark-added', 'POST', ['_token' => 'bad']);

        $this->expectException(AccessDeniedException::class);
        $controller->markAdded(1, $request);
    }

    public function testMarkAddedThrowsWhenRowMissing(): void
    {
        $repo = $this->createRepository();

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $this->createMock(UrlGeneratorInterface::class));
        $container->set('request_stack', new RequestStack());

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $request = Request::create('/99/mark-added', 'POST', ['_token' => 'ok']);

        $this->expectException(NotFoundHttpException::class);
        $controller->markAdded(99, $request);
    }

    public function testClearDeletesRowsAndRedirects(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'm.one',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
            'b' => [
                'hits'      => 1,
                'messageId' => 'm.two',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        self::assertGreaterThan(0, $repo->count([]));

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static function (CsrfToken $token): bool {
            return $token->getId() === 'missing_log_clear' && $token->getValue() === 'good';
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/missing-log/');

        $request = Request::create('/clear', 'POST', ['_token' => 'good', 'status' => 'pending']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $response = $controller->clear($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $repo->count([]));
    }

    public function testClearThrowsWhenCsrfInvalid(): void
    {
        $repo = $this->createRepository();

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $this->createMock(UrlGeneratorInterface::class));
        $container->set('request_stack', new RequestStack());

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $request = Request::create('/clear', 'POST', ['_token' => 'bad']);

        $this->expectException(AccessDeniedException::class);
        $controller->clear($request);
    }

    public function testClearStatusDeletesOnlyCurrentStatusAndRedirects(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'm.pending',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
            'b' => [
                'hits'      => 1,
                'messageId' => 'm.added',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $rows = $repo->findByStatus(MissingTranslationLogStatus::Pending, 10);
        self::assertCount(2, $rows);
        $rows[0]->setStatus(MissingTranslationLogStatus::Added);
        $repo->flush();
        $repo->clearManaged();

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static function (CsrfToken $token): bool {
            return $token->getId() === 'missing_log_clear_status' && $token->getValue() === 'good';
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/missing-log/');

        $request = Request::create('/clear-status', 'POST', ['_token' => 'good', 'status' => 'pending']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $response = $controller->clearStatus($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertCount(0, $repo->findByStatus(MissingTranslationLogStatus::Pending, 10));
        self::assertCount(1, $repo->findByStatus(MissingTranslationLogStatus::Added, 10));
    }

    public function testClearStatusThrowsWhenCsrfInvalid(): void
    {
        $repo = $this->createRepository();

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $this->createMock(UrlGeneratorInterface::class));
        $container->set('request_stack', new RequestStack());

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $request = Request::create('/clear-status', 'POST', ['_token' => 'bad', 'status' => 'pending']);

        $this->expectException(AccessDeniedException::class);
        $controller->clearStatus($request);
    }

    public function testIndexDeniesWhenAccessCheckerRejectsAnonymousUser(): void
    {
        $repo = $this->createMock(MissingTranslationLogRepository::class);
        $repo->expects(self::never())->method('findByStatus');

        $checker = $this->createMock(MissingLogUiAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn(null);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.token_storage', $storage);

        $controller = new MissingTranslationLogUiController($repo, $checker);
        $controller->setContainer($container);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('MissingLogUiAccessCheckerInterface');
        $controller->index(Request::create('/'));
    }

    public function testIndexAllowsWhenAccessCheckerAcceptsUser(): void
    {
        $repo = $this->createMock(MissingTranslationLogRepository::class);
        $repo->method('findByStatus')->willReturn([]);

        $user    = $this->createMock(UserInterface::class);
        $checker = $this->createMock(MissingLogUiAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->with($user)->willReturn(true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>ok</html>');

        $container = new Container();
        $container->set('twig', $twig);
        $container->set('security.token_storage', $storage);

        $controller = new MissingTranslationLogUiController($repo, $checker);
        $controller->setContainer($container);

        $response = $controller->index(Request::create('/'));
        self::assertSame(200, $response->getStatusCode());
    }
}
