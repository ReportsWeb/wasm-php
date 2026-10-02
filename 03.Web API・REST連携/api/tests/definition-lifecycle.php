<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Pao\Reports\Web\PrintData;
$first=['Version'=>'1','CoordinateUnit'=>'mm','Objects'=>[],'Title'=>'first'];
$second=$first; $second['Title']='second';
$document=(new PrintData())->setDefinition($first)->pageStart();
$rejected=false;
try { $document->setDefinition($second); } catch (LogicException $e) { $rejected=true; }
if (!$rejected) throw new RuntimeException('Definition changed inside an unfinished page');
$document->setValue('message','first page')->pageEnd()->setDefinition($second)->pageStart()->pageEnd();
$data=$document->toArray();
if ($data['Pages'][0]['Definition']['Title']!=='first' || $data['Definition']['Title']!=='second')
    throw new RuntimeException('Changing the default definition changed a completed page');
echo "PASS: unfinished-page guard and completed-page definition preservation\n";
