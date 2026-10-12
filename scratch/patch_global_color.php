<?php
$dir = 'c:/xampp/htdocs/pragmavet';
$files = glob($dir . '/*.php');

$replacements = [
    '#1E5AA8' => '#2563eb', // primary navy -> bright blue
    '#1e5aa8' => '#2563eb', 
    '#15407A' => '#1e40af', // dark navy -> dark bright blue
    '#15407a' => '#1e40af',
    '#4A7FC4' => '#3b82f6', // secondary -> lighter blue
    '#4a7fc4' => '#3b82f6',
    '30, 90, 168' => '37, 99, 235', // rgba spaces
    '30,90,168' => '37,99,235', // rgba no spaces
    '#1877b6' => '#1d4ed8', // gradient end
    '#1877B6' => '#1d4ed8'
];

$count = 0;
foreach ($files as $file) {
    if (is_file($file)) {
        $content = file_get_contents($file);
        $original = $content;
        
        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
        
        if ($content !== $original) {
            file_put_contents($file, $content);
            echo "Updated colors in: " . basename($file) . "\n";
            $count++;
        }
    }
}
echo "\nTotal files updated: $count\n";
?>
