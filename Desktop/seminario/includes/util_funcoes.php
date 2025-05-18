<?php
/**
 * Arquivo com funções utilitárias para o sistema de seminários
 * 
 * Este arquivo contém funções auxiliares que são usadas em várias partes
 * do sistema para operações comuns, como verificação de limites de vagas.
 * 
 * @package    seminario
 */

/**
 * Verifica se um seminário tem vagas ilimitadas
 * 
 * @param int $total_vagas O total de vagas configurado para o seminário
 * @param int $vagas_disponiveis Número de vagas ainda disponíveis
 * @return bool Retorna true se o seminário tem vagas ilimitadas
 */
function seminario_tem_vagas_ilimitadas($total_vagas, $vagas_disponiveis = null) {
    // Um seminário tem vagas ilimitadas apenas quando total_vagas é explicitamente -1
    // ou quando vagas_disponiveis é explicitamente -1
    return ($total_vagas === -1) || ($vagas_disponiveis === -1);
}

/**
 * Verifica se um seminário tem vagas disponíveis
 * 
 * @param int $total_vagas O total de vagas configurado para o seminário
 * @param int $vagas_disponiveis Número de vagas ainda disponíveis
 * @return bool Retorna true se o seminário tem vagas disponíveis
 */
function seminario_tem_vagas_disponiveis($total_vagas, $vagas_disponiveis) {
    // Um seminário tem vagas disponíveis quando:
    // 1. Tem vagas ilimitadas (total_vagas === -1 ou vagas_disponiveis === -1)
    // 2. Tem um número positivo de vagas disponíveis (vagas_disponiveis > 0)
    return seminario_tem_vagas_ilimitadas($total_vagas, $vagas_disponiveis) || ($vagas_disponiveis > 0);
}

/**
 * Converte o valor de limite de vagas do Moodle para o padrão do sistema
 * 
 * No Moodle, o valor 0 representa "sem limite", mas no nosso sistema
 * usamos -1 para representar "sem limite"
 * 
 * @param int $moodle_limit O valor do limite de vagas no Moodle (customint3)
 * @return int O valor do limite convertido para o padrão do sistema
 */
function converter_limite_moodle($moodle_limit) {
    // Se o valor do Moodle for 0, convertemos para -1 (nosso padrão para "ilimitado")
    if ((int)$moodle_limit === 0) {
        return -1;
    }
    // Para outros valores, mantemos como estão
    return (int)$moodle_limit;
}

/**
 * Formata a exibição do limite de vagas para apresentação ao usuário
 * 
 * @param int $total_vagas O total de vagas configurado para o seminário
 * @param int $vagas_disponiveis Número de vagas ainda disponíveis (opcional)
 * @param string $estilo_tag Tag HTML para envolver o texto formatado (opcional)
 * @return string Texto formatado para exibição
 */
function formatar_limite_vagas($total_vagas, $vagas_disponiveis = null, $estilo_tag = '') {
    $abrir_tag = $estilo_tag ? "<{$estilo_tag}>" : '';
    $fechar_tag = $estilo_tag ? "</{$estilo_tag}>" : '';
    
    if (seminario_tem_vagas_ilimitadas($total_vagas, $vagas_disponiveis)) {
        return "{$abrir_tag}Vagas ilimitadas{$fechar_tag}";
    } elseif ($vagas_disponiveis !== null) {
        if ($vagas_disponiveis <= 0) {
            return "{$abrir_tag}0 de {$total_vagas} vagas disponíveis{$fechar_tag}";
        } else {
            return "{$abrir_tag}{$vagas_disponiveis} de {$total_vagas} vagas disponíveis{$fechar_tag}";
        }
    } else {
        return "{$abrir_tag}Total de {$total_vagas} vagas{$fechar_tag}";
    }
} 