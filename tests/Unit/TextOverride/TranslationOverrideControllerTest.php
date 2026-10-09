<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Nowo\TranslationYamlToolsBundle\TextOverride\EditableTranslationKeys;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\StripTagsTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideLocales;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

#[CoversClass(TranslationOverrideController::class)]
final class TranslationOverrideControllerTest extends TestCase
{
    public function testDeniesWithoutSecurityBundle(): void
    {
        $controller = $this->controller($this->createMock(TranslationOverrideRepository::class), ['ROLE_ADMIN']);
        $controller->setContainer(new Container());

        $this->expectException(AccessDeniedException::class);
        $controller->index(Request::create('/'));
    }

    public function testDeniesWhenNoRoleIsGrantedOrRolesAreEmpty(): void
    {
        foreach ([['ROLE_ADMIN', 'ROLE_EDITOR'], []] as $roles) {
            $checker = $this->createMock(AuthorizationCheckerInterface::class);
            $checker->method('isGranted')->willReturn(false);
            $container = new Container();
            $container->set('security.authorization_checker', $checker);
            $controller = $this->controller($this->createMock(TranslationOverrideRepository::class), $roles);
            $controller->setContainer($container);

            try {
                $controller->index(Request::create('/'));
                self::fail('Expected access denied.');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGrantedEditorSaveRecordsUserIdentifier(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_EDITOR');
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana@example.com', null, ['ROLE_EDITOR']), 'main', ['ROLE_EDITOR']));
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/texts/edit?key=site.a');
        $request = Request::create('/edit?key=site.a', 'POST', ['_token' => 'ok', 'texts' => ['en' => 'Edited']]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack([$request]);

        $container = new Container();
        $container->set('security.authorization_checker', $checker);
        $container->set('security.token_storage', $tokens);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $this->createStub(Environment::class));

        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('findOne')->willReturn(null);
        $repository->expects(self::once())->method('persist')->with(self::callback(
            static fn ($row): bool => $row->getUpdatedBy() === 'ana@example.com' && $row->getValue() === 'Edited',
        ));
        $controller = $this->controller($repository, ['ROLE_ADMIN', 'ROLE_EDITOR']);
        $controller->setContainer($container);

        $response = $controller->edit($request);
        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @param list<string> $roles
     */
    private function controller(TranslationOverrideRepository $repository, array $roles): TranslationOverrideController
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['site.a' => 'A'], 'en');
        $overrides = new TranslationOverrides($repository, new ArrayAdapter(), new StripTagsTranslationOverrideHtmlSanitizer());
        $locales   = new TranslationOverrideLocales(new TranslationDefaultLocaleResolver(new ParameterBag(['kernel.default_locale' => 'en'])));

        return new TranslationOverrideController(
            $overrides,
            new TranslationOverrideEditor($overrides, $translator, $locales),
            new EditableTranslationKeys(['messages' => ['site.']]),
            $translator,
            'layout.html.twig',
            $roles,
        );
    }
}
