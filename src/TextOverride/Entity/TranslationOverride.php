<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TranslationYamlToolsBundle\TextOverride\Doctrine\TranslationOverrideMetadataListener;
use Nowo\TranslationYamlToolsBundle\TextOverride\OverridingTranslator;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;

/**
 * Operator-edited replacement for one translation message, per (locale, domain, key).
 *
 * Applied at runtime by {@see OverridingTranslator}
 * and written through {@see TranslationOverrides}. Deleting the row restores the shipped catalogue
 * text. Table name and unique constraint are set by {@see TranslationOverrideMetadataListener}
 * ({@code overrides.table_prefix} + "override").
 */
#[ORM\Entity(repositoryClass: TranslationOverrideRepository::class)]
class TranslationOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id on persist) */
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $locale;

    #[ORM\Column(length: 128)]
    private string $domain;

    #[ORM\Column(name: 'message_key', length: 255)]
    private string $messageKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $value = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** User identifier of the last editor (no relation to the host User entity). */
    #[ORM\Column(name: 'updated_by', length: 180, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct(string $locale, string $domain, string $messageKey, ?DateTimeImmutable $now = null)
    {
        $this->locale     = $locale;
        $this->domain     = $domain;
        $this->messageKey = $messageKey;
        $this->createdAt  = $now ?? new DateTimeImmutable();
        $this->updatedAt  = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value, ?string $updatedBy = null, ?DateTimeImmutable $now = null): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value = $value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedBy = $updatedBy !== null && $updatedBy !== '' ? mb_substr($updatedBy, 0, 180) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = $now ?? new DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }
}
