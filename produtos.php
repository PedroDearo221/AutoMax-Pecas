<?php
// Produtos AutoMax - dados armazenados no Oracle FreeSQL.
declare(strict_types=1);
require_once __DIR__ . '/automax.php';
axSession();
axRequireLogin();
require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function consultar($conn, string $sql, array $params = []): array {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar consulta.');
    foreach ($params as $name => &$value) {
        oci_bind_by_name($stmt, $name, $value);
    }
    unset($value);
    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        error_log('AutoMax Oracle: ' . ($e['message'] ?? 'Erro desconhecido'));
        oci_free_statement($stmt);
        throw new RuntimeException('Não foi possível consultar o banco.');
    }
    $rows = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) $rows[] = $row;
    oci_free_statement($stmt);
    return $rows;
}
function executar($conn, string $sql, array $params = []): void {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar operação.');
    foreach ($params as $name => &$value) {
        oci_bind_by_name($stmt, $name, $value);
    }
    unset($value);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt);
        $code = $e['code'] ?? 0;
        error_log('AutoMax Oracle ' . $code . ': ' . ($e['message'] ?? 'Erro desconhecido'));
        oci_free_statement($stmt);
        if ($code === 1) throw new RuntimeException('Código de produto já cadastrado.');
        if ($code === 2292) throw new RuntimeException('O produto está sendo utilizado em outros registros e não pode ser excluído.');
        throw new RuntimeException('Falha ao salvar no banco. Confira os dados.');
    }
    oci_free_statement($stmt);
}

if (empty($_SESSION['automax_csrf'])) $_SESSION['automax_csrf'] = bin2hex(random_bytes(32));
$erro = '';
$valores = ['id'=>'', 'codigo'=>'', 'nome'=>'', 'categoria'=>'', 'marca'=>'', 'preco'=>'', 'minimo'=>'', 'descricao'=>''];
$conn = null;
try {
    $conn = conexaoOracle();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['automax_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sessão inválida. Atualize a página e tente novamente.');
        }
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'excluir') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new RuntimeException('Produto inválido.');
            executar($conn, 'DELETE FROM PRODUTO WHERE ID_PRODUTO = :id', [':id' => $id]);
            oci_commit($conn);
            header('Location: produtos.php?ok=excluido', true, 303);
            exit;
        }
        if ($acao !== 'salvar') throw new RuntimeException('Operação inválida.');

        $valores = [
            'id' => trim((string)($_POST['id'] ?? '')),
            'codigo' => strtoupper(trim((string)($_POST['codigo'] ?? ''))),
            'nome' => trim((string)($_POST['nome'] ?? '')),
            'categoria' => trim((string)($_POST['categoria'] ?? '')),
            'marca' => trim((string)($_POST['marca'] ?? '')),
            'preco' => trim((string)($_POST['preco'] ?? '')),
            'minimo' => trim((string)($_POST['minimo'] ?? '')),
            'descricao' => trim((string)($_POST['descricao'] ?? '')),
        ];
        $id = $valores['id'] !== '' ? filter_var($valores['id'], FILTER_VALIDATE_INT) : null;
        if ($valores['id'] !== '' && (!$id || $id < 1)) throw new RuntimeException('ID inválido.');
        if ($valores['codigo'] === '' || mb_strlen($valores['codigo']) > 30 || $valores['nome'] === '' || mb_strlen($valores['nome']) > 100) throw new RuntimeException('Informe código e nome válidos.');
        if (mb_strlen($valores['descricao']) > 300) throw new RuntimeException('A descrição excede 300 caracteres.');
        $cat = filter_var($valores['categoria'], FILTER_VALIDATE_INT);
        $marca = filter_var($valores['marca'], FILTER_VALIDATE_INT);
        if (!$cat || !$marca || $cat < 1 || $marca < 1) throw new RuntimeException('Selecione uma categoria e uma marca.');
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $valores['preco'])) throw new RuntimeException('Preço inválido. Use ponto como separador decimal.');
        $minimo = filter_var($valores['minimo'], FILTER_VALIDATE_INT);
        if ($minimo === false || $minimo < 0) throw new RuntimeException('Estoque mínimo deve ser um inteiro não negativo.');
        $preco = $valores['preco'];
        $codigo = $valores['codigo'];
        $nome = $valores['nome'];
        $descricao = $valores['descricao'] === '' ? null : $valores['descricao'];
        $params = [':codigo'=>$codigo, ':nome'=>$nome, ':categoria'=>$cat, ':marca'=>$marca, ':preco'=>$preco, ':minimo'=>$minimo, ':descricao'=>$descricao];
        if ($id === null) {
            executar($conn, 'INSERT INTO PRODUTO (ID_PRODUTO, ID_CATEGORIA, ID_MARCA, CODIGO, NOME, DESCRICAO, PRECO, ESTOQUE_MINIMO) VALUES (SEQ_AUTOMAX_PRODUTO.NEXTVAL, :categoria, :marca, :codigo, :nome, :descricao, :preco, :minimo)', $params);
        } else {
            $params[':id'] = $id;
            executar($conn, 'UPDATE PRODUTO SET ID_CATEGORIA=:categoria, ID_MARCA=:marca, CODIGO=:codigo, NOME=:nome, DESCRICAO=:descricao, PRECO=:preco, ESTOQUE_MINIMO=:minimo WHERE ID_PRODUTO=:id', $params);
        }
        oci_commit($conn);
        header('Location: produtos.php?ok=' . ($id === null ? 'criado' : 'editado'), true, 303);
        exit;
    }
} catch (Throwable $e) {
    if ($conn) oci_rollback($conn);
    $erro = $e->getMessage();
}

