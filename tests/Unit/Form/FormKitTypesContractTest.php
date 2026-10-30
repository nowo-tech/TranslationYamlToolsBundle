<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function class_uses;
use function glob;
use function in_array;

/**
 * REQ-TWIG-005 rule 11: every page form type of the bundle is a FormKit Type bound to the bundle profile.
 */
#[CoversNothing]
final class FormKitTypesContractTest extends TestCase
{
    public function testEveryFormTypeIsAFormKitTypeWithTheBundleProfile(): void
    {
        $files = glob(__DIR__ . '/../../../src/Form/*Type.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            /** @var class-string $class */
            $class      = 'Nowo\\TranslationYamlToolsBundle\\Form\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(FormKitConfig::class);

            self::assertCount(1, $attributes, $class . ' carries #[FormKitConfig].');
            self::assertSame(NowoTranslationYamlToolsExtension::FORM_KIT_PROFILE, $attributes[0]->newInstance()->name);
            self::assertTrue(in_array(FormOptionsTrait::class, class_uses($class), true), $class . ' uses FormOptionsTrait.');
        }
    }
}
