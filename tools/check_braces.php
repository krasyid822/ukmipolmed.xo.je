<?php
$path = __DIR__ . '/../blog.php';
$s = file_get_contents($path);
$len = strlen($s);
$stack = [];
$in_s = 0; // 1 double, 2 single
$in_c = 0; // 1 inside /* */
$line = 1;
$positions = [];
for ($i = 0; $i < $len; $i++) {
    $ch = $s[$i];
    if ($ch === "\n") $line++;
    $next2 = ($i + 1 < $len) ? $s[$i+1] : '';
    if ($in_c) {
        if ($ch === '*' && $next2 === '/') { $in_c = 0; $i++; continue; }
        continue;
    }
    if ($in_s) {
        if ($in_s === 1 && $ch === '"' && $s[$i-1] !== '\\') { $in_s = 0; }
        if ($in_s === 2 && $ch === '\'' && $s[$i-1] !== '\\') { $in_s = 0; }
        continue;
    }
    if ($ch === '/' && $next2 === '/') { // skip to EOL
        $npos = strpos($s, "\n", $i+2);
        if ($npos === false) break;
        $i = $npos;
        continue;
    }
    if ($ch === '/' && $next2 === '*') { $in_c = 1; $i++; continue; }
    if ($ch === '"') { $in_s = 1; continue; }
    if ($ch === "'") { $in_s = 2; continue; }
    if ($ch === '{') { $stack[] = [$i, $line]; }
    if ($ch === '}') {
        if (count($stack) === 0) {
            $positions[] = [ 'type' => 'unmatched_closing', 'byte' => $i, 'line' => $line ];
        } else {
            array_pop($stack);
        }
    }
}
foreach ($stack as $p) $positions[] = ['type' => 'unmatched_opening', 'byte' => $p[0], 'line' => $p[1]];
if (empty($positions)) {
    echo "All braces matched\n";
} else {
    foreach ($positions as $pos) {
        echo $pos['type'] . " at line " . $pos['line'] . " (byte " . $pos['byte'] . ")\n";
        $lines = explode("\n", $s);
        $ln = $pos['line'];
        $start = max(1, $ln - 3);
        $end = min(count($lines), $ln + 3);
        echo "--- context around line $ln ---\n";
        for ($i = $start; $i <= $end; $i++) {
            $mark = ($i === $ln) ? '>>' : '  ';
            echo sprintf("%s %4d: %s\n", $mark, $i, $lines[$i-1]);
        }
        echo "------------------------------\n";
    }
}
