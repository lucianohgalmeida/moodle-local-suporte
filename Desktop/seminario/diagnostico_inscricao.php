<?php
// Iniciar captura de saída para poder exibir erros
ob_start();

// Importar arquivo de configuração
require_once 'config.php';

// Importar funções utilitárias
require_once 'includes/util_funcoes.php';

// Verificar se o ID do curso foi fornecido
if (!isset($_GET['curso_id'])) {
    // Mostrar formulário para inserir o ID do curso
    echo '<!DOCTYPE html>
<html lang="pt-br">
<head>
    <title>Diagnóstico de Limites de Vagas</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.5; }
        h1 { color: #333; }
        form { margin: 20px 0; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"] { padding: 8px; width: 200px; }
        button { padding: 8px 16px; background-color: #0066cc; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #0052a3; }
    </style>
</head>
<body>
    <h1>Diagnóstico de Limites de Vagas</h1>
    
    <form method="GET">
        <div>
            <label for="curso_id">ID do Curso/Seminário:</label>
            <input type="text" id="curso_id" name="curso_id" placeholder="Digite o ID do curso">
        </div>
        <button type="submit">Verificar</button>
    </form>
</body>
</html>';
    ob_end_flush();
    exit;
}

// Pegar o ID do curso da URL
$curso_id = intval($_GET['curso_id']);

// Exibir cabeçalho HTML
echo '<!DOCTYPE html>
<html lang="pt-br">
<head>
    <title>Diagnóstico de Limites de Vagas - Curso ' . $curso_id . '</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.5; }
        h1 { color: #333; }
        h2 { color: #0066cc; margin-top: 30px; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .error { color: #cc0000; }
        .success { color: #009900; }
        .info { color: #0066cc; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
</head>
<body>
    <h1>Diagnóstico de Limites de Vagas</h1>
    <p><a href="?">Voltar para formulário</a></p>
    <h2>Informações do Curso ' . $curso_id . '</h2>';

// Obter informações básicas do curso
echo '<h3>Informações Básicas do Curso:</h3>';
try {
    $curso_info = call_moodle_api('core_course_get_courses', [
        'options' => ['ids' => [$curso_id]]
    ]);
    
    if (isset($curso_info['exception']) || isset($curso_info['error'])) {
        echo '<p class="error">Erro ao obter informações do curso: ' . json_encode($curso_info) . '</p>';
    } else if (empty($curso_info)) {
        echo '<p class="error">Curso não encontrado com o ID: ' . $curso_id . '</p>';
    } else {
        $curso = $curso_info[0];
        echo '<table>';
        echo '<tr><th>Campo</th><th>Valor</th></tr>';
        echo '<tr><td>ID</td><td>' . $curso['id'] . '</td></tr>';
        echo '<tr><td>Nome completo</td><td>' . htmlspecialchars($curso['fullname']) . '</td></tr>';
        echo '<tr><td>Nome curto</td><td>' . htmlspecialchars($curso['shortname']) . '</td></tr>';
        echo '<tr><td>Categoria</td><td>' . $curso['categoryid'] . '</td></tr>';
        echo '<tr><td>Visível</td><td>' . ($curso['visible'] ? 'Sim' : 'Não') . '</td></tr>';
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<p class="error">Exceção ao obter informações básicas: ' . $e->getMessage() . '</p>';
}

// Obter métodos de inscrição
echo '<h3>Métodos de Inscrição:</h3>';
try {
    $enrol_methods = call_moodle_api('core_enrol_get_course_enrolment_methods', [
        'courseid' => $curso_id
    ]);
    
    if (isset($enrol_methods['exception']) || isset($enrol_methods['error'])) {
        echo '<p class="error">Erro ao obter métodos de inscrição: ' . json_encode($enrol_methods) . '</p>';
    } else if (empty($enrol_methods)) {
        echo '<p class="info">Não foram encontrados métodos de inscrição para este curso.</p>';
    } else {
        echo '<table>';
        echo '<tr><th>ID</th><th>Tipo</th><th>Nome</th><th>Status</th><th>Ações</th></tr>';
        
        $tem_metodo_self = false;
        
        foreach ($enrol_methods as $method) {
            echo '<tr>';
            echo '<td>' . $method['id'] . '</td>';
            echo '<td>' . $method['type'] . '</td>';
            echo '<td>' . htmlspecialchars($method['name']) . '</td>';
            echo '<td>' . ($method['status'] == 0 ? '<span class="success">Ativo</span>' : '<span class="error">Inativo</span>') . '</td>';
            echo '<td><a href="#" onclick="obterDetalhes(' . $method['id'] . '); return false;">Ver detalhes</a></td>';
            echo '</tr>';
            
            if ($method['type'] === 'self') {
                $tem_metodo_self = true;
            }
        }
        
        echo '</table>';
        
        if (!$tem_metodo_self) {
            echo '<p class="info">Este curso não tem o método de autoinscrição (self) habilitado.</p>';
        }
    }
} catch (Exception $e) {
    echo '<p class="error">Exceção ao obter métodos de inscrição: ' . $e->getMessage() . '</p>';
}

// Obter detalhes específicos do método self (autoinscrição)
echo '<h3>Detalhes da Autoinscrição:</h3>';
try {
    $enrol_methods = call_moodle_api('core_enrol_get_course_enrolment_methods', [
        'courseid' => $curso_id
    ]);
    
    if (!isset($enrol_methods['exception']) && !isset($enrol_methods['error']) && !empty($enrol_methods)) {
        $metodo_self = null;
        
        // Procurar pelo método de autoinscrição
        foreach ($enrol_methods as $method) {
            if ($method['type'] === 'self') {
                $metodo_self = $method;
                break;
            }
        }
        
        if ($metodo_self) {
            echo '<p class="success">Método de autoinscrição encontrado (ID: ' . $metodo_self['id'] . ')</p>';
            
            // Obter detalhes da instância
            $instancia_info = call_moodle_api('core_enrol_get_instance_info', [
                'instanceid' => $metodo_self['id']
            ]);
            
            if (isset($instancia_info['exception']) || isset($instancia_info['error'])) {
                echo '<p class="error">Erro ao obter informações da instância: ' . json_encode($instancia_info) . '</p>';
            } else {
                echo '<table>';
                echo '<tr><th>Campo</th><th>Valor</th><th>Descrição</th></tr>';
                
                foreach ($instancia_info as $key => $value) {
                    $descricao = '';
                    
                    // Adicionar descrições para campos específicos
                    if ($key === 'customint3') {
                        $descricao = 'Número máximo de usuários inscritos (0 = ilimitado)';
                    } else if ($key === 'status') {
                        $descricao = '0 = ativo, 1 = inativo';
                    } else if ($key === 'enrolenddate') {
                        $descricao = 'Data de término de inscrições';
                    } else if ($key === 'enrolstartdate') {
                        $descricao = 'Data de início de inscrições';
                    }
                    
                    echo '<tr>';
                    echo '<td>' . $key . '</td>';
                    
                    // Formatar valores específicos
                    if ($key === 'enrolenddate' || $key === 'enrolstartdate') {
                        if (!empty($value)) {
                            echo '<td>' . date('d/m/Y H:i:s', $value) . ' (' . $value . ')</td>';
                        } else {
                            echo '<td>Não definido</td>';
                        }
                    } else {
                        echo '<td>' . (is_string($value) ? htmlspecialchars($value) : json_encode($value)) . '</td>';
                    }
                    
                    echo '<td>' . $descricao . '</td>';
                    echo '</tr>';
                }
                
                echo '</table>';
                
                // Destacar o limite de vagas
                if (isset($instancia_info['customint3'])) {
                    // Converter o valor para o padrão do sistema
                    $moodle_limit = (int)$instancia_info['customint3'];
                    $system_limit = converter_limite_moodle($moodle_limit);
                    
                    echo '<div style="margin-top: 20px; padding: 15px; background-color: #f0f8ff; border-left: 5px solid #0066cc; border-radius: 5px;">';
                    echo '<strong>Limite de vagas (customint3):</strong> ';
                    
                    if ($moodle_limit === 0) {
                        echo '<span style="color: #0066cc; font-size: 1.2em;">Ilimitado (0 no Moodle, -1 no sistema)</span>';
                    } else {
                        echo '<span style="color: #0066cc; font-size: 1.2em;">' . $moodle_limit . ' vagas</span>';
                    }
                    
                    echo '<div style="margin-top: 10px; padding: 8px; background-color: #fff; border: 1px solid #ddd; border-radius: 3px;">';
                    echo '<strong>Interpretação pelo sistema:</strong> ';
                    
                    if (seminario_tem_vagas_ilimitadas($system_limit)) {
                        echo '<span style="color: #009900; font-weight: bold;">Vagas ilimitadas</span>';
                        echo '<div style="font-style: italic; margin-top: 5px;">O sistema irá interpretar este valor como "sem limite de vagas".</div>';
                    } else {
                        echo '<span style="color: #009900; font-weight: bold;">Limite de ' . $system_limit . ' vagas</span>';
                        echo '<div style="font-style: italic; margin-top: 5px;">O sistema irá limitar a ' . $system_limit . ' o número de participantes.</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                } else {
                    echo '<p class="info">O campo customint3 (limite de vagas) não está definido.</p>';
                }
            }
        } else {
            echo '<p class="info">Este curso não tem o método de autoinscrição (self) habilitado.</p>';
        }
    }
} catch (Exception $e) {
    echo '<p class="error">Exceção ao obter detalhes da autoinscrição: ' . $e->getMessage() . '</p>';
}

// Script para obter detalhes de um método específico
echo '<script>
function obterDetalhes(instanceId) {
    fetch("detalhes_metodo.php?instance_id=" + instanceId)
        .then(response => response.json())
        .then(data => {
            alert(JSON.stringify(data, null, 2));
        })
        .catch(error => {
            alert("Erro ao obter detalhes: " + error);
        });
}
</script>';

echo '
</body>
</html>';

// Imprimir a saída capturada
ob_end_flush(); 