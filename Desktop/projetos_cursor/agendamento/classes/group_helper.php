<?php
namespace local_agendamento;

defined('MOODLE_INTERNAL') || die();

class group_helper {
    /**
     * Retorna o grupo 'PROVA PRESENCIAL' de um curso, se existir.
     * @param int $courseid
     * @param string $groupname
     * @return object|false
     */
    public static function get_group_by_name($courseid, $groupname = 'PROVA PRESENCIAL') {
        global $DB;
        return $DB->get_record('groups', ['courseid' => $courseid, 'name' => $groupname], '*', IGNORE_MISSING);
    }

    /**
     * Conta o número de estudantes inscritos em um grupo.
     * @param int $groupid
     * @return int
     */
    public static function count_group_members($groupid) {
        global $DB;
        
        // Verificar se o grupo existe
        $group = $DB->get_record('groups', ['id' => $groupid]);
        if (!$group) {
            return 0;
        }
        
        // Usar o método core do Moodle para obter diretamente os usuários do grupo
        $countusers = 0;
        try {
            $countusers = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id)
                FROM {user} u
                JOIN {groups_members} gm ON gm.userid = u.id
                WHERE gm.groupid = ?
                AND u.deleted = 0", 
                [$groupid]
            );
        } catch (\Exception $e) {
            error_log('Erro ao contar membros do grupo: ' . $e->getMessage());
            // Método alternativo em caso de erro
            $countusers = $DB->count_records('groups_members', ['groupid' => $groupid]);
        }
        
        error_log("Grupo: {$groupid}, Curso: {$group->courseid}, Membros: {$countusers}");
        
        // Se tivemos um erro ou zero, tente um método alternativo
        if ($countusers == 0) {
            $directcount = $DB->count_records('groups_members', ['groupid' => $groupid]);
            error_log("Contagem alternativa para Grupo {$groupid}: {$directcount}");
            return $directcount;
        }
        
        return $countusers;
    }
} 