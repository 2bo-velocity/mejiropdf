<?php

declare(strict_types=1);

/**
 * MejiroPDF — image embedding example.
 *
 * JPEGs stream in untouched (DCTDecode); PNGs embed with a predictor, and
 * transparent PNGs get a soft mask — all with a flat memory footprint.
 *
 * Run on PHP 8.3+:  php examples/image.php [image.jpg|png ...]
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;

$fixtures = __DIR__ . '/../tests/fixtures/images';
$images = $argv;
\array_shift($images);
if ($images === []) {
    $images = ["{$fixtures}/rgb.jpg", "{$fixtures}/rgb.png", "{$fixtures}/rgba.png"];
}

$pdf = new Document();
$pdf->addPage()->text(20, 25, 'Image embedding', size: 20.0, color: '#06B6D4');

$y = 40.0;
foreach ($images as $path) {
    if (!\is_readable($path)) {
        \fwrite(\STDERR, "skip (unreadable): {$path}\n");
        continue;
    }
    $pdf->text(20, $y, \basename($path), size: 10.0);
    $pdf->image($path, 20, $y + 3, width: 50.0); // height auto from aspect ratio
    $y += 60.0;
}

$pdf->save(__DIR__ . '/image.pdf');

echo "Wrote image.pdf.\n";
