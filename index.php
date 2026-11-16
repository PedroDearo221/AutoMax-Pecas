<?php
require_once __DIR__ . '/automax.php';
axRequireLogin();
// Dashboard AutoMax-Peças: leitura dos dados do Oracle FreeSQL.
require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';

function escaparDashboard($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$indicadores = null;
$produtosRecentes = [];
$estoqueBaixo = [];
$erroDashboard = false;
$connDashboard = null;

try {
    $connDashboard = conexaoOracle();
    $sqlIndicadores = "SELECT
        (SELECT COUNT(*) FROM CLIENTE) AS TOTAL_CLIENTES,
        (SELECT COUNT(*) FROM VEICULO) AS TOTAL_VEICULOS,
        (SELECT COUNT(*) FROM PRODUTO) AS TOTAL_PRODUTOS,
        (SELECT COUNT(*) FROM CATEGORIA) AS TOTAL_CATEGORIAS,
        (SELECT NVL(SUM(QUANTIDADE),0) FROM ESTOQUE) AS TOTAL_UNIDADES,
        (SELECT COUNT(*) FROM VW_ESTOQUE_BAIXO) AS TOTAL_ESTOQUE_BAIXO,
        (SELECT COUNT(*) FROM ORCAMENTO WHERE STATUS='PENDENTE') AS TOTAL_PENDENTES,
        (SELECT COUNT(*) FROM PRODUTO P LEFT JOIN ESTOQUE E ON E.ID_PRODUTO=P.ID_PRODUTO WHERE E.ID_ESTOQUE IS NULL) AS TOTAL_SEM_ESTOQUE
        FROM DUAL";
    $stmt = oci_parse($connDashboard, $sqlIndicadores);
    if (!$stmt || !oci_execute($stmt)) {
        throw new RuntimeException('Falha ao consultar os indicadores.');
    }
    $indicadores = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);
    if (!$indicadores) {
        throw new RuntimeException('Sem resposta na consulta de indicadores.');
    }

    $sqlProdutos = "SELECT P.CODIGO, P.NOME, C.NOME AS CATEGORIA,
        M.NOME AS MARCA, P.PRECO
        FROM PRODUTO P
        JOIN CATEGORIA C ON C.ID_CATEGORIA = P.ID_CATEGORIA
        JOIN MARCA M ON M.ID_MARCA = P.ID_MARCA
        ORDER BY P.ID_PRODUTO DESC
        FETCH FIRST 5 ROWS ONLY";
    $stmt = oci_parse($connDashboard, $sqlProdutos);
    if (!$stmt || !oci_execute($stmt)) {
        throw new RuntimeException('Falha ao consultar os produtos.');
    }
    while ($linha = oci_fetch_assoc($stmt)) {
        $produtosRecentes[] = $linha;
    }
    oci_free_statement($stmt);
    $sqlBaixo = "SELECT CODIGO, PRODUTO, CATEGORIA, MARCA, QUANTIDADE, ESTOQUE_MINIMO
        FROM VW_ESTOQUE_BAIXO ORDER BY QUANTIDADE ASC FETCH FIRST 10 ROWS ONLY";
    $stmt = oci_parse($connDashboard, $sqlBaixo);
    if (!$stmt || !oci_execute($stmt)) throw new RuntimeException('Falha ao consultar estoque baixo.');
    while (($linha = oci_fetch_assoc($stmt)) !== false) $estoqueBaixo[]=$linha;
    oci_free_statement($stmt);
} catch (Throwable $erro) {
    error_log('AutoMax dashboard: ' . $erro->getMessage());
    $erroDashboard = true;
    $indicadores = null;
    $produtosRecentes = [];
} finally {
    if ($connDashboard) {
        oci_close($connDashboard);
    }
}
?>


