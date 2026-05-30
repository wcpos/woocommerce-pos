<?php

/**
 * @package php-font-lib
 * @link    https://github.com/dompdf/php-font-lib
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace WCPOS\Vendor\FontLib\Table\Type;

use WCPOS\Vendor\FontLib\Table\Table;
use WCPOS\Vendor\FontLib\Glyph\Outline;
use WCPOS\Vendor\FontLib\Glyph\OutlineSimple;
/**
 * `glyf` font table.
 *
 * @package php-font-lib
 * @property Outline[] $data
 */
class glyf extends Table
{
    protected function _parse()
    {
        $font = $this->getFont();
        $offset = $font->pos();
        $loca = $font->getData("loca");
        $real_loca = \array_slice($loca, 0, -1);
        // Not the last dummy loca entry
        $data = array();
        foreach ($real_loca as $gid => $location) {
            $_offset = $offset + $loca[$gid];
            $_size = $loca[$gid + 1] - $loca[$gid];
            $data[$gid] = Outline::init($this, $_offset, $_size, $font);
        }
        $this->data = $data;
    }
    public function getGlyphIDs($gids = array())
    {
        $glyphIDs = array();
        foreach ($gids as $_gid) {
            $_glyph = $this->data[$_gid];
            $glyphIDs = \array_merge($glyphIDs, $_glyph->getGlyphIDs());
        }
        return \array_unique(\array_merge($gids, $glyphIDs));
    }
    public function toHTML($n = 500)
    {
        $max = 160;
        $font = $this->getFont();
        $head = $font->getData("head");
        $head_json = \json_encode($head);
        $os2 = $font->getData("OS/2");
        $os2_json = \json_encode($os2);
        $hmtx = $font->getData("hmtx");
        $hmtx_json = \json_encode($hmtx);
        $names = $font->getData("post", "names");
        $glyphIndexArray = \array_flip($font->getUnicodeCharMap());
        $width = \abs($head["xMin"]) + $head["xMax"];
        $height = \abs($head["yMin"]) + $head["yMax"];
        $ratio = 1;
        if ($width > $max || $height > $max) {
            $ratio = \max($width, $height) / $max;
            $width = \round($width / $ratio);
            $height = \round($height / $ratio);
        }
        $s = "<h3>" . "Only the first {$n} simple glyphs are shown (" . \count($this->data) . " total)\n    <div class='glyph-view simple'>Simple glyph</div>\n    <div class='glyph-view composite'>Composite glyph</div>\n    Zoom: <input type='range' value='100' max='400' onchange='Glyph.resize(this.value)' />\n    </h3>\n    <script>\n      Glyph.ratio  = {$ratio};\n      Glyph.head   = {$head_json};\n      Glyph.os2    = {$os2_json};\n      Glyph.hmtx   = {$hmtx_json};\n      Glyph.width  = {$width};\n      Glyph.height = {$height};\n    </script>";
        foreach ($this->data as $g => $glyph) {
            if ($n-- <= 0) {
                break;
            }
            $glyph->parseData();
            $shape = array("SVGContours" => $glyph->getSVGContours(), "xMin" => $glyph->xMin, "yMin" => $glyph->yMin, "xMax" => $glyph->xMax, "yMax" => $glyph->yMax);
            $shape_json = \json_encode($shape);
            $type = $glyph instanceof OutlineSimple ? "simple" : "composite";
            $char = isset($glyphIndexArray[$g]) ? $glyphIndexArray[$g] : 0;
            $name = isset($names[$g]) ? $names[$g] : \sprintf("uni%04x", $char);
            $char = $char ? "&#{$glyphIndexArray[$g]};" : "";
            if ($char === "" && empty($shape["SVGContours"])) {
                $n++;
                continue;
            }
            $s .= "<div class='glyph-view {$type}' id='glyph-{$g}'>\n              <span class='glyph-id'>{$g}</span>\n              <span class='char'>{$char}</span>\n              <span class='char-name'>{$name}</span>\n              ";
            if ($type == "composite") {
                foreach ($glyph->getGlyphIDs() as $_id) {
                    $s .= "<a href='#glyph-{$_id}' class='glyph-component-id'>{$_id}</a> ";
                }
            }
            $s .= "<br />\n            <canvas width='{$width}' height='{$height}' id='glyph-canvas-{$g}'></canvas>\n            </div>\n            <script>Glyph.glyphs.push([{$g},{$shape_json}]);</script>";
        }
        return $s;
    }
    protected function _encode()
    {
        $font = $this->getFont();
        $subset = $font->getSubset();
        $data = $this->data;
        $loca = array();
        $length = 0;
        foreach ($subset as $gid) {
            $loca[] = $length;
            $bytes = $data[$gid]->encode();
            $pad = 0;
            $mod = $bytes % 4;
            if ($mod != 0) {
                $pad = 4 - $mod;
                $font->write(\str_pad("", $pad, "\x00"), $pad);
            }
            $length += $bytes + $pad;
        }
        $loca[] = $length;
        // dummy loca
        $font->getTableObject("loca")->data = $loca;
        return $length;
    }
}
