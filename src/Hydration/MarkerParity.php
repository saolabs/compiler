<?php

declare(strict_types=1);

namespace Saola\Compiler\Hydration;

/**
 * So id hydrate giữa Blade và JS của cùng một view.
 *
 * Client claim DOM SSR theo id: element (`$__VIEW_ID__ . '-e1'` ↔ this.html(`e1`)),
 * output và reactive (`@startMarker('output', 'e1o1')` ↔ this.output(`e1o1`)).
 * Một id chỉ có ở một phía là DOM bị dựng lại hoặc nhân đôi lúc hydrate.
 *
 * Chỉ so id tĩnh: id trong vòng lặp (`"-e1l11-{$loop->index}"` ↔ `` `e1l11-${…}` ``)
 * bị bỏ qua ở CẢ hai phía; id của khối lặp bao ngoài vẫn được so.
 */
final class MarkerParity
{
    /** @return array{blade: list<string>, js: list<string>} id chỉ có ở mỗi phía */
    public static function diff(string $blade, string $js): array
    {
        $bladeIds = self::bladeIds($blade);
        $jsIds = self::jsIds($js);

        return [
            'blade' => array_values(array_diff($bladeIds, $jsIds)),
            'js' => array_values(array_diff($jsIds, $bladeIds)),
        ];
    }

    /** @return list<string> */
    public static function bladeIds(string $blade): array
    {
        preg_match_all("/@startMarker\\('(?:output|reactive)',\\s*'([^']+)'/", $blade, $markers);
        preg_match_all("/\\\$__VIEW_ID__ \\. '-([^']+)'/", $blade, $elements);

        return array_values(array_unique([...$markers[1], ...$elements[1]]));
    }

    /** @return list<string> */
    public static function jsIds(string $js): array
    {
        preg_match_all('/this\.(?:html|output|reactive)\(`([^`$]+)`/', $js, $m);

        return array_values(array_unique($m[1]));
    }
}
