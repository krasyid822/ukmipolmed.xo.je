<?php
$s = file_get_contents(__DIR__ . '/../blog.php');
$open = substr_count($s, '{');
$close = substr_count($s, '}');
echo "opens=$open closes=$close\n";
