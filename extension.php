<?php
// Streams the browser extension (./extension) as a zip. Public: it contains no secrets.
$dir = realpath(__DIR__ . '/extension');
if (!$dir || !class_exists('ZipArchive')) { http_response_code(500); header('Content-Type: text/plain'); exit('The zip extension for PHP is required.'); }
$tmp = tempnam(sys_get_temp_dir(), 'rcx'); $z = new ZipArchive();
$z->open($tmp, ZipArchive::OVERWRITE);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile()) $z->addFile($f->getPathname(), 'current-recap-extension/' . substr($f->getPathname(), strlen($dir) + 1));
}
$z->close();
header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="current-recap-extension.zip"'); header('Content-Length: ' . filesize($tmp));
readfile($tmp); unlink($tmp);
