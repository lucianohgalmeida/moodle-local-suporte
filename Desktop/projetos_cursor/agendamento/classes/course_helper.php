<?php
namespace local_agendamento;

defined('MOODLE_INTERNAL') || die();

use context_course;

class course_helper {
    /**
     * Busca cursos visíveis com grupo 'PROVA PRESENCIAL', filtrando por termo, categoria e paginação.
     * @param string $search
     * @param int $categoryid
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function search_courses_with_group($search = '', $categoryid = 0, $page = 1, $perpage = 10) {
        global $DB;
        $offset = ($page - 1) * $perpage;
        $params = ['visible' => 1];
        $wheres = ['c.visible = :visible'];

        if ($search) {
            $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
            $wheres[] = '(c.fullname LIKE :search1 OR c.shortname LIKE :search2)';
        }
        if ($categoryid) {
            // Busca recursiva em subcategorias
            $categoryids = self::get_subcategories($categoryid);
            list($catinsql, $catparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
            $wheres[] = 'c.category ' . $catinsql;
            $params = array_merge($params, $catparams);
        }
        $sql = 'SELECT c.* FROM {course} c WHERE ' . implode(' AND ', $wheres) . ' ORDER BY c.fullname ASC';
        $courses = $DB->get_records_sql($sql, $params, $offset, $perpage);

        // Filtrar cursos que possuem grupo 'PROVA PRESENCIAL'
        $filtered = [];
        $categories = [];
        foreach ($courses as $course) {
            $group = $DB->get_record('groups', ['courseid' => $course->id, 'name' => 'PROVA PRESENCIAL'], '*', IGNORE_MISSING);
            if ($group) {
                $filtered[] = $course;
                $categories[$course->category] = self::get_category_hierarchy($course->category);
            }
        }
        // Total para paginação
        $total = count($filtered);
        return ['courses' => $filtered, 'categories' => $categories, 'total' => $total];
    }

    /**
     * Retorna todos os IDs de subcategorias recursivamente.
     */
    public static function get_subcategories($categoryid) {
        global $DB;
        $ids = [$categoryid];
        $children = $DB->get_records('course_categories', ['parent' => $categoryid]);
        foreach ($children as $child) {
            $ids = array_merge($ids, self::get_subcategories($child->id));
        }
        return $ids;
    }

    /**
     * Retorna a hierarquia da categoria (ex: "Ensino Médio / 3º Ano").
     */
    public static function get_category_hierarchy($categoryid) {
        global $DB;
        $names = [];
        while ($categoryid) {
            $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name, parent');
            if ($cat) {
                array_unshift($names, $cat->name);
                $categoryid = $cat->parent;
            } else {
                break;
            }
        }
        return implode(' / ', $names);
    }
    
    /**
     * Conta o número de estudantes inscritos em um curso.
     * @param int $courseid
     * @return int
     */
    public static function count_course_students($courseid) {
        global $DB;
        
        try {
            // Buscar role ID para estudante
            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            if (!$studentrole) {
                return 0;
            }
            
            // Método alternativo que não usa context_course
            $sql = "SELECT COUNT(DISTINCT ra.userid) 
                    FROM {role_assignments} ra 
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    JOIN {user} u ON u.id = ra.userid
                    WHERE ctx.contextlevel = 50
                    AND ctx.instanceid = :courseid 
                    AND ra.roleid = :roleid
                    AND u.deleted = 0
                    AND u.suspended = 0";
                    
            $params = [
                'courseid' => $courseid,
                'roleid' => $studentrole->id
            ];
            
            $count = $DB->count_records_sql($sql, $params);
            error_log("Curso: {$courseid}, Estudantes: {$count}");
            
            return $count;
        } catch (\Exception $e) {
            error_log("Erro ao contar estudantes do curso: " . $e->getMessage());
            return 0;
        }
    }
} 