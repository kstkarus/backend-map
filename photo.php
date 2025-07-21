<?php
$uploads_dir = __DIR__ . '/uploads/';
$default_img = $uploads_dir . 'default.jpg';

$file = isset($_GET['file']) ? basename($_GET['file']) : '';
$path = $uploads_dir . $file;

if ($file && file_exists($path)) {
    $img = $path;
} else {
    $img = $default_img;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
header('Content-Type: ' . finfo_file($finfo, $img));
finfo_close($finfo);
readfile($img); 