<?php
$path = __DIR__ . '/../blog.php';
$s = file_get_contents($path);
$len = strlen($s);
$balance = 0;
$in_s = 0; $in_c = 0;
$line = 1;
$lines = explode("\n", $s);
$pos = 0;
for ($li = 0; $li < count($lines); $li++) {
    $lineStr = $lines[$li] . "\n";
    $l = strlen($lineStr);
    for ($i = 0; $i < $l; $i++, $pos++) {
        $ch = $lineStr[$i];
        $next = ($i+1<$l)?$lineStr[$i+1]:'';
        if ($in_c) {
            if ($ch=='*' && $next=='/') { $in_c=0; $i++; $pos++; continue; }
            continue;
        }
        if ($in_s) {
            if (($in_s==1 && $ch=='"' && ($i==0 || $lineStr[$i-1] != "\\")) || ($in_s==2 && $ch=="'" && ($i==0 || $lineStr[$i-1] != "\\"))) { $in_s=0; }
            continue;
        }
        if ($ch=='"') { $in_s=1; continue; }
        if ($ch=="'") { $in_s=2; continue; }
        if ($ch=='/' && $next=='/') { break; }
        if ($ch=='/' && $next=='*') { $in_c=1; $i++; $pos++; continue; }
        if ($ch=='{') $balance++;
        if ($ch=='}') $balance--;
    }
    echo str_pad($li+1,4, ' ', STR_PAD_LEFT) . ' | ' . str_pad($balance,4,' ',STR_PAD_LEFT) . ' | ' . rtrim($lines[$li]) . "\n";
}
