<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\FormCsrfRequirement;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(MissingLogWebUiSecurityPass::class)]
#[CoversClass(FormCsrfRequirement::class)]
final class MissingLogWebUiSecurityPassTest extends TestCase
{
    public function testSkipsWhenWebUiDisabled(): void
    {
        $container = $this->baseContainer(enabled: false);

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testThrowsWhenWebUiEnabledWithoutSecurity(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('allow_unauthenticated');

        (new MissingLogWebUiSecurityPass())->process($container);
    }

    public function testAllowsUnauthenticatedWithoutSecurity(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: true);

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testRegistersSubscriberWhenSecurityPresent(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));
        $container->setDefinition('security.token_storage', new Definition(stdClass::class));
        $container->setAlias(MissingLogUiAccessCheckerInterface::class, 'security.authorization_checker');
        $container->setDefinition(MissingTranslationLogUiController::class, new Definition(MissingTranslationLogUiController::class));

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(MissingLogUiAccessSubscriber::class));
        $controllerArgs = $container->getDefinition(MissingTranslationLogUiController::class)->getArguments();
        self::assertArrayHasKey('$accessChecker', $controllerArgs);
    }

    public function testRegistersSubscriberWhenAccessRolesEmpty(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: false, accessRoles: [], customChecker: false);
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));
        $container->setAlias(MissingLogUiAccessCheckerInterface::class, 'security.authorization_checker');

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function authenticationModeProvider(): iterable
    {
        yield 'authenticated UI' => [false];
        yield 'allow_unauthenticated (demo)' => [true];
    }

    #[DataProvider('authenticationModeProvider')]
    public function testFailsClosedWithoutTheFormCsrfExtension(bool $allowUnauthenticated): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: $allowUnauthenticated, csrf: false);
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));
        $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing_translation_log.web_ui.enabled requires CSRF protection');

        (new MissingLogWebUiSecurityPass())->process($container);
    }

    public function testFailsClosedWhenFormCsrfIsDisabled(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: true);
        $container->setParameter('form.type_extension.csrf.enabled', false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new MissingLogWebUiSecurityPass())->process($container);
    }

    public function testFailsClosedWithoutTheTokenManager(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: true, csrf: false);
        $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new MissingLogWebUiSecurityPass())->process($container);
    }

    public function testRequiresUiKit(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: true);
        $container->setParameter('kernel.bundles', []);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing_translation_log.web_ui.enabled requires NowoUiKitBundle');

        (new MissingLogWebUiSecurityPass())->process($container);
    }

    public function testSkipsWhenWebUiEnabledParameterMissing(): void
    {
        $container = new ContainerBuilder();

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testSkipsWhenAccessCheckerInterfaceMissing(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testSkipsWhenSubscriberAlreadyRegistered(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));
        $container->setAlias(MissingLogUiAccessCheckerInterface::class, 'security.authorization_checker');
        $existing = new Definition(MissingLogUiAccessSubscriber::class);
        $container->setDefinition(MissingLogUiAccessSubscriber::class, $existing);

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertSame($existing, $container->getDefinition(MissingLogUiAccessSubscriber::class));
    }

    /**
     * @param list<string> $accessRoles
     */
    private function baseContainer(
        bool $enabled,
        bool $allowUnauthenticated = false,
        array $accessRoles = ['ROLE_ADMIN'],
        bool $customChecker = false,
        bool $csrf = true,
    ): ContainerBuilder {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoUiKitBundle' => 'Nowo\\UiKitBundle\\NowoUiKitBundle']);
        if ($csrf) {
            $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));
            $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));
            $container->setParameter('form.type_extension.csrf.enabled', true);
        }
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', $enabled);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.access_roles', $accessRoles);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.custom_access_checker', $customChecker);

        return $container;
    }
}
