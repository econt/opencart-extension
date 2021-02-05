<?php

define('BASE_DIR', dirname(__FILE__));

define('EXTENSION_VERSION', '2.0.1');
define('EXTENSION_ZIP_NAME', 'EcontDelivery-OpenCart-Extensio.ocmod.zip');

@unlink(BASE_DIR . DIRECTORY_SEPARATOR . EXTENSION_ZIP_NAME);

$zip = new ZipArchive();
if ($zip->open(BASE_DIR . DIRECTORY_SEPARATOR . EXTENSION_ZIP_NAME, ZipArchive::CREATE) !== true) die('Error 1');

/** @var RecursiveDirectoryIterator $dirContent */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $dirContent) {
    $pathName = $dirContent->getPathName();
    if (!$dirContent->isDir()) continue;

    $pathName = $dirContent->getPathName();
    if (in_array(str_replace(BASE_DIR, '', $pathName), [
        '/.idea'
    ])) continue;

    $files = glob($pathName . DIRECTORY_SEPARATOR . '*econt*', GLOB_BRACE);
    if (count($files) <= 0) continue;
    foreach ($files as $file) {
        $zip->addFile($file, 'upload' . str_replace(BASE_URL, '', $file));
    }
}
$zip->addFromString('install.xml', "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<modification>
    <code>econt_delivery_and_payment" . trim(`git rev-parse --short HEAD`) . "</code>
    <name>Econt payment and delivery</name>
    <version>1.5.1</version>
    <author>Econt Express</author>
    <link>https://delivery.econt.com</link>
</modification>");
$zip->close();