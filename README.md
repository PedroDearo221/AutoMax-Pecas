# AutoMax-Peças — Sistema de Gestão de Autopeças

## Sobre o projeto

O AutoMax-Peças é um sistema web desenvolvido como projeto acadêmico da disciplina de Projeto em Computação Aplicada, do curso de Ciência da Computação.

O objetivo é facilitar o gerenciamento de uma loja de autopeças, integrando uma interface web a um banco de dados Oracle por meio de PHP.

## Tecnologias utilizadas

- **HTML5 e CSS3:** estrutura e estilização das páginas.
- **PHP:** processamento das operações e integração com o banco de dados.
- **Oracle Database (FreeSQL):** armazenamento dos dados.
- **OCI8:** comunicação entre PHP e Oracle.
- **XAMPP:** ambiente de desenvolvimento local.

## Funcionalidades

### Implementadas e testadas

- Cadastro, consulta, edição e exclusão de clientes.
- Cadastro, consulta, edição e exclusão de veículos.
- Cadastro, consulta, edição e exclusão de produtos.
- Dashboard com indicadores reais do banco de dados.
- Relatórios com informações obtidas do Oracle.

### Em desenvolvimento

- Controle de estoque.
- Criação e gerenciamento de orçamentos.
- Autenticação e gerenciamento de usuários.

## Estrutura do projeto

- `index.php` — Dashboard.
- `clientes.php` — Gerenciamento de clientes.
- `veiculos.php` — Gerenciamento de veículos.
- `produtos.php` — Gerenciamento de produtos.
- `estoque.php` — Controle de estoque.
- `orcamentos.php` — Gerenciamento de orçamentos.
- `relatorios.php` — Relatórios.
- `login.php` — Interface de autenticação.
- `css/` — Estilos visuais.
- `database/` — Scripts de criação do banco de dados.

## Como executar

O projeto utiliza PHP com a extensão OCI8 e conexão com um banco de dados Oracle.

**As instruções detalhadas de instalação e configuração serão adicionadas antes da entrega final.**

O arquivo de conexão com credenciais reais não está incluído no repositório por motivos de segurança.


## Finalidade

Projeto acadêmico desenvolvido para demonstrar a aplicação prática de programação web, integração com banco de dados relacional e operações de gerenciamento de informações.