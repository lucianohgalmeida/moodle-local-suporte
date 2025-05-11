document.addEventListener('DOMContentLoaded', function () {
    const links = document.querySelectorAll('a[href="#modal-suporte"]');

    function carregarEMostrarModal() {
        if (!document.getElementById('modal-suporte')) {
            fetch('/local/suporte/modal.html')
                .then(res => res.text())
                .then(html => {
                    const div = document.createElement('div');
                    div.innerHTML = html;
                    document.body.appendChild(div);

                    fetch('/local/suporte/ajax.php', {
                        method: 'POST',
                        body: new FormData()
                    })
                    .then(res => res.json())
                    .then(data => {
                        document.getElementById('nomeSolicitante').textContent = data.nome;
                        document.getElementById('emailSolicitante').textContent = data.email;
                    });

                    const form = document.getElementById('form-suporte');
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        const formData = new FormData(form);

                        fetch('/local/suporte/ajax.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                alert(data.error);
                                return;
                            }

                            if (data.success) {
                                alert('Mensagem enviada com sucesso!');
                                form.reset();
                                $('#modal-suporte').modal('hide');
                            } else {
                                alert('Erro ao enviar a mensagem.');
                            }
                        });
                    });

                    $('#modal-suporte').modal('show');
                });
        } else {
            $('#modal-suporte').modal('show');
        }
    }

    links.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            carregarEMostrarModal();
        });
    });
});
