<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TranslationYamlToolsBundle\Controller\MissingTranslationLogUiController;
use Nowo\TranslationYamlToolsBundle\EventListener\TranslationYamlToolsConflictListener;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 6 on the bundle's Web UI routes: 409 + X-Conflict-Code, Retry-After only for CONFLICT_001;
 * unique violations, lost connections and host routes are never answered.
 */
#[CoversClass(TranslationYamlToolsConflictListener::class)]
final class TranslationYamlToolsConflictListenerTest extends TestCase
{
    private const OVERRIDES = TranslationOverrideController::class . '::edit';

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function conflicts(): iterable
    {
        yield 'deadlock' => [new DeadlockException(self::driver('Deadlock found'), null)];
        yield 'lock wait timeout' => [new LockWaitTimeoutException(self::driver('Lock wait timeout exceeded'), null)];
        yield 'runner failure' => [TransientStorageFailure::fromPrevious(new DeadlockException(self::driver('deadlock'), null))];
        yield 'wrapped' => [new RuntimeException('controller', 0, new DeadlockException(self::driver('deadlock'), null))];
    }

    #[DataProvider('conflicts')]
    public function testLockConflictAnswers409WithRetryAfterAndConflict001(Throwable $failure): void
    {
        $event = $this->event($failure, self::OVERRIDES);

        (new TranslationYamlToolsConflictListener())($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(TranslationYamlToolsConflictListener::MESSAGE, $answer->getMessage());
        self::assertSame($failure, $answer->getPrevious());
    }

    public function testFailureHintAndConfiguredStatusAreUsed(): void
    {
        $event = $this->event(TransientStorageFailure::fromPrevious(new DeadlockException(self::driver('deadlock'), null), 7), MissingTranslationLogUiController::class . '::markAdded');

        (new TranslationYamlToolsConflictListener(new ConflictResponsePolicy(503, 2)))($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(503, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '7'], $answer->getHeaders());
    }

    public function testCommittedConflictAnswersConflict002WithoutRetryAfter(): void
    {
        $committed = new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 1, new DeadlockException(self::driver('deadlock'), null), true);
        $event     = $this->event($committed, self::OVERRIDES);

        (new TranslationYamlToolsConflictListener(translator: $this->translator()))($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders());
        self::assertSame('Parte guardada.', $answer->getMessage());
    }

    public function testEarlierCommitOfTheSameRequestAnswersConflict002(): void
    {
        $ledger = new CommitLedger();
        $event  = $this->event(new DeadlockException(self::driver('deadlock'), null), self::OVERRIDES);
        $event->getRequest()->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();

        (new TranslationYamlToolsConflictListener(translator: $this->translator()))($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders());
    }

    public function testTranslatedRetryMessageAndFallbackWhenTheKeyIsMissing(): void
    {
        $event = $this->event(new DeadlockException(self::driver('deadlock'), null), self::OVERRIDES);
        (new TranslationYamlToolsConflictListener(translator: $this->translator()))($event);
        self::assertSame('Inténtalo de nuevo.', $event->getThrowable()->getMessage());

        $event = $this->event(new DeadlockException(self::driver('deadlock'), null), self::OVERRIDES);
        (new TranslationYamlToolsConflictListener(translator: new Translator('es')))($event);
        self::assertSame(TranslationYamlToolsConflictListener::MESSAGE, $event->getThrowable()->getMessage());
    }

    /**
     * @return iterable<string, array{Throwable, mixed}>
     */
    public static function notAnswered(): iterable
    {
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driver('duplicate'), null), self::OVERRIDES];
        yield 'lost connection' => [new ConnectionLost(self::driver('gone away'), null), self::OVERRIDES];
        yield 'other error' => [new RuntimeException('boom'), self::OVERRIDES];
        yield 'host route' => [new DeadlockException(self::driver('deadlock'), null), 'App\\Controller\\HomeController::index'];
        yield 'closure controller' => [new DeadlockException(self::driver('deadlock'), null), static fn (): null => null];
    }

    #[DataProvider('notAnswered')]
    public function testOtherFailuresAndHostRoutesAreLeftAlone(Throwable $failure, mixed $controller): void
    {
        $event = $this->event($failure, $controller);

        (new TranslationYamlToolsConflictListener())($event);

        self::assertSame($failure, $event->getThrowable());
    }

    private function event(Throwable $failure, mixed $controller): ExceptionEvent
    {
        $request = Request::create('/_translation_yaml_tools/texts/edit', 'POST');
        $request->attributes->set('_controller', $controller);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function translator(): Translator
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['conflict.retry' => 'Inténtalo de nuevo.', 'conflict.committed' => 'Parte guardada.'], 'es', 'NowoTranslationYamlToolsBundle');

        return $translator;
    }

    private static function driver(string $message): AbstractException
    {
        return new class($message) extends AbstractException {
        };
    }
}
