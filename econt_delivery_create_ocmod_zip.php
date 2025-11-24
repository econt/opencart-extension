<?php
// Zip-ването (може да) работи различно под windows и linux
define('BASE_URL', dirname(__FILE__));
@unlink(BASE_URL . DIRECTORY_SEPARATOR . 'econt_delivery.ocmod.zip');
$zip = new ZipArchive();
if ($zip->open(BASE_URL . DIRECTORY_SEPARATOR . 'econt_delivery.ocmod.zip', ZipArchive::CREATE) !== true) die('error 1');

$directory_iterator = new RecursiveDirectoryIterator(__DIR__, \RecursiveDirectoryIterator::SKIP_DOTS);
$iterator       = new RecursiveIteratorIterator($directory_iterator, RecursiveIteratorIterator::SELF_FIRST);
$iterator->setMaxDepth(-1);

//$regex_iterator = new RegexIterator($iterator, '/^.*econt_payment\.\w{3,}$/iJ');
$regex_iterator = new RegexIterator($iterator, '/^.+econt_(payment|delivery|delivery_customer_info_modal|delivery_version_notification|delivery_checkout_script|payment_logo_dark|payment_logo_light|pay_logo)\.\w{3,}$/i');
$regex_iterator->setMode(RecursiveRegexIterator::GET_MATCH);
$regex_iterator->setFlags(RegexIterator::USE_KEY);

$regex_iterator->next();
while ($regex_iterator->valid()) {
    $item = $regex_iterator->current();
    $file = reset($item);
    if (in_array(basename($file), ['econt_delivery_create_ocmod_zip.php', 'econt_delivery.ocmod.zip'])) continue;

//    $zip->addFile($file, DIRECTORY_SEPARATOR . 'upload' . str_replace(BASE_URL, '', $file));
    $zip->addFile($file, 'upload' . str_replace(BASE_URL, '', $file));
    $regex_iterator->next();
}

//foreach ($regex_iterator as $file) { // payment and shipping
////foreach (new RegexIterator(new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)), '/.*econt_delivery.*/i', RecursiveRegexIterator::GET_MATCH) as $file) { // only shipping
////foreach (new RegexIterator(new RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)), '/.*econt_payment.*/i', RecursiveRegexIterator::GET_MATCH) as $file) { // only payment
//    $file = reset($file);
//    if (in_array(basename($file), ['econt_delivery_create_ocmod_zip.php', 'econt_delivery.ocmod.zip'])) continue;
//
////    $zip->addFile($file, DIRECTORY_SEPARATOR . 'upload' . str_replace(BASE_URL, '', $file));
//    $zip->addFile($file, 'upload' . str_replace(BASE_URL, '', $file));
//}
/**  $zip->addFromString(DIRECTORY_SEPARATOR . 'install.xml', "<?xml version=\"1.0\" encoding=\"utf-8\"?> */
$zip->addFromString('install.xml', "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<modification>
    <code>econt_delivery_and_payment" . trim(`git rev-parse --short HEAD`) . "</code>
    <name>Econt payment and delivery</name>
    <version>1.7.5</version>
    <author>Econt Express</author>
    <link>https://delivery.econt.com</link>
</modification>");
$zip->close();

//function getDirContents($dir, &$results = array()) {
//    $files = scandir($dir);
//
//    foreach ($files as $key => $value) {
//        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
//        if (!is_dir($path)) {
//            $results[] = $path;
//        } else if ($value != "." && $value != "..") {
//            getDirContents($path, $results);
//            $results[] = $path;
//        }
//    }
//
//    return $results;
//}
//
//var_dump(getDirContents(__DIR__ . '/admin/view/template/extension/payment/'));
