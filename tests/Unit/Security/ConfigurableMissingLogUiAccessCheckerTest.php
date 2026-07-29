<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Security;

use Nowo\TranslationYamlToolsBundle\Security\ConfigurableMissingLogUiAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(ConfigurableMissingLogUiAccessChecker::class)]
final class ConfigurableMissingLogUiAccessCheckerTest extends TestCase
{
    public function testAllowsAccessWhenNoRolesConfigured(): void
    {
        $checker = new ConfigurableMissingLogUiAccessChecker(
            $this->createMock(AuthorizationCheckerInterface::class),
            [],
        );

        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testAllowsAccessWhenUserHasAnyConfiguredRole(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_ADMIN',
        );

        $checker = new ConfigurableMissingLogUiAccessChecker($authorization, ['ROLE_USER', 'ROLE_ADMIN']);

        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testDeniesAccessWhenNoRoleMatches(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(false);

        $checker = new ConfigurableMissingLogUiAccessChecker($authorization, ['ROLE_ADMIN']);

        self::assertFalse($checker->canAccess(new stdClass()));
    }
}
