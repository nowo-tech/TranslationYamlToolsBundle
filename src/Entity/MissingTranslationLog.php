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
    private int $hitCount = 0;

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

    public function __construct(string $messageId, string $domain, string $locale, DateTimeImmutable $seenAt, ?string $callSite = null)
    {
        $this->messageId   = $messageId;
        $this->domain      = $domain;
        $this->locale      = $locale;
        $this->firstSeenAt = $seenAt;
        $this->lastSeenAt  = $seenAt;
        $this->hitCount    = 1;
        $this->callSite    = self::normalizeCallSite($callSite);
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

    public function registerAdditionalHits(int $hits, DateTimeImmutable $at, ?string $latestCallSite = null): void
    {
        if ($hits < 1) {
            return;
        }
        $this->hitCount += $hits;
        $this->lastSeenAt = $at;
        $normalized       = self::normalizeCallSite($latestCallSite);
        if ($normalized !== null) {
            $this->callSite = $normalized;
        }
    }

    private static function normalizeCallSite(?string $callSite): ?string
    {
        if ($callSite === null || $callSite === '') {
            return null;
        }

        return strlen($callSite) <= 1024 ? $callSite : substr($callSite, 0, 1021) . '...';
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
