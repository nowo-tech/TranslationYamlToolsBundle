<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
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
        $container = new ContainerBuilder();
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', false);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', false);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role', 'ROLE_ADMIN');

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testThrowsWhenWebUiEnabledWithoutSecurity(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', true);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', false);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role', 'ROLE_ADMIN');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('allow_unauthenticated');

        (new MissingLogWebUiSecurityPass())->process($container);
    }

    public function testAllowsUnauthenticatedWithoutSecurity(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', true);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', true);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role', 'ROLE_ADMIN');

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }

    public function testRegistersSubscriberWhenSecurityPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.enabled', true);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.allow_unauthenticated', false);
        $container->setParameter('nowo_translation_yaml_tools.missing_translation_log.web_ui.required_role', 'ROLE_ADMIN');
        $container->setDefinition('security.authorization_checker', new Definition(stdClass::class));

        (new MissingLogWebUiSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(MissingLogUiAccessSubscriber::class));
    }
}
