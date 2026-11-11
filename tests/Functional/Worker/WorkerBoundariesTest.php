<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\WorkerBoundariesTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function gc_collect_cycles;
use function is_array;
use function memory_get_usage;
use function sprintf;

/**
 * Kept-alive kernel, services resetter never called (REQ-RUNTIME-002): a host that removes or bypasses
 * services_resetter (http_kernel driven directly), `messenger:consume --no-reset`, long-running console processes.
 * One booted kernel, the same TranslationOverrides instance, many units of work for two tenants in alternation
 * (tenant A reads the English greeting, tenant B the Spanish one).
 *
 * Before every unit "another worker" edits both overrides in the database and drops the shared cache entry (what its
 * own TranslationOverrides::flush() does), so a unit that still reads the previous unit's memo renders a stale text.
 *
 * N = 500 per path: HTTP through http_kernel (no Kernel::handle(), so no resetter), Messenger through a real Worker
 * without ResetServicesListener (every 7th handler fails), and console Application runs. Memory margin: 1 MiB after a
 * 50-unit warm-up.
 */
final class WorkerBoundariesTest extends KernelTestCase
{
    private const ITERATIONS    = 500;
    private const WARMUP        = 50;
    private const MEMORY_MARGIN = 1024 * 1024;

    /** @var list<string> */
    private array $leaks = [];

    private int $memoryAtWarmup = 0;

    private int $memoryAtEnd = 0;

    protected static function getKernelClass(): string
    {
        return WorkerBoundariesTestKernel::class;
    }

    public function testManyRequestsKeepTenantsIsolatedAndSeeOtherWorkersEdits(): void
    {
        $this->boot();
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->editTexts($i);
            $this->memoryMark($i);
            $tenant   = $this->tenantFor($i);
            $request  = Request::create('/greet/' . $tenant);
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $this->check((string) $response->getContent(), $tenant, $i);
            $http->terminate($request, $response);
        }

