<?php

declare(strict_types=1);

/**
 * MejiroPDF — embedded TrueType example.
 *
 * Embeds a .ttf so accented Latin (café, £, ¥, ©) renders identically in every
 * viewer — something the Standard 14 WinAnsi fonts can only do for plain ASCII.
 *
 * Run on PHP 8.3+ (ext-mbstring):  php examples/truetype.php [/path/to/font.ttf]
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Font\TrueTypeFont;

$path = $argv[1] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
if (!\is_readable($path)) {
    \fwrite(\STDERR, "Font not found: {$path}\nPass a .ttf path as the first argument.\n");
    exit(1);
}

$font = TrueTypeFont::fromFile($path);

$pdf = new Document();
$pdf->addPage()
    ->text(20, 30, 'Embedded TrueType', font: StandardFont::HELVETICA, size: 20.0, color: '#06B6D4')
    ->text(20, 45, "Accented Latin renders perfectly:", font: $font, size: 13.0)
    ->text(20, 55, "café, naïve, £, ¥, €, ©, — “curly quotes”", font: $font, size: 16.0)
    ->text(20, 68, "Œuvre — Ærø — Straße — ½ ⅓ ¾", font: $font, size: 16.0);

$pdf->save(__DIR__ . '/truetype.pdf');

echo "Wrote truetype.pdf using {$font->baseFontName()}.\n";
