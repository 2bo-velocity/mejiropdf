<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. This program is distributed WITHOUT ANY WARRANTY; see the GNU
 * Affero General Public License <https://www.gnu.org/licenses/> for details.
 */

namespace MejiroPDF\Page;

/**
 * Controls the mandatory "Powered by MejiroPDF" footer credit.
 *
 * Under AGPLv3 the credit is enabled by default. Holders of a commercial
 * licence construct this with enabled: false. The engine performs no
 * license-key verification of its own and never contacts the network: PHP ships
 * as source, so a key check would be trivially removable, and a network round
 * trip would cost more than every other operation in this library combined.
 * Whether disabling it is permitted is settled by the licence, not by code.
 *
 * Disabling does not remove the credit — it makes it invisible. The draw
 * command is still emitted, on the page's back layer, in the page background
 * colour, so the string survives in the content stream and ordinary text
 * extraction can still find it. That is deliberate: the marker is what makes a
 * licence audit possible, and being extractable is the point rather than a
 * leak.
 */
final readonly class CreditOptions
{
    /** Appended to the marker so an audit can tell which terms were in play. */
    public const string MARKER_SUFFIX = ' [AGPLv3 Community Edition]';

    /**
     * @param float       $fontSize     Font size (pt).
     * @param float       $gray         Grey level of the credit ink, 0.0 (black) .. 1.0 (white).
     * @param float       $marginBottom Distance from the page bottom edge (mm).
     * @param float       $marginSide   Distance from the left/right page edge (mm).
     * @param string|null $markerColor  Page background colour (#RRGGBB) the invisible
     *                                  marker blends into; null means white. Set this
     *                                  when the page carries a background colour, or the
     *                                  marker shows up against it.
     */
    public function __construct(
        public bool $enabled = true,
        public string $text = 'Powered by MejiroPDF',
        public float $fontSize = 7.5,
        public float $gray = 0.5,
        public float $marginBottom = 8.0,
        public float $marginSide = 12.0,
        public ?string $linkUrl = 'https://mejiropdf.com',
        public ?string $markerColor = null,
    ) {
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    /**
     * The string drawn when the credit is hidden: the credit plus a licence
     * identifier, so a extracted match is unambiguous.
     */
    public function markerText(): string
    {
        return $this->text . self::MARKER_SUFFIX;
    }
}
