<?php
// AJAX: Busca de cursos para o painel de liberação de provas presenciais
require_once(__DIR__ . '/../../config.php');
require_login();

// Verificação de capability
$context = context_system::instance();
if (!has_capability('moodle/site:config', $context) && !has_capability('moodle/course:view', $context) && !has_capability('moodle/group:manage', $context)) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

require_sesskey();

// Parâmetros
$search = optional_param('search', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 1, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);

// Helpers
require_once($CFG->dirroot . '/agendamento/classes/course_helper.php');
require_once($CFG->dirroot . '/agendamento/classes/group_helper.php');

try {
    $courses = \local_agendamento\course_helper::search_courses_with_group($search, $categoryid, $page, $perpage);

    $result = [];
    foreach ($courses['courses'] as $course) {
        try {
            $group = \local_agendamento\group_helper::get_group_by_name($course->id, 'PROVA PRESENCIAL');
            if ($group) {
                $group_members = \local_agendamento\group_helper::count_group_members($group->id);
                $course_students = \local_agendamento\course_helper::count_course_students($course->id);
                
                // Garantir que groupid é um inteiro válido
                $groupid = (int)$group->id;
                
                // Log para debug
                error_log("Curso: {$course->id}, Grupo: {$groupid}, Total estudantes: {$course_students}, No grupo: {$group_members}");
                
                $result[] = [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'category' => isset($courses['categories'][$course->category]) ? $courses['categories'][$course->category] : '',
                    'groupid' => $groupid,
                    'group_members' => $group_members,
                    'course_students' => $course_students
                ];
            }
        } catch (Exception $ex) {
            error_log("Erro ao processar curso {$course->id}: " . $ex->getMessage());
            continue;
        }
    }

    $response = [
        'courses' => $result,
        'total' => $courses['total'],
        'page' => $page,
        'perpage' => $perpage,
        'status' => 'success'
    ];

} catch (Exception $e) {
    error_log("Erro no processamento da busca: " . $e->getMessage());
    $response = [
        'courses' => [],
        'total' => 0,
        'page' => $page,
        'perpage' => $perpage,
        'status' => 'error',
        'message' => 'Ocorreu um erro durante a busca. Tente novamente.'
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
exit; 