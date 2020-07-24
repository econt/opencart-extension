<?php

define('BASE_URL', dirname(__FILE__));
@unlink(BASE_URL . DIRECTORY_SEPARATOR . 'econt_delivery.ocmod.zip');
$zip = new ZipArchive();
if ($zip->open(BASE_URL . DIRECTORY_SEPARATOR . 'econt_delivery.ocmod.zip', ZipArchive::CREATE) !== true) die('error 1');
//foreach (new RegexIterator(new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)), '/.*econt_[delivery,payment].*/i', RecursiveRegexIterator::GET_MATCH) as $file) { // payment and shipping
foreach (new RegexIterator(new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)), '/.*econt_delivery.*/i', RecursiveRegexIterator::GET_MATCH) as $file) { // only shipping
//foreach (new RegexIterator(new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)), '/.*econt_payment.*/i', RecursiveRegexIterator::GET_MATCH) as $file) { // only payment
    $file = reset($file);
    if (in_array(basename($file), ['econt_delivery_create_ocmod_zip.php', 'econt_delivery.ocmod.zip'])) continue;

//    $zip->addFile($file, DIRECTORY_SEPARATOR . 'upload' . str_replace(BASE_URL, '', $file));
    $zip->addFile($file, 'upload' . str_replace(BASE_URL, '', $file));
}
/**  $zip->addFromString(DIRECTORY_SEPARATOR . 'install.xml', "<?xml version=\"1.0\" encoding=\"utf-8\"?> */
$zip->addFromString('install.xml', "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<modification>
    <code>econt_delivery_" . trim(`git rev-parse --short HEAD`) . "</code>
    <name>Econt Payment</name>
    <version>1.3.1</version>
    <author>Econt Express</author>
    <link>https://delivery.econt.com</link>
</modification>");
$zip->close();