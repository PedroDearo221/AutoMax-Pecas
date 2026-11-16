<?php
declare(strict_types=1);

require_once __DIR__ . '/automax.php';
axSession();
axRequireLogin();

require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';

if (empty($_SESSION['estoque_csrf'])) {
    $_SESSION['estoque_csrf'] = bin2hex(random_bytes(32));
}

function escapar($valor): string {
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function executarConsulta($conn, string $sql): array {
    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar consulta.');
    }

    try {
        if (!oci_execute($stmt)) {
            $erro = oci_error($stmt);
            error_log($erro['message'] ?? 'Erro Oracle');
            throw new RuntimeException('Erro ao consultar o banco.');
        }

        $dados = [];

        while (
            ($linha = oci_fetch_array(
                $stmt,
                OCI_ASSOC | OCI_RETURN_NULLS
            )) !== false
        ) {
            $dados[] = $linha;
        }

        return $dados;
    } finally {
        oci_free_statement($stmt);
    }
}

function situacaoProduto(array $produto): string {
    if ($produto['QUANTIDADE'] === null) {
        return 'nao_cadastrado';
    }

    $quantidade = (int)$produto['QUANTIDADE'];
    $minimo = (int)$produto['ESTOQUE_MINIMO'];

    if ($quantidade === 0) {
        return 'esgotado';
    }

    if ($quantidade <= $minimo) {
        return 'baixo';
    }

    return 'disponivel';
}

function nomeSituacao(string $situacao): string {
    return match ($situacao) {
        'disponivel' => 'Disponível',
        'baixo' => 'Estoque baixo',
        'esgotado' => 'Esgotado',
        'nao_cadastrado' => 'Não cadastrado',
        default => 'Desconhecido'
    };
}

function classeSituacao(string $situacao): string {
    return match ($situacao) {
        'disponivel' => 'status-ok',
        'baixo' => 'status-baixo',
        'esgotado' => 'status-esgotado',
        default => 'status-nao-cadastrado'
    };
}

function moeda($valor): string {
    return 'R$ ' . number_format(
        (float)$valor,
        2,
        ',',
        '.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = null;

    try {
        $token = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($_SESSION['estoque_csrf'], $token)) {
            throw new RuntimeException('Sessão inválida.');
        }

        $idProduto = filter_var(
            $_POST['id_produto'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $quantidadeTexto = trim(
            (string)($_POST['quantidade'] ?? '')
        );

        if (!$idProduto) {
            throw new RuntimeException('Produto inválido.');
        }

        if (
            $quantidadeTexto === '' ||
            !ctype_digit($quantidadeTexto) ||
            strlen($quantidadeTexto) > 10
        ) {
            throw new RuntimeException(
                'Informe uma quantidade inteira de 0 até 9999999999.'
            );
        }

        $quantidade = (int)$quantidadeTexto;

        if ($quantidade > 9999999999) {
            throw new RuntimeException('Quantidade inválida.');
        }

        $conn = conexaoOracle();

        $sql = "
            MERGE INTO ESTOQUE E
            USING (
                SELECT
                    :id_produto AS ID_PRODUTO,
                    :quantidade AS QUANTIDADE
                FROM DUAL
            ) D

            ON (E.ID_PRODUTO = D.ID_PRODUTO)

            WHEN MATCHED THEN
                UPDATE SET
                    E.QUANTIDADE = D.QUANTIDADE,
                    E.DATA_ATUALIZACAO = SYSDATE

            WHEN NOT MATCHED THEN
                INSERT (
                    ID_ESTOQUE,
                    ID_PRODUTO,
                    QUANTIDADE,
                    DATA_ATUALIZACAO
                )
                VALUES (
                    SEQ_AUTOMAX_ESTOQUE.NEXTVAL,
                    D.ID_PRODUTO,
                    D.QUANTIDADE,
                    SYSDATE
                )
        ";

        $stmt = oci_parse($conn, $sql);

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar gravação.');
        }

        try {
            oci_bind_by_name(
                $stmt,
                ':id_produto',
                $idProduto
            );

            oci_bind_by_name(
                $stmt,
                ':quantidade',
                $quantidade
            );

            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $erro = oci_error($stmt);
                error_log($erro['message'] ?? 'Erro Oracle');

                throw new RuntimeException(
                    'Não foi possível salvar o estoque.'
                );
            }

            if (!oci_commit($conn)) {
                throw new RuntimeException(
                    'Não foi possível confirmar a alteração.'
                );
            }

            $_SESSION['estoque_mensagem'] =
                'Quantidade salva com sucesso!';
        } finally {
            oci_free_statement($stmt);
        }

    } catch (Throwable $erro) {
        if ($conn) {
            oci_rollback($conn);
        }

        error_log('AutoMax Estoque: ' . $erro->getMessage());

        $_SESSION['estoque_mensagem'] =
            'Erro ao salvar: verifique a quantidade e o produto.';
    } finally {
        if ($conn) {
            oci_close($conn);
        }
    }

    header('Location: estoque.php', true, 303);
    exit;
}

