<?php
require('../../config.php');

$filename = required_param('f', PARAM_FILE);
$filepath = $CFG->tempdir . '/suporte/' . $filename;

if (!file_exists($filepath)) {
    print_error('Arquivo não encontrado.');
}

$displayname = preg_replace('/^[a-f0-9]{40}_/', '', $filename);
if (empty($displayname) || preg_match('/[^a-zA-Z0-9_.-]/', $displayname)) {
    $displayname = 'arquivo-suporte-' . time();
}

// Limpa buffer e envia headers manualmente
@ob_end_clean();
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $displayname . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));
flush();
readfile($filepath);
exit;