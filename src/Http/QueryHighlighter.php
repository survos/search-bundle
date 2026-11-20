<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Http;

final class QueryHighlighter
{
    /** Literal terms only; intentionally does not claim stemmer or fuzzy-match parity. */
    public static function highlight(string $text, string $query): ?array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $terms = preg_split('/[^\p{L}\p{N}]+/u', $query, flags: PREG_SPLIT_NO_EMPTY);
        if (!$terms) {
            return null;
        }
        $terms = array_unique($terms);
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).')/iu';
        $parts = preg_split($pattern, $text, flags: PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts || 1 === count($parts)) {
            return null;
        }
        $html = '';
        $matched = [];
        foreach ($parts as $i => $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= $i % 2 ? '<mark>'.$escaped.'</mark>' : $escaped;
            if ($i % 2) {
                $matched[] = $part;
            }
        }

        return ['value' => $html, 'matchLevel' => 'partial', 'matchedWords' => array_values(array_unique($matched))];
    }
}