$categorias = []; $marcas = []; $produtos = []; $estoqueResumo = ['UNIDADES'=>0,'BAIXOS'=>0];
$busca = trim((string)($_GET['busca'] ?? ''));
try {
    if (!$conn) $conn = conexaoOracle();
    $categorias = consultar($conn, 'SELECT ID_CATEGORIA, NOME FROM CATEGORIA ORDER BY NOME');
    $marcas = consultar($conn, 'SELECT ID_MARCA, NOME FROM MARCA ORDER BY NOME');
    $resumo = consultar($conn, "SELECT NVL(SUM(E.QUANTIDADE),0) AS UNIDADES,
        COUNT(CASE WHEN E.QUANTIDADE IS NOT NULL AND E.QUANTIDADE <= P.ESTOQUE_MINIMO THEN 1 END) AS BAIXOS
        FROM PRODUTO P LEFT JOIN ESTOQUE E ON E.ID_PRODUTO=P.ID_PRODUTO");
    if ($resumo) $estoqueResumo = $resumo[0];
    $pesquisa = '%' . mb_strtoupper($busca, 'UTF-8') . '%';
    $produtos = consultar($conn, 'SELECT P.ID_PRODUTO, P.CODIGO, P.NOME, P.DESCRICAO, P.PRECO, P.ESTOQUE_MINIMO, P.ID_CATEGORIA, P.ID_MARCA, C.NOME AS CATEGORIA, M.NOME AS MARCA, E.QUANTIDADE FROM PRODUTO P LEFT JOIN ESTOQUE E ON E.ID_PRODUTO=P.ID_PRODUTO JOIN CATEGORIA C ON C.ID_CATEGORIA=P.ID_CATEGORIA JOIN MARCA M ON M.ID_MARCA=P.ID_MARCA WHERE (:termo1 IS NULL OR UPPER(P.CODIGO) LIKE :termo2 OR UPPER(P.NOME) LIKE :termo3 OR UPPER(C.NOME) LIKE :termo4 OR UPPER(M.NOME) LIKE :termo5) ORDER BY P.NOME', [':termo1'=>$busca === '' ? null : $pesquisa, ':termo2'=>$pesquisa, ':termo3'=>$pesquisa, ':termo4'=>$pesquisa, ':termo5'=>$pesquisa]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['editar'])) {
        $idEditar = filter_var($_GET['editar'], FILTER_VALIDATE_INT);
        if ($idEditar) {
            $achados = consultar($conn, 'SELECT ID_PRODUTO, CODIGO, NOME, DESCRICAO, PRECO, ESTOQUE_MINIMO, ID_CATEGORIA, ID_MARCA FROM PRODUTO WHERE ID_PRODUTO=:id', [':id'=>$idEditar]);
            if ($achados) {
                $p = $achados[0];
                $valores = ['id'=>$p['ID_PRODUTO'], 'codigo'=>$p['CODIGO'], 'nome'=>$p['NOME'], 'categoria'=>$p['ID_CATEGORIA'], 'marca'=>$p['ID_MARCA'], 'preco'=>$p['PRECO'], 'minimo'=>$p['ESTOQUE_MINIMO'], 'descricao'=>$p['DESCRICAO']];
            }
        }
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}
if ($conn) oci_close($conn);
$editando = (string)$valores['id'] !== '';
$mensagens = ['criado'=>'Produto cadastrado com sucesso.', 'editado'=>'Produto atualizado com sucesso.', 'excluido'=>'Produto excluído com sucesso.'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos | AutoPeças</title>
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
            flex-shrink: 0;
        }

        .painel-icone {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .painel-icone i {
            width: 54px;
            height: 54px;
            color: #f28c28;
            stroke-width: 1.6;
        }

        .icone-card {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icone-card i {
            width: 23px;
            height: 23px;
            stroke-width: 1.8;
        }

        .icone-card.azul i {
            color: #3b82f6;
        }

        .icone-card.roxo i {
            color: #8b5cf6;
        }

        .icone-card.laranja i {
            color: #f28c28;
        }

        .icone-card.verde i {
            color: #22c55e;
        }

        .botao i {
            width: 17px;
            height: 17px;
            vertical-align: middle;
            margin-right: 5px;
        }

        .acoes-produto {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .botao-excluir-produto {
            background-color: #dc2626;
            color: #ffffff;
            border: 1px solid #dc2626;
        }

        .botao-excluir-produto:hover {
            background-color: #b91c1c;
            border-color: #b91c1c;
        }

        .botao-excluir-produto i {
            color: #ffffff;
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
            <a href="index.php">
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

            <a href="produtos.php" class="ativo">
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
        <section class="painel-controle">
            <div class="painel-texto">
                <span class="painel-etiqueta">PAINEL DE CONTROLE</span>
                <h2>Visão geral dos produtos</h2>
                <p>Cadastre, consulte e atualize as peças armazenadas no Oracle FreeSQL.</p>
            </div>
        </section>
        <?php if ($erro): ?><p role="alert" style="padding:14px;color:#b91c1c;background:#fee2e2;border-radius:8px;"><?= h($erro) ?></p><?php endif; ?>
        <?php if (isset($mensagens[$_GET['ok'] ?? ''])): ?><p role="status" style="padding:14px;color:#166534;background:#dcfce7;border-radius:8px;"><?= h($mensagens[$_GET['ok']]) ?></p><?php endif; ?>
        <section class="indicadores">
            <article class="card-indicador"><div class="indicador-topo"><span>Produtos exibidos</span><span class="icone-card azul"><span class="icone-sem-js" aria-hidden="true">▣</span></span></div><h3><?= count($produtos) ?></h3><p class="texto-secundario"><?= $busca !== '' ? 'Resultado da busca' : 'Produtos cadastrados' ?></p></article>
            <article class="card-indicador"><div class="indicador-topo"><span>Estoque disponível</span><span class="icone-card roxo"><span class="icone-sem-js" aria-hidden="true">▤</span></span></div><h3><?= number_format((int)$estoqueResumo['UNIDADES'],0,',','.') ?></h3><p class="texto-secundario">Unidades cadastradas</p></article>
            <article class="card-indicador"><div class="indicador-topo"><span>Estoque baixo</span><span class="icone-card laranja"><span class="icone-sem-js" aria-hidden="true">!</span></span></div><h3><?= (int)$estoqueResumo['BAIXOS'] ?></h3><p class="texto-secundario">Abaixo ou no mínimo</p></article>
            <article class="card-indicador"><div class="indicador-topo"><span>Categorias</span><span class="icone-card verde"><span class="icone-sem-js" aria-hidden="true">•</span></span></div><h3><?= count($categorias) ?></h3><p class="texto-secundario">Categorias cadastradas</p></article>
        </section>
        <?php $confirmarProduto = filter_var($_GET['excluir'] ?? null, FILTER_VALIDATE_INT); ?>
        <?php if ($confirmarProduto): ?>
        <section class="secao" role="alert">
            <h2>Confirmar exclusão do produto</h2>
            <p>Você tem certeza que deseja excluir o produto selecionado? Produtos com estoque ou orçamentos vinculados não poderão ser excluídos.</p>
            <form method="post" action="produtos.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['automax_csrf']) ?>">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int)$confirmarProduto ?>">
                <button type="submit" class="botao botao-excluir-produto">Confirmar exclusão</button>
                <a href="produtos.php" class="botao botao-secundario">Cancelar</a>
            </form>
        </section>
        <?php endif; ?>
        <section class="secao" id="formulario-produto">
            <div class="secao-cabecalho"><div><h2><?= $editando ? 'Editar produto' : 'Cadastrar produto' ?></h2><p>Informe os dados da peça automotiva.</p></div></div>
            <form action="produtos.php" method="post" id="form-produto">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['automax_csrf']) ?>">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= h($valores['id']) ?>">
                <div class="form-grid">
                    <div class="campo"><label for="codigo">Código do produto *</label><input id="codigo" name="codigo" type="text" maxlength="30" required value="<?= h($valores['codigo']) ?>" placeholder="Ex.: PEC001"></div>
                    <div class="campo"><label for="nome-produto">Nome do produto *</label><input id="nome-produto" name="nome" type="text" maxlength="100" required value="<?= h($valores['nome']) ?>"></div>
                    <div class="campo"><label for="categoria">Categoria *</label><select id="categoria" name="categoria" required><option value="">Selecione</option><?php foreach ($categorias as $c): ?><option value="<?= h($c['ID_CATEGORIA']) ?>" <?= (string)$c['ID_CATEGORIA'] === (string)$valores['categoria'] ? 'selected' : '' ?>><?= h($c['NOME']) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="marca-produto">Marca *</label><select id="marca-produto" name="marca" required><option value="">Selecione</option><?php foreach ($marcas as $m): ?><option value="<?= h($m['ID_MARCA']) ?>" <?= (string)$m['ID_MARCA'] === (string)$valores['marca'] ? 'selected' : '' ?>><?= h($m['NOME']) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="preco-venda">Preço de venda (R$) *</label><input id="preco-venda" name="preco" type="number" min="0" step="0.01" required value="<?= h($valores['preco']) ?>" placeholder="0.00"></div>
                    <div class="campo"><label for="estoque-minimo">Estoque mínimo *</label><input id="estoque-minimo" name="minimo" type="number" min="0" step="1" required value="<?= h($valores['minimo']) ?>"></div>
                    <div class="campo campo-completo"><label for="descricao">Descrição</label><textarea id="descricao" name="descricao" maxlength="300" rows="3" placeholder="Detalhes, aplicação e compatibilidade da peça..."><?= h($valores['descricao']) ?></textarea></div>
                </div>
                <p class="texto-secundario">A quantidade pode ser atualizada na página Estoque. Preço de custo não é registrado no banco.</p>
                <div class="form-acoes">
                    <a href="produtos.php" class="botao botao-secundario"><span class="icone-sem-js" aria-hidden="true">↺</span> <?= $editando ? 'Cancelar edição' : 'Limpar formulário' ?></a>
                    <button type="submit" class="botao botao-principal"><span class="icone-sem-js" aria-hidden="true">✓</span><?= $editando ? ' Salvar alterações' : ' Cadastrar produto' ?></button>
                </div>
            </form>
        </section>
        <section class="secao">
            <div class="secao-cabecalho"><div><h2>Produtos cadastrados</h2><p>Consulte, edite ou exclua os produtos da loja.</p></div></div>
            <form class="filtros" action="produtos.php" method="get">
                <div class="campo busca"><label for="buscar-produto">Buscar produto</label><input type="search" id="buscar-produto" name="busca" placeholder="Código, nome, marca ou categoria..." value="<?= h($busca) ?>"></div>
                <button type="submit" class="botao botao-principal"><span class="icone-sem-js" aria-hidden="true">⌕</span> Buscar</button>
                <a class="botao botao-secundario" href="produtos.php"><span class="icone-sem-js" aria-hidden="true">×</span> Limpar</a>
            </form>
            <p class="resultado-busca">Exibindo <?= count($produtos) ?> produto(s).</p>
            <div class="tabela-container"><table id="tabela-produtos"><thead><tr><th>Código</th><th>Produto</th><th>Categoria</th><th>Marca</th><th>Preço de venda</th><th>Estoque</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php if (!$produtos): ?><tr><td colspan="8" style="text-align:center;padding:25px">Nenhum produto encontrado.</td></tr><?php endif; ?>
            <?php foreach ($produtos as $p): ?>
                <tr><td><?= h($p['CODIGO']) ?></td><td><?= h($p['NOME']) ?></td><td><?= h($p['CATEGORIA']) ?></td><td><?= h($p['MARCA']) ?></td><td>R$ <?= number_format((float)$p['PRECO'], 2, ',', '.') ?></td><td><?= $p['QUANTIDADE'] === null ? '—' : number_format((int)$p['QUANTIDADE'],0,',','.') ?></td><td><span class="texto-secundario"><?= $p['QUANTIDADE'] === null ? 'Não cadastrado' : ((int)$p['QUANTIDADE']===0 ? 'Esgotado' : ((int)$p['QUANTIDADE'] <= (int)$p['ESTOQUE_MINIMO'] ? 'Estoque baixo' : 'Disponível')) ?></span></td><td><div class="acoes-produto"><a class="botao botao-principal" href="produtos.php?editar=<?= urlencode((string)$p['ID_PRODUTO']) ?>#formulario-produto"><span class="icone-sem-js" aria-hidden="true">✎</span> Editar</a><a class="botao botao-excluir-produto" href="produtos.php?excluir=<?= (int)$p['ID_PRODUTO'] ?>">Excluir</a></div></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <footer class="rodape"><p>Sistema AutoPeças — Gestão de produtos</p></footer>
    </main>

</body>
</html>
