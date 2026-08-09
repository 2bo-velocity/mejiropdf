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
 * What the document says about itself: the fields a reader shows under
 * "Properties", and the language a screen reader should announce it in.
 *
 * `/Producer`, `/Creator` and the dates are the engine's to write and are not
 * here. Everything here is the author's, and unset fields are simply not
 * emitted — a document that supplies none is byte-for-byte what it was before
 * this existed.
 *
 * For PDF/A the same values are mirrored into the XMP packet, because a
 * property present in both the info dictionary and XMP must agree.
 */
final readonly class DocumentInfo
{
    /**
     * @param string|null $title    `/Title` — the work, not the file name.
     * @param string|null $author   `/Author`.
     * @param string|null $subject  `/Subject`.
     * @param string|null $keywords `/Keywords`. When the credit is hidden the
     *                              licence marker is prepended to this; see
     *                              {@see CreditOptions}.
     * @param string|null $language `/Lang` as an RFC 3066 tag (`ja`, `en-GB`).
     */
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $keywords = null,
        public ?string $language = null,
    ) {
    }

    /** True when nothing was supplied, so the writer can skip it entirely. */
    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->author === null
            && $this->subject === null
            && $this->keywords === null
            && $this->language === null;
    }
}
