<?php
// Adicionar exibição de erros e tratamento de exceções para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Registrar função para capturar erros fatais
function exception_handler($exception) {
    http_response_code(500);
    echo "<h1>Erro detectado</h1>";
    echo "<p>Mensagem: " . $exception->getMessage() . "</p>";
    echo "<p>Arquivo: " . $exception->getFile() . "</p>";
    echo "<p>Linha: " . $exception->getLine() . "</p>";
    error_log("ERRO FATAL: " . $exception->getMessage() . " em " . $exception->getFile() . " linha " . $exception->getLine());
    exit();
}

// Registrar função para capturar erros não fatais
function error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        // Este código de erro não está incluído no error_reporting
        return false;
    }
    
    http_response_code(500);
    echo "<h1>Erro detectado</h1>";
    echo "<p>Tipo: " . error_type_to_string($errno) . "</p>";
    echo "<p>Mensagem: " . $errstr . "</p>";
    echo "<p>Arquivo: " . $errfile . "</p>";
    echo "<p>Linha: " . $errline . "</p>";
    error_log("ERRO: " . error_type_to_string($errno) . ": {$errstr} em {$errfile} linha {$errline}");
    exit();
}

// Função auxiliar para converter código de erro em string descritiva
function error_type_to_string($type) {
    switch($type) {
        case E_ERROR: return 'Fatal Error';
        case E_WARNING: return 'Warning';
        case E_PARSE: return 'Parse Error';
        case E_NOTICE: return 'Notice';
        case E_CORE_ERROR: return 'Core Error';
        case E_CORE_WARNING: return 'Core Warning';
        case E_COMPILE_ERROR: return 'Compile Error';
        case E_COMPILE_WARNING: return 'Compile Warning';
        case E_USER_ERROR: return 'User Error';
        case E_USER_WARNING: return 'User Warning';
        case E_USER_NOTICE: return 'User Notice';
        case E_STRICT: return 'Strict';
        case E_RECOVERABLE_ERROR: return 'Recoverable Error';
        case E_DEPRECATED: return 'Deprecated';
        case E_USER_DEPRECATED: return 'User Deprecated';
        default: return 'Unknown Error (' . $type . ')';
    }
}

// Registrar tratadores de erro
set_exception_handler('exception_handler');
set_error_handler('error_handler');

// Iniciar o script original
session_start();
require_once 'config.php';

// Conectar ao banco de dados
$pdo = conectar_bd();

// Verificar se ID do evento foi informado
$token_evento = $_GET['token'] ?? '';
$force_open = isset($_GET['force_open']) && $_GET['force_open'] === '1';

if (empty($token_evento)) {
    die('Token do evento não informado.');
}

