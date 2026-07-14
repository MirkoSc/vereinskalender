<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deliberately tiny Markdown subset for the legal pages (headings, lists,
 * links, bold/italic, paragraphs). Input is HTML-escaped first, so the
 * admin-entered content can never inject markup.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        $html = [];
        $listOpen = false;
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . self::inline(implode('<br>', $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$listOpen, &$html): void {
            if ($listOpen) {
                $html[] = '</ul>';
                $listOpen = false;
            }
        };

        foreach (preg_split('/\r\n|\n|\r/', $escaped) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $closeList();
                $level = strlen($m[1]) + 1; // # -> h2 (h1 is the page title)
                $html[] = sprintf('<h%d>%s</h%1$d>', $level, self::inline($m[2]));
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if (!$listOpen) {
                    $html[] = '<ul>';
                    $listOpen = true;
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        // links: [text](https://...) - only http(s), the input is escaped
        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            static fn(array $m): string => '<a href="' . $m[2] . '" rel="noopener">' . $m[1] . '</a>',
            $text,
        );
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        return (string) preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
    }
}
