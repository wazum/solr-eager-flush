<?php

declare(strict_types=1);

/*
 * Writes the .svg files the README embeds out of the .html source.
 *
 * The source keeps every responsive variant in one file behind container queries,
 * which GitHub cannot run. A README picks its variant with <picture media>, so each
 * one has to become a standalone file. The plain name is the variant the README
 * shows by default.
 *
 * Usage: php diagrams/export.php
 */

const EXPORTS = [
    'eager-flush-on-save' => ['' => 'tablet', '-wide' => 'wide', '-narrow' => 'narrow'],
];

function variant(string $source, string $name): string
{
    $open = strpos($source, \sprintf('<svg class="%s"', $name));
    if (false === $open) {
        throw new RuntimeException(\sprintf('no <svg class="%s">', $name));
    }

    $end = strpos($source, '</svg>', $open);
    if (false === $end) {
        throw new RuntimeException(\sprintf('unclosed <svg class="%s">', $name));
    }

    return substr($source, $open, $end + \strlen('</svg>') - $open);
}

/*
 * Without width and height GitHub renders the image at its own default instead of the
 * drawing's aspect ratio. XML knows five entities, so an HTML one such as &middot;
 * parses in the source page but makes the exported file malformed, which GitHub then
 * shows as a broken image.
 */
function standalone(string $markup): string
{
    if (preg_match('/&(?!(amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)[^;\s]*;/', $markup, $stray)) {
        throw new RuntimeException($stray[0] . ' is not an XML entity, write the character itself');
    }

    if (!preg_match('/viewBox="[\d.-]+ [\d.-]+ ([\d.]+) ([\d.]+)"/', $markup, $box)) {
        throw new RuntimeException('no viewBox');
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . preg_replace(
        '/^<svg class="\w+"/',
        \sprintf('<svg width="%s" height="%s"', $box[1], $box[2]),
        $markup,
    ) . "\n";
}

$written = 0;
foreach (EXPORTS as $diagram => $files) {
    $source = file_get_contents(__DIR__ . '/' . $diagram . '.html');
    if (false === $source) {
        throw new RuntimeException('cannot read ' . $diagram . '.html');
    }

    foreach ($files as $suffix => $name) {
        $target = __DIR__ . '/' . $diagram . $suffix . '.svg';
        file_put_contents($target, standalone(variant($source, $name)));
        echo basename($target), ' - ', $name, "\n";
        ++$written;
    }
}

echo $written, " file(s)\n";
