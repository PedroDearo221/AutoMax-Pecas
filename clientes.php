<?php
declare(strict_types=1);
require_once __DIR__ . '/automax.php';
axSession();
axRequireLogin();
require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';

function h(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function queryOracle($conn, string $sql, array $params = []): array {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar consulta.');
    foreach ($params as $key => &$value) oci_bind_by_name($stmt, $key, $value);
    unset($value);
    if (!oci_execute($stmt)) {
        $err = oci_error($stmt);
        error_log('AutoMax: ' . ($err['message'] ?? 'Falha na consulta'));
        oci_free_statement($stmt);
        throw new RuntimeException('Não foi possível consultar o Oracle.');
    }
    $result = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) $result[] = $row;
    oci_free_statement($stmt);
    return $result;
}
function modifyOracle($conn, string $sql, array $params): void {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar operação.');
    foreach ($params as $key => &$value) oci_bind_by_name($stmt, $key, $value);
    unset($value);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmt);
        $code = (int)($err['code'] ?? 0);
        error_log('AutoMax: ' . ($err['message'] ?? 'Falha no banco'));
        oci_free_statement($stmt);
        if ($code === 1) throw new RuntimeException('Já existe um cliente com este CPF.');
        if ($code === 2292) throw new RuntimeException('Este cliente possui veículos ou outros registros vinculados e não pode ser excluído.');
        throw new RuntimeException('Não foi possível salvar os dados no Oracle.');
    }
    oci_free_statement($stmt);
}
function camposEndereco(string $valor): array {
    $nomes = ['cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'estado'];
    $v = array_fill_keys($nomes, '');
    $json = json_decode($valor, true);
    if (is_array($json)) {
        $mapa = ['cep'=>'c', 'endereco'=>'r', 'numero'=>'n', 'complemento'=>'x', 'bairro'=>'b', 'cidade'=>'d', 'estado'=>'u'];
        foreach ($mapa as $key => $short) $v[$key] = (string)($json[$short] ?? '');
    } else {
        $v['endereco'] = $valor; // Compatibilidade com endereços antigos em texto.
    }
    return $v;
}
function enderecoVisivel(string $valor): string {
    $a = camposEndereco($valor);
    $p = array_filter([$a['endereco'], $a['numero'] !== '' ? 'Nº ' . $a['numero'] : '', $a['complemento'], $a['bairro'], trim($a['cidade'].' '.$a['estado']), $a['cep']], fn($v) => $v !== '');
    return implode(', ', $p) ?: 'Endereço não informado';
}
function cpfVisivel(string $cpf): string {
    return strlen($cpf) === 11 ? '***.***.***-' . substr($cpf, -2) : $cpf;
}

if (empty($_SESSION['automax_csrf'])) $_SESSION['automax_csrf'] = bin2hex(random_bytes(32));
$campos = ['cpf','nome','telefone','email','cep','endereco','numero','complemento','bairro','cidade','estado'];
$dados = array_fill_keys($campos, '');
$idEdicao = '';
$erro = '';
$clientes = [];
$conn = null;
try {
    $conn = conexaoOracle();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['automax_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sessão inválida. Recarregue a página.');
        }
        $acao = (string)($_POST['acao'] ?? '');
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if ($acao === 'excluir') {
            if (!$id || $id < 1) throw new RuntimeException('Cliente inválido.');
            modifyOracle($conn, 'DELETE FROM CLIENTE WHERE ID_CLIENTE=:id', [':id'=>$id]);
            oci_commit($conn);
            header('Location: clientes.php?ok=excluido', true, 303);
            exit;
        }
        if ($acao !== 'salvar') throw new RuntimeException('Operação desconhecida.');
        $idEdicao = $id ? (string)$id : '';
        foreach ($campos as $campo) $dados[$campo] = trim((string)($_POST[$campo] ?? ''));
        $dados['cpf'] = preg_replace('/\\D/', '', $dados['cpf']);
        if (strlen($dados['cpf']) !== 11) throw new RuntimeException('Informe um CPF de 11 dígitos.');
        if ($dados['nome'] === '' || mb_strlen($dados['nome']) > 100) throw new RuntimeException('Informe o nome (até 100 caracteres).');
        if (mb_strlen($dados['email']) > 100 || ($dados['email'] !== '' && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Informe um e-mail válido (até 100 caracteres).');
        if (mb_strlen($dados['telefone']) > 20) throw new RuntimeException('Telefone muito longo.');
        if ($dados['telefone'] === '') throw new RuntimeException('Informe o telefone.');
        $endereco = json_encode([
            'c'=>$dados['cep'], 'r'=>$dados['endereco'], 'n'=>$dados['numero'],
            'x'=>$dados['complemento'], 'b'=>$dados['bairro'],
            'd'=>$dados['cidade'], 'u'=>$dados['estado']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($endereco) > 200) throw new RuntimeException('Endereço muito longo para o banco. Encurte os campos do endereço.');
        if ($idEdicao !== '') {
            modifyOracle($conn, 'UPDATE CLIENTE SET CPF=:cpf, NOME=:nome, TELEFONE=:telefone, EMAIL=:email, ENDERECO=:endereco WHERE ID_CLIENTE=:id', [
                ':cpf'=>$dados['cpf'], ':nome'=>$dados['nome'], ':telefone'=>$dados['telefone'], ':email'=>$dados['email'], ':endereco'=>$endereco, ':id'=>$id
            ]);
        } else {
            modifyOracle($conn, 'INSERT INTO CLIENTE (ID_CLIENTE, CPF, NOME, TELEFONE, EMAIL, ENDERECO) VALUES (SEQ_AUTOMAX_CLIENTE.NEXTVAL, :cpf, :nome, :telefone, :email, :endereco)', [
                ':cpf'=>$dados['cpf'], ':nome'=>$dados['nome'], ':telefone'=>$dados['telefone'], ':email'=>$dados['email'], ':endereco'=>$endereco
            ]);
        }
        oci_commit($conn);
        header('Location: clientes.php?ok=' . ($idEdicao !== '' ? 'editado' : 'criado'), true, 303);
        exit;
    }
    if (isset($_GET['editar'])) {
        $id = filter_var($_GET['editar'], FILTER_VALIDATE_INT);
        if ($id && $id > 0) {
            $found = queryOracle($conn, 'SELECT * FROM CLIENTE WHERE ID_CLIENTE=:id', [':id'=>$id]);
            if ($found) {
                $row = $found[0];
                $idEdicao = (string)$row['ID_CLIENTE'];
                $dados = array_merge($dados, camposEndereco((string)($row['ENDERECO'] ?? '')));
                foreach (['cpf'=>'CPF','nome'=>'NOME','telefone'=>'TELEFONE','email'=>'EMAIL'] as $field=>$column) $dados[$field] = (string)($row[$column] ?? '');
            }
        }
    }
    $clientes = queryOracle($conn, 'SELECT ID_CLIENTE, CPF, NOME, TELEFONE, EMAIL, ENDERECO FROM CLIENTE ORDER BY NOME');
} catch (Throwable $e) {
    if ($conn) oci_rollback($conn);
    $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível processar a operação.';
    error_log('AutoMax clientes: ' . $e->getMessage());
} finally {
    if ($conn) oci_close($conn);
}
$buscaCliente = mb_substr(trim((string)($_GET['buscar'] ?? '')), 0, 100);
$clientesVisiveis = array_values(array_filter($clientes, static function ($c) use ($buscaCliente): bool {
    if ($buscaCliente === '') return true;
    $texto = implode(' ', [(string)($c['NOME'] ?? ''), (string)($c['CPF'] ?? ''), (string)($c['EMAIL'] ?? '')]);
    return mb_stripos($texto, $buscaCliente, 0, 'UTF-8') !== false;
}));
$total = count($clientes);
$totalTelefone = count(array_filter($clientes, fn($c)=>trim((string)($c['TELEFONE'] ?? '')) !== ''));
$totalEmail = count(array_filter($clientes, fn($c)=>trim((string)($c['EMAIL'] ?? '')) !== ''));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | AutoPeças</title>
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

        .resultado-busca {
            margin: 10px 0;
        }

        [hidden] {
            display: none !important;
        }

        .acoes-cliente {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .botao-excluir {
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 9px 13px;
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            transition: background 0.2s, transform 0.2s;
        }

        .botao-excluir:hover {
            background: #b02a37;
            transform: translateY(-1px);
        }

        .botao-editar {
            cursor: pointer;
        }

        .mensagem-cep {
            display: block;
            margin-top: 5px;
            font-size: 12px;
        }

        .mensagem-cep.sucesso {
            color: #198754;
        }

        .mensagem-cep.erro {
            color: #dc3545;
        }

        @media (max-width: 700px) {
            .acoes-cliente {
                flex-direction: column;
                align-items: stretch;
            }

            .acoes-cliente button {
                width: 100%;
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
        <a href="index.php">
            <span class="icone-sem-js" aria-hidden="true">▦</span>
            Dashboard
        </a>

        <a href="clientes.php" class="ativo">
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
            <h1>Clientes</h1>
            <p>Cadastre e gerencie os clientes da sua empresa.</p>
        </div>

        <a href="#formulario-cliente" class="botao botao-principal">
            + Novo cliente
        </a>
    </header>

    <section class="boas-vindas">
        <div>
            <span class="etiqueta">GESTÃO DE CLIENTES</span>
            <h2>Organize seus clientes</h2>
            <p>
                Mantenha os dados de contato organizados para facilitar
                o atendimento e a criação de orçamentos.
            </p>
        </div>
    </section>

    <section class="indicadores">

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Total de clientes</span>
                <span class="icone-card azul">
                    <span class="icone-sem-js" aria-hidden="true">♧</span>
                </span>
            </div>
            <h3 id="total-clientes"><?= $total ?></h3>
            <p class="texto-secundario">Clientes cadastrados</p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Pessoas físicas</span>
                <span class="icone-card roxo">
                    <span class="icone-sem-js" aria-hidden="true">•</span>
                </span>
            </div>
            <h3 id="total-pessoas-fisicas"><?= $total ?></h3>
            <p class="texto-secundario">Cadastros com CPF</p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Com telefone</span>
                <span class="icone-card verde">
                    <span class="icone-sem-js" aria-hidden="true">•</span>
                </span>
            </div>
            <h3 id="total-telefones"><?= $totalTelefone ?></h3>
            <p class="texto-secundario">Contatos informados</p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Com e-mail</span>
                <span class="icone-card laranja">
                    <span class="icone-sem-js" aria-hidden="true">•</span>
                </span>
            </div>
            <h3 id="total-emails"><?= $totalEmail ?></h3>
            <p class="texto-secundario">E-mails informados</p>
        </article>

    </section>

    <?php if ($erro !== ''): ?>
    <p class="resultado-busca" role="alert" style="color:#b91c1c"><?= h($erro) ?></p>
    <?php elseif (isset($_GET['ok'])): ?>
    <p class="resultado-busca" role="status" style="color:#198754">Operação concluída no Oracle FreeSQL.</p>
    <?php endif; ?>
    <?php $clienteExcluir = filter_var($_GET['excluir'] ?? null, FILTER_VALIDATE_INT); ?>
    <?php if ($clienteExcluir): ?>
    <?php foreach ($clientes as $c): if ((int)$c['ID_CLIENTE']!==(int)$clienteExcluir) continue; ?>
    <section class="secao" role="alert"><h2>Confirmar exclusão</h2>
    <p>Deseja excluir o cliente <?= h($c['NOME']) ?>? A exclusão será bloqueada se houver veículos ou orçamentos vinculados.</p>
    <form method="post" action="clientes.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['automax_csrf']) ?>">
        <input type="hidden" name="acao" value="excluir">
        <input type="hidden" name="id" value="<?= (int)$c['ID_CLIENTE'] ?>">
        <button type="submit" class="botao botao-principal">Confirmar exclusão</button>
        <a class="botao botao-secundario" href="clientes.php">Cancelar</a>
    </form></section>
    <?php endforeach; ?>
    <?php endif; ?>
    <section class="secao" id="formulario-cliente">

        <div class="secao-cabecalho">
            <div>
                <h2 id="titulo-formulario"><?= $idEdicao !== '' ? 'Editar cliente' : 'Cadastrar cliente' ?></h2>
                <p>Preencha os dados para registrar um cliente.</p>
            </div>
        </div>

        <form id="form-cliente" action="clientes.php" method="post">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['automax_csrf']) ?>">
            <input type="hidden" id="cliente-editando" name="id" value="<?= h($idEdicao) ?>">

            <div class="form-grid">

                <div class="campo">
                    <label for="cpf">CPF</label>
                    <input
                        type="text"
                        id="cpf"
                        name="cpf"
                        placeholder="000.000.000-00"
                        maxlength="14"
                        inputmode="numeric"
                        required
                     value="<?= h($dados['cpf']) ?>">
                </div>

                <div class="campo campo-largo">
                    <label for="nome">Nome completo</label>
                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        placeholder="Digite o nome completo"
                        required
                     value="<?= h($dados['nome']) ?>">
                </div>

                <div class="campo">
                    <label for="telefone">Telefone</label>
                    <input
                        type="tel"
                        id="telefone"
                        name="telefone"
                        placeholder="(00) 00000-0000"
                        maxlength="15"
                        inputmode="numeric"
                        required
                     value="<?= h($dados['telefone']) ?>">
                </div>

                <div class="campo campo-largo">
                    <label for="email">E-mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="cliente@email.com"
                     value="<?= h($dados['email']) ?>">
                </div>

                <div class="campo">
                    <label for="cep">CEP</label>
                    <input
                        type="text"
                        id="cep"
                        name="cep"
                        placeholder="00000-000"
                        maxlength="9"
                        inputmode="numeric"
                        autocomplete="postal-code"
                        required
                     value="<?= h($dados['cep']) ?>">
                    <small id="mensagem-cep" class="mensagem-cep">
                        Preencha o endereço manualmente (sem JavaScript).
                    </small>
                </div>

                <div class="campo campo-largo">
                    <label for="endereco">Rua / Avenida</label>
                    <input
                        type="text"
                        id="endereco"
                        name="endereco"
                        placeholder="Preencha manualmente"
                        autocomplete="address-line1"
                     value="<?= h($dados['endereco']) ?>">
                </div>

                <div class="campo">
                    <label for="numero">Número</label>
                    <input
                        type="text"
                        id="numero"
                        name="numero"
                        placeholder="Nº da residência"
                        maxlength="10"
                        autocomplete="address-line2"
                        required
                     value="<?= h($dados['numero']) ?>">
                </div>

                <div class="campo">
                    <label for="complemento">Complemento</label>
                    <input
                        type="text"
                        id="complemento"
                        name="complemento"
                        placeholder="Apartamento, casa, bloco..."
                     value="<?= h($dados['complemento']) ?>">
                </div>

                <div class="campo">
                    <label for="bairro">Bairro</label>
                    <input
                        type="text"
                        id="bairro"
                        name="bairro"
                        placeholder="Preencha manualmente"
                     value="<?= h($dados['bairro']) ?>">
                </div>

                <div class="campo">
                    <label for="cidade">Cidade</label>
                    <input
                        type="text"
                        id="cidade"
                        name="cidade"
                        placeholder="Preencha manualmente"
                     value="<?= h($dados['cidade']) ?>">
                </div>

                <div class="campo">
                    <label for="estado">Estado (UF)</label>
                    <input
                        type="text"
                        id="estado"
                        name="estado"
                        placeholder="UF"
                        maxlength="2"
                     value="<?= h($dados['estado']) ?>">
                </div>

            </div>

            <div class="form-acoes">
                <a href="clientes.php#formulario-cliente" class="botao botao-secundario">Limpar formulário</a>

                <button
                    type="submit"
                    id="botao-salvar-cliente"
                    class="botao botao-principal"
                >
                    <?= $idEdicao !== '' ? 'Salvar alterações' : 'Cadastrar cliente' ?>
                </button>
            </div>
        </form>
    </section>

    <section class="secao">

        <div class="secao-cabecalho">
            <div>
                <h2>Clientes cadastrados</h2>
                <p>Consulte os clientes registrados no sistema.</p>
            </div>

            <span class="contador" id="contador-clientes">
                <?= $total ?> <?= $total === 1 ? 'registro' : 'registros' ?>
            </span>
        </div>

        <form class="filtros" method="get" action="clientes.php">
            <div class="campo busca">
                <label for="buscar">Buscar cliente</label>
                <input
                    type="search"
                    id="buscar"
                    name="buscar" value="<?= h($buscaCliente) ?>"
                    placeholder="Digite o nome ou CPF do cliente..."
                >
            </div>

            <button
                type="submit"
                id="btn-buscar"
                class="botao botao-principal"
            >
                Buscar
            </button>

            <a href="clientes.php" class="botao botao-secundario">Limpar</a>
        </form>

        <p id="resultado-busca" class="resultado-busca" aria-live="polite">Exibindo <?= count($clientesVisiveis) ?> de <?= count($clientes) ?> cliente(s).</p>

        <div class="tabela-container">
            <table id="tabela-clientes">
                <thead>
                    <tr>
                        <th>CPF</th>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>E-mail</th>
                        <th>Endereço</th>
                        <th>Ações</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($clientesVisiveis as $cliente): ?>
                    <tr>
                        <td><?= h(cpfVisivel((string)$cliente['CPF'])) ?></td>
                        <td><?= h($cliente['NOME']) ?></td>
                        <td><?= h($cliente['TELEFONE'] ?: '—') ?></td>
                        <td><?= h($cliente['EMAIL'] ?: '—') ?></td>
                        <td><?= h(enderecoVisivel((string)($cliente['ENDERECO'] ?? ''))) ?></td>
                        <td>
                            <div class="acoes-cliente">
                                <a class="botao botao-principal botao-editar" href="clientes.php?editar=<?= (int)$cliente['ID_CLIENTE'] ?>#formulario-cliente">Editar</a>
                                <a href="clientes.php?excluir=<?= (int)$cliente['ID_CLIENTE'] ?>" class="botao-excluir">Excluir</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clientesVisiveis): ?><tr><td colspan="6" style="text-align:center;padding:24px">Nenhum cliente encontrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="rodape">
        <p>© 2026 AutoPeças — Sistema de Gestão</p>
        <p>Projeto acadêmico</p>
    </footer>

</main>



</body>
</html>