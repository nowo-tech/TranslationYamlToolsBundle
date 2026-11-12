<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host;

use Doctrine\ORM\Mapping as ORM;

/**
 * A standard (attribute-mapped) host entity on the application's default manager: the bundle must never flush it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id on persist) */
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $title,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
