<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Worker;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Nowo\TranslationYamlToolsBundle\TextOverride\EventSubscriber\TranslationOverridesClearSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Audit NM (1.18.1): the console bookkeeping of both boundary subscribers survives listeners that throw or stop the
 * propagation. A REAL console Application and a REAL HttpKernel share one event dispatcher in one process (a kept-alive
 * worker, no services resetter): commands run in-process, then main requests are handled by the same kernel.
 */
final class ConsoleBoundaryCounterTest extends TestCase
{
    private int $clears = 0;

    private EventDispatcher $dispatcher;

    private Application $application;

    private HttpKernel $kernel;

    /** Clears seen inside the running outer command, after its nested command ended. */
    private ?int $clearsAfterNested = null;

    /**
     * @return iterable<string, array{Closure(self): EventSubscriberInterface, bool}>
     */
    public static function subscribers(): iterable
    {
        yield 'entity manager boundary' => [static fn (self $test): EventSubscriberInterface => $test->entityManagerSubscriber(), true];
        yield 'text overrides memo' => [static fn (self $test): EventSubscriberInterface => $test->overridesSubscriber(), false];
    }

    /**
     * (a) A console.terminate listener at priority 0 throws: the next main request and the next command still clear.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testATerminateListenerThatThrowsDoesNotLeakTheCommand(Closure $factory): void
    {
        $this->boot($factory);
        $failed = false;
        $this->dispatcher->addListener(ConsoleEvents::TERMINATE, static function () use (&$failed): void {
            if (!$failed) {
                $failed = true;

                throw new RuntimeException('terminate listener failed once');
            }
        });

        $this->runCommand('app:noop');
        $this->assertNextUnitsClear();
    }

    /**
     * (b) A console.error listener throws (Symfony then skips console.terminate): the next main request and the next
     * command still clear.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testAnErrorListenerThatThrowsDoesNotLeakTheCommand(Closure $factory): void
    {
        $this->boot($factory);
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        });

        $this->runCommand('app:fail');
        $this->assertNextUnitsClear();
    }

    /**
     * (c) A nested command the subscriber never saw start (a listener before it stopped the propagation): its
     * console.terminate does not end the outer unit.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testAnUnknownNestedCommandDoesNotEndTheOuterUnit(Closure $factory): void
    {
        $this->dispatcher = new EventDispatcher();
        // Registered before the subscriber at the same priority, so it runs first and hides the nested command.
        $this->dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:nested') {
                $event->stopPropagation();
            }
        }, PHP_INT_MAX);
        $this->boot($factory, $this->dispatcher);

        $this->runCommand('app:outer');
        self::assertSame(1, $this->clearsAfterNested, 'The nested command must not clear the outer unit mid-run.');
        self::assertSame(2, $this->clears, 'The outer command clears at its start and at its end.');
    }

    /**
     * (d) A console.command listener above 4096 stops the propagation for the nested command: the outer unit is not
     * cleared mid-run.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testAStoppedNestedCommandEventDoesNotClearTheOuterUnit(Closure $factory): void
    {
        $this->boot($factory);
        $this->dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:nested') {
                $event->stopPropagation();
            }
        }, 5000);

        $this->runCommand('app:outer');
        self::assertSame(1, $this->clearsAfterNested, 'The nested command must not clear the outer unit mid-run.');
        self::assertSame(2, $this->clears);
    }

    /**
     * (e) Unchanged behaviour: the outermost command clears at its start and end, a nested one does not, and every
     * main request clears.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testOutermostCommandsAndMainRequestsStillClear(Closure $factory): void
    {
        $this->boot($factory);

        $this->runCommand('app:outer');
        self::assertSame(1, $this->clearsAfterNested);
        self::assertSame(2, $this->clears);
        $this->handleRequest();
        self::assertSame(4, $this->clears, 'A main request clears at its start and at kernel.terminate.');
    }

    /**
     * A command run in-process by a controller whose console.terminate never ran is dropped when the request ends.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testARequestDropsACommandThatNeverTerminated(Closure $factory): void
    {
        $this->dispatcher = new EventDispatcher();
        // Registered before the subscriber at the same priority: the subscriber never sees the error nor the terminate.
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        }, PHP_INT_MAX);
        $this->boot($factory, $this->dispatcher);

        $this->handleRequest(function (): void {
            $this->runCommand('app:fail');
        });
        $this->assertNextUnitsClear();
    }

    /**
     * Audit NL2-v: a command that runs itself in-process, whose inner run fails and whose error listener throws (no
     * terminate for the inner run): the outer run ends normally and the next units still clear.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testASelfRecursiveCommandWithAThrowingErrorListener(Closure $factory): void
    {
        $this->boot($factory);
        $runs = 0;
        $this->application->addCommand((new Command('app:recursive'))->setCode(function () use (&$runs): int {
            if (++$runs > 1) {
                throw new RuntimeException('inner run failed');
            }
            $throw = static function (): never {
                throw new RuntimeException('error listener failed');
            };
            $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
            $this->runCommand('app:recursive');
            $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);

            return Command::SUCCESS;
        }));

        $this->runCommand('app:recursive');
        self::assertSame(2, $this->clears, 'The outer run clears at its start and at its end only.');
        $this->assertNextUnitsClear();
    }

    /**
     * Audit NL2-v: an error listener at PHP_INT_MIN throws (after every other one): the next units still clear.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testAnErrorListenerAtTheLowestPriorityThatThrows(Closure $factory): void
    {
        $this->boot($factory);
        $throw = static function (): never {
            throw new RuntimeException('error listener failed');
        };
        $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw, PHP_INT_MIN);

        $this->runCommand('app:fail');
        $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
        $this->assertNextUnitsClear();
    }

    /**
     * Audit NL2-v: a WorkerMessageReceivedEvent listener throws inside an in-process consume command (no handled,
     * failed or stopped event follows): the message ends with the command and the next units still clear.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testAThrowingReceivedListenerInsideAConsumeCommand(Closure $factory): void
    {
        $this->boot($factory);
        $this->application->addCommand((new Command('app:consume'))->setCode(function (): int {
            $throw = static function (): never {
                throw new RuntimeException('received listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $throw);
            try {
                $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
            } finally {
                $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throw);
            }

            return Command::SUCCESS;
        }));

        $this->runCommand('app:consume');
        $this->assertNextUnitsClear();
    }

    /**
     * Audit NL2-v: a main request handled in-process inside another main request does not end the outer unit.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testANestedMainRequestDoesNotEndTheOuterUnit(Closure $factory, bool $httpGated): void
    {
        $this->boot($factory);
        $inside = null;
        $this->handleRequest(function () use (&$inside): void {
            $nested = Request::create('/nested');
            $nested->attributes->set('_controller', static fn (): Response => new Response('nested'));
            $this->kernel->handle($nested);
            $before = $this->clears;
            $this->runCommand('app:noop');
            $inside = $this->clears - $before;
        });

        self::assertSame(0, $inside, 'A command run after the nested request is still inside the outer request.');
        self::assertSame($httpGated ? 2 : 3, $this->clears, 'Outer start and terminate (plus the nested start for the memo).');
        $this->assertNextUnitsClear();
    }

    /**
     * Audit NL2-v2: a message handler runs an in-process command that fails with a throwing console.error listener (no
     * terminate), the message fails, the worker stops: no message stays in flight and the next units still clear.
     *
     * @param Closure(self): EventSubscriberInterface $factory
     */
    #[DataProvider('subscribers')]
    public function testAFailedCommandInsideAFailedMessageThenAStoppedWorker(Closure $factory): void
    {
        $this->boot($factory);
        $throw = static function (): never {
            throw new RuntimeException('error listener failed');
        };
        $envelope = new Envelope(new stdClass());

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
        $this->runCommand('app:fail');
        $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('handler failed')));
        $this->dispatcher->dispatch(new WorkerStoppedEvent((new ReflectionClass(Worker::class))->newInstanceWithoutConstructor()));
        $this->assertNextUnitsClear();
    }

    public function entityManagerSubscriber(): EventSubscriberInterface
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        return new EntityManagerBoundarySubscriber([], [$manager], [], 'translations');
    }

    public function overridesSubscriber(): EventSubscriberInterface
    {
        $memo = $this->createStub(ResetInterface::class);
        $memo->method('reset')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        return new TranslationOverridesClearSubscriber([$memo]);
    }

    private function assertNextUnitsClear(): void
    {
        $before = $this->clears;
        $this->handleRequest();
        self::assertSame($before + 2, $this->clears, 'The next main request must clear at its start and terminate.');
        $this->runCommand('app:noop');
        self::assertSame($before + 4, $this->clears, 'The next outermost command must clear at its start and end.');
    }

    /**
     * @param Closure(self): EventSubscriberInterface $factory
     */
    private function boot(Closure $factory, ?EventDispatcher $dispatcher = null): void
    {
        $this->clears     = 0;
        $this->dispatcher = $dispatcher ?? new EventDispatcher();
        $this->dispatcher->addSubscriber($factory($this));

        $this->kernel      = new HttpKernel($this->dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
        $this->application = new Application();
        $this->application->setDispatcher($this->dispatcher);
        $this->application->setAutoExit(false);
        $this->application->setCatchExceptions(false);
        $this->application->addCommand((new Command('app:noop'))->setCode(static fn (): int => Command::SUCCESS));
        $this->application->addCommand((new Command('app:fail'))->setCode(static function (): never {
            throw new RuntimeException('command failed');
        }));
        $this->application->addCommand((new Command('app:nested'))->setCode(static fn (): int => Command::SUCCESS));
        $this->application->addCommand((new Command('app:outer'))->setCode(function (): int {
            $this->runCommand('app:nested');
            $this->clearsAfterNested = $this->clears;

            return Command::SUCCESS;
        }));
    }

    private function runCommand(string $name): void
    {
        try {
            $this->application->doRun(new ArrayInput(['command' => $name]), new NullOutput());
        } catch (Throwable) {
            // The failing listeners and commands above.
        }
    }

    /**
     * @param (Closure(): void)|null $controller
     */
    private function handleRequest(?Closure $controller = null): void
    {
        $request = Request::create('/');
        $request->attributes->set('_controller', static function () use ($controller): Response {
            if ($controller instanceof Closure) {
                $controller();
            }

            return new Response('ok');
        });
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
    }
}
