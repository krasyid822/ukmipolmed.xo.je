<?php
$lines = file(__DIR__ . '/../blog.php');
foreach ($lines as $i => $l) {
    if (trim($l) === '}') echo ($i+1) . ": }\n";
}
