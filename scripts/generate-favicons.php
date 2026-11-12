<?php
declare(strict_types=1);

$srcPath = __DIR__ . '/../public/assets/images/PICKLERS_LOGO.png';
if (!file_exists($srcPath)) {
    die("Source logo not found: $srcPath\n");
}

$src = imagecreatefrompng($srcPath);
$w = imagesx($src);
$h = imagesy($src);

foreach ([16, 32, 48, 192] as $dim) {
    $thumb = imagecreatetruecolor($dim, $dim);
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
    imagefilledrectangle($thumb, 0, 0, $dim, $dim, $transparent);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $dim, $dim, $w, $h);
    imagepng($thumb, __DIR__ . "/../public/assets/images/favicon-{$dim}x{$dim}.png");
    imagepng($thumb, __DIR__ . "/../public/favicon-{$dim}x{$dim}.png");
    imagedestroy($thumb);
}

// Generate standard ICO file containing 16x16, 32x32, 48x48
$icons = [
    16 => file_get_contents(__DIR__ . '/../public/favicon-16x16.png'),
    32 => file_get_contents(__DIR__ . '/../public/favicon-32x32.png'),
    48 => file_get_contents(__DIR__ . '/../public/favicon-48x48.png'),
];

$count = count($icons);
$header = pack('vvv', 0, 1, $count);
$dirs = '';
$offset = 6 + ($count * 16);
$data = '';

foreach ($icons as $size => $pngBytes) {
    $len = strlen($pngBytes);
    $dirs .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $len, $offset);
    $data .= $pngBytes;
    $offset += $len;
}

$ico = $header . $dirs . $data;
file_put_contents(__DIR__ . '/../public/assets/images/favicon.ico', $ico);
file_put_contents(__DIR__ . '/../public/favicon.ico', $ico);
file_put_contents(__DIR__ . '/../favicon.ico', $ico);

// Copy SVG to root and public/
copy(__DIR__ . '/../public/assets/images/favicon.svg', __DIR__ . '/../public/favicon.svg');
copy(__DIR__ . '/../public/assets/images/favicon.svg', __DIR__ . '/../favicon.svg');

echo "Favicons generated and copied successfully!\n";

