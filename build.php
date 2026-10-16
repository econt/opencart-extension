<?php
/**
 * Прави двата пакета на модула в dist/:
 *  - econt_delivery.ocmod.zip      OpenCart 3: install.xml + upload/admin/…, upload/catalog/… от oc3/
 *  - econt_delivery_oc4.ocmod.zip  OpenCart 4: съдържанието на oc4/ в корена на zip-а. Името е точно това:
 *                                  инсталаторът на OC4 взима от него името на разширението, а от него
 *                                  зависи namespace-ът EcontDeliveryOc4.
 *
 * Версията е само във VERSION. Тя влиза в install.xml (OC3) и в install.json (OC4), като oc4/install.json
 * се обновява, ако е с друга версия.
 *
 * Пускане: php build.php (PHP 7.4+ с разширението zip)
 */

const ROOT = __DIR__;
const DIST = ROOT . '/dist';

$version = trim((string)@file_get_contents(ROOT . '/VERSION'));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fail("VERSION: очаква се X.Y.Z, а е '$version'");
}

if (!is_dir(DIST) && !mkdir(DIST)) {
    fail('Не може да се създаде ' . DIST);
}

// OpenCart 3
// Кодът на модификацията трябва да е различен за всяка версия, иначе OC3 отказва да я качи.
// Проверката за нова версия в админа търси 'econt_delivery_and_payment%' в oc_modification.
$commit = gitShortHash();
$installXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<modification>
    <code>econt_delivery_and_payment$commit</code>
    <name>Econt payment and delivery</name>
    <version>$version</version>
    <author>Econt Express</author>
    <link>https://delivery.econt.com</link>
</modification>
XML;
$files = ['install.xml' => $installXml];
foreach (listFiles(ROOT . '/oc3', ['admin', 'catalog']) as $file) {
    $files['upload/' . $file] = ROOT . '/oc3/' . $file;
}
writeZip(DIST . '/econt_delivery.ocmod.zip', $files);

// OpenCart 4
$installJsonFile = ROOT . '/oc4/install.json';
$installJson = json_decode((string)file_get_contents($installJsonFile), true);
if (!is_array($installJson)) {
    fail("$installJsonFile: невалиден JSON");
}
if (($installJson['version'] ?? null) !== $version) {
    $installJson['version'] = $version;
    file_put_contents($installJsonFile, json_encode($installJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "oc4/install.json: версията е сменена на $version\n";
}
$files = [];
foreach (listFiles(ROOT . '/oc4', ['admin', 'catalog', 'install.json']) as $file) {
    $files[$file] = ROOT . '/oc4/' . $file;
}
writeZip(DIST . '/econt_delivery_oc4.ocmod.zip', $files);

echo "Версия $version ($commit)\n";

/**
 * Файловете под $paths (папки или файлове), относително спрямо $base, с / за разделител и подредени.
 * Пропуска скритите файлове (.DS_Store и т.н.).
 */
function listFiles(string $base, array $paths): array
{
    $result = [];
    foreach ($paths as $path) {
        if (is_file("$base/$path")) {
            $result[] = $path;
            continue;
        }
        if (!is_dir("$base/$path")) {
            fail("Липсва $base/$path");
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/$path", FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename()[0] !== '.') {
                $result[] = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            }
        }
    }
    sort($result);
    return $result;
}

/**
 * @param array $files име в zip-а => път до файла или съдържанието му (за install.xml)
 */
function writeZip(string $zipFile, array $files)
{
    @unlink($zipFile);
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
        fail("Не може да се създаде $zipFile");
    }
    foreach ($files as $name => $source) {
        $ok = $name === 'install.xml' ? $zip->addFromString($name, $source) : $zip->addFile($source, $name);
        if (!$ok) {
            fail("$zipFile: не може да се добави $name");
        }
    }
    $zip->close();
    echo basename($zipFile) . ': ' . count($files) . " файла\n";
}

/**
 * Краткият hash на HEAD. Чете .git директно, ако няма git (например в контейнера на OpenCart).
 */
function gitShortHash(): string
{
    $hash = trim((string)@shell_exec('git -C ' . escapeshellarg(ROOT) . ' rev-parse --short HEAD 2>/dev/null'));
    if ($hash !== '') {
        return $hash;
    }
    $head = trim((string)@file_get_contents(ROOT . '/.git/HEAD'));
    if (strpos($head, 'ref: ') === 0) {
        $ref = substr($head, 5);
        $head = trim((string)@file_get_contents(ROOT . "/.git/$ref"));
        if ($head === '' && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', (string)@file_get_contents(ROOT . '/.git/packed-refs'), $m)) {
            $head = $m[1];
        }
    }
    if (!preg_match('/^[0-9a-f]{40}$/', $head)) {
        fail('Не може да се определи commit-ът на HEAD');
    }
    return substr($head, 0, 7);
}

function fail(string $message)
{
    fwrite(STDERR, "build.php: $message\n");
    exit(1);
}
