<?php
// Rebuild the PWA raster icons with PHP's GD extension. No external assets needed.
foreach ([192, 512] as $size) {
    $im = imagecreatetruecolor($size, $size);
    $teal = imagecolorallocate($im, 23, 108, 96); $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $teal); imageantialias($im, true);
    $points = [];
    for ($i = 0; $i <= 100; $i++) {
        $t = $i / 100; $y = .22 + .39 * $t; $x = .5 + .215 * sin($t * M_PI / 2);
        $points[] = (int) ($x * $size); $points[] = (int) ($y * $size);
    }
    for ($i = 0; $i <= 100; $i++) { $a = $i / 100 * M_PI; $points[] = (int) ((.5 + .215 * cos($a)) * $size); $points[] = (int) ((.61 + .215 * sin($a)) * $size); }
    for ($i = 100; $i >= 0; $i--) { $t = $i / 100; $points[] = (int) ((.5 - .215 * sin($t * M_PI / 2)) * $size); $points[] = (int) ((.22 + .39 * $t) * $size); }
    imagefilledpolygon($im, $points, $white); imagepng($im, __DIR__."/../public/icons/icon-$size.png"); unset($im);
}
