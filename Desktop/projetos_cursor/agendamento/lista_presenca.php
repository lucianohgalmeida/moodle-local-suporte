<?php
// Lista de presença para impressão
require_once(__DIR__ . '/../config.php');
require_login();

// Verificação de capability
$context = context_system::instance();
if (!has_capability('moodle/site:config', $context) && !has_capability('moodle/course:view', $context) && !has_capability('moodle/group:manage', $context)) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

// Parâmetros
$courseid = required_param('course', PARAM_INT);
$groupid = required_param('group', PARAM_INT);

// Carregar dados do curso e grupo
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$group = $DB->get_record('groups', ['id' => $groupid], '*', MUST_EXIST);

// Carregar membros do grupo - Alterado para ordenar por firstname
$members = [];
$sql = "SELECT u.id, u.firstname, u.lastname, u.email 
        FROM {user} u
        JOIN {groups_members} gm ON gm.userid = u.id
        WHERE gm.groupid = :groupid
        AND u.deleted = 0
        ORDER BY u.firstname, u.lastname";
$members = $DB->get_records_sql($sql, ['groupid' => $groupid]);

// Carregar categoria do curso
$category = $DB->get_record('course_categories', ['id' => $course->category], '*', MUST_EXIST);

// Formatar a data atual
$date = userdate(time(), '%d/%m/%Y');

// Definição do estilo para impressão
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Presença - <?php echo $course->fullname; ?></title>
    <style>
        @media print {
            @page {
                size: 21cm 29.7cm; /* A4 */
                margin: 0;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 12pt;
                line-height: 1.3;
                color: #000;
                margin: 0;
                padding: 0;
                width: 100%;
                height: 100%;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .container {
                width: 100%;
                max-width: 19cm;
                margin: 1cm auto;
                padding: 0;
                box-sizing: border-box;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
            }
            .header img {
                max-width: 250px;
                height: auto;
            }
            .course-info {
                margin-bottom: 20px;
                text-align: center;
            }
            .course-name {
                font-size: 16pt;
                font-weight: bold;
                margin-bottom: 10px;
            }
            .course-category {
                font-size: 14pt;
                margin-bottom: 5px;
            }
            .date {
                font-size: 12pt;
                text-align: right;
                margin-bottom: 20px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
            th, td {
                border: 1px solid #000;
                padding: 8px;
                text-align: left;
            }
            th {
                background-color: #f2f2f2;
            }
            .signature-line {
                border-top: 1px solid #000;
                width: 200px;
                margin-top: 50px;
                text-align: center;
                padding-top: 5px;
            }
            .no-print {
                display: none;
            }
            .footer {
                text-align: center;
                font-size: 10pt;
                margin-top: 30px;
                position: absolute;
                bottom: 1cm;
                left: 0;
                right: 0;
            }
            /* Evitar quebras de página em lugares inadequados */
            .page-break {
                page-break-before: always;
            }
            .avoid-break {
                page-break-inside: avoid;
            }
        }

        /* Estilos para visualização na tela */
        body {
            font-family: Arial, sans-serif;
            font-size: 12pt;
            line-height: 1.3;
            color: #000;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            width: 100%;
            max-width: 21cm;
            margin: 0 auto;
            padding: 30px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header img {
            max-width: 250px;
            height: auto;
        }
        .course-info {
            margin-bottom: 20px;
            text-align: center;
        }
        .course-name {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .course-category {
            font-size: 14pt;
            margin-bottom: 5px;
        }
        .date {
            font-size: 12pt;
            text-align: right;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            margin-top: 50px;
            text-align: center;
            padding-top: 5px;
            margin-left: auto;
            margin-right: auto;
        }
        .no-print {
            margin-bottom: 20px;
            text-align: center;
        }
        .no-print button {
            padding: 10px 20px;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
        }
        .no-print button.secondary {
            background-color: #6c757d;
        }
        .footer {
            text-align: center;
            font-size: 10pt;
            margin-top: 30px;
            color: #666;
        }
        .print-guide {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Imprimir Lista de Presença</button>
        <button onclick="window.close()" class="secondary">Fechar</button>
        <div class="print-guide">
            <p><strong>Dicas para impressão:</strong></p>
            <ul>
                <li>Use papel A4</li>
                <li>Nas configurações de impressão, selecione "Sem margens" ou "Padrão", se disponível</li>
                <li>Certifique-se de que a opção "Imprimir planos de fundo e imagens" está ativada para exibir as bordas das tabelas</li>
                <li>Desative a opção "Cabeçalhos e rodapés" para evitar bordas extras</li>
            </ul>
        </div>
    </div>
    
    <div class="container avoid-break">
        <div class="header">
            <img src="/agendamento/img/logoUnifsm.png" alt="Logo UNIFSM">
        </div>
        
        <div class="course-info">
            <div class="course-name"><?php echo $course->fullname; ?></div>
            <div class="course-category">Categoria: <?php echo $category->name; ?></div>
            <div>Grupo: <?php echo $group->name; ?></div>
        </div>
        
        <div class="date">Data: <?php echo $date; ?></div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">Nº</th>
                    <th style="width: 45%;">Nome do Estudante</th>
                    <th style="width: 50%;">Assinatura</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                if (!empty($members)) {
                    foreach ($members as $member) {
                        $fullname = $member->firstname . ' ' . $member->lastname;
                        echo '<tr>';
                        echo '<td>' . $counter . '</td>';
                        echo '<td>' . $member->firstname . '</td>';
                        echo '<td></td>';
                        echo '</tr>';
                        $counter++;
                    }
                } else {
                    echo '<tr><td colspan="3" style="text-align:center;">Nenhum estudante inscrito neste grupo.</td></tr>';
                }
                // Adicionar algumas linhas extras para alunos que não estejam na lista
                for ($i = 0; $i < 5; $i++) {
                    echo '<tr>';
                    echo '<td>' . $counter . '</td>';
                    echo '<td></td>';
                    echo '<td></td>';
                    echo '</tr>';
                    $counter++;
                }
                ?>
            </tbody>
        </table>
        
        <div style="text-align: center;">
            <div class="signature-line">Assinatura do Aplicador</div>
        </div>
        
        <div class="footer">
            Lista de presença gerada em <?php echo $date; ?> via Sistema de Agendamento de Provas Presenciais
        </div>
    </div>
</body>
</html> 