        $this->assertNoLeakAndFlatMemory('HTTP requests');
    }

    public function testManyMessengerMessagesKeepTenantsIsolatedAndSeeOtherWorkersEdits(): void
    {
        $this->boot();
        $translator = $this->translator();
        $dispatcher = $this->dispatcher();

        foreach ($dispatcher->getListeners(WorkerRunningEvent::class) as $listener) {
            self::assertFalse(
                is_array($listener) && $listener[0] instanceof ResetServicesListener,
                'The worker must run without ResetServicesListener (messenger:consume --no-reset).',
            );
        }

        $receiver = new TenantGreetingReceiver(self::ITERATIONS);
        $bus      = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            TenantGreetingMessage::class => [function (TenantGreetingMessage $message) use ($translator): void {
                $this->memoryMark($message->index);
                $this->check(WorkerBoundariesTestKernel::unitOfWork($translator, $message->tenant), $message->tenant, $message->index);
                // The next message runs after another worker's edit.
                $this->editTexts($message->index + 1);
                if ($message->fail) {
                    throw new RuntimeException('Mailer down for tenant ' . $message->tenant);
                }
            }],
        ]))]);

        $this->editTexts(0);
        $this->runWorker(new Worker(['async' => $receiver], $bus, $dispatcher), $dispatcher);

        self::assertSame(self::ITERATIONS, $receiver->acknowledged + $receiver->rejected);
        self::assertGreaterThan(0, $receiver->rejected);
        $this->assertNoLeakAndFlatMemory('Messenger messages');
    }

    public function testManyConsoleRunsKeepTenantsIsolatedAndSeeOtherWorkersEdits(): void
    {
        $this->boot();
        $translator = $this->translator();

        $command = (new Command('app:greet-tenant'))
            ->addArgument('tenant', InputArgument::REQUIRED)
            ->addArgument('index', InputArgument::REQUIRED)
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($translator): int {
                $tenant = (string) $input->getArgument('tenant');
                $this->check(WorkerBoundariesTestKernel::unitOfWork($translator, $tenant), $tenant, (int) $input->getArgument('index'));

                return Command::SUCCESS;
            });

        $application = $this->application();
        $application->setCommandLoader(new FactoryCommandLoader(['app:greet-tenant' => static fn (): Command => $command]));

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->editTexts($i);
            $this->memoryMark($i);
            $exitCode = $application->run(
                new ArrayInput(['command' => 'app:greet-tenant', 'tenant' => $this->tenantFor($i), 'index' => (string) $i]),
                new NullOutput(),
            );
            self::assertSame(Command::SUCCESS, $exitCode);
        }

        $this->assertNoLeakAndFlatMemory('console command runs');
    }

    /**
     * A command run in-process from another command (Application::doRun()) fires console.command / console.terminate
     * but keeps the outer command's memo; the outermost command still ends with the memo dropped.
     */
    public function testNestedCommandKeepsTheOuterCommandsMemo(): void
    {
        $this->boot();
        $translator  = $this->translator();
        $application = $this->application();

        $seen  = [];
        $inner = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $outer = (new Command('app:outer'))->setCode(function (InputInterface $input, OutputInterface $output) use ($application, $translator, &$seen): int {
            $seen[] = WorkerBoundariesTestKernel::unitOfWork($translator, 'A');
            $this->editTexts(99);
            $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
            $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
            $seen[] = WorkerBoundariesTestKernel::unitOfWork($translator, 'A');

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader([
            'app:outer' => static fn (): Command => $outer,
            'app:inner' => static fn (): Command => $inner,
        ]));

        $this->editTexts(1);
        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput()));
        self::assertSame([$this->expected('A', 1), $this->expected('A', 1)], $seen, 'A nested command dropped the outer command\'s memo.');
        self::assertSame($this->expected('A', 99), WorkerBoundariesTestKernel::unitOfWork($translator, 'A'), 'The outermost console.terminate drops the memo.');
    }

    /**
     * A command run in-process from a Messenger handler keeps the handler's memo; the message boundaries still drop it.
     */
    public function testCommandRunFromAMessageHandlerKeepsTheHandlersMemo(): void
    {
        $this->boot();
        $translator  = $this->translator();
        $dispatcher  = $this->dispatcher();
        $application = $this->application();
        $inner       = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $application->setCommandLoader(new FactoryCommandLoader(['app:inner' => static fn (): Command => $inner]));

        $seen = [];
        $bus  = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            TenantGreetingMessage::class => [function (TenantGreetingMessage $message) use ($application, $translator, &$seen): void {
                $before = WorkerBoundariesTestKernel::unitOfWork($translator, $message->tenant);
                $this->editTexts(100 + $message->index);
                $application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
                $seen[] = [$before, WorkerBoundariesTestKernel::unitOfWork($translator, $message->tenant)];
            }],
        ]))]);

        $this->editTexts(0);
        $this->runWorker(new Worker(['async' => new TenantGreetingReceiver(4)], $bus, $dispatcher), $dispatcher);

        self::assertSame([
            [$this->expected('A', 0), $this->expected('A', 0)],
            [$this->expected('B', 100), $this->expected('B', 100)],
            [$this->expected('A', 101), $this->expected('A', 101)],
            [$this->expected('B', 102), $this->expected('B', 102)],
        ], $seen, 'A command run from the handler dropped its memo, or the message boundaries did not.');

        // After the worker stopped, a standalone command drops the memo again.
        self::assertSame($this->expected('B', 103), WorkerBoundariesTestKernel::unitOfWork($translator, 'B'));
        $this->editTexts(7);
        $application->run(new ArrayInput(['command' => 'app:inner']), new NullOutput());
        self::assertSame($this->expected('B', 7), WorkerBoundariesTestKernel::unitOfWork($translator, 'B'));
    }

    private function boot(): void
    {
        self::bootKernel(['debug' => false]);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(TranslationOverride::class)]);
        $repository = $this->repository();
        $repository->persist((new TranslationOverride('en', 'messages', WorkerBoundariesTestKernel::KEY))->setValue('seed'));
        $repository->persist((new TranslationOverride('es', 'messages', WorkerBoundariesTestKernel::KEY))->setValue('seed'));
        $repository->flush();
        $this->leaks          = [];
        $this->memoryAtWarmup = 0;
        $this->memoryAtEnd    = 0;
    }

    /**
     * Another worker edits both overrides (database + shared cache invalidation, as its TranslationOverrides::flush()
     * does). This worker's TranslationOverrides instance is not told.
     */
    private function editTexts(int $revision): void
    {
        $repository = $this->repository();
        foreach (['A', 'B'] as $tenant) {
            $row = $repository->findOne(WorkerBoundariesTestKernel::localeOf($tenant), 'messages', WorkerBoundariesTestKernel::KEY);
            self::assertInstanceOf(TranslationOverride::class, $row);
            $row->setValue($this->text($tenant, $revision, '%name%'));
        }
        $repository->flush();

        $cache = self::getContainer()->get(WorkerBoundariesTestKernel::CACHE);
        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->delete(TranslationOverrides::CACHE_KEY);
    }

    private function text(string $tenant, int $revision, string $name): string
    {
        return sprintf('%s %s, revision %d', $tenant === 'A' ? 'Hello' : 'Hola', $name, $revision);
    }

    private function expected(string $tenant, int $revision): string
    {
        return $this->text($tenant, $revision, $tenant);
    }

    private function check(string $rendered, string $tenant, int $index): void
    {
        if ($rendered !== $this->expected($tenant, $index)) {
            $this->leaks[] = sprintf('unit %d (tenant %s) rendered "%s"', $index, $tenant, $rendered);
        }
    }

    private function memoryMark(int $index): void
    {
        if ($index === self::WARMUP) {
            gc_collect_cycles();
            $this->memoryAtWarmup = memory_get_usage();
        }
        if ($index === self::ITERATIONS - 1) {
            gc_collect_cycles();
            $this->memoryAtEnd = memory_get_usage();
        }
    }

    private function assertNoLeakAndFlatMemory(string $path): void
    {
        self::assertSame([], $this->leaks, sprintf('Stale or foreign override text between %s.', $path));
        self::assertGreaterThan(0, $this->memoryAtWarmup);
        self::assertLessThan(self::MEMORY_MARGIN, $this->memoryAtEnd - $this->memoryAtWarmup, sprintf('Memory grew across %s.', $path));
    }

    private function runWorker(Worker $worker, EventDispatcherInterface $dispatcher): void
    {
        $stopOnIdle = static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stopOnIdle);

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdle);
        }
    }

    private function application(): Application
    {
        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($this->dispatcher());

        return $application;
    }

    private function repository(): TranslationOverrideRepository
    {
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);
        self::assertInstanceOf(TranslationOverrideRepository::class, $repository);

        return $repository;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get(WorkerBoundariesTestKernel::TRANSLATOR);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function tenantFor(int $index): string
    {
        return $index % 2 === 0 ? 'A' : 'B';
    }
}
