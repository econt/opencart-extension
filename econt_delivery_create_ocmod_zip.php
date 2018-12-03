<?php

function remove($path) {
    if (!is_dir($path)) {
        return @unlink($path);
    } else {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $dirContent) {
            ($dirContent->isDir() && !$dirContent->isLink()) ? @rmdir($dirContent->getPathname()) : @unlink($dirContent->getPathname());
        }
        return @rmdir($path);
    }
}

$ocmodZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'econt_delivery.ocmod.zip';
remove($ocmodZipPath);

$uploadPath = __DIR__ . DIRECTORY_SEPARATOR . 'upload';
remove($uploadPath);
mkdir($uploadPath, 777, true);

$files = array();
foreach (new RegexIterator(new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)), '/.*econt_delivery.*/i', RecursiveRegexIterator::GET_MATCH) as $file) {
    var_dump($file);
}