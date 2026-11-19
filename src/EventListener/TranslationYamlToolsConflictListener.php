<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\EventListener;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function is_string;
use function str_starts_with;

/**
 * Since 1.19 (REQ-RUNTIME-003 rule 6): a lock conflict on one of the bundle's own Web UI routes (the text-overrides
 * desk and the missing-log pages) gets the platform conflict answer instead of a 500.
 *
 * A conflict is a deadlock, lock wait timeout or serialization failure (MySQL / MariaDB 1213, 1205, 1020; PostgreSQL
 * 40001, 40P01, 55P03), a {@see TransientStorageFailure} (the bundle's write units rethrow those as one), or a lost
 * savepoint whose commit outcome is unknown, anywhere in the failure (transaction-doctrine's classifier walks
 * getPrevious() and Messenger's wrapped exceptions). Unique (and other constraint) violations and lost connections are
 * never conflicts: they stay real errors.
 *
 * The answer is an HttpException rendered by your error page, with the `conflict_response.status` (409 by default,
 * 503 for hosts that want it) and `X-Conflict-Code`:
 *
 * - `CONFLICT_001` and `Retry-After` when nothing was committed;
 * - `CONFLICT_002` and no `Retry-After` when part of the request was ({@see ConflictCode::forRequest()}: the request
 *   commit mark of transaction-doctrine's bundle with `commit_detection`, a committed ConcurrencyConflict, a Messenger
 *   handler that already completed, a lost savepoint).
 *
 * Host routes are never answered (the `_controller` must be one of the bundle's). Priority -64: after the
 * application's own exception listeners, before the framework's error listener (-128). Stateless.
 */
final readonly class TranslationYamlToolsConflictListener
{
    /** Generic, client-safe text when nothing was committed (also the translation fallback). */
    public const MESSAGE = 'The same data was being changed at the same time. Try again.';

    /** Generic, client-safe text when part of the request was committed (also the translation fallback). */
    public const COMMITTED_MESSAGE = 'Part of the request was saved before a conflict. Reload before trying again.';

    /** Namespace of the bundle's controllers (the missing-log UI and the text-overrides desk). */
    private const CONTROLLER_PREFIX = 'Nowo\\TranslationYamlToolsBundle\\';

    private const TRANSLATION_DOMAIN = 'NowoTranslationYamlToolsBundle';

    /**
     * @param ConflictResponsePolicy $policy status (409 or 503) and Retry-After (`conflict_response`)
     */
    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Answers a lock conflict of a bundle route.
     */
    public function __invoke(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return;
        }
        $failure = $event->getThrowable();
        if (!self::isConflict($failure)) {
            return;
        }
        $code = ConflictCode::forRequest($request, $failure);
        $event->setThrowable($this->policy->httpExceptionFor($code, $this->message($code), $failure, $this->retryAfterHint($failure)));
    }

    /**
     * Whether the failure gets the conflict answer ({@see TransientFailureClassifier::findConflict()}): a lock conflict
     * or a transient failure (a replay can succeed), or a lost savepoint whose commit outcome is unknown. Never true for
     * a unique violation or a lost connection.
     */
    public static function isConflict(Throwable $failure): bool
    {
        return TransientFailureClassifier::findConflict($failure) instanceof Throwable;
    }

    /**
     * The Retry-After hint of the first {@see TransientStorageFailure} of the chain, or null (the configured value).
     */
    private function retryAfterHint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
    }

    private function message(ConflictCode $code): string
    {
        [$key, $fallback] = $code === ConflictCode::Committed ? ['conflict.committed', self::COMMITTED_MESSAGE] : ['conflict.retry', self::MESSAGE];
        $translated       = $this->translator?->trans($key, [], self::TRANSLATION_DOMAIN);

        return $translated === null || $translated === $key ? $fallback : $translated;
    }
}
