<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;

use function strlen;

#[ORM\Entity(repositoryClass: MissingTranslationLogRepository::class)]
class MissingTranslationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id on persist) */
    private ?int $id = null;

    #[ORM\Column(name: 'message_id', length: 500)]
    private string $messageId;

    #[ORM\Column(length: 180)]
    private string $domain;

    #[ORM\Column(length: 32)]
    private string $locale;

    #[ORM\Column(type: 'string', length: 16, enumType: MissingTranslationLogStatus::class)]
    private MissingTranslationLogStatus $status = MissingTranslationLogStatus::Pending;

    #[ORM\Column]
    private int $hitCount = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $statusChangedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'call_site', length: 1024, nullable: true)]
    private ?string $callSite = null;

    #[ORM\Column(name: 'request_route', length: 180, nullable: true)]
    private ?string $requestRoute = null;

    #[ORM\Column(name: 'request_method', length: 8, nullable: true)]
    private ?string $requestMethod = null;

    #[ORM\Column(name: 'request_path', length: 2048, nullable: true)]
    private ?string $requestPath = null;

    public function __construct(
        string $messageId,
        string $domain,
        string $locale,
        DateTimeImmutable $seenAt,
        ?string $callSite = null,
        ?string $requestRoute = null,
        ?string $requestMethod = null,
        ?string $requestPath = null,
    ) {
        $this->messageId     = $this->normalizeMessageId($messageId);
        $this->domain        = $this->normalizeDomain($domain);
        $this->locale        = $this->normalizeLocale($locale);
        $this->firstSeenAt   = $seenAt;
        $this->lastSeenAt    = $seenAt;
        $this->callSite      = $this->normalizeCallSite($callSite);
        $this->requestRoute  = $this->normalizeRequestRoute($requestRoute);
        $this->requestMethod = $this->normalizeRequestMethod($requestMethod);
        $this->requestPath   = $this->normalizeRequestPath($requestPath);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getStatus(): MissingTranslationLogStatus
    {
        return $this->status;
    }

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getStatusChangedAt(): ?DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCallSite(): ?string
    {
        return $this->callSite;
    }

    public function getRequestRoute(): ?string
    {
        return $this->requestRoute;
    }

    public function getRequestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function getRequestPath(): ?string
    {
        return $this->requestPath;
    }

    public function registerAdditionalHits(
        int $hits,
        DateTimeImmutable $at,
        ?string $latestCallSite = null,
        ?string $latestRequestRoute = null,
        ?string $latestRequestMethod = null,
        ?string $latestRequestPath = null,
    ): void {
        if ($hits < 1) {
            return;
        }
        $this->hitCount += $hits;
        $this->lastSeenAt = $at;
        $normalized       = $this->normalizeCallSite($latestCallSite);
        if ($normalized !== null) {
            $this->callSite = $normalized;
        }
        $nr = $this->normalizeRequestRoute($latestRequestRoute);
        if ($nr !== null) {
            $this->requestRoute = $nr;
        }
        $nm = $this->normalizeRequestMethod($latestRequestMethod);
        if ($nm !== null) {
            $this->requestMethod = $nm;
        }
        $np = $this->normalizeRequestPath($latestRequestPath);
        if ($np !== null) {
            $this->requestPath = $np;
        }
    }

    private function normalizeMessageId(string $messageId): string
    {
        return strlen($messageId) <= 500 ? $messageId : substr($messageId, 0, 497) . '...';
    }

    private function normalizeDomain(string $domain): string
    {
        return strlen($domain) <= 180 ? $domain : substr($domain, 0, 177) . '...';
    }

    private function normalizeLocale(string $locale): string
    {
        return strlen($locale) <= 32 ? $locale : substr($locale, 0, 32);
    }

    private function normalizeCallSite(?string $callSite): ?string
    {
        if ($callSite === null || $callSite === '') {
            return null;
        }

        return strlen($callSite) <= 1024 ? $callSite : substr($callSite, 0, 1021) . '...';
    }

    private function normalizeRequestRoute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= 180 ? $value : substr($value, 0, 177) . '...';
    }

    private function normalizeRequestMethod(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= 8 ? $value : substr($value, 0, 8);
    }

    private function normalizeRequestPath(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= 2048 ? $value : substr($value, 0, 2045) . '...';
    }

    public function setStatus(MissingTranslationLogStatus $status, ?DateTimeImmutable $at = null): void
    {
        $this->status          = $status;
        $this->statusChangedAt = $at ?? new DateTimeImmutable();
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }
}
