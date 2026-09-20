function osmStaticMap(float $lat1, float $lng1, float $lat2, float $lng2, int $w = 900, int $h = 600): ?string
{
$n2x = fn($lon, $z) => ($lon + 180) / 360 * (1 << $z) * 256; $n2y=function ($lat, $z) { $r=deg2rad($lat); return (1 -
    log(tan($r) + 1 / cos($r)) / M_PI) / 2 * (1 << $z) * 256; }; // highest zoom where both points fit inside the image
    with padding $pad=70; for ($z=17; $z> 2; $z--) {
    $dx = abs($n2x($lng1, $z) - $n2x($lng2, $z));
    $dy = abs($n2y($lat1, $z) - $n2y($lat2, $z));
    if ($dx <= $w - 2 * $pad && $dy <=$h - 2 * $pad) break; } $left=($n2x($lng1, $z) + $n2x($lng2, $z)) / 2 - $w / 2;
        $top=($n2y($lat1, $z) + $n2y($lat2, $z)) / 2 - $h / 2; $img=imagecreatetruecolor($w, $h); imagefill($img, 0, 0,
        imagecolorallocate($img, 230, 230, 230)); $n=1 << $z; for ($ty=(int) floor($top / 256); $ty <=(int) floor(($top
        + $h) / 256); $ty++) { if ($ty < 0 || $ty>= $n)
        continue;
        for ($tx = (int) floor($left / 256); $tx <= (int) floor(($left + $w) / 256); $tx++) { $wrapX=(($tx % $n) + $n) %
            $n; $ch=curl_init("https://tile.openstreetmap.org/$z/$wrapX/$ty.png"); curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=> true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'CEE-IT-Connects/1.0 (OJT vicinity map)',
            ]);
            $data = curl_exec($ch);
            $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            if (!$ok || !$data)
            continue;
            $tile = @imagecreatefromstring($data);
            if (!$tile)
            continue;
            imagecopy($img, $tile, (int) round($tx * 256 - $left), (int) round($ty * 256 - $top), 0, 0, 256, 256);
            imagedestroy($tile);
            }
            }

            $p1 = [(int) round($n2x($lng1, $z) - $left), (int) round($n2y($lat1, $z) - $top)];
            $p2 = [(int) round($n2x($lng2, $z) - $left), (int) round($n2y($lat2, $z) - $top)];

            $blue = imagecolorallocate($img, 37, 99, 235);
            $red = imagecolorallocate($img, 220, 38, 38);
            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 0, 0, 0);

            imagesetthickness($img, 4);
            imageline($img, $p1[0], $p1[1], $p2[0], $p2[1], $blue);

            foreach ([[$p1, $red, 'PLV'], [$p2, $blue, 'HTE']] as [$p, $col, $label]) {
            imagefilledellipse($img, $p[0], $p[1], 26, 26, $white);
            imagefilledellipse($img, $p[0], $p[1], 20, 20, $col);
            imagestring($img, 5, $p[0] + 16, $p[1] - 8, $label, $black);
            }

            imagestring($img, 2, 6, $h - 16, '(c) OpenStreetMap contributors', $black);

            $file = tempnam(sys_get_temp_dir(), 'map_');
            imagepng($img, $file);
            imagedestroy($img);
            return $file;
            }