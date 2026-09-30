<?php

namespace Plugin\BlastmailSync\Service;

/**
 * blastmail から取得した項目一覧と、EC-CUBE 側の項目候補を突き合わせて、空欄のマッピングを埋める。
 *
 * 照合は 2 段階だけ（表記ゆれの言い換え辞書は持たない）:
 *  1. 項目名の完全一致
 *  2. 正規化して一致（全角英数→半角、英字の大小、空白・記号の除去）
 *
 * 「累計」と「累積」のような語の違いは 2 でも一致しないため、そのままにして未一致として返す。
 * 誤った項目へ同期してしまうより、人に直してもらうほうが安全なため。
 */
class DefineMatcher
{
    /**
     * @param array<int, array{id:string, name:string, type?:string, uses?:bool}> $defines blastmail の使用中の項目
     * @param array<string, array{0:string, 1:string, 2?:string}>                 $catalog CustomerSource::fieldCatalog()
     * @param array<string, string>                                               $current 現在のマッピング（key => 項目名）
     *
     * @return array{mapping: array<string, string>, filled: array<string, string>, unmatched: array<int, string>, ambiguous: array<int, string>}
     *               mapping: 埋めた後のマッピング / filled: 今回埋めたもの（EC-CUBE 側のラベル => 項目名）
     *               unmatched: 一致しなかった EC-CUBE 側のラベル / ambiguous: 正規化すると重複する blastmail の項目名
     */
    public static function autoMap(array $defines, array $catalog, array $current): array
    {
        // 完全一致用と正規化一致用の索引。正規化して衝突する名前は、どちらとも決められないので使わない
        $byName = [];
        $byNormalized = [];
        $collision = [];
        foreach ($defines as $d) {
            $name = (string) ($d['name'] ?? '');
            if ('' === $name) {
                continue;
            }
            $byName[$name] = $name;
            $key = self::normalize($name);
            if ('' === $key) {
                continue;
            }
            if (isset($byNormalized[$key]) && $byNormalized[$key] !== $name) {
                $collision[$key] = true;
                continue;
            }
            $byNormalized[$key] = $name;
        }

        $mapping = $current;
        $filled = [];
        $unmatched = [];
        $ambiguous = [];
        foreach ($catalog as $key => $meta) {
            if ('' !== (string) ($mapping[$key] ?? '')) {
                continue;   // 入力済みは触らない
            }
            $label = (string) $meta[0];
            $candidate = (string) ($meta[2] ?? $meta[0]);
            $hit = $byName[$candidate] ?? null;
            if (null === $hit) {
                $normalized = self::normalize($candidate);
                if (isset($collision[$normalized])) {
                    $ambiguous[] = $candidate;
                    continue;
                }
                $hit = $byNormalized[$normalized] ?? null;
            }
            if (null === $hit) {
                $unmatched[] = $label;
                continue;
            }
            $mapping[$key] = $hit;
            $filled[$label] = $hit;
        }

        return ['mapping' => $mapping, 'filled' => $filled, 'unmatched' => $unmatched, 'ambiguous' => array_values(array_unique($ambiguous))];
    }

    /**
     * 照合用に項目名をそろえる。全角英数・全角スペースを半角にし、英字を小文字にし、空白と記号を落とす。
     * 語そのものは変えない（「累積」を「累計」にはしない）。
     */
    public static function normalize(string $s): string
    {
        if (function_exists('mb_convert_kana')) {
            $s = mb_convert_kana($s, 'asKV', 'UTF-8');   // 英数字・記号・スペースを半角、半角カナを全角に
        }
        $s = mb_strtolower($s, 'UTF-8');

        return (string) preg_replace('/[\s\x{3000}_\-–—・:：\/\\\\()（）\[\]［］{}｛｝.．,，]/u', '', $s);
    }
}
