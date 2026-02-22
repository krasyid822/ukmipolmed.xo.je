<?php
require __DIR__ . '/../blog.php';
$d = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
$url = save_data_uri_image($d, 'http://localhost');
var_dump($url);
if ($url) {
    $p = __DIR__ . '/../.uploads/' . basename(parse_url($url, PHP_URL_PATH));
    var_dump(file_exists($p), @getimagesize($p));
}
