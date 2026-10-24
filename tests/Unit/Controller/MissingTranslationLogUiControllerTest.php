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
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
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
        $this->withForms($container);
        $controller->setContainer($container);

        $response = $controller->index(Request::create('/?status=pending'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('ok', (string) $response->getContent());
    }

    public function testIndexBuildsOneMarkAddedFormPerPendingRow(): void
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

        $context = [];
        $twig    = $this->createMock(Environment::class);
        $twig->expects(self::exactly(2))->method('render')->willReturnCallback(static function (string $name, array $vars) use (&$context): string {
            $context[] = $vars;

            return '<html>ok</html>';
        });

        $container = new Container();
        $container->set('twig', $twig);
        $this->withForms($container);

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $controller->index(Request::create('/?status=pending'));
        $controller->index(Request::create('/?status=added'));

        self::assertSame([$id], array_keys($context[0]['mark_added_forms']));
        $view = $context[0]['mark_added_forms'][$id];
        self::assertInstanceOf(FormView::class, $view);
        self::assertSame('missing_log_mark_added_' . $id, $view->vars['name']);
        self::assertArrayHasKey('_token', $view->children);
        self::assertInstanceOf(FormView::class, $context[0]['clear_status_form']);
        self::assertSame('pending', $context[0]['clear_status_form']->children['status']->vars['value']);
        self::assertSame([], $context[1]['mark_added_forms'], 'Only the pending list has row actions.');
        self::assertSame('added', $context[1]['clear_form']->children['status']->vars['value']);
    }

    public function testClearWithoutStatusFallsBackToPending(): void
    {
        $repo = $this->createRepository();

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => $route . '?' . http_build_query($parameters));

        $request = Request::create('/clear', 'POST', ['missing_log_clear_all' => ['_token' => 'any']]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('router', $router);
        $container->set('request_stack', new RequestStack([$request]));
        $this->withForms($container);

        $controller = new MissingTranslationLogUiController($repo);
        $controller->setContainer($container);

        $response = $controller->clear($request);
        self::assertSame('nowo_translation_yaml_tools_missing_log_index?status=pending', $response->getTargetUrl());
    }

    public function testActionWithoutFormDataIsDenied(): void
    {
        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $this->withForms($container);

        $controller = new MissingTranslationLogUiController($this->createRepository());
        $controller->setContainer($container);

        $this->expectException(AccessDeniedException::class);
        $controller->clearStatus(Request::create('/clear-status', 'POST', ['_token' => 'legacy-field-name']));
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

        $request = Request::create('/' . $id . '/mark-added', 'POST', ['missing_log_mark_added_' . $id => ['_token' => 'good']]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new MissingTranslationLogUiController($repo);
        $this->withForms($container);
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
        $this->withForms($container);
        $controller->setContainer($container);

        $request = Request::create('/1/mark-added', 'POST', ['missing_log_mark_added_1' => ['_token' => 'bad']]);

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
        $this->withForms($container);
        $controller->setContainer($container);

        $request = Request::create('/99/mark-added', 'POST', ['missing_log_mark_added_99' => ['_token' => 'ok']]);

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

        $request = Request::create('/clear', 'POST', ['missing_log_clear_all' => ['_token' => 'good', 'status' => 'pending']]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new MissingTranslationLogUiController($repo);
        $this->withForms($container);
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
        $this->withForms($container);
        $controller->setContainer($container);

        $request = Request::create('/clear', 'POST', ['missing_log_clear_all' => ['_token' => 'bad']]);

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

        $request = Request::create('/clear-status', 'POST', ['missing_log_clear_status' => ['_token' => 'good', 'status' => 'pending']]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('twig', $this->createMock(Environment::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new MissingTranslationLogUiController($repo);
        $this->withForms($container);
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
        $this->withForms($container);
        $controller->setContainer($container);

        $request = Request::create('/clear-status', 'POST', ['missing_log_clear_status' => ['_token' => 'bad', 'status' => 'pending']]);

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
        $this->withForms($container);
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
        $this->withForms($container);
        $controller->setContainer($container);

        $response = $controller->index(Request::create('/'));
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Registers a real form factory (CSRF extension backed by the container token manager, or an
     * always-valid one) and a router stub unless the test already set them.
     */
    private function withForms(Container $container): void
    {
        $csrf = $container->has('security.csrf.token_manager') ? $container->get('security.csrf.token_manager') : null;
        if (!$csrf instanceof CsrfTokenManagerInterface) {
            $csrf = $this->createStub(CsrfTokenManagerInterface::class);
            $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 'token-' . $id));
            $csrf->method('isTokenValid')->willReturn(true);
        }
        $container->set('form.factory', Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrf))
            ->getFormFactory());
        if (!$container->has('router')) {
            $router = $this->createStub(UrlGeneratorInterface::class);
            $router->method('generate')->willReturn('/missing-log/action');
            $container->set('router', $router);
        }
    }
}
