<?php
declare(strict_types=1);

/**
 * Version info and a tiny Markdown renderer for release notes.
 */

/** GitHub repository ("owner/name") that releases are published to. Empty = no update checks. */
const UPDATE_REPO = 'curlyjason/encounterManager';

const APP_ROOT = __DIR__ . '/..';

function app_version(): string
{
    $v = @file_get_contents(APP_ROOT . '/VERSION');
    return $v === false ? '0.0.0' : trim($v);
}

/** A working copy under git is where releases are made: never self-update it. */
function is_dev_copy(): bool
{
    return is_dir(APP_ROOT . '/.git');
}

/** Just enough Markdown for release notes: headings, bullets, bold, italics, code, links, paragraphs. */
function md_to_html(string $md): string
{
    $inline = function (string $t): string {
        $t = htmlspecialchars($t, ENT_QUOTES);
        $t = preg_replace('~`([^`]+)`~', '<code>$1</code>', $t);
        $t = preg_replace('~\*\*(.+?)\*\*~', '<strong>$1</strong>', $t);
        $t = preg_replace('~(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])~', '<em>$1</em>', $t);
        $t = preg_replace('~\[([^\]]+)\]\((https?://[^)\s]+)\)~', '<a href="$2" target="_blank" rel="noopener">$1</a>', $t);
        return $t;
    };
    $html = [];
    $list = false;
    $para = [];
    $flush = function () use (&$para, &$html, $inline) {
        if ($para) {
            $html[] = '<p>' . $inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    foreach (preg_split('~\R~', $md) as $line) {
        if (preg_match('~^\s*<!--.*-->\s*$~', $line)) {
            continue;
        }
        if (preg_match('~^\s*[-*]\s+(.*)$~', $line, $m)) {
            $flush();
            if (!$list) {
                $html[] = '<ul>';
                $list = true;
            }
            $html[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($list && trim($line) !== '' && preg_match('~^\s{2,}\S~', $line)) {
            $html[count($html) - 1] = substr(end($html), 0, -5) . ' ' . $inline(trim($line)) . '</li>';
            continue;
        }
        if ($list) {
            $html[] = '</ul>';
            $list = false;
        }
        if (preg_match('~^(#{1,4})\s+(.*)$~', $line, $m)) {
            $flush();
            $n = min(4, strlen($m[1]) + 1);
            $html[] = "<h$n>" . $inline($m[2]) . "</h$n>";
        } elseif (trim($line) === '') {
            $flush();
        } else {
            $para[] = trim($line);
        }
    }
    $flush();
    if ($list) {
        $html[] = '</ul>';
    }
    return implode("\n", $html);
}
