<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

$ext = pathinfo($path, PATHINFO_EXTENSION);
$staticExts = ['css','js','png','jpg','jpeg','gif','webp','svg','ico','ttf','woff','woff2','json','map'];

if ($ext && in_array(strtolower($ext), $staticExts)) {
    $public = __DIR__;
    $file = $public . $path;
    if (file_exists($file)) {
        return false;
    }
}

if (str_starts_with($path, '/install')) {
    require __DIR__ . '/install.php';
    return true;
}

if (str_starts_with($path, '/admin')) {
    require __DIR__ . '/admin.php';
    return true;
}

require __DIR__ . '/index.php';
return true;