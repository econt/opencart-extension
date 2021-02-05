<?php

define('BASE_DIR', dirname(__FILE__));

define('EXTENSION_VERSION', '2.0.1');
define('EXTENSION_ZIP_NAME', 'EcontDelivery-OpenCart-Extensio.ocmod.zip');

//@unlink(BASE_DIR . DIRECTORY_SEPARATOR . EXTENSION_ZIP_NAME);

//$zip = new ZipArchive();
//if ($zip->open(BASE_DIR . DIRECTORY_SEPARATOR . EXTENSION_ZIP_NAME, ZipArchive::CREATE) !== true) die('error 1');

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
//        var_dump($dirContent->getPathname());
        var_dump('upload' . str_replace(BASE_DIR, '', $file));
    }
//    $zip->addFile($file, 'upload' . str_replace(BASE_URL, '', $file));

//    if (count(glob($dirContent->getPathName() . DIRECTORY_SEPARATOR . '*econt*', GLOB_BRACE)) > 0) continue;


//    $totalCount++;
//    if ($params['justCalc']) echo $dirContent->getPathName() . '; ';
//    else {
//        $result = (Util::removeDir($dirContent->getPathName()) ? 'OK' : 'ERR');
//        echo $dirContent->getPathName() . ' - ' . $result . '; ';
//        if ($result === 'ERR') {
//            $totalCount--;
//            $totalCountWithError++;
//        }
//    }
}

//foreach (new RegexIterator(
//    new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)),
//    '/^+econt_/i',
//    RecursiveRegexIterator::GET_MATCH,
//    RegexIterator::USE_KEY
//) as $file) {
//    var_dump($file);
//    $file = reset($file);
//    if (in_array(basename($file), ['econt_delivery_create_ocmod_zip.php', 'econt_delivery.ocmod.zip'])) continue;
//
////    var_dump();
////    $zip->addFile($file, DIRECTORY_SEPARATOR . 'upload' . str_replace(BASE_URL, '', $file));
//}