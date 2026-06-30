<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Command\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\Command\MissingTranslationLog\MissingTranslationLogListCommand;
use Nowo\TranslationYamlToolsBundle\Command\MissingTranslationLog\MissingTranslationLogMarkAddedCommand;
use Nowo\TranslationYamlToolsBundle\Command\MissingTranslationLog\MissingTranslationLogValidateCommand;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\MissingTranslationLogTestEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MissingTranslationLogListCommand::class)]
#[CoversClass(MissingTranslationLogMarkAddedCommand::class)]
#[CoversClass(MissingTranslationLogValidateCommand::class)]
final class MissingTranslationLogCommandsTest extends TestCase
{
    private function createRepository(): MissingTranslationLogRepository
    {
        return MissingTranslationLogTestEntityManagerFactory::createRepository();
    }

    public function testListInvalidStatus(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('findByStatus');

        $tester = new CommandTester(new MissingTranslationLogListCommand($repository));
        $exit   = $tester->execute(['--status' => 'nope']);

        self::assertSame(2, $exit);
    }

    public function testListEmptyRows(): void
    {
        $repository = $this->createRepository();
        $tester     = new CommandTester(new MissingTranslationLogListCommand($repository));
        $exit       = $tester->execute(['--status' => 'pending']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No rows', $tester->getDisplay());
    }

    public function testListPrintsTable(): void
    {
        $repository = $this->createRepository();
        $repository->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => '/path',
            ],
        ]);
        $id = $repository->findByStatus(MissingTranslationLogStatus::Pending, 5)[0]->getId();
        self::assertNotNull($id);

        $tester = new CommandTester(new MissingTranslationLogListCommand($repository));
        $exit   = $tester->execute(['--status' => 'pending', '--limit' => '10']);

        self::assertSame(0, $exit);
        self::assertStringContainsString((string) $id, $tester->getDisplay());
        self::assertStringContainsString('k', $tester->getDisplay());
        self::assertStringContainsString('/path', $tester->getDisplay());
        self::assertStringContainsString('request_route', $tester->getDisplay());
        self::assertStringContainsString('request_method', $tester->getDisplay());
        self::assertStringContainsString('request_path', $tester->getDisplay());
    }

    public function testMarkAddedNotFound(): void
    {
        $repository = $this->createRepository();
        $tester     = new CommandTester(new MissingTranslationLogMarkAddedCommand($repository));
        self::assertSame(1, $tester->execute(['id' => '99']));
    }

    public function testMarkAddedSuccess(): void
    {
        $repository = $this->createRepository();
        $repository->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'mid',
                'domain'    => 'd',
                'locale'    => 'fr',
                'callSite'  => null,
            ],
        ]);
        $row   = $repository->findByStatus(MissingTranslationLogStatus::Pending, 5)[0];
        $rowId = $row->getId();
        self::assertNotNull($rowId);

        $tester = new CommandTester(new MissingTranslationLogMarkAddedCommand($repository));
        self::assertSame(0, $tester->execute(['id' => (string) $rowId]));

        $fresh = $repository->findOneById($rowId);
        self::assertNotNull($fresh);
        self::assertSame(MissingTranslationLogStatus::Added, $fresh->getStatus());
    }

    public function testValidateNotFound(): void
    {
        $repository = $this->createRepository();
        $tester     = new CommandTester(new MissingTranslationLogValidateCommand($repository));
        self::assertSame(1, $tester->execute(['id' => '1']));
    }

    public function testValidateWithNote(): void
    {
        $repository = $this->createRepository();
        $repository->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'm',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $rows = $repository->findByStatus(MissingTranslationLogStatus::Pending, 5);
        $rowId = $rows[0]->getId();
        self::assertNotNull($rowId);

        $tester = new CommandTester(new MissingTranslationLogValidateCommand($repository));
        self::assertSame(0, $tester->execute(['id' => (string) $rowId, '--note' => 'ok']));

        $fresh = $repository->findOneById($rowId);
        self::assertNotNull($fresh);
        self::assertSame(MissingTranslationLogStatus::Validated, $fresh->getStatus());
        self::assertSame('ok', $fresh->getNotes());
    }

    public function testValidateWithoutNote(): void
    {
        $repository = $this->createRepository();
        $repository->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'm2',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $rowId = $repository->findByStatus(MissingTranslationLogStatus::Pending, 5)[0]->getId();
        self::assertNotNull($rowId);

        $tester = new CommandTester(new MissingTranslationLogValidateCommand($repository));
        self::assertSame(0, $tester->execute(['id' => (string) $rowId]));

        $fresh = $repository->findOneById($rowId);
        self::assertNotNull($fresh);
        self::assertSame(MissingTranslationLogStatus::Validated, $fresh->getStatus());
    }
}