$mensagem = $_SESSION['estoque_mensagem'] ?? '';
unset($_SESSION['estoque_mensagem']);

$erroConsulta = '';

$produtos = [];

$indicadores = [
    'produtos' => 0,
    'unidades' => 0,
    'baixo' => 0,
    'esgotados' => 0,
    'sem_cadastro' => 0
];

$conn = null;

try {
    $conn = conexaoOracle();

    $sql = "
        SELECT
            P.ID_PRODUTO,
            P.CODIGO,
            P.NOME,
            C.NOME AS CATEGORIA,
            M.NOME AS MARCA,
            P.PRECO,
            P.ESTOQUE_MINIMO,
            E.QUANTIDADE,
            TO_CHAR(
                E.DATA_ATUALIZACAO,
                'DD/MM/YYYY HH24:MI'
            ) AS DATA_ATUALIZACAO
        FROM PRODUTO P
        INNER JOIN CATEGORIA C
            ON C.ID_CATEGORIA = P.ID_CATEGORIA
        INNER JOIN MARCA M
            ON M.ID_MARCA = P.ID_MARCA
        LEFT JOIN ESTOQUE E
            ON E.ID_PRODUTO = P.ID_PRODUTO
        ORDER BY P.NOME
    ";

    $produtos = executarConsulta($conn, $sql);

    foreach ($produtos as &$produto) {
        $produto['SITUACAO'] = situacaoProduto($produto);

        $indicadores['produtos']++;

        if ($produto['QUANTIDADE'] !== null) {
            $indicadores['unidades'] +=
                (int)$produto['QUANTIDADE'];
        }

        switch ($produto['SITUACAO']) {
            case 'baixo':
                $indicadores['baixo']++;
                break;

            case 'esgotado':
                $indicadores['esgotados']++;
                break;

            case 'nao_cadastrado':
                $indicadores['sem_cadastro']++;
                break;
        }
    }
    unset($produto);

} catch (Throwable $erro) {
    error_log('AutoMax Consulta: ' . $erro->getMessage());

    $erroConsulta =
        'Não foi possível carregar os dados do Oracle.';
} finally {
    if ($conn) {
        oci_close($conn);
    }
}

$busca = mb_substr(
    trim((string)($_GET['busca'] ?? '')),
    0,
    100
);

$filtro = (string)($_GET['situacao'] ?? 'todos');

$filtrosValidos = [
    'todos',
    'disponivel',
    'baixo',
    'esgotado',
    'nao_cadastrado'
];

if (!in_array($filtro, $filtrosValidos, true)) {
    $filtro = 'todos';
}

$produtosFiltrados = [];

