<?php
require('../../config.php');
require_login();
require_once($CFG->libdir . '/messagelib.php');
require_once($CFG->libdir . '/filelib.php');

// Apenas para retornar nome e e-mail no carregamento do modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    echo json_encode([
        'nome' => fullname($USER),
        'email' => $USER->email
    ]);
    exit;
}

// Captura os dados do formulário
$assunto  = trim(clean_param($_POST['assunto'] ?? '', PARAM_TEXT));
$mensagem = trim(clean_param($_POST['mensagem'] ?? '', PARAM_RAW));
$categoria = trim(clean_param($_POST['categoria'] ?? '', PARAM_TEXT));

if ($assunto === '' || $mensagem === '' || $categoria === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Todos os campos são obrigatórios.',
        'nome' => fullname($USER),
        'email' => $USER->email
    ]);
    exit;
}

// Dados do solicitante
$nomeSolicitante  = fullname($USER);
$emailSolicitante = $USER->email;
$siteurl = parse_url($CFG->wwwroot, PHP_URL_HOST);
$tituloEmail = "[Suporte Moodle - $siteurl] [$categoria] $assunto";

// Corpo do e-mail
$emailmessage = <<<EOT
📩 NOVA MENSAGEM DE SUPORTE

👤 Solicitante: $nomeSolicitante
📧 E-mail: $emailSolicitante
🏷️ Categoria: $categoria

📝 Assunto: $assunto

📄 Mensagem:
$mensagem

---
Para responder, envie um e-mail para: $emailSolicitante
EOT;

// Salvar anexo em moodledata/temp/suporte/
if (!empty($_FILES['anexo']['tmp_name'])) {
    $maxsize = 5 * 1024 * 1024;
    if ($_FILES['anexo']['size'] > $maxsize) {
        echo json_encode([
            'success' => false,
            'error' => 'O anexo excede o limite de 5MB.'
        ]);
        http_response_code(400);
        exit;
    }

    $filename = clean_param($_FILES['anexo']['name'], PARAM_FILE);
    $safehash = sha1(time() . $USER->id . $filename);
    $finalname = $safehash . '_' . $filename;

    $supportdir = make_temp_directory('suporte');
    $filepath = $supportdir . '/' . $finalname;

    if (move_uploaded_file($_FILES['anexo']['tmp_name'], $filepath)) {
        $url = new moodle_url('/local/suporte/download.php', ['f' => $finalname]);
        $emailmessage .= "\n\n📎 Arquivo enviado: " . $url->out(false);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao salvar o anexo.'
        ]);
        http_response_code(500);
        exit;
    }
}

// Envio de teste
$destinatario = $DB->get_record('user', ['email' => 'helpdesk@dtcom.com.br']);

$success = email_to_user($destinatario, $USER, $tituloEmail, $emailmessage);

header('Content-Type: application/json');
echo json_encode([
    'success' => $success,
    'nome' => $nomeSolicitante,
    'email' => $emailSolicitante
]);
?>
