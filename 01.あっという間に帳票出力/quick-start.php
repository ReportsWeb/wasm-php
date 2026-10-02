<?php
declare(strict_types=1);

require getenv('COMPOSER_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';

use Pao\Reports\Web\PrintData;

function createPrintData(): array
{
    $definition = json_decode(file_get_contents(__DIR__ . '/report.prepdj'), true, 512, JSON_THROW_ON_ERROR);
    return (new PrintData())
        ->setDefinition($definition)
        ->pageStart()
        ->setValue('Message', 'PHPから、あっという間に帳票出力')
        ->pageEnd()
        ->toArray();
}