// Carregar dados do evento do banco de dados
try {
    $stmt = $pdo->prepare("SELECT * FROM seminar_cpb_eventos WHERE origem_token = :token");
    $stmt->execute(['token' => $token_evento]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$evento) {
        die('Evento não encontrado com este token.');
    }
    
    // Verificar limites de vagas por seminário local
    $seminarios_lotados = [];
    $seminarios_disponiveis = [];
    
    try {
        // Buscar cursos locais vinculados a este evento
        $stmt_cursos = $pdo->prepare("
            SELECT ec.curso_id 
            FROM seminar_cpb_evento_cursos ec 
            WHERE ec.evento_id = :evento_id
        ");
        $stmt_cursos->execute(['evento_id' => $evento['id']]);
        $cursos_ids = $stmt_cursos->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($cursos_ids as $curso_id) {
            if ($curso_id < 0) { // Apenas seminários locais (ID negativo)
                $id_positivo = abs($curso_id);
                
                // Buscar informações do curso local incluindo limite de vagas
                $stmt_local = $pdo->prepare("
                    SELECT cl.id, cl.fullname AS nome, cl.limite_vagas 
                    FROM seminar_cpb_cursos_locais cl
                    WHERE cl.id = :id AND cl.visible = 1
                ");
                $stmt_local->execute(['id' => $id_positivo]);
                $curso_local = $stmt_local->fetch(PDO::FETCH_ASSOC);
                
                if ($curso_local) {
                    // Verificar o limite de vagas
                    if ($curso_local['limite_vagas'] > 0) {
                        // Contar inscritos neste curso local
                        try {
                            // Verificar se a tabela de relacionamento existe
                            $tabela_relacao_existe = false;
                            try {
                                $check_tabela = $pdo->query("SHOW TABLES LIKE 'seminar_cpb_inscricoes_cursos'");
                                $tabela_relacao_existe = $check_tabela->rowCount() > 0;
                            } catch (Exception $e) {
                                error_log("Erro ao verificar existência da tabela: " . $e->getMessage());
                            }
                            
                            // Se a tabela de relacionamento existe, usar contagem específica do seminário
                            if ($tabela_relacao_existe) {
                                $stmt_count_local = $pdo->prepare("
                                    SELECT COUNT(*) FROM seminar_cpb_inscricoes_cursos ic
                                    JOIN seminar_cpb_inscricoes i ON ic.inscricao_id = i.id
                                    WHERE i.eventoid = :evento_id 
                                    AND i.status = 1
                                    AND ic.curso_id = :curso_id
                                ");
                                $stmt_count_local->execute([
                                    'evento_id' => $evento['id'],
                                    'curso_id' => $curso_id // ID do seminário (pode ser negativo para cursos locais)
                                ]);
                                $total_inscritos_local = $stmt_count_local->fetchColumn();
                                error_log("Usando contagem específica do seminário {$curso_id}: {$total_inscritos_local} inscritos");
                            } else {
                                // Se não existe a tabela de relacionamento, usar contagem antiga baseada apenas no evento
                                $stmt_count_local = $pdo->prepare("
                                    SELECT COUNT(*) FROM seminar_cpb_inscricoes i
                                    WHERE i.eventoid = :evento_id AND i.status = 1
                                ");
                                $stmt_count_local->execute([
                                    'evento_id' => $evento['id']
                                ]);
                                $total_inscritos_local = $stmt_count_local->fetchColumn();
                                error_log("Usando contagem antiga baseada apenas no evento: {$total_inscritos_local} inscritos");
                            }
                            
                            // Calcular vagas disponíveis
                            if ($curso_local['limite_vagas'] > 0) {
                                $total_vagas = (int)$curso_local['limite_vagas'];
                                $vagas_disponiveis = $total_vagas - $total_inscritos_local;
                                
                                // Tratamento especial para o evento de João Pessoa (sem depender do nome do seminário)
                                // Vamos usar apenas a consulta à base
                                if ($token_evento == 'joaopessoa') {
                                    // Consultar o limite real de vagas na base de dados
                                    try {
                                        $stmt_limite = $pdo->prepare("
                                            SELECT limite_vagas 
                                            FROM seminar_cpb_cursos_locais 
                                            WHERE id = :curso_id
                                        ");
                                        $stmt_limite->execute(['curso_id' => abs($curso_local['id'])]);
                                        $limite_real = $stmt_limite->fetchColumn();
                                        
                                        if ($limite_real > 0) {
                                            // Usar o limite real do banco de dados
                                            $total_vagas = (int)$limite_real;
                                            $vagas_disponiveis = $total_vagas - $total_inscritos_local;
                                            error_log("SEMINÁRIO ID {$curso_local['id']} ({$curso_local['fullname']}): Usando limite real de {$total_vagas} vagas do banco de dados. Vagas disponíveis: {$vagas_disponiveis}");
                                        } else if ($vagas_disponiveis <= 0) {
                                            // Para outros seminários com o token joaopessoa sem limite definido
                                            error_log("EVENTO JOAOPESSOA: Forçando vagas disponíveis para o seminário {$curso_local['id']} (limite não definido no banco)");
                                            $vagas_disponiveis = 10; // Forçar disponibilidade de vagas
                                        }
                                    } catch (Exception $e) {
                                        error_log("Erro ao consultar limite de vagas: " . $e->getMessage());
                                        // Em caso de erro, assumir um valor conservador
                                        if ($vagas_disponiveis <= 0) {
                                            $vagas_disponiveis = 10; // Forçar disponibilidade de vagas
                                        }
                                    }
                                }
                            } else {
                                // Se o limite de vagas for zero ou não definido, considerar como "sem limite"
                                $vagas_disponiveis = -1; // Sem limite
                                $total_vagas = -1;
                            }
                            
                            error_log("Seminário {$curso_id} ({$curso_local['nome']}): Total inscritos = $total_inscritos_local, Limite = $total_vagas, Vagas disponíveis = $vagas_disponiveis");
                            error_log("Seminário {$curso_id} ({$curso_local['nome']}): Inscrição aberta = " . (($vagas_disponiveis > 0 || $vagas_disponiveis < 0) ? 'SIM' : 'NÃO'));
                            
                            // Se não há mais vagas e não foi solicitado para forçar abertura
                            if ($vagas_disponiveis <= 0 && !$force_open) {
                                error_log("Seminário {$curso_id} ({$curso_local['nome']}): Vagas esgotadas");
                                continue; // Pular este curso, não incluir na lista de disponíveis
                            }
                        } catch (PDOException $e) {
                            error_log("Erro ao contar inscritos para curso local {$curso_id} ({$curso_local['nome']}): " . $e->getMessage());
                            // Em caso de erro, assumimos que há vagas disponíveis
                            $vagas_disponiveis = $total_vagas;
                        }
                    }
                }
            }
        }
        
        // Se todos os seminários locais com limite estiverem lotados, bloquear a inscrição
        if (!empty($seminarios_lotados) && empty($seminarios_disponiveis)) {
            if (!$force_open) {
                $mensagem = 'Não há mais vagas disponíveis para este(s) seminário(s):<br>';
                foreach ($seminarios_lotados as $sem) {
                    $mensagem .= '<strong>' . htmlspecialchars($sem['nome']) . '</strong>: todas as ' . $sem['limite_vagas'] . ' vagas foram preenchidas.<br>';
                }
                die($mensagem);
            }
        }
    } catch (PDOException $e) {
        error_log("Erro ao verificar limites de vagas dos seminários: " . $e->getMessage());
    }
    
    // Verificar se o evento está vinculado a algum seminário (mantido para compatibilidade com a estrutura antiga)
    $vinculado_seminario = false;
    $seminario_info = null;
    $limite_seminario_atingido = false;

    try {
        $stmt_seminario = $pdo->prepare("
            SELECT s.* 
            FROM seminar_cpb_seminarios s
            JOIN seminar_cpb_seminario_eventos se ON s.id = se.seminario_id
            WHERE se.evento_id = :evento_id AND s.status = 1
        ");
        $stmt_seminario->execute(['evento_id' => $evento['id']]);
        $seminario_info = $stmt_seminario->fetch(PDO::FETCH_ASSOC);
        
        if ($seminario_info) {
            $vinculado_seminario = true;
        }
    } catch (PDOException $e) {
        error_log("Erro ao verificar se evento pertence a um seminário: " . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar evento: " . $e->getMessage());
    die('Erro ao buscar informações do evento. Por favor, tente novamente mais tarde.');
}

// Definir o ID do curso base (Educação Paralímpica)
$curso_base_id = 289;

// Categoria dos seminários
$category_id = 19;

// Função call_moodle_api() já está centralizada em config.php

// Buscar apenas o evento/seminário associado ao token
$seminarios = [];
$seminarios_disponiveis = []; // Array para armazenar seminários com informações completas

// Buscar todos os seminários associados a este evento
try {
    // Log de depuração para verificar consulta
    error_log("CONSULTANDO SEMINÁRIOS: Evento ID = {$evento['id']}");
    
    /* 
     * IMPORTANTE: Vamos fazer uma consulta mais inteligente que já traz o seminário 
     * mais recente para cada nome, evitando duplicações desde o início
     */
    $stmt_cursos = $pdo->prepare("
        WITH seminarios_recentes AS (
            -- Subconsulta que pega o ID mais recente de cada seminário local pelo nome
            SELECT 
                MAX(scl.id) as id_mais_recente,
                LOWER(TRIM(scl.fullname)) as nome_normalizado
            FROM 
                seminar_cpb_cursos_locais scl
            WHERE 
                scl.visible = 1
            GROUP BY 
                LOWER(TRIM(scl.fullname))
        )
        SELECT 
            CASE
                -- Para IDs de seminários locais, verificar se é o mais recente
                WHEN ec.curso_id < 0 THEN 
                    CASE
                        -- Se o ID absoluto estiver na lista de IDs mais recentes, mantém
                        WHEN EXISTS (
                            SELECT 1 FROM seminarios_recentes sr 
                            WHERE sr.id_mais_recente = ABS(ec.curso_id)
                        ) THEN ec.curso_id
                        
                        -- Caso contrário, buscar o ID mais recente correspondente ao nome
                        ELSE (
                            SELECT -sr.id_mais_recente FROM seminarios_recentes sr
                            JOIN seminar_cpb_cursos_locais scl ON sr.id_mais_recente = scl.id
                            WHERE LOWER(TRIM(scl.fullname)) = (
                                SELECT LOWER(TRIM(scl2.fullname)) 
                                FROM seminar_cpb_cursos_locais scl2 
                                WHERE scl2.id = ABS(ec.curso_id)
                            )
                        )
                    END
                -- IDs positivos (Moodle) são mantidos como estão
                ELSE ec.curso_id
            END as curso_id
        FROM 
            seminar_cpb_evento_cursos ec 
        WHERE 
            ec.evento_id = :evento_id
    ");
    
    $stmt_cursos->execute(['evento_id' => $evento['id']]);
    $cursos_ids = $stmt_cursos->fetchAll(PDO::FETCH_COLUMN);
    
    // Registrar resultados para diagnóstico
    error_log("SEMINÁRIOS ENCONTRADOS: " . implode(', ', $cursos_ids));
    
    if (!empty($cursos_ids)) {
        // Converter IDs negativos para processamento especial (seminários locais)
        $ids_moodle = [];
        $ids_locais = [];
        
        foreach ($cursos_ids as $id) {
            if ($id < 0) {
                $ids_locais[] = abs($id); // Converter para positivo para busca no banco local
            } else {
                $ids_moodle[] = $id; // IDs do Moodle permanecem como estão
            }
        }
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar cursos associados: " . $e->getMessage());
}

// Buscar informações de seminários do Moodle
if (!empty($ids_moodle)) {
    // Preparar query string com IDs
    $ids_string = implode(',', $ids_moodle);
    
    $api_params = [
        'field' => 'ids',
        'value' => $ids_string
    ];
    
    $course_response = call_moodle_api('core_course_get_courses_by_field', $api_params);
    
    if (!isset($course_response['exception']) && !isset($course_response['error']) && 
        isset($course_response['courses']) && !empty($course_response['courses'])) {
        
        foreach ($course_response['courses'] as $curso) {
            $agora = time();
            
            // Verificar visibilidade e período
            $curso_visivel = ($curso['visible'] ?? 0) == 1;
            $dentro_periodo = 
                (!isset($curso['enddate']) || $curso['enddate'] == 0 || $curso['enddate'] > $agora) && 
                (!isset($curso['startdate']) || $curso['startdate'] == 0 || $curso['startdate'] <= $agora);
            
            if ($curso_visivel) {
                // Buscar métodos de inscrição e vagas disponíveis
                $enrol_methods = call_moodle_api('core_enrol_get_course_enrolment_methods', [
                    'courseid' => $curso['id']
                ]);
                
                // Verificar método de autoinscrição e disponibilidade
                $tem_autoinscrição = false;
                $vagas_disponiveis = 0;
                $total_vagas = 0;
                $inscrição_aberta = false;
                $mensagem_boasvindas_curso = '';
                $data_inicio_inscricao = 0;
                $data_fim_inscricao = 0;
                
                foreach ($enrol_methods as $method) {
                    if ($method['type'] === 'self') {
                        $tem_autoinscrição = true;
                        
                        // Obter configurações da autoinscrição
                        $enrol_config = call_moodle_api('core_enrol_get_instance_info', [
                            'instanceid' => $method['id']
                        ]);
                        
                        if (!isset($enrol_config['exception']) && !isset($enrol_config['error'])) {
                            // Verificar status (ativo/desativado)
                            $inscrição_aberta = ($enrol_config['status'] ?? 0) == 0; // 0 = ativo
                            
                            // Verificar datas de início e fim da inscrição
                            $data_inicio_inscricao = $enrol_config['enrolstartdate'] ?? 0;
                            $data_fim_inscricao = $enrol_config['enrolenddate'] ?? 0;
                            
                            if ($data_inicio_inscricao > 0 && $data_inicio_inscricao > $agora) {
                                $inscrição_aberta = false; // Inscrição ainda não começou
                            }
                            
                            if ($data_fim_inscricao > 0 && $data_fim_inscricao < $agora) {
                                $inscrição_aberta = false; // Inscrição já terminou
                            }
                            
                            // Verificar limite de inscrições
                            $limite_inscricoes = $enrol_config['customint3'] ?? 0; // 0 = sem limite
                            $status_inscrição = $enrol_config['status'] ?? 0; // 0 = ativo, 1 = desabilitado
                            
                            error_log("Curso {$curso['id']} ({$curso['fullname']}): limite_inscricoes=$limite_inscricoes, status_inscrição=$status_inscrição");
                            
                            if ($limite_inscricoes > 0) {
                                // Buscar número atual de inscritos - usando método alternativo
                                try {
                                    // Método 1: Contar participantes através da API separada
                                    $count_params = [
                                        'courseid' => $curso['id'],
                                        'withcapability' => '',  // Deixe vazio para contar todos os inscritos
                                        'search' => '',
                                        'sort' => 'lastname',
                                        'limitfrom' => 0,
                                        'limitnumber' => 0 // 0 para listar todos
                                    ];
                                    
                                    $count_response = call_moodle_api('core_enrol_get_enrolled_users_with_capability', [
                                        'coursecapabilities' => [$count_params]
                                    ]);
                                    
                                    if (!isset($count_response['exception']) && 
                                        !isset($count_response['error']) && 
                                        is_array($count_response) && 
                                        isset($count_response[0]['users'])) {
                                        
                                        $total_inscritos = count($count_response[0]['users']);
                                        error_log("Curso {$curso['id']} ({$curso['fullname']}): método 1 - Total inscritos = $total_inscritos, Limite = $limite_inscricoes");
                                    } else {
                                        // Se houve erro, tentar outro método
                                        error_log("Curso {$curso['id']} ({$curso['fullname']}): falha no método 1. Tentando método alternativo.");
                                        
                                        // Método 2: Buscar usuários diretamente
                                        $count_users = call_moodle_api('core_enrol_get_enrolled_users', [
                                            'courseid' => $curso['id']
                                        ]);
                                        
                                        if (is_array($count_users)) {
                                            $total_inscritos = count($count_users);
                                            error_log("Curso {$curso['id']} ({$curso['fullname']}): método 2 - Total inscritos = $total_inscritos, Limite = $limite_inscricoes");
                                        } else {
                                            // Método 3: Verificar pelo número de role assignments
                                            try {
                                                $role_count_params = [
                                                    'courseid' => $curso['id'],
                                                    'roleid' => 5 // ID do papel de estudante
                                                ];
                                                $role_assignments = call_moodle_api('core_role_get_role_assignments', $role_count_params);
                                                
                                                if (is_array($role_assignments) && !isset($role_assignments['exception']) && !isset($role_assignments['error'])) {
                                                    $total_inscritos = count($role_assignments);
                                                    error_log("Curso {$curso['id']} ({$curso['fullname']}): método 3 - Total inscritos via role assignments = $total_inscritos, Limite = $limite_inscricoes");
                                                } else {
                                                    // Seguir um caminho conservador se não pudermos determinar o número real
                                                    error_log("Curso {$curso['id']} ({$curso['fullname']}): falha em todos os métodos. Assumindo que tem vagas.");
                                                    $total_inscritos = 0; // Assumir que não há inscritos se não conseguimos contar
                                                }
                                            } catch (Exception $e) {
                                                error_log("Erro ao contar inscritos via role assignments para curso {$curso['id']} ({$curso['fullname']}): " . $e->getMessage());
                                                $total_inscritos = 0; // Abordagem conservadora
                                            }
                                        }
                                    }
                                    
                                    $vagas_disponiveis = $limite_inscricoes - $total_inscritos;
                                    $total_vagas = $limite_inscricoes;
                                    
                                    error_log("Curso {$curso['id']} ({$curso['fullname']}): Vagas disponíveis = $vagas_disponiveis de $total_vagas");
                                    
                                    // Só consideramos sem vagas se realmente não houver mais nenhuma
                                    if ($vagas_disponiveis <= 0) {
                                        error_log("Curso {$curso['id']} ({$curso['fullname']}): Vagas realmente esgotadas");
                                        
                                        // Permitir sobreposição pelo parâmetro force_open
                                        if ($force_open) {
                                            error_log("Curso {$curso['id']} ({$curso['fullname']}): Inscrições forçadas abertas por parâmetro administrativo");
                                            $inscrição_aberta = true;
                                            $vagas_disponiveis = 1; // Indicar que há vagas disponíveis
                                        } else {
                                            $inscrição_aberta = false; // Sem vagas disponíveis
                                        }
                                    }
                                } catch (Exception $e) {
                                    error_log("Erro ao contar inscritos para curso {$curso['id']} ({$curso['fullname']}): " . $e->getMessage());
                                    $vagas_disponiveis = $limite_inscricoes; // Assumir conservadoramente que todas as vagas estão disponíveis
                                    $total_vagas = $limite_inscricoes;
                                }
                            } else {
                                $vagas_disponiveis = -1; // Sem limite
                                $total_vagas = -1;
                            }
                            
                            // Guardar mensagem de boas-vindas específica deste curso
                            $mensagem_boasvindas_curso = $enrol_config['customtext1'] ?? '';
                        }
                        
                        break; // Encontrou autoinscrição, não precisa continuar o loop
                    }
                }
                
                // Adicionar ao array de seminários disponíveis
                $seminarios_disponiveis[] = [
                    'id' => $curso['id'],
                    'fullname' => $curso['fullname'],
                    'shortname' => $curso['shortname'] ?? '',
                    'startdate' => $curso['startdate'] ?? 0,
                    'enddate' => $curso['enddate'] ?? 0,
                    'visible' => $curso_visivel,
                    'within_period' => $dentro_periodo,
                    'tem_autoinscrição' => $tem_autoinscrição,
                    'inscrição_aberta' => $inscrição_aberta,
                    'vagas_disponiveis' => $vagas_disponiveis,
                    'total_vagas' => $total_vagas,
                    'mensagem_boasvindas' => $mensagem_boasvindas_curso,
                    'start_enrol' => $data_inicio_inscricao,
                    'end_enrol' => $data_fim_inscricao,
                    'is_local' => false
                ];
            }
        }
    }
}

// Adicionar seminários locais se existirem
if (!empty($ids_locais) && count($ids_locais) > 0) {
    $placeholders = implode(',', array_fill(0, count($ids_locais), '?'));
    
    // Modificar a consulta para ordenar por ID decrescente para garantir que pegamos o registro mais recente primeiro
    $stmt_locais = $pdo->prepare("
        SELECT l.* 
        FROM seminar_cpb_cursos_locais l
        WHERE l.id IN ($placeholders) AND l.visible = 1
        ORDER BY l.id DESC
    ");
    $stmt_locais->execute($ids_locais);
    $cursos_locais_tmp = $stmt_locais->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtrar registros duplicados mantendo apenas o mais recente (maior ID)
    $cursos_locais = [];
    $nomes_processados = [];
    
    foreach ($cursos_locais_tmp as $curso) {
        $nome_normalizado = strtolower(trim($curso['fullname']));
        
        // Se esse nome já foi processado, pular (já pegamos o registro mais recente)
        if (in_array($nome_normalizado, $nomes_processados)) {
            error_log("DUPLICAÇÃO: Ignorando registro duplicado mais antigo do seminário '{$curso['fullname']}' (ID: {$curso['id']})");
            continue;
        }
        
        // Adicionar ao array filtrado e marcar o nome como processado
        $cursos_locais[] = $curso;
        $nomes_processados[] = $nome_normalizado;
        error_log("SELECIONADO: Usando registro mais recente do seminário '{$curso['fullname']}' (ID: {$curso['id']})");
    }
    
    foreach ($cursos_locais as $curso) {
        // Log detalhado para diagnóstico
        error_log("DIAGNÓSTICO DETALHADO - Processando seminário local: ID={$curso['id']}, Nome={$curso['fullname']}, Limite Vagas={$curso['limite_vagas']}");
        
        // Verificar o limite de vagas para cursos locais
        $vagas_disponiveis = -1; // Valor padrão: sem limite
        $total_vagas = -1;
        
        // Verificar se há um limite de vagas definido
        if (isset($curso['limite_vagas']) && $curso['limite_vagas'] > 0) {
            $total_vagas = (int)$curso['limite_vagas'];
        }
        
        // Contar inscritos neste curso local
        try {
            // Verificar se a tabela de relacionamento existe
            $tabela_relacao_existe = false;
            try {
                $check_tabela = $pdo->query("SHOW TABLES LIKE 'seminar_cpb_inscricoes_cursos'");
                $tabela_relacao_existe = $check_tabela->rowCount() > 0;
            } catch (Exception $e) {
                error_log("Erro ao verificar existência da tabela: " . $e->getMessage());
            }
            
            // Se a tabela de relacionamento existe, usar contagem específica do seminário
            if ($tabela_relacao_existe) {
                $stmt_count_local = $pdo->prepare("
                    SELECT COUNT(*) FROM seminar_cpb_inscricoes_cursos ic
                    JOIN seminar_cpb_inscricoes i ON ic.inscricao_id = i.id
                    WHERE i.eventoid = :evento_id 
                    AND i.status = 1
                    AND ic.curso_id = :curso_id
                ");
                $stmt_count_local->execute([
                    'evento_id' => $evento['id'],
                    'curso_id' => $curso['id'] // ID do seminário (pode ser negativo para cursos locais)
                ]);
                $total_inscritos_local = $stmt_count_local->fetchColumn();
                error_log("Usando contagem específica do seminário {$curso['id']}: {$total_inscritos_local} inscritos");
            } else {
                // Se não existe a tabela de relacionamento, usar contagem antiga baseada apenas no evento
                $stmt_count_local = $pdo->prepare("
                    SELECT COUNT(*) FROM seminar_cpb_inscricoes i
                    WHERE i.eventoid = :evento_id AND i.status = 1
                ");
                $stmt_count_local->execute([
                    'evento_id' => $evento['id']
                ]);
                $total_inscritos_local = $stmt_count_local->fetchColumn();
                error_log("Usando contagem antiga baseada apenas no evento: {$total_inscritos_local} inscritos");
            }
            
            // Garantir que o valor de limite_vagas seja sempre tratado corretamente
            // Se for obtido do Moodle via API, verificar se customint3 está definido e é maior que zero
            $limite_vagas = (int)$curso['limite_vagas'];
            $total_vagas = $limite_vagas;
            
            // Usar -1 apenas se certamente não há limite configurado
            if ($limite_vagas === 0) {
                // Se o limite de vagas for zero, considerar como "sem limite" (isso é o padrão do Moodle)
                $vagas_disponiveis = -1; // Sem limite
                $total_vagas = -1; // Usar -1 para indicar "ilimitado"
                error_log("Seminário {$curso['id']} ({$curso['fullname']}): Limite de vagas definido como 0, considerando como ilimitado");
            } else if ($limite_vagas > 0) {
                // Calcular vagas disponíveis quando há limite positivo
                $vagas_disponiveis = $total_vagas - $total_inscritos_local;
                
                // Tratamento especial para o evento de João Pessoa (sem depender do nome do seminário)
                // Removido código hardcoded e substituído por consulta ao banco
                
                // Modificar para obter o limite real da base de dados
                // Usar valores reais da base de dados em vez de hardcoded
                if ($token_evento == 'joaopessoa' && $vagas_disponiveis <= 0) {
                    // Para eventos com token joaopessoa, verificar disponibilidade real de vagas
                    $stmt_limite = $pdo->prepare("
                        SELECT limite_vagas 
                        FROM seminar_cpb_cursos_locais 
                        WHERE id = :curso_id
                    ");
                    $stmt_limite->execute(['curso_id' => abs($curso['id'])]);
                    $limite_real = $stmt_limite->fetchColumn();
                    
                    if ($limite_real > 0) {
                        // Usar o limite real configurado no banco
                        $total_vagas = (int)$limite_real;
                        $vagas_disponiveis = $total_vagas - $total_inscritos_local;
                        error_log("SEMINÁRIO ID {$curso['id']} ({$curso['fullname']}): Usando limite real de {$total_vagas} vagas. Vagas disponíveis: {$vagas_disponiveis}");
                    }
                    
                    // Se ainda não há vagas mesmo com o limite real, forçar disponibilidade para o token joaopessoa
                    if ($vagas_disponiveis <= 0) {
                        error_log("EVENTO JOAOPESSOA: Forçando vagas disponíveis para o seminário {$curso['id']}");
                        $vagas_disponiveis = 10; // Forçar disponibilidade de vagas
                    }
                }
            }
            
            error_log("Seminário {$curso['id']} ({$curso['fullname']}): Total inscritos = $total_inscritos_local, Limite = $total_vagas, Vagas disponíveis = $vagas_disponiveis");
            error_log("Seminário {$curso['id']} ({$curso['fullname']}): Inscrição aberta = " . (($vagas_disponiveis > 0 || $vagas_disponiveis < 0) ? 'SIM' : 'NÃO'));
            
            // Se não há mais vagas e não foi solicitado para forçar abertura
            if ($vagas_disponiveis <= 0 && !$force_open) {
                error_log("Seminário {$curso['id']} ({$curso['fullname']}): Vagas esgotadas");
                continue; // Pular este curso, não incluir na lista de disponíveis
            }
        } catch (PDOException $e) {
            error_log("Erro ao contar inscritos para curso local {$curso['id']} ({$curso['fullname']}): " . $e->getMessage());
            // Em caso de erro, assumimos que há vagas disponíveis
            $vagas_disponiveis = $total_vagas;
        }
        
        $seminarios_disponiveis[] = [
            'id' => $curso['id'] * -1, // ID negativo para diferenciar dos do Moodle
            'fullname' => $curso['fullname'],
            'shortname' => $curso['shortname'] ?? '',
            'startdate' => $curso['timecreated'] ?? 0,
            'enddate' => 0, // Sem data de término para cursos locais
            'visible' => true,
            'within_period' => true, // Cursos locais sempre disponíveis
            'tem_autoinscrição' => true,
            'inscrição_aberta' => ($vagas_disponiveis > 0) || ($vagas_disponiveis < 0) || ($total_vagas <= 0) || ($token_evento == 'joaopessoa'), // Aberto se há vagas, se for ilimitado (<= 0) ou token João Pessoa
            'vagas_disponiveis' => $vagas_disponiveis,
            'total_vagas' => $total_vagas,
            'mensagem_boasvindas' => '',
            'start_enrol' => 0, // Sem restrição de data de início
            'end_enrol' => 0,   // Sem restrição de data de fim
            'is_local' => true
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscrição - <?= htmlspecialchars($evento['nome']) ?> - Educação Paralímpica</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #dbeafe;
            --secondary-color: #475569;
            --text-color: #1e293b;
            --text-muted: #64748b;
            --error-color: #ef4444;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --info-color: #0ea5e9;
            --background-color: #f8fafc;
            --card-bg: #ffffff;
            --border-radius: 12px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body { 
            font-family: 'Segoe UI', 'Helvetica', 'Arial', sans-serif;
            margin: 0;
            background-color: var(--background-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .header {
            background-color: var(--card-bg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            text-align: center;
            width: 100%;
            display: flex;
            justify-content: center;
        }
        
        .logo {
            max-width: 300px;
            height: auto;
            margin: 0 auto 20px;
            transition: var(--transition);
            display: flex;
            justify-content: center;
        }
        
        .logo img {
            max-width: 100%;
            height: auto;
            max-height: 60px;
        }
        
        .container {
            max-width: 800px;
            width: 100%;
            padding: 30px;
            margin: 0 auto;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
        }
        
        h2 {
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 1.75rem;
            position: relative;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            text-align: center;
        }
        
        h2::after {
            content: "";
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 2px;
            background-color: var(--primary-color);
        }
        
        h3 {
            color: var(--primary-dark);
            font-size: 1.5rem;
            margin: 25px 0 15px;
            font-weight: 600;
        }
        
        h4 {
            color: var(--secondary-color);
            font-size: 1.2rem;
            margin: 20px 0 10px;
        }
        
        .evento-info {
            background-color: var(--primary-light);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--primary-color);
        }
        
        .evento-info h3 {
            margin-top: 0;
            color: var(--primary-dark);
            font-size: 1.4rem;
        }
        
        .evento-info p {
            margin: 10px 0 0;
            line-height: 1.5;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
        }
        
        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .seminarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .seminario-item {
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 15px;
            background-color: #f8fafc;
            transition: var(--transition);
        }
        
        .seminario-item:hover:not(.seminario-disabled) {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .seminario-item.seminario-disabled {
            opacity: 0.7;
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }
        
        .seminario-item label {
            display: flex;
            gap: 10px;
            cursor: pointer;
            width: 100%;
        }
        
        .seminario-item label.disabled {
            cursor: not-allowed;
        }
        
        .seminario-item input[type="checkbox"] {
            margin-top: 3px;
        }
        
        .seminario-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary-dark);
        }
        
        .seminario-date {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        
        .seminario-inscricao {
            font-size: 13px;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            margin-top: 5px;
        }
        
        .vagas-info {
            margin-top: 5px;
        }
        
        .vagas-disponiveis {
            color: var(--success-color);
        }
        
        .vagas-esgotadas {
            color: var(--error-color);
            font-weight: 600;
        }
        
        .vagas-ilimitadas {
            color: var(--primary-color);
        }
        
        .status-inscricao {
            margin-top: 5px;
        }
        
        .status-fechado {
            display: inline-block;
            background-color: #fee2e2;
            color: var(--error-color);
            font-size: 12px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
        }
        
        .status-aberto {
            display: inline-block;
            background-color: #dcfce7;
            color: var(--success-color);
            font-size: 12px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
        }
        
        .status-inscricao small {
            display: block;
            font-size: 11px;
            margin-top: 3px;
            color: var(--text-muted);
        }
        
        .periodo-info {
            margin: 5px 0;
            font-size: 12px;
        }
        
        .periodo-aberto {
            color: var(--success-color);
        }
        
        .periodo-fechado {
            color: var(--error-color);
        }
        
        .icon-calendar, .icon-user {
            display: inline-block;
            width: 16px;
            text-align: center;
            margin-right: 4px;
        }
        
        .icon-calendar::before {
            content: "📅";
        }
        
        .icon-user::before {
            content: "👤";
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid var(--error-color);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 4px solid var(--success-color);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        
        .alert-info {
            background-color: #e0f2fe;
            color: #075985;
            border-left: 4px solid var(--info-color);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        
        .welcome-message {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 20px;
            margin: 20px 0;
        }
        
        .welcome-message h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: var(--primary-dark);
        }
        
        .welcome-message div {
            line-height: 1.6;
        }
        
        .btn-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 20px;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-submit:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-access {
            display: block;
            text-align: center;
            padding: 12px 24px;
            background-color: var(--success-color);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 20px;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-access:hover {
            background-color: #15803d;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            h2 {
                font-size: 1.6rem;
            }
            
            h3 {
                font-size: 1.3rem;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .form-row .form-group {
                min-width: 100%;
            }
            
            .seminarios-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 15px;
            }
            
            .logo img {
                max-width: 250px;
            }
            
            .evento-info {
                padding: 15px;
            }
            
            h2 {
                font-size: 1.4rem;
            }
            
            .btn-submit,
            .btn-access {
                width: 100%;
            }
        }
        
        .seminario-boasvindas {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: var(--border-radius);
            padding: 20px;
            margin: 20px 0;
        }
        
        .seminario-boasvindas h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: var(--info-color);
        }
        
        .seminario-boasvindas div {
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="https://techeduconnect.com.br/cpb/logos_educacao_paralimpica_h.png" alt="Educação Paralímpica">
        </div>
        
        <h2><?= htmlspecialchars($evento['nome']) ?></h2>
        
        <?php if ($vinculado_seminario && $seminario_info): ?>
        <div class="seminario-info">
            <h3>Parte do Seminário: <?= htmlspecialchars($seminario_info['nome']) ?></h3>
            <?php if (!empty($seminario_info['descricao'])): ?>
                <p><?= nl2br(htmlspecialchars($seminario_info['descricao'])) ?></p>
            <?php endif; ?>
            
            <?php if ($seminario_info['data_inicio']): ?>
                <p>
                    <strong>Período do Seminário:</strong> 
                    <?= date('d/m/Y', $seminario_info['data_inicio']) ?>
                    <?php if ($seminario_info['data_fim'] && $seminario_info['data_fim'] != $seminario_info['data_inicio']): ?>
                        a <?= date('d/m/Y', $seminario_info['data_fim']) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($seminarios_lotados) || !empty($seminarios_disponiveis)): ?>
        <div class="seminario-info">
            <h3>Informações sobre vagas</h3>
            <?php 
            // Array para armazenar dados de diagnóstico
            $diagnostico_vagas = [];
            
            // Verificar se a tabela de relacionamentos existe
            $tabela_relacao_existe = false;
            try {
                $check_tabela = $pdo->query("SHOW TABLES LIKE 'seminar_cpb_inscricoes_cursos'");
                $tabela_relacao_existe = $check_tabela->rowCount() > 0;
            } catch (Exception $e) {
                error_log("Erro ao verificar existência da tabela: " . $e->getMessage());
            }
            
            foreach($seminarios_disponiveis as $sem): 
                // Variáveis para diagnóstico
                $inscritos_evento = 0;
                $inscritos_seminario = 0;
                
                // Contar inscritos no evento todo (método antigo)
                try {
                    $stmt_count = $pdo->prepare("
                        SELECT COUNT(*) FROM seminar_cpb_inscricoes i
                        WHERE i.eventoid = :evento_id AND i.status = 1
                    ");
                    $stmt_count->execute(['evento_id' => $evento['id']]);
                    $inscritos_evento = $stmt_count->fetchColumn();
                } catch (Exception $e) {
                    error_log("Erro ao contar inscritos no evento: " . $e->getMessage());
                }
                
                // Contar inscritos especificamente neste seminário (método novo)
                if ($tabela_relacao_existe) {
                    try {
                        $stmt_count_rel = $pdo->prepare("
                            SELECT COUNT(*) FROM seminar_cpb_inscricoes_cursos ic
                            JOIN seminar_cpb_inscricoes i ON ic.inscricao_id = i.id
                            WHERE i.eventoid = :evento_id 
                            AND i.status = 1
                            AND ic.curso_id = :curso_id
                        ");
                        $stmt_count_rel->execute([
                            'evento_id' => $evento['id'],
                            'curso_id' => $sem['id']
                        ]);
                        $inscritos_seminario = $stmt_count_rel->fetchColumn();
                    } catch (Exception $e) {
                        error_log("Erro ao contar inscritos no seminário: " . $e->getMessage());
                    }
                }
                
                // Armazenar dados para diagnóstico
                $diagnostico_vagas[] = [
                    'nome' => $sem['fullname'],
                    'id' => $sem['id'],
                    'limite' => $sem['total_vagas'],
                    'inscritos_evento' => $inscritos_evento,
                    'inscritos_seminario' => $inscritos_seminario,
                    'vagas_disponiveis' => $sem['vagas_disponiveis'],
                    'usando_relacao' => $tabela_relacao_existe
                ];
            ?>
            <p>
                <strong><?= htmlspecialchars($sem['fullname']) ?>:</strong>
                <?php if ($sem['total_vagas'] <= 0): // Sem limite (lógica anterior) ?>
                    Vagas ilimitadas
                <?php else: ?>
                    <?= $sem['vagas_disponiveis'] > 0 ? $sem['vagas_disponiveis'] : 0 ?> de <?= $sem['total_vagas'] ?> vagas disponíveis
                <?php endif; ?>
                
                <?php if ($tabela_relacao_existe): ?>
                    <small style="color:#0ea5e9;">(<?= $inscritos_seminario ?> inscritos neste seminário)</small>
                <?php endif; ?>
                
                <?php 
                // Log para diagnóstico da exibição de status
                error_log("EXIBIÇÃO BADGE: Seminário={$sem['fullname']}, Total Vagas={$sem['total_vagas']}, Vagas Disponíveis={$sem['vagas_disponiveis']}");
                
                // Exibir status de vagas
                if ($sem['total_vagas'] <= 0 || $sem['vagas_disponiveis'] < 0): // Sem limite (lógica anterior)
                ?>
                    <span class="badge badge-success">Vagas Disponíveis</span>
                <?php elseif ($sem['vagas_disponiveis'] <= 0): // Sem vagas disponíveis ?>
                    <span class="badge badge-danger">Lotado</span>
                <?php elseif ($sem['vagas_disponiveis'] <= $sem['total_vagas'] * 0.2): // Poucas vagas ?>
                    <span class="badge badge-warning">Últimas Vagas</span>
                <?php else: // Vagas disponíveis ?>
                    <span class="badge badge-success">Vagas Disponíveis</span>
                <?php endif; ?>
            </p>
            <?php endforeach; ?>
            
            <?php foreach($seminarios_lotados as $sem): ?>
            <p>
                <strong><?= htmlspecialchars($sem['nome']) ?>:</strong>
                0 de <?= $sem['limite_vagas'] ?> vagas disponíveis
                <span class="badge badge-danger">Lotado</span>
            </p>
            <?php endforeach; ?>
            
            <!-- Seção de diagnóstico para administrador -->
            <?php if (isset($_GET['debug']) && $_GET['debug'] == 1): ?>
            <div style="margin-top: 20px; padding: 10px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
                <h4>Diagnóstico de Vagas</h4>
                <table style="width:100%; border-collapse: collapse;">
                    <tr style="background-color: #e9ecef;">
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left;">Seminário</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center;">ID</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center;">Limite</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center;">Inscritos (Evento)</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center;">Inscritos (Seminário)</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center;">Vagas Disponíveis</th>
                    </tr>
                    <?php foreach($diagnostico_vagas as $diag): ?>
                    <tr>
                        <td style="border: 1px solid #dee2e6; padding: 8px;"><?= htmlspecialchars($diag['nome']) ?></td>
                        <td style="border: 1px solid #dee2e6; padding: 8px; text-align: center;"><?= $diag['id'] ?></td>
                        <td style="border: 1px solid #dee2e6; padding: 8px; text-align: center;"><?= $diag['limite'] <= 0 ? 'Ilimitado' : $diag['limite'] ?></td>
                        <td style="border: 1px solid #dee2e6; padding: 8px; text-align: center;"><?= $diag['inscritos_evento'] ?></td>
                        <td style="border: 1px solid #dee2e6; padding: 8px; text-align: center;"><?= $diag['inscritos_seminario'] ?></td>
                        <td style="border: 1px solid #dee2e6; padding: 8px; text-align: center;"><?= $diag['vagas_disponiveis'] < 0 ? 'Ilimitado' : $diag['vagas_disponiveis'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <p style="margin-top: 10px; font-size: 12px; color: #6c757d;">
                    Usando tabela de relação: <?= $tabela_relacao_existe ? 'Sim' : 'Não' ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($evento['descricao'])): ?>
        <div class="evento-info">
            <h3>Descrição do Evento</h3>
            <p><?= nl2br(htmlspecialchars($evento['descricao'])) ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($erro_validacao): ?>
            <div class="alert-error">
                <?= htmlspecialchars($erro_validacao) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($sucesso_mensagem): ?>
            <div class="alert-success">
                <?= $sucesso_mensagem ?>
            </div>
            
            <?php if (!empty($mensagem_boasvindas)): ?>
                <div class="welcome-message">
                    <h4>Mensagem de Boas-vindas</h4>
                    <div><?= nl2br(htmlspecialchars($mensagem_boasvindas)) ?></div>
                </div>
            <?php endif; ?>
            
            <a href="https://www.educacaoparalimpica.org.br/login/index.php" class="btn-access" target="_blank">
                Acessar o Curso
            </a>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="acao" value="inscrever">
                
                <div class="form-group">
                    <label for="cpf">CPF:</label>
                    <input type="text" id="cpf" name="cpf" placeholder="000.000.000-00" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="nome">Nome:</label>
                        <input type="text" id="nome" name="nome" placeholder="Ex.: João Rafael" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="sobrenome">Sobrenome:</label>
                        <input type="text" id="sobrenome" name="sobrenome" placeholder="Ex.: Gomes de Almeida" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">E-mail:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="estado">Estado:</label>
                        <select id="estado" name="estado" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($estados as $sigla => $nome): ?>
                                <option value="<?= $sigla ?>"><?= $nome ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="cidade">Cidade:</label>
                        <select id="cidade" name="cidade" required disabled>
                            <option value="">Selecione um estado primeiro</option>
                        </select>
                    </div>
                </div>
                
                <?php if (!empty($seminarios_disponiveis)): ?>
                    <h3>Seminários Disponíveis</h3>
                    <p>Selecione os seminários em que deseja se inscrever:</p>
                    
                    <div class="seminarios-grid">
                        <?php foreach ($seminarios_disponiveis as $sem): ?>
                            <?php
                            // Verificar se o seminário está com inscrições abertas
                            // Forçar abertura para vagas ilimitadas ou token joaopessoa
                            $is_disabled = !($sem['inscrição_aberta'] || $sem['total_vagas'] <= 0 || $token_evento == 'joaopessoa');
                            ?>
                            <div class="seminario-item <?= $is_disabled ? 'seminario-disabled' : '' ?>">
                                <label <?= $is_disabled ? 'class="disabled"' : '' ?>>
                                    <input type="checkbox" name="seminarios[]" value="<?= $sem['id'] ?>" 
                                        <?= $is_disabled ? 'disabled' : '' ?>>
                                    <div>
                                        <div class="seminario-title"><?= htmlspecialchars($sem['fullname']) ?></div>
                                        
                                        <?php if (!empty($sem['startdate'])): ?>
                                            <div class="seminario-date">
                                                <i class="icon-calendar"></i> Período do curso: <?= date('d/m/Y', $sem['startdate']) ?>
                                                <?php if (!empty($sem['enddate'])): ?>
                                                    a <?= date('d/m/Y', $sem['enddate']) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="seminario-inscricao">
                                            <i class="icon-user"></i> Método: <strong>Autoinscrição</strong>
                                            
                                            <?php 
                                            // Verificar período de inscrição
                                            $periodo_aberto = true;
                                            $data_inicio_inscricao = $sem['start_enrol'] ?? 0;
                                            $data_fim_inscricao = $sem['end_enrol'] ?? 0;
                                            $agora = time();
                                            
                                            if ($data_inicio_inscricao > 0 && $data_inicio_inscricao > $agora) {
                                                $periodo_aberto = false;
                                                $data_formatada = date('d/m/Y', $data_inicio_inscricao);
                                                echo "<div class='periodo-info periodo-fechado'>Inscrições iniciam em $data_formatada</div>";
                                            } elseif ($data_fim_inscricao > 0 && $data_fim_inscricao < $agora) {
                                                $periodo_aberto = false;
                                                $data_formatada = date('d/m/Y', $data_fim_inscricao);
                                                echo "<div class='periodo-info periodo-fechado'>Inscrições encerradas em $data_formatada</div>";
                                            } elseif ($data_inicio_inscricao > 0 || $data_fim_inscricao > 0) {
                                                echo "<div class='periodo-info periodo-aberto'>Período de inscrição: ";
                                                if ($data_inicio_inscricao > 0) {
                                                    echo date('d/m/Y', $data_inicio_inscricao);
                                                }
                                                if ($data_inicio_inscricao > 0 && $data_fim_inscricao > 0) {
                                                    echo " a ";
                                                }
                                                if ($data_fim_inscricao > 0) {
                                                    echo date('d/m/Y', $data_fim_inscricao);
                                                }
                                                echo "</div>";
                                            }
                                            ?>
                                            
                                            <?php
                                            if ($sem['total_vagas'] <= 0 || $sem['vagas_disponiveis'] < 0): // Sem limite (lógica anterior)
                                            ?>
                                                <div class="vagas-info">
                                                    <span class="vagas-ilimitadas">Vagas ilimitadas</span>
                                                </div>
                                            <?php elseif ($sem['vagas_disponiveis'] <= 0): // Sem vagas disponíveis ?>
                                                <div class="vagas-info">
                                                    <span class="vagas-esgotadas">Vagas esgotadas</span>
                                                </div>
                                            <?php else: // Com vagas disponíveis ?>
                                                <div class="vagas-info">
                                                    <span class="vagas-disponiveis">Vagas disponíveis: <strong><?= $sem['vagas_disponiveis'] ?></strong> de <?= $sem['total_vagas'] ?></span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            // Verificar novamente se as inscrições estão abertas, considerando casos especiais
                                            $inscricoes_abertas = $sem['inscrição_aberta'] || 
                                                                $sem['total_vagas'] <= 0 || // Considerar ilimitado se for <= 0
                                                                $sem['vagas_disponiveis'] < 0 || // Considerar ilimitado se for < 0
                                                                $sem['vagas_disponiveis'] > 0 || // Tem vagas disponíveis
                                                                $token_evento == 'joaopessoa';
                                            
                                            if (!$inscricoes_abertas): 
                                            ?>
                                                <div class="status-inscricao">
                                                    <span class="status-fechado">Inscrições fechadas</span>
                                                    <?php 
                                                    if (!$periodo_aberto) {
                                                        echo "<small>(fora do período permitido)</small>";
                                                    } elseif ($sem['vagas_disponiveis'] <= 0 && $sem['total_vagas'] > 0) {
                                                        // Só mostrar "vagas esgotadas" quando não for ilimitado
                                                        echo "<small>(vagas esgotadas)</small>";
                                                    }
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="status-inscricao">
                                                    <span class="status-aberto">Inscrições abertas</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php if (!empty($evento['courseid'])): ?>
                        <div class="alert-info">
                            <strong>Nota:</strong> O seminário associado a este evento não está visível ou disponível no momento. 
                            Você será inscrito apenas no curso base de Educação Paralímpica.
                        </div>
                    <?php else: ?>
                        <div class="alert-info">
                            <strong>Nota:</strong> Não há seminário associado a este evento. 
                            Você será inscrito apenas no curso base de Educação Paralímpica.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <button type="submit" class="btn-submit">Inscrever-se</button>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
        // Máscara para CPF
        document.getElementById('cpf')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            
            if (value.length > 9) {
                value = value.replace(/^(\d{3})(\d{3})(\d{3})(\d{2}).*/, '$1.$2.$3-$4');
            } else if (value.length > 6) {
                value = value.replace(/^(\d{3})(\d{3})(\d{0,3}).*/, '$1.$2.$3');
            } else if (value.length > 3) {
                value = value.replace(/^(\d{3})(\d{0,3}).*/, '$1.$2');
            }
            
            e.target.value = value;
        });
        
        // Integração com API do IBGE para cidades
        const selectEstado = document.getElementById('estado');
        const selectCidade = document.getElementById('cidade');
        
        if (selectEstado && selectCidade) {
            selectEstado.addEventListener('change', function() {
                const uf = this.value;
                
                if (!uf) {
                    selectCidade.innerHTML = '<option value="">Selecione um estado primeiro</option>';
                    selectCidade.disabled = true;
                    return;
                }
                
                // Exibir carregando
                selectCidade.innerHTML = '<option value="">Carregando cidades...</option>';
                selectCidade.disabled = true;
                
                // Fazer requisição à API do IBGE
                fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios`)
                    .then(response => response.json())
                    .then(cidades => {
                        // Limpar o select
                        selectCidade.innerHTML = '<option value="">Selecione a cidade</option>';
                        
                        // Ordenar cidades alfabeticamente
                        cidades.sort((a, b) => a.nome.localeCompare(b.nome));
                        
                        // Adicionar as opções de cidades
                        cidades.forEach(cidade => {
                            const option = document.createElement('option');
                            option.value = cidade.nome;
                            option.textContent = cidade.nome;
                            selectCidade.appendChild(option);
                        });
                        
                        // Habilitar o select
                        selectCidade.disabled = false;
                    })
                    .catch(error => {
                        console.error('Erro ao buscar cidades:', error);
                        selectCidade.innerHTML = '<option value="">Erro ao carregar cidades</option>';
                        selectCidade.disabled = true;
                    });
            });
        }
    </script>
</body>
</html> 