<?php

/**
 * Plugin local_suporte - Controle de chamados técnicos no Moodle
 *
 * Desenvolvido por: Luciano Almeida
 * Empresa: TechEduConnect (https://techeduconnect.com.br)
 * Data: 09/05/2025
 * Descrição: Plugin para abertura de chamados com formulário moderno,
 * validação de campos, limite de anexo e envio via AJAX.
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_suporte'; // Nome completo do plugin (obrigatório)
$plugin->version   = 2025050800;      // Versão no formato yyyymmddxx (obrigatório)
$plugin->requires  = 2022041900;      // Requer no mínimo Moodle 4.0 (obrigatório)
$plugin->maturity  = MATURITY_STABLE; // Opcional: define o nível de estabilidade
$plugin->release   = '1.0.0';         // Opcional: número de versão legível