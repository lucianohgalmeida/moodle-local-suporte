# Plugin local_suporte para Moodle

Este plugin permite a abertura e o gerenciamento de chamados técnicos diretamente no Moodle, facilitando o contato entre usuários e a equipe de suporte.

## Funcionalidades
- Formulário moderno para abertura de chamados
- Validação de campos obrigatórios
- Limite de anexo (até 5MB)
- Envio de dados via AJAX
- Categorização do chamado (bug, dúvida, sugestão, etc.)
- Notificação por e-mail para a equipe de suporte

## Instalação
1. Faça o download dos arquivos do plugin.
2. Coloque a pasta `local/suporte` dentro do diretório `local` do seu Moodle.
3. Acesse a administração do site para concluir a instalação.

## Uso
- Acesse o formulário de suporte pelo menu ou atalho configurado.
- Preencha os campos obrigatórios, selecione a categoria e envie sua solicitação.
- O suporte receberá um e-mail com os detalhes do chamado.

## Requisitos
- Moodle 4.0 ou superior

## Estrutura do plugin
- `version.php`: informações de versão e requisitos
- `ajax.php`: processamento dos chamados e envio de e-mail
- `modal.html`: interface do formulário de suporte

## Suporte
Dúvidas ou sugestões: helpdesk@dtcom.com.br

---
Desenvolvido por Luciano Almeida - TechEduConnect
