<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(MissingLogWebUiSecurityPass::class)]
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

    public function testSkipsSubscriberWhenAccessRolesEmptyAndNoCustomChecker(): void
    {
        $container = $this->baseContainer(enabled: true, allowUnauthenticated: false, accessRoles: [], customChecker: false);
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));
        $container->setAlias(MissingLogUiAccessCheckerInterface::class, 'security.authorization_checker');

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
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
    ): ContainerBuilder {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', $enabled);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.access_roles', $accessRoles);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.security.custom_access_checker', $customChecker);

        return $container;
    }
}
