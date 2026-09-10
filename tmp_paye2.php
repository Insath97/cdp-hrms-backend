<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\SriLankanTaxService;

$basic = 250000;
$epf = SriLankanTaxService::epfEmployee($basic);
$taxable = $basic - $epf;
$paye = SriLankanTaxService::paye($taxable);

echo "Basic: $basic" . PHP_EOL;
echo "EPF (8%): $epf" . PHP_EOL;
echo "Taxable (basic - epf): $taxable" . PHP_EOL;
echo "PAYE on taxable: $paye" . PHP_EOL;
echo PHP_EOL;
echo "PAYE on basic (no EPF deduction): " . SriLankanTaxService::paye($basic) . PHP_EOL;
