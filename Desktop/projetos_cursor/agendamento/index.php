<?php
// Página principal do Painel de Liberação de Provas Presenciais
require_once(__DIR__ . '/../config.php');
require_login();

// Verificação de capability (apenas administradores ou responsáveis)
$context = context_system::instance();
if (!has_capability('moodle/site:config', $context) && !has_capability('moodle/course:view', $context) && !has_capability('moodle/group:manage', $context)) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

// Obter lista de categorias principais
$categories = $DB->get_records('course_categories', ['parent' => 0], 'sortorder', 'id, name');

// Gerar número de versão para evitar cache
$version = time();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/agendamento/index.php'));
$PAGE->set_title('Painel de Liberação de Provas Presenciais');
$PAGE->set_heading('Painel de Liberação de Provas Presenciais');
$PAGE->requires->css('/agendamento/assets/style.css');
$PAGE->requires->js('/agendamento/assets/script.js?v='.$version);

// Bootstrap e FontAwesome já são carregados pelo tema padrão do Moodle

echo $OUTPUT->header();
?>
<div class="container my-4">
    <h2 class="mb-4"><i class="fa fa-chalkboard-teacher"></i> Painel de Liberação de Provas Presenciais</h2>
    
    <div class="row mb-3">
        <div class="col-md-3">
            <label for="category-select" class="form-label">Categoria</label>
            <select id="category-select" class="form-select">
                <option value="0">Todas as categorias</option>
                <?php 
                foreach ($categories as $category) {
                    echo '<option value="'.$category->id.'">'.$category->name.'</option>';
                }
                ?>
            </select>
        </div>
        <div class="col-md-9">
            <label for="course-search" class="form-label">Busca</label>
            <input type="text" id="course-search" class="form-control" placeholder="Buscar disciplina (nome ou código)" autocomplete="off">
        </div>
    </div>
    
    <div id="courses-list" class="row g-4 mt-2">
        <!-- Cards dos cursos serão inseridos aqui via JS -->
    </div>
    <div class="row mt-3">
        <div class="col text-center">
            <button id="load-more" class="btn btn-outline-primary d-none">
                <i class="fa fa-plus"></i> Carregar mais
            </button>
        </div>
    </div>
</div>
<?php
echo $OUTPUT->footer(); 