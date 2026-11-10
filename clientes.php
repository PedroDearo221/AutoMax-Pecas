<?php
declare(strict_types=1);
session_start();
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
    <script src="https://unpkg.com/lucide@latest"></script>

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
            <i data-lucide="layout-dashboard"></i>
            Dashboard
        </a>

        <a href="clientes.php" class="ativo">
            <i data-lucide="users-round"></i>
            Clientes
        </a>

        <a href="veiculos.php">
            <i data-lucide="car-front"></i>
            Veículos
        </a>

        <a href="produtos.php">
            <i data-lucide="package"></i>
            Produtos
        </a>

        <a href="estoque.php">
            <i data-lucide="boxes"></i>
            Estoque
        </a>

        <a href="orcamentos.php">
            <i data-lucide="file-text"></i>
            Orçamentos
        </a>

        <a href="relatorios.php">
            <i data-lucide="chart-no-axes-column"></i>
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
                    <i data-lucide="users-round"></i>
                </span>
            </div>
            <h3 id="total-clientes"><?= $total ?></h3>
            <p class="texto-secundario">Clientes cadastrados</p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Pessoas físicas</span>
                <span class="icone-card roxo">
                    <i data-lucide="user-round"></i>
                </span>
            </div>
            <h3 id="total-pessoas-fisicas"><?= $total ?></h3>
            <p class="texto-secundario">Cadastros com CPF</p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Com telefone</span>
                <span class="icone-card verde">
                    <i data-lucide="phone"></i>
                </span>
            </div>
            <h3 id="total-telefones"><?= $totalTelefone ?></h3>
            <p class="texto-secundario">Contatos informados</p>
        </article>

        <article class="card-indicador">
            <div class="indicador-topo">
                <span>Com e-mail</span>
                <span class="icone-card laranja">
                    <i data-lucide="mail"></i>
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
                        Digite o CEP para preencher o endereço.
                    </small>
                </div>

                <div class="campo campo-largo">
                    <label for="endereco">Rua / Avenida</label>
                    <input
                        type="text"
                        id="endereco"
                        name="endereco"
                        placeholder="Preenchido pelo CEP"
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
                        placeholder="Preenchido pelo CEP"
                     value="<?= h($dados['bairro']) ?>">
                </div>

                <div class="campo">
                    <label for="cidade">Cidade</label>
                    <input
                        type="text"
                        id="cidade"
                        name="cidade"
                        placeholder="Preenchido pelo CEP"
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
                <button
                    type="button"
                    id="cancelar-edicao"
                    class="botao botao-secundario"
                >
                    Limpar formulário
                </button>

                <button
                    type="submit"
                    id="botao-salvar-cliente"
                    class="botao botao-principal"
                >
                    Cadastrar cliente
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

        <div class="filtros">
            <div class="campo busca">
                <label for="buscar">Buscar cliente</label>
                <input
                    type="search"
                    id="buscar"
                    name="buscar"
                    placeholder="Digite o nome ou CPF do cliente..."
                >
            </div>

            <button
                type="button"
                id="btn-buscar"
                class="botao botao-principal"
            >
                Buscar
            </button>

            <button
                type="button"
                id="btn-limpar-busca"
                class="botao botao-secundario"
            >
                Limpar
            </button>
        </div>

        <p id="resultado-busca" class="resultado-busca" aria-live="polite"></p>

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
                <?php foreach ($clientes as $cliente): ?>
                    <tr>
                        <td><?= h(cpfVisivel((string)$cliente['CPF'])) ?></td>
                        <td><?= h($cliente['NOME']) ?></td>
                        <td><?= h($cliente['TELEFONE'] ?: '—') ?></td>
                        <td><?= h($cliente['EMAIL'] ?: '—') ?></td>
                        <td><?= h(enderecoVisivel((string)($cliente['ENDERECO'] ?? ''))) ?></td>
                        <td>
                            <div class="acoes-cliente">
                                <a class="botao botao-principal botao-editar" href="clientes.php?editar=<?= (int)$cliente['ID_CLIENTE'] ?>#formulario-cliente">Editar</a>
                                <form method="post" action="clientes.php" onsubmit="return confirm('Deseja realmente excluir este cliente?');">
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="csrf" value="<?= h($_SESSION['automax_csrf']) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$cliente['ID_CLIENTE'] ?>">
                                    <button class="botao-excluir" type="submit">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clientes): ?><tr><td colspan="6" style="text-align:center;padding:24px">Nenhum cliente cadastrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="rodape">
        <p>© 2026 AutoPeças — Sistema de Gestão</p>
        <p>Projeto acadêmico</p>
    </footer>

</main>

<script>
    if (window.lucide) lucide.createIcons();
    const cpf = document.getElementById('cpf');
    const tel = document.getElementById('telefone');
    const cep = document.getElementById('cep');
    const msg = document.getElementById('mensagem-cep');
    cpf.addEventListener('input', () => {
        let n = cpf.value.replace(/\D/g, '').slice(0,11);
        cpf.value = n.replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})(\d{1,2})$/,'$1-$2');
    });
    tel.addEventListener('input', () => {
        const n = tel.value.replace(/\D/g,'').slice(0,11);
        if (n.length <= 2) tel.value = n ? '(' + n : '';
        else if (n.length <= 6) tel.value = '('+n.slice(0,2)+') '+n.slice(2);
        else { const k=n.length===11?7:6; tel.value='('+n.slice(0,2)+') '+n.slice(2,k)+'-'+n.slice(k); }
    });
    let ultimaConsulta=0;
    cep.addEventListener('input', () => {
        const n=cep.value.replace(/\D/g,'').slice(0,8);
        cep.value=n.length>5?n.slice(0,5)+'-'+n.slice(5):n;
        if (n.length===8) consultarCEP(n);
    });
    async function consultarCEP(n) {
        const atual=++ultimaConsulta;
        msg.textContent='Consultando CEP...';
        try {
            const r=await fetch('https://viacep.com.br/ws/'+n+'/json/');
            if (!r.ok) throw new Error('Falha na consulta');
            const d=await r.json();
            if (atual!==ultimaConsulta) return;
            if (d.erro) throw new Error('CEP não encontrado');
            const v={endereco:d.logradouro||'', bairro:d.bairro||'', cidade:d.localidade||'', estado:d.uf||''};
            for (const [k,val] of Object.entries(v)) document.getElementById(k).value=val;
            msg.textContent='CEP encontrado. Informe o número.';
            msg.className='mensagem-cep sucesso';
        } catch (e) {
            if (atual!==ultimaConsulta) return;
            msg.textContent='CEP não encontrado ou indisponível. Você pode digitar o endereço.';
            msg.className='mensagem-cep erro';
        }
    }
    document.getElementById('cancelar-edicao').addEventListener('click', () => {
        location.href='clientes.php#formulario-cliente';
    });
    const busca=document.getElementById('buscar');
    const linhas=[...document.querySelectorAll('#tabela-clientes tbody tr')].filter(tr=>tr.children.length===6);
    const resultado=document.getElementById('resultado-busca');
    function buscar() {
        const termo=busca.value.trim().toLocaleLowerCase('pt-BR');
        let n=0;
        for (const tr of linhas) {
            const encontrado=tr.textContent.toLocaleLowerCase('pt-BR').includes(termo);
            tr.hidden=!encontrado;
            if(encontrado)n++;
        }
        resultado.textContent='Exibindo '+n+' de '+linhas.length+' cliente(s).';
    }
    busca.addEventListener('input',buscar);
    document.getElementById('btn-buscar').addEventListener('click',buscar);
    document.getElementById('btn-limpar-busca').addEventListener('click',()=>{busca.value='';buscar();busca.focus();});
    buscar();
</script>

</body>
</html>