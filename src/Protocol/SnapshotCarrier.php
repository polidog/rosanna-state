<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Snapshot\SnapshotVerificationException;

/**
 * JavaScript なしの全ページ応答で、各 action フォームにページ上のスナップショットを足す (SN-19)。
 * 次の POST はフォーム 1 つ分しか送らず、Isolated のページには他に状態の置き場所がない。
 * 文字列で答える replay には木がないので、出来上がった HTML を読む。
 *
 * @internal
 */
final class SnapshotCarrier
{
    private const MAX_CARRIED = 256;

    /** 中身を文字として読む要素。同じ形の文字列を拾わない */
    private const OPAQUE_TAGS = ['script' => true, 'style' => true, 'textarea' => true, 'title' => true];

    private const TAG_PATTERN = '/\G<([a-zA-Z][^\s\/>]*+)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/';

    private const ATTRIBUTE_PATTERN = '/([^\s=\/"\'>]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>]++)))?/';

    public static function carry(string $html, ?SnapshotSerializer $serializer): string
    {
        if ($serializer === null) {
            return $html;
        }

        [$wrappers, $snapshots, $formEnds] = self::scan($html);
        if ($wrappers < 2 || $formEnds === []) {
            return $html;
        }

        $fields = '';
        $count = 0;
        foreach (array_keys($snapshots) as $json) {
            $json = (string) $json;
            if ($count === self::MAX_CARRIED) {
                break;
            }
            try {
                $serializer->deserialize($json);
            } catch (SnapshotVerificationException) {
                continue; // 運ぶと次の action が 400 になる
            }
            $count++;
            $fields .= '<input type="hidden" name="_rosanna_snapshots[]" value="' . htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        }
        if ($fields === '') {
            return $html;
        }

        // 後ろから入れると前の位置がずれない
        foreach (array_reverse($formEnds) as $offset) {
            $html = substr_replace($html, $fields, $offset, 0);
        }

        return $html;
    }

    /**
     * 開始タグを 1 つずつ読む。属性の値の中や、コメントと文字として読む要素の中の同じ形の文字列を拾わないため。
     *
     * @return array{int, array<string, true>, list<int>} ラッパーの数、スナップショット、フォームの開始タグの直後の位置
     */
    private static function scan(string $html): array
    {
        $wrappers = 0;
        $snapshots = [];
        $formEnds = [];
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length && ($open = strpos($html, '<', $offset)) !== false) {
            if (substr_compare($html, '<!--', $open, 4) === 0) {
                $close = strpos($html, '-->', $open + 4);
                if ($close === false) {
                    break;
                }
                $offset = $close + 3;

                continue;
            }

            if (preg_match(self::TAG_PATTERN, $html, $tag, 0, $open) !== 1) {
                $offset = $open + 1;

                continue;
            }
            $offset = $open + strlen($tag[0]);
            $tagName = strtolower($tag[1]);

            $attributes = self::attributes($tag[2]);
            if (array_key_exists('data-rosanna', $attributes)) {
                $wrappers++;
            }
            if (($attributes['data-rosanna-snapshot'] ?? '') !== '') {
                $snapshots[$attributes['data-rosanna-snapshot']] = true;
            }
            if ($tagName === 'form' && array_key_exists('data-rosanna-form', $attributes)) {
                $formEnds[] = $offset;
            }

            if (isset(self::OPAQUE_TAGS[$tagName])) {
                $close = stripos($html, '</' . $tagName, $offset);
                if ($close === false) {
                    break;
                }
                $offset = $close;
            }
        }

        return [$wrappers, $snapshots, $formEnds];
    }

    /** @return array<string, string> 小文字の名前 => デコードした値 */
    private static function attributes(string $text): array
    {
        preg_match_all(self::ATTRIBUTE_PATTERN, $text, $matches, PREG_SET_ORDER);

        $attributes = [];
        foreach ($matches as $match) {
            $value = ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
            $attributes[strtolower($match[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $attributes;
    }
}
