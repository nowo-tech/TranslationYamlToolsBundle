<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Host;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Host SQL filter of the test kernels (tenant-style parameter); adds no constraint, only its state is asserted.
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return '';
    }
}
