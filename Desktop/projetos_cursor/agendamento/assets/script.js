document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('course-search');
    const categorySelect = document.getElementById('category-select');
    const coursesList = document.getElementById('courses-list');
    const loadMoreBtn = document.getElementById('load-more');
    let page = 1;
    let lastSearch = '';
    let lastCategory = '0';
    let loading = false;
    let total = 0;
    let perpage = 10;

    function renderCard(course) {
        // Renderiza o card usando template Mustache (simples)
        console.log('Renderizando card com groupid:', course.groupid);
        const memberUrl = `/group/members.php?group=${course.groupid}`;
        console.log('URL gerada:', memberUrl);
        
        // Texto para exibição de participantes
        let courseStudentsText = '';
        if (course.course_students === 0) {
            courseStudentsText = '0 estudantes inscritos na disciplina';
        } else if (course.course_students === 1) {
            courseStudentsText = `<strong>${course.course_students}</strong> estudante inscrito na disciplina`;
        } else {
            courseStudentsText = `<strong>${course.course_students}</strong> estudantes inscritos na disciplina`;
        }
        
        let groupMembersText = '';
        if (course.group_members === 0) {
            groupMembersText = '0 estudantes no grupo PROVA PRESENCIAL';
        } else if (course.group_members === 1) {
            groupMembersText = `<strong>${course.group_members}</strong> estudante no grupo PROVA PRESENCIAL`;
        } else {
            groupMembersText = `<strong>${course.group_members}</strong> estudantes no grupo PROVA PRESENCIAL`;
        }
        
        return `
        <div class="col-md-6 col-lg-4">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column">
              <h5 class="card-title mb-2">
                <i class="fa fa-book text-primary"></i>
                <a href="/course/view.php?id=${course.id}" target="_blank">${course.fullname}</a>
              </h5>
              <h6 class="card-subtitle mb-2 text-muted">
                <i class="fa fa-folder-open"></i> ${course.category}
              </h6>
              <p class="mb-2">
                <i class="fa fa-users text-primary"></i> ${courseStudentsText}
              </p>
              <p class="mb-3">
                <i class="fa fa-user-check text-success"></i> ${groupMembersText}
              </p>
              <div class="mt-auto d-grid gap-2">
                <a href="/group/members.php?group=${course.groupid}" class="btn btn-success" target="_blank">
                  <i class="fa fa-unlock"></i> Liberar Prova
                </a>
                <a href="/mod/quiz/index.php?id=${course.id}" class="btn btn-outline-primary" target="_blank">
                  <i class="fa fa-question-circle"></i> Questionários
                </a>
                <a href="/agendamento/lista_presenca.php?course=${course.id}&group=${course.groupid}" class="btn btn-outline-secondary" target="_blank">
                  <i class="fa fa-clipboard-list"></i> Lista de Presença
                </a>
              </div>
            </div>
          </div>
        </div>`;
    }

    function showErrorMessage(message) {
        coursesList.innerHTML = `
            <div class="col-12">
                <div class="alert alert-danger">
                    <i class="fa fa-exclamation-triangle"></i> ${message}
                    <div class="mt-3">
                        <button onclick="window.location.reload()" class="btn btn-outline-danger">
                            <i class="fa fa-sync"></i> Tentar Novamente
                        </button>
                    </div>
                </div>
            </div>`;
    }

    function fetchCourses(search, category, append = false) {
        if (loading) return;
        loading = true;
        if (!append) {
            coursesList.innerHTML = '<div class="text-center w-100 py-5"><span class="spinner-border"></span><p class="mt-3">Buscando disciplinas...</p></div>';
            page = 1;
        } else {
            loadMoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span> Carregando...';
            loadMoreBtn.disabled = true;
        }
        
        const timestamp = new Date().getTime(); // Prevenir cache
        fetch(`/agendamento/ajax/search_courses.php?search=${encodeURIComponent(search)}&categoryid=${category}&page=${page}&perpage=${perpage}&sesskey=${M.cfg.sesskey}&t=${timestamp}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error(`Erro na resposta do servidor: ${res.status} ${res.statusText}`);
                }
                return res.json();
            })
            .then(data => {
                if (data.status === 'error') {
                    throw new Error(data.message || 'Erro ao buscar disciplinas');
                }
                
                total = data.total;
                if (!append) coursesList.innerHTML = '';
                
                if (data.courses.length === 0 && !append) {
                    coursesList.innerHTML = `
                        <div class="col-12 text-center">
                            <div class="alert alert-info">
                                <i class="fa fa-info-circle"></i> 
                                Nenhuma disciplina encontrada com o grupo PROVA PRESENCIAL.
                                <div class="mt-2">Tente selecionar outra categoria ou refine sua busca.</div>
                            </div>
                        </div>`;
                } else {
                    data.courses.forEach(course => {
                        coursesList.innerHTML += renderCard(course);
                    });
                }
                
                if ((page * perpage) < total) {
                    loadMoreBtn.classList.remove('d-none');
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.innerHTML = '<i class="fa fa-plus"></i> Carregar mais';
                } else {
                    loadMoreBtn.classList.add('d-none');
                }
                loading = false;
            })
            .catch(error => {
                console.error('Erro na busca:', error);
                showErrorMessage('Erro ao buscar disciplinas. Tente novamente.');
                loading = false;
                if (append) {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.innerHTML = '<i class="fa fa-plus"></i> Carregar mais';
                }
            });
    }

    searchInput.addEventListener('input', function(e) {
        const value = e.target.value.trim();
        lastSearch = value;
        lastCategory = categorySelect.value;
        fetchCourses(value, lastCategory);
    });

    categorySelect.addEventListener('change', function() {
        lastCategory = this.value;
        fetchCourses(lastSearch, lastCategory);
    });

    loadMoreBtn.addEventListener('click', function() {
        if (loading) return;
        page++;
        fetchCourses(lastSearch, lastCategory, true);
    });

    // Busca inicial
    fetchCourses('', categorySelect.value);
}); 