<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-protected action form of the missing-translation log Web UI ("Mark added", "Clear current status",
 * "Clear all rows"). It carries no visible field: the CSRF token, plus a hidden {@code status} child when the
 * {@code status} option is set (the status the list returns to after the action).
 *
 * Set {@code csrf_token_id} to the action's token id (e.g. {@code missing_log_clear}).
 *
 * @extends AbstractType<array<string, mixed>|null>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
final class MissingTranslationLogActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['status'] !== null) {
            $builder->add('status', HiddenType::class, [
                'data' => $options['status'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'status'          => null,
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
        ]);
        $resolver->setAllowedTypes('status', ['null', 'string']);
    }
}
