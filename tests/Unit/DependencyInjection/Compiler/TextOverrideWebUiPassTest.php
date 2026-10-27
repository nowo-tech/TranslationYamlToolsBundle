<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\TextOverrideWebUiPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(TextOverrideWebUiPass::class)]
final class TextOverrideWebUiPassTest extends TestCase
{
    public function testSkipsWhenDisabledOrUnconfigured(): void
    {
        (new TextOverrideWebUiPass())->process(new ContainerBuilder());
        (new TextOverrideWebUiPass())->process($this->container(false, false, []));
        $this->addToAssertionCount(1);
    }

    public function testRequiresSecurityUnlessAllowUnauthenticated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('security-bundle');
        (new TextOverrideWebUiPass())->process($this->container(true, false, ['security.csrf.token_manager', 'twig']));
    }

    public function testRequiresCsrf(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('CSRF');
        (new TextOverrideWebUiPass())->process($this->container(true, false, ['security.authorization_checker', 'twig']));
    }

    public function testRequiresTheFormCsrfExtension(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');
        (new TextOverrideWebUiPass())->process($this->container(true, false, ['security.authorization_checker', 'security.csrf.token_manager', 'twig', 'form.factory'], csrfExtension: false));
    }

    public function testRequiresFormCsrfEnabled(): void
    {
        $container = $this->container(true, false, ['security.authorization_checker', 'security.csrf.token_manager', 'twig', 'form.factory']);
        $container->setParameter('form.type_extension.csrf.enabled', false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');
        (new TextOverrideWebUiPass())->process($container);
    }

    public function testRequiresTwig(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('twig-bundle');
        (new TextOverrideWebUiPass())->process($this->container(true, true, ['security.csrf.token_manager']));
    }

    public function testRequiresForm(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Form component');
        (new TextOverrideWebUiPass())->process($this->container(true, true, ['security.csrf.token_manager', 'twig']));
    }

    public function testPassesWithAllRequirements(): void
    {
        (new TextOverrideWebUiPass())->process($this->container(true, false, ['security.authorization_checker', 'security.csrf.token_manager', 'twig', 'form.factory']));
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<string> $services
     */
    private function container(bool $enabled, bool $allowUnauthenticated, array $services, bool $csrfExtension = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_translation_yaml_tools.overrides.web_ui.enabled', $enabled);
        $container->setParameter('nowo_translation_yaml_tools.overrides.web_ui.security.allow_unauthenticated', $allowUnauthenticated);
        foreach ($services as $id) {
            $container->setDefinition($id, new Definition());
        }
        if ($csrfExtension) {
            $container->setDefinition('form.type_extension.csrf', new Definition());
            $container->setParameter('form.type_extension.csrf.enabled', true);
        }

        return $container;
    }
}
