<?php

declare(strict_types=1);

// Scanned as text by TranslationParityChecker::checkLiteralKeys() (never autoloaded).
$translator->trans('a.b');
$translator->trans('missing.key', ['%x%' => 1]);
$translator->trans('form.only', [], 'form');
$translator->trans($computed);
$controller->addFlash('success', 'flash.ok');
$controller->addFlash('error', 'flash.missing');
