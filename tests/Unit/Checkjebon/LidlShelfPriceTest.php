<?php declare(strict_types=1);

use App\Services\Checkjebon\LidlShelfPrice;

// Dataset name and lidl.nl title for the same IAN, observed 2026-10-05.
test('a dataset name agrees with the page title it abbreviates', function (string $datasetName, string $title): void {
    expect(LidlShelfPrice::namesAgree($datasetName, $title))->toBeTrue();
})->with([
    ['Vaatwastabs all in', 'Vaatwastabletten'],
    ['Chocolade noot&roz.', 'Chocoladereep noot & rozijn'],
    ['Kipfilet gerookt', 'Gerookte kipfilet'],
    ['Proteïne poeder', 'Proteïne poeder banaan'],
]);

test('a dataset name for another product does not agree', function (string $datasetName, string $title): void {
    expect(LidlShelfPrice::namesAgree($datasetName, $title))->toBeFalse();
})->with([
    ['Witte mini puntjes', 'Witbrood'],
    ['Chili merlot BIB', 'Chileense Cabernet Sauvignon'],
    ['Bio appels', 'Bio-zwarte bonen'],
    ['Gerookte zalm', 'Gerookte kipfilet'],
    ['Chocolade melk', 'Chocoladereep noot & rozijn'],
    ['Proteïne poeder chocolade', 'Proteïne poeder banaan'],
    ['Volle melk', 'Halfvolle melk'],
    ['Vaatwastabletten 40 stuks', 'Vaatwastabletten 80 stuks'],
]);
