<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Ddd\Domain;

/**
 * A DDD host's domain entity: plain PHP, mapped in XML (Ddd/mapping/Page.orm.xml), no bundle base class or trait. It
 * references its editable text only by translation key (a string), never by a bundle entity.
 */
class Page
{
    /** @phpstan-ignore property.onlyWritten, property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    public function __construct(
        private string $slug,
        private string $titleKey,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function titleKey(): string
    {
        return $this->titleKey;
    }
}