foreach ($produtos as $produto) {
    $texto = implode(' ', [
        $produto['CODIGO'],
        $produto['NOME'],
        $produto['CATEGORIA'],
        $produto['MARCA']
    ]);

    $correspondeBusca = $busca === '' ||
        mb_stripos($texto, $busca, 0, 'UTF-8') !== false;

    $correspondeFiltro = $filtro === 'todos' ||
        $produto['SITUACAO'] === $filtro;

    if ($correspondeBusca && $correspondeFiltro) {
        $produtosFiltrados[] = $produto;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Estoque | AutoPeças</title>

    <link rel="stylesheet" href="css/estilo.css">

    <style>
        .menu a {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .estoque-mensagem {
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: #eaf4ff;
            color: #275784;
        }

        .estoque-erro {
            background: #fff0f0;
            color: #a52b2b;
        }

        .estoque-observacao {
            margin: 16px 0;
            font-size: 13px;
            color: #68778a;
        }

        .estoque-formulario {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .estoque-formulario input {
            width: 105px;
            padding: 9px;
            border: 1px solid #dce2ea;
            border-radius: 7px;
        }

        .estoque-formulario button {
            cursor: pointer;
            white-space: nowrap;
        }

        .estoque-data {
            display: block;
            margin-top: 5px;
            color: #8490a0;
            font-size: 11px;
        }

        .status-nao-cadastrado {
            color: #68778a;
            background: #edf0f4;
            padding: 5px 10px;
            border-radius: 12px;
        }

        .status-produto {
            white-space: nowrap;
        }

        .tabela-container {
            overflow-x: auto;
        }

        #tabela-estoque th,
        #tabela-estoque td {
            white-space: nowrap;
            vertical-align: middle;
        }

        .mensagem-vazia {
            padding: 20px;
            text-align: center;
        }

        .filtros {
            display: flex;
            align-items: end;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filtros .campo {
            min-width: 180px;
        }

        .filtros .campo.busca {
            flex: 1;
        }

        .filtros input,
        .filtros select {
            width: 100%;
            box-sizing: border-box;
        }

        .acoes-filtro {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .estoque-formulario {
                flex-direction: column;
                align-items: stretch;
            }

            .estoque-formulario input {
                width: 100%;
            }
        }
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
        <a href="index.php">Dashboard</a>
        <a href="clientes.php">Clientes</a>
        <a href="veiculos.php">Veículos</a>
        <a href="produtos.php">Produtos</a>
        <a href="estoque.php" class="ativo">Estoque</a>
        <a href="orcamentos.php">Orçamentos</a>
        <a href="relatorios.php">Relatórios</a>
    </nav>

    <div class="sidebar-rodape">
        <span class="status-bolinha"></span>
        <span>Sistema operacional</span>
    </div>

</aside>

<main class="conteudo">

    <section class="painel-controle">
        <div class="painel-texto">
            <span class="painel-etiqueta">
                CONTROLE DE ESTOQUE
            </span>

            <h2>Gestão de estoque</h2>

            <p>
                Acompanhe as quantidades disponíveis,
                identifique peças com estoque baixo e
                monitore as necessidades de reposição.
            </p>
        </div>
    </section>

    <?php if ($mensagem): ?>
        <div class="estoque-mensagem">
            <?= escapar($mensagem) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroConsulta): ?>
        <div class="estoque-mensagem estoque-erro">
            <?= escapar($erroConsulta) ?>
        </div>
    <?php endif; ?>

    <section class="indicadores">

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Produtos cadastrados</span>
            </div>

            <h3><?= $indicadores['produtos'] ?></h3>

            <p class="texto-secundario">
                Produtos no catálogo
            </p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Unidades disponíveis</span>
            </div>

            <h3>
                <?= number_format(
                    $indicadores['unidades'],
                    0,
                    ',',
                    '.'
                ) ?>
            </h3>

            <p class="texto-secundario">
                Quantidade total de peças
            </p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Estoque baixo</span>
            </div>

            <h3><?= $indicadores['baixo'] ?></h3>

            <p class="texto-secundario">
                Produtos abaixo ou iguais ao mínimo
            </p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Produtos esgotados</span>
            </div>

            <h3><?= $indicadores['esgotados'] ?></h3>

            <p class="texto-secundario">
                Produtos com quantidade zero
            </p>
        </article>

    </section>

    <section class="secao">

        <div class="secao-cabecalho">
            <div>
                <h2>Produtos que precisam de atenção</h2>

                <p>
                    Peças com quantidade igual ou inferior
                    ao estoque mínimo.
                </p>
            </div>
        </div>

        <div class="tabela-container">
            <table id="tabela-alertas">

                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Produto</th>
                        <th>Quantidade atual</th>
                        <th>Estoque mínimo</th>
                        <th>Situação</th>
                    </tr>
                </thead>

                <tbody>

                <?php
                $alertas = 0;

                foreach ($produtos as $produto):
                    if (!in_array(
                        $produto['SITUACAO'],
                        ['baixo', 'esgotado'],
                        true
                    )) {
                        continue;
                    }

                    $alertas++;
                ?>

                    <tr>
                        <td><?= escapar($produto['CODIGO']) ?></td>
                        <td><?= escapar($produto['NOME']) ?></td>
                        <td><?= (int)$produto['QUANTIDADE'] ?></td>
                        <td><?= (int)$produto['ESTOQUE_MINIMO'] ?></td>

                        <td>
                            <span class="status-produto <?= classeSituacao($produto['SITUACAO']) ?>">
                                <?= nomeSituacao($produto['SITUACAO']) ?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if ($alertas === 0): ?>
                    <tr>
                        <td colspan="5" class="mensagem-vazia">
                            Nenhum produto com estoque baixo ou esgotado.
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

    <section class="secao">

        <div class="secao-cabecalho">
            <div>
                <h2>Posição do estoque</h2>

                <p>
                    Consulte e atualize as quantidades
                    das peças cadastradas.
                </p>
            </div>

            <a href="produtos.php"
               class="botao botao-principal">
                Gerenciar produtos
            </a>
        </div>

        <form method="get" action="estoque.php">

            <div class="filtros">

                <div class="campo busca">
                    <label for="busca">Buscar peça</label>

                    <input
                        type="search"
                        name="busca"
                        id="busca"
                        maxlength="100"
                        value="<?= escapar($busca) ?>"
                        placeholder="Código, nome, categoria ou marca..."
                    >
                </div>

                <div class="campo">
                    <label for="situacao">Situação</label>

                    <select name="situacao" id="situacao">
                        <?php
                        $opcoes = [
                            'todos' => 'Todas',
                            'disponivel' => 'Disponível',
                            'baixo' => 'Estoque baixo',
                            'esgotado' => 'Esgotado',
                            'nao_cadastrado' => 'Não cadastrado'
                        ];

                        foreach ($opcoes as $valor => $texto):
                        ?>
                            <option
                                value="<?= escapar($valor) ?>"
                                <?= $filtro === $valor ? 'selected' : '' ?>
                            >
                                <?= escapar($texto) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="acoes-filtro">
                    <button
                        type="submit"
                        class="botao botao-principal"
                    >
                        Filtrar
                    </button>

                    <a
                        href="estoque.php"
                        class="botao botao-secundario"
                    >
                        Limpar
                    </a>
                </div>

            </div>

        </form>

        <p class="estoque-observacao">
            Produtos sem quantidade cadastrada não são
            considerados esgotados.

            <?php if ($indicadores['sem_cadastro'] > 0): ?>
                <strong>
                    <?= $indicadores['sem_cadastro'] ?>
                    produto(s) aguardando cadastro de estoque.
                </strong>
            <?php endif; ?>
        </p>

        <p class="resultado-busca">
            Exibindo <?= count($produtosFiltrados) ?>
            produto(s).
        </p>

        <div class="tabela-container">

            <table id="tabela-estoque">

                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Produto</th>
                        <th>Categoria</th>
                        <th>Marca</th>
                        <th>Quantidade</th>
                        <th>Estoque mínimo</th>
                        <th>Preço de venda</th>
                        <th>Valor estimado</th>
                        <th>Situação</th>
                        <th>Atualizar quantidade</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($produtosFiltrados as $produto): ?>

                    <?php
                    $quantidade =
                        $produto['QUANTIDADE'] === null
                            ? null
                            : (int)$produto['QUANTIDADE'];

                    $preco = (float)$produto['PRECO'];

                    $situacao = $produto['SITUACAO'];
                    ?>

                    <tr>
                        <td><?= escapar($produto['CODIGO']) ?></td>
                        <td><?= escapar($produto['NOME']) ?></td>
                        <td><?= escapar($produto['CATEGORIA']) ?></td>
                        <td><?= escapar($produto['MARCA']) ?></td>

                        <td>
                            <?= $quantidade === null
                                ? '—'
                                : number_format($quantidade, 0, ',', '.')
                            ?>
                        </td>

                        <td>
                            <?= (int)$produto['ESTOQUE_MINIMO'] ?>
                        </td>

                        <td><?= moeda($preco) ?></td>

                        <td>
                            <?= $quantidade === null
                                ? '—'
                                : moeda($quantidade * $preco)
                            ?>
                        </td>

                        <td>
                            <span class="status-produto <?= classeSituacao($situacao) ?>">
                                <?= nomeSituacao($situacao) ?>
                            </span>

                            <?php if ($produto['DATA_ATUALIZACAO']): ?>
                                <small class="estoque-data">
                                    Atualizado:
                                    <?= escapar($produto['DATA_ATUALIZACAO']) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td>

                            <form
                                method="post"
                                action="estoque.php"
                                class="estoque-formulario"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= escapar($_SESSION['estoque_csrf']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="id_produto"
                                    value="<?= (int)$produto['ID_PRODUTO'] ?>"
                                >

                                <input
                                    type="number"
                                    name="quantidade"
                                    min="0"
                                    max="9999999999"
                                    step="1"
                                    required
                                    value="<?= $quantidade === null ? '' : $quantidade ?>"
                                >

                                <button
                                    type="submit"
                                    class="botao botao-secundario"
                                >
                                    Salvar
                                </button>

                            </form>

                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$produtosFiltrados): ?>
                    <tr>
                        <td colspan="10" class="mensagem-vazia">
                            <?= $erroConsulta
                                ? 'Erro ao consultar o banco.'
                                : 'Nenhum produto encontrado.'
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <footer class="rodape">
        <p>Sistema AutoPeças — Controle de estoque</p>
        <p>Dados consultados no Oracle FreeSQL</p>
    </footer>

</main>

</body>
</html>