<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoPeças | Painel de Gestão</title>
    <link rel="stylesheet" href="css/estilo.css">


    <style>
        .menu a {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .menu a i {
            width: 19px;
            height: 19px;
            color: #f28c28;
            stroke-width: 1.8;
            flex-shrink: 0;
        }

        .boas-vindas-icone {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f28c28;
        }

        .boas-vindas-icone i {
            width: 54px;
            height: 54px;
            stroke-width: 1.5;
        }

        .icone-card {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .icone-card i {
            width: 23px;
            height: 23px;
            stroke-width: 1.8;
        }

        .atalho-icone {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f28c28;
        }

        .atalho-icone i {
            width: 28px;
            height: 28px;
            stroke-width: 1.8;
        }


        .topo-acoes {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 24px;
            flex-wrap: wrap;
        }


        .perfil-usuario {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .perfil-foto-botao {
            position: relative;
            width: 48px;
            height: 48px;
            padding: 0;
            border: 2px solid #f28c28;
            border-radius: 50%;
            background: #fff;
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
        }

        .perfil-foto-botao img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .perfil-camera {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.45);
            color: #fff;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .perfil-foto-botao:hover .perfil-camera,
        .perfil-foto-botao:focus-visible .perfil-camera {
            opacity: 1;
        }

        .perfil-camera i {
            width: 19px;
            height: 19px;
        }

        .perfil-informacoes {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }

        .perfil-informacoes strong {
            color: #25364b;
            font-size: 14px;
        }

        .perfil-informacoes small {
            color: #718096;
            font-size: 12px;
        }

        .botao-editar-nome {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0;
            border: none;
            background: transparent;
            color: #f28c28;
            font-size: 12px;
            cursor: pointer;
            width: fit-content;
        }

        .botao-editar-nome:hover {
            color: #d87516;
            text-decoration: underline;
        }

        .botao-editar-nome i {
            width: 13px;
            height: 13px;
        }

        @media (max-width: 768px) {
            .topo-acoes {
                justify-content: flex-start;
                width: 100%;
                gap: 16px;
            }
        }
     .icone-sem-js {display:inline-block; min-width:1.05em; font-size:1.1em; font-weight:700; line-height:1; vertical-align:middle;}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">A</div>
        <div>
            <h2>Auto<span>Peças</span></h2>
            <small>Sistema de Gestão</small>
        </div>
    </div>

    <p class="menu-titulo">MENU PRINCIPAL</p>

    <nav class="menu">
        <a href="index.php" class="ativo">
            <span class="icone-sem-js" aria-hidden="true">▦</span>
            Dashboard
        </a>

        <a href="clientes.php">
            <span class="icone-sem-js" aria-hidden="true">♧</span>
            Clientes
        </a>

        <a href="veiculos.php">
            <span class="icone-sem-js" aria-hidden="true">▱</span>
            Veículos
        </a>

        <a href="produtos.php">
            <span class="icone-sem-js" aria-hidden="true">▣</span>
            Produtos
        </a>

        <a href="estoque.php">
            <span class="icone-sem-js" aria-hidden="true">▤</span>
            Estoque
        </a>

        <a href="orcamentos.php">
            <span class="icone-sem-js" aria-hidden="true">≡</span>
            Orçamentos
        </a>

        <a href="relatorios.php">
            <span class="icone-sem-js" aria-hidden="true">▥</span>
            Relatórios
        </a>
    </nav>

    <div class="sidebar-rodape">
        <span class="status-bolinha"></span>
        <span>Sistema operacional</span>
    </div>



</aside>

<main class="conteudo">

    <header class="topo">
        <div>
            <h1>Dashboard</h1>
            <p>Bem-vindo ao painel de gerenciamento da AutoPeças.</p>
        </div>

        <div class="topo-acoes">

            <div class="perfil-informacoes">
                <strong><?= escaparDashboard(axUser()['nome'] ?? 'Demonstração') ?></strong>
                <small><?= axUser() ? escaparDashboard(axUser()['tipo']) : 'Sem sessão de usuário' ?></small>
                <a href="login.php"><?= axUser() ? 'Minha conta / sair' : 'Entrar no sistema' ?></a>
            </div>

            <a href="novo-orcamento.php" class="botao botao-principal">
                + Novo orçamento
            </a>
        </div>
    </header>

    <section class="boas-vindas">
        <div>
            <span class="etiqueta">PAINEL DE CONTROLE</span>
            <h2>Visão geral do negócio</h2>
            <p>
                Acompanhe os produtos, clientes, estoque e orçamentos
                em um só lugar.
            </p>
        </div>
    </section>

    <section class="indicadores">
        <?php if ($erroDashboard): ?>
            <p role="alert">Não foi possível consultar o Oracle FreeSQL. Verifique a conexão e tente atualizar a página.</p>
        <?php endif; ?>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Clientes cadastrados</span>
                <span class="icone-card azul"><span class="icone-sem-js" aria-hidden="true">♧</span></span>
            </div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_CLIENTES'] : '—' ?></h3>
            <p class="texto-secundario">Clientes registrados</p>
            <a href="clientes.php">Ver clientes →</a>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Produtos cadastrados</span>
                <span class="icone-card roxo"><span class="icone-sem-js" aria-hidden="true">▣</span></span>
            </div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_PRODUTOS'] : '—' ?></h3>
            <p class="texto-secundario">Itens no catálogo</p>
            <a href="produtos.php">Ver produtos →</a>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Veículos cadastrados</span>
                <span class="icone-card laranja"><span class="icone-sem-js" aria-hidden="true">▱</span></span>
            </div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_VEICULOS'] : '—' ?></h3>
            <p class="texto-secundario">Veículos vinculados a clientes</p>
            <a href="veiculos.php">Ver veículos →</a>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Categorias cadastradas</span>
                <span class="icone-card verde"><span class="icone-sem-js" aria-hidden="true">•</span></span>
            </div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_CATEGORIAS'] : '—' ?></h3>
            <p class="texto-secundario">Categorias de peças</p>
            <a href="produtos.php">Ver catálogo →</a>
        </article>
    </section>

    <section class="indicadores">
        <article class="card-indicador">
            <div class="indicador-topo"><span>Unidades em estoque</span><span class="icone-card roxo">▣</span></div>
            <h3><?= $indicadores !== null ? number_format((int)$indicadores['TOTAL_UNIDADES'], 0, ',', '.') : '—' ?></h3>
            <p class="texto-secundario">Soma das quantidades cadastradas</p><a href="estoque.php">Ver estoque →</a>
        </article>
        <article class="card-indicador">
            <div class="indicador-topo"><span>Peças com estoque baixo</span><span class="icone-card laranja">!</span></div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_ESTOQUE_BAIXO'] : '—' ?></h3>
            <p class="texto-secundario">Inclui quantidade zero quando mínimo ≥ 0</p><a href="estoque.php">Conferir estoque →</a>
        </article>
        <article class="card-indicador">
            <div class="indicador-topo"><span>Sem estoque cadastrado</span><span class="icone-card azul">?</span></div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_SEM_ESTOQUE'] : '—' ?></h3>
            <p class="texto-secundario">Produtos sem quantidade informada</p><a href="estoque.php">Cadastrar quantidade →</a>
        </article>
        <article class="card-indicador">
            <div class="indicador-topo"><span>Orçamentos pendentes</span><span class="icone-card verde">≡</span></div>
            <h3><?= $indicadores !== null ? (int)$indicadores['TOTAL_PENDENTES'] : '—' ?></h3>
            <p class="texto-secundario">Registros com status PENDENTE</p><a href="orcamentos.php">Ver orçamentos →</a>
        </article>
    </section>

    <section class="secao">
        <div class="secao-cabecalho">
            <div>
                <h2>Últimos produtos cadastrados</h2>
                <p>Produtos do catálogo, em ordem de cadastro pelo identificador.</p>
            </div>
            <a href="produtos.php" class="link-ver-mais">Ver catálogo completo →</a>
        </div>
        <div class="tabela-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Produto</th>
                        <th>Categoria</th>
                        <th>Marca</th>
                        <th>Preço de venda</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$erroDashboard && count($produtosRecentes) === 0): ?>
                    <tr><td colspan="5">Nenhum produto cadastrado.</td></tr>
                <?php elseif ($erroDashboard): ?>
                    <tr><td colspan="5">Dados temporariamente indisponíveis.</td></tr>
                <?php else: ?>
                    <?php foreach ($produtosRecentes as $produto): ?>
                        <tr>
                            <td><?= escaparDashboard($produto['CODIGO']) ?></td>
                            <td><?= escaparDashboard($produto['NOME']) ?></td>
                            <td><?= escaparDashboard($produto['CATEGORIA']) ?></td>
                            <td><?= escaparDashboard($produto['MARCA']) ?></td>
                            <td>R$ <?= number_format((float)$produto['PRECO'], 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="texto-secundario" style="margin-top:12px">Indicadores de estoque e orçamentos obtidos das tabelas atuais do Oracle FreeSQL.</p>
    </section>

    <section class="secao">
        <div class="secao-cabecalho"><div><h2>Produtos com estoque baixo</h2>
            <p>Baseado no estoque mínimo cadastrado para cada peça.</p></div>
            <a href="estoque.php" class="link-ver-mais">Ver estoque →</a>
        </div>
        <div class="tabela-container"><table><thead><tr>
            <th>Código</th><th>Produto</th><th>Categoria</th><th>Quantidade</th><th>Mínimo</th>
        </tr></thead><tbody>
            <?php foreach ($estoqueBaixo as $p): ?>
            <tr><td><?= escaparDashboard($p['CODIGO']) ?></td>
                <td><?= escaparDashboard($p['PRODUTO']) ?></td>
                <td><?= escaparDashboard($p['CATEGORIA']) ?></td>
                <td><?= (int)$p['QUANTIDADE'] ?></td>
                <td><?= (int)$p['ESTOQUE_MINIMO'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$estoqueBaixo): ?><tr><td colspan="5">Nenhum alerta de estoque cadastrado.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="secao">
        <div class="secao-cabecalho">
            <div>
                <h2>Acesso rápido</h2>
                <p>Vá direto para a funcionalidade desejada.</p>
            </div>
        </div>

        <div class="atalhos">
            <a href="clientes.php" class="atalho">
                <span class="atalho-icone">
                    <span class="icone-sem-js" aria-hidden="true">+</span>
                </span>
                <strong>Cadastrar cliente</strong>
                <small>Gerencie seus clientes</small>
            </a>

            <a href="veiculos.php" class="atalho">
                <span class="atalho-icone">
                    <span class="icone-sem-js" aria-hidden="true">▱</span>
                </span>
                <strong>Cadastrar veículo</strong>
                <small>Associe veículos aos clientes</small>
            </a>

            <a href="produtos.php" class="atalho">
                <span class="atalho-icone">
                    <span class="icone-sem-js" aria-hidden="true">+</span>
                </span>
                <strong>Cadastrar produto</strong>
                <small>Atualize seu catálogo</small>
            </a>

            <a href="novo-orcamento.php" class="atalho">
                <span class="atalho-icone">
                    <span class="icone-sem-js" aria-hidden="true">+</span>
                </span>
                <strong>Criar orçamento</strong>
                <small>Monte um novo orçamento</small>
            </a>
        </div>
    </section>

    <footer class="rodape">
        <p>© 2026 AutoPeças — Sistema de Gestão</p>
        <p>Projeto acadêmico</p>
    </footer>

</main>



</body>
</html>
