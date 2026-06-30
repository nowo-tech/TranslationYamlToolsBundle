<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Repository;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\MissingTranslationLogTestEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingTranslationLogRepository::class)]
final class MissingTranslationLogRepositoryTest extends TestCase
{
    private function createRepository(): MissingTranslationLogRepository
    {
        return MissingTranslationLogTestEntityManagerFactory::createRepository();
    }

    public function testPersistBufferEmptyIsNoOp(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([]);
        self::assertSame(0, $repo->count([]));
    }

    public function testPersistBufferInsertAndDuplicateUpdatesHits(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => null,
            ],
        ]);
        $repo->persistBuffer([
            'a' => [
                'hits'      => 2,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => '/tmp/x.php:1',
            ],
        ]);

        $rows = $repo->findByStatus(MissingTranslationLogStatus::Pending, 10);
        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->getHitCount());
        self::assertSame('/tmp/x.php:1', $rows[0]->getCallSite());
    }

    public function testFindByStatusAndFindOneById(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'id.a',
                'domain'    => 'd',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);
        $id = $repo->findByStatus(MissingTranslationLogStatus::Pending, 5)[0]->getId();
        self::assertNotNull($id);
        $one = $repo->findOneById($id);
        self::assertNotNull($one);
        self::assertSame('id.a', $one->getMessageId());
    }

    public function testPersistBufferTruncatesLongCallSite(): void
    {
        $long = str_repeat('x', 1100);
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k',
                'domain'    => 'm',
                'locale'    => 'es',
                'callSite'  => $long,
            ],
        ]);
        $site = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0]->getCallSite();
        self::assertNotNull($site);
        self::assertSame(1024, strlen($site));
        self::assertStringEndsWith('...', $site);
    }

    public function testPersistBufferInsertsRequestFieldsAndDuplicateUpdatesThem(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'           => 1,
                'messageId'      => 'k1',
                'domain'         => 'messages',
                'locale'         => 'es',
                'callSite'       => null,
                'requestRoute'   => 'r1',
                'requestMethod'  => 'POST',
                'requestPath'    => '/a',
            ],
        ]);
        $repo->getEntityManager()->clear();
        $row = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame('r1', $row->getRequestRoute());
        self::assertSame('POST', $row->getRequestMethod());
        self::assertSame('/a', $row->getRequestPath());

        $repo->persistBuffer([
            'a' => [
                'hits'           => 1,
                'messageId'      => 'k1',
                'domain'         => 'messages',
                'locale'         => 'es',
                'callSite'       => '/f.php:1',
                'requestRoute'   => 'r2',
                'requestMethod'  => 'PATCH',
                'requestPath'    => '/b',
            ],
        ]);
        $repo->getEntityManager()->clear();
        $row2 = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame(2, $row2->getHitCount());
        self::assertSame('/f.php:1', $row2->getCallSite());
        self::assertSame('r2', $row2->getRequestRoute());
        self::assertSame('PATCH', $row2->getRequestMethod());
        self::assertSame('/b', $row2->getRequestPath());
    }

    public function testPersistBufferDuplicateWithoutRequestFieldsPreservesRequestContext(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'           => 1,
                'messageId'      => 'k1',
                'domain'         => 'messages',
                'locale'         => 'es',
                'callSite'       => null,
                'requestRoute'   => 'keep_me',
                'requestMethod'  => 'GET',
                'requestPath'    => '/first',
            ],
        ]);
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'es',
                'callSite'  => '/only.php:9',
            ],
        ]);

        $row = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0];
        self::assertSame(2, $row->getHitCount());
        self::assertSame('/only.php:9', $row->getCallSite());
        self::assertSame('keep_me', $row->getRequestRoute());
        self::assertSame('GET', $row->getRequestMethod());
        self::assertSame('/first', $row->getRequestPath());
    }

    public function testPersistBufferTruncatesLongRequestPath(): void
    {
        $long = '/' . str_repeat('p', 2100);
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'          => 1,
                'messageId'     => 'k',
                'domain'        => 'm',
                'locale'        => 'es',
                'callSite'      => null,
                'requestRoute'  => null,
                'requestMethod' => 'GET',
                'requestPath'   => $long,
            ],
        ]);
        $path = $repo->findByStatus(MissingTranslationLogStatus::Pending, 1)[0]->getRequestPath();
        self::assertNotNull($path);
        self::assertSame(2048, strlen($path));
        self::assertStringEndsWith('...', $path);
    }

    public function testClearAllDeletesRowsAndReturnsCount(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
            'b' => [
                'hits'      => 1,
                'messageId' => 'k2',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);

        self::assertSame(2, $repo->count([]));
        self::assertSame(2, $repo->clearAll());
        self::assertSame(0, $repo->count([]));
    }

    public function testClearByStatusDeletesOnlyGivenStatus(): void
    {
        $repo = $this->createRepository();
        $repo->persistBuffer([
            'a' => [
                'hits'      => 1,
                'messageId' => 'k1',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
            'b' => [
                'hits'      => 1,
                'messageId' => 'k2',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ]);

        $rows = $repo->findByStatus(MissingTranslationLogStatus::Pending, 10);
        self::assertCount(2, $rows);
        $rows[0]->setStatus(MissingTranslationLogStatus::Added);
        $repo->getEntityManager()->flush();
        $repo->getEntityManager()->clear();

        self::assertSame(1, $repo->clearByStatus(MissingTranslationLogStatus::Pending));
        self::assertCount(0, $repo->findByStatus(MissingTranslationLogStatus::Pending, 10));
        self::assertCount(1, $repo->findByStatus(MissingTranslationLogStatus::Added, 10));
    }
}
