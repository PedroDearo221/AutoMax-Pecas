<?php
// AutoMax-Peças: cadastro de veículos no Oracle FreeSQL
// Requer C:\xampp\automax_private\conexao.php (função conexaoOracle).
declare(strict_types=1);
session_start();
if (empty($_SESSION['automax_csrf'])) {
    $_SESSION['automax_csrf'] = bin2hex(random_bytes(32));
}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function executar($conn, string $sql, array $params = []): void {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) { throw new RuntimeException('Não foi possível preparar a operação.'); }
    foreach ($params as $key => &$value) {
        if (!oci_bind_by_name($stmt, ':' . $key, $value, -1)) {
            oci_free_statement($stmt);
            throw new RuntimeException('Não foi possível vincular um parâmetro.');
        }
    }
    unset($value);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException('Falha ao executar operação Oracle' . (isset($e['code']) ? ' (ORA-' . $e['code'] . ')' : '') . '.');
    }
    oci_free_statement($stmt);
}
function buscarTodos($conn, string $sql): array {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) { throw new RuntimeException('Não foi possível consultar o banco de dados.'); }
    $items = [];
    while (($r = oci_fetch_assoc($stmt)) !== false) $items[] = $r;
    oci_free_statement($stmt);
    return $items;
}
$mensagem = '';
$erro = '';
$clientes = [];
$veiculos = [];
$conn = null;
try {
    require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';
    $conn = conexaoOracle();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['automax_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sessão inválida. Atualize a página e tente novamente.');
        }
        $acao = (string)($_POST['acao'] ?? '');
        $id = filter_var($_POST['id_veiculo'] ?? null, FILTER_VALIDATE_INT);
        if ($acao === 'excluir') {
            if (!$id || $id < 1) throw new RuntimeException('Veículo inválido.');
            executar($conn, 'DELETE FROM VEICULO WHERE ID_VEICULO = :id', ['id' => $id]);
            if (!oci_commit($conn)) throw new RuntimeException('Falha ao confirmar exclusão.');
            $_SESSION['automax_msg_veic'] = 'Veículo excluído com sucesso.';
        } elseif ($acao === 'salvar') {
            $placa = strtoupper(trim((string)($_POST['placa'] ?? '')));
            $marca = trim((string)($_POST['marca'] ?? ''));
            $modelo = trim((string)($_POST['modelo'] ?? ''));
            $ano = filter_var($_POST['ano'] ?? null, FILTER_VALIDATE_INT);
            $idCliente = filter_var($_POST['id_cliente'] ?? null, FILTER_VALIDATE_INT);
            if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa)) throw new RuntimeException('Informe uma placa válida (ABC1234 ou ABC1D23).');
            if ($marca === '' || mb_strlen($marca) > 50 || $modelo === '' || mb_strlen($modelo) > 80) throw new RuntimeException('Verifique marca e modelo.');
            if (!$ano || $ano < 1900 || $ano > (int)date('Y') + 1) throw new RuntimeException('Ano de fabricação inválido.');
            if (!$idCliente || $idCliente < 1) throw new RuntimeException('Selecione um cliente cadastrado.');
            $params = ['cliente' => $idCliente, 'marca' => $marca, 'modelo' => $modelo, 'ano' => $ano, 'placa' => $placa];
            if ($id && $id > 0) {
                $params['id'] = $id;
                executar($conn, 'UPDATE VEICULO SET ID_CLIENTE=:cliente, MARCA=:marca, MODELO=:modelo, ANO=:ano, PLACA=:placa WHERE ID_VEICULO=:id', $params);
                $_SESSION['automax_msg_veic'] = 'Veículo atualizado com sucesso.';
            } else {
                executar($conn, 'INSERT INTO VEICULO (ID_VEICULO, ID_CLIENTE, MARCA, MODELO, ANO, PLACA) VALUES (SEQ_AUTOMAX_VEICULO.NEXTVAL, :cliente, :marca, :modelo, :ano, :placa)', $params);
                $_SESSION['automax_msg_veic'] = 'Veículo cadastrado com sucesso.';
            }
            if (!oci_commit($conn)) throw new RuntimeException('Falha ao confirmar cadastro.');
        } else throw new RuntimeException('Operação desconhecida.');
        header('Location: veiculos.php');
        exit;
    }
    $clientes = buscarTodos($conn, 'SELECT ID_CLIENTE, NOME, CPF FROM CLIENTE ORDER BY NOME');
    $veiculos = buscarTodos($conn, 'SELECT V.ID_VEICULO, V.ID_CLIENTE, V.PLACA, V.MARCA, V.MODELO, V.ANO, C.NOME AS PROPRIETARIO FROM VEICULO V JOIN CLIENTE C ON C.ID_CLIENTE = V.ID_CLIENTE ORDER BY V.ID_VEICULO DESC');
    if (isset($_SESSION['automax_msg_veic'])) {
        $mensagem = $_SESSION['automax_msg_veic'];
        unset($_SESSION['automax_msg_veic']);
    }
} catch (Throwable $e) {
    if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') oci_rollback($conn);
    error_log('[AutoMax veiculos] ' . $e->getMessage());
    $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao acessar o Oracle FreeSQL. Consulte o log do servidor.';
}
if ($conn) oci_close($conn);
$totalVeiculos = count($veiculos);
$marcasDiferentes = count(array_unique(array_map(fn($v) => mb_strtolower($v['MARCA'], 'UTF-8'), $veiculos)));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veículos | AutoPeças</title>
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
            flex-shrink: 0;
        }

        .icone-boas-vindas {
            width: 54px;
            height: 54px;
            color: #f28c28;
            flex-shrink: 0;
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

        .icone-card.azul i { color: #3b82f6; }
        .icone-card.roxo i { color: #8b5cf6; }
        .icone-card.verde i { color: #22c55e; }
        .icone-card.laranja i { color: #f28c28; }

        .boas-vindas {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .acoes-veiculo {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .botao-excluir-veiculo {
            background: #dc3545;
            color: white;
            border: none;
        }

        .botao-excluir-veiculo:hover {
            background: #b02a37;
        }

        .resultado-busca {
            margin: 12px 0;
        }

        tr[hidden] {
            display: none;
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
            <a href="index.php"><i data-lucide="layout-dashboard"></i>Dashboard</a>
            <a href="clientes.php"><i data-lucide="users-round"></i>Clientes</a>
            <a href="veiculos.php" class="ativo"><i data-lucide="car-front"></i>Veículos</a>
            <a href="produtos.php"><i data-lucide="package"></i>Produtos</a>
            <a href="estoque.php"><i data-lucide="boxes"></i>Estoque</a>
            <a href="orcamentos.php"><i data-lucide="file-text"></i>Orçamentos</a>
            <a href="relatorios.php"><i data-lucide="chart-no-axes-column"></i>Relatórios</a>
        </nav>

        <div class="sidebar-rodape">
            <span class="status-bolinha"></span>
            <span>Sistema operacional</span>
        </div>
    </aside>

    <main class="conteudo">

        <header class="topo">
            <div>
                <h1>Veículos</h1>
                <p>Gerencie os veículos cadastrados no sistema.</p>
            </div>

            <a href="#formulario-veiculo" class="botao botao-principal">
                <i data-lucide="plus"></i>
                Novo veículo
            </a>
        </header>

        <section class="boas-vindas">
            <div>
                <h2>Cadastro de veículos</h2>
                <p>
                    Cadastre veículos, vincule-os aos clientes e mantenha
                    as informações organizadas para agilizar os atendimentos.
                </p>
            </div>
        </section>

        <?php if ($mensagem): ?><div class="secao" role="status" style="padding:15px;margin-bottom:15px;color:#166534"><?= h($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="secao" role="alert" style="padding:15px;margin-bottom:15px;color:#b91c1c"><?= h($erro) ?></div><?php endif; ?>
        <section class="indicadores">

            <article class="card-indicador">
                <div class="indicador-topo">
                    <span>Total de veículos</span>
                    <span class="icone-card azul"><i data-lucide="car-front"></i></span>
                </div>
                <h3 id="total-veiculos"><?= $totalVeiculos ?></h3>
                <p class="texto-secundario">Veículos cadastrados</p>
            </article>

            <article class="card-indicador">
                <div class="indicador-topo">
                    <span>Carros</span>
                    <span class="icone-card roxo"><i data-lucide="car"></i></span>
                </div>
                <h3 id="total-carros">—</h3>
                <p class="texto-secundario">Tipo ainda não existe no banco</p>
            </article>

            <article class="card-indicador">
                <div class="indicador-topo">
                    <span>Motocicletas</span>
                    <span class="icone-card verde"><i data-lucide="bike"></i></span>
                </div>
                <h3 id="total-motos">—</h3>
                <p class="texto-secundario">Tipo ainda não existe no banco</p>
            </article>

            <article class="card-indicador">
                <div class="indicador-topo">
                    <span>Marcas cadastradas</span>
                    <span class="icone-card laranja"><i data-lucide="settings"></i></span>
                </div>
                <h3 id="total-marcas"><?= $marcasDiferentes ?></h3>
                <p class="texto-secundario">Marcas diferentes</p>
            </article>

        </section>

        <section class="secao" id="formulario-veiculo">
            <div class="secao-cabecalho">
                <div>
                    <h2 id="titulo-formulario">Cadastrar veículo</h2>
                    <p>Preencha as informações do veículo e do proprietário.</p>
                </div>
            </div>

            <form id="form-veiculo" method="post" action="veiculos.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['automax_csrf']) ?>">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" id="veiculo-editando" name="id_veiculo" value="">

                <div class="form-grid">

                    <div class="campo">
                        <label for="placa">Placa *</label>
                        <input type="text" id="placa" name="placa" maxlength="7"
                               placeholder="ABC1D23" required>
                    </div>

                    <div class="campo">
                        <label for="marca">Marca *</label>
                        <input type="text" id="marca" name="marca" placeholder="Ex.: Chevrolet" required>
                    </div>

                    <div class="campo">
                        <label for="modelo">Modelo *</label>
                        <input type="text" id="modelo" name="modelo" placeholder="Ex.: Onix" required>
                    </div>

                    <div class="campo">
                        <label for="ano">Ano de fabricação *</label>
                        <input type="number" id="ano" name="ano" min="1900" max="2099"
                               placeholder="Ex.: 2022" required>
                    </div>

                    <div class="campo campo-completo">
                        <label for="id_cliente">Proprietário cadastrado *</label>
                        <select id="id_cliente" name="id_cliente" required>
                            <option value="">Selecione um cliente</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= (int)$cliente['ID_CLIENTE'] ?>"><?= h($cliente['NOME']) ?> — CPF final <?= h(substr((string)$cliente['CPF'], -2)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$clientes): ?><small>Cadastre um cliente na página Clientes antes de incluir um veículo.</small><?php endif; ?>
                    </div>

                </div>

                <div class="form-acoes">
                    <button type="button" id="cancelar-veiculo"
                            class="botao botao-secundario">
                        <i data-lucide="eraser"></i>
                        Limpar formulário
                    </button>

                    <button type="submit" id="salvar-veiculo"
                            class="botao botao-principal">
                        <i data-lucide="plus"></i>
                        Cadastrar veículo
                    </button>
                </div>
            </form>
        </section>

        <section class="secao">
            <div class="secao-cabecalho">
                <div>
                    <h2>Veículos cadastrados</h2>
                    <p>Consulte, pesquise, edite e exclua os veículos registrados.</p>
                </div>
            </div>

            <div class="filtros">
                <div class="campo busca">
                    <label for="buscar-veiculo">Buscar veículo</label>
                    <input type="search" id="buscar-veiculo"
                           placeholder="Placa, marca, modelo ou proprietário...">
                </div>

                <button type="button" id="botao-buscar-veiculo"
                        class="botao botao-principal">
                    <i data-lucide="search"></i>Buscar
                </button>

                <button type="button" id="limpar-busca-veiculo"
                        class="botao botao-secundario">
                    <i data-lucide="x"></i>Limpar
                </button>
            </div>

            <p id="resultado-busca-veiculo" class="resultado-busca"
               aria-live="polite"></p>

            <div class="tabela-container">
                <table id="tabela-veiculos">
                    <thead>
                        <tr>
                            <th>Placa</th>
                            <th>Marca / Modelo</th>
                            <th>Ano</th>
                            
                            <th>Proprietário</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($veiculos as $v): ?>
                        <tr data-id="<?= (int)$v['ID_VEICULO'] ?>"
                            data-placa="<?= h($v['PLACA']) ?>"
                            data-marca="<?= h($v['MARCA']) ?>"
                            data-modelo="<?= h($v['MODELO']) ?>"
                            data-ano="<?= h($v['ANO']) ?>"
                            data-id-cliente="<?= (int)$v['ID_CLIENTE'] ?>">
                            <td><?= h($v['PLACA']) ?></td>
                            <td><?= h($v['MARCA'] . ' ' . $v['MODELO']) ?></td>
                            <td><?= h($v['ANO']) ?></td>
                            <td><?= h($v['PROPRIETARIO']) ?></td>
                            <td>
                                <div class="acoes-veiculo">
                                    <button type="button" class="botao botao-principal botao-editar-veiculo"><i data-lucide="pencil"></i> Editar</button>
                                    <button type="button" class="botao botao-excluir-veiculo"><i data-lucide="trash-2"></i> Excluir</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="rodape">
            <p>Sistema AutoPeças — Gestão de veículos e clientes</p>
        </footer>

    </main>

    <script>
    const formulario = document.getElementById('form-veiculo');
    const tabela = document.querySelector('#tabela-veiculos tbody');
    const inputBusca = document.getElementById('buscar-veiculo');
    const campoId = document.getElementById('veiculo-editando');
    const botaoSalvar = document.getElementById('salvar-veiculo');
    const titulo = document.getElementById('titulo-formulario');
    const tokenCsrf = <?= json_encode($_SESSION['automax_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function limparForm() {
        formulario.reset();
        campoId.value = '';
        titulo.textContent = 'Cadastrar veículo';
        botaoSalvar.innerHTML = '<i data-lucide="plus"></i> Cadastrar veículo';
        window.lucide?.createIcons();
    }
    document.getElementById('cancelar-veiculo').addEventListener('click', limparForm);
    document.getElementById('placa').addEventListener('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,7);
    });
    tabela.addEventListener('click', event => {
        const editar = event.target.closest('.botao-editar-veiculo');
        const excluir = event.target.closest('.botao-excluir-veiculo');
        if (!editar && !excluir) return;
        const linha = event.target.closest('tr');
        if (!linha) return;
        if (editar) {
            campoId.value = linha.dataset.id;
            document.getElementById('placa').value = linha.dataset.placa;
            document.getElementById('marca').value = linha.dataset.marca;
            document.getElementById('modelo').value = linha.dataset.modelo;
            document.getElementById('ano').value = linha.dataset.ano;
            document.getElementById('id_cliente').value = linha.dataset.idCliente;
            titulo.textContent = 'Editar veículo';
            botaoSalvar.innerHTML = '<i data-lucide="save"></i> Salvar alterações';
            window.lucide?.createIcons();
            document.getElementById('formulario-veiculo').scrollIntoView({behavior:'smooth'});
        } else if (excluir) {
            if (!confirm('Excluir permanentemente o veículo ' + linha.dataset.placa + '?')) return;
            const f = document.createElement('form');
            f.method = 'post'; f.action = 'veiculos.php';
            for (const [nome, valor] of Object.entries({acao:'excluir', csrf:tokenCsrf, id_veiculo:linha.dataset.id})) {
                const campo = document.createElement('input');
                campo.type = 'hidden'; campo.name = nome; campo.value = valor;
                f.appendChild(campo);
            }
            document.body.appendChild(f); f.submit();
        }
    });
    function buscarVeiculos() {
        const termo = inputBusca.value.trim().toLocaleLowerCase('pt-BR');
        const linhas = Array.from(tabela.querySelectorAll('tr'));
        let encontrados = 0;
        linhas.forEach(l => { const ok = l.textContent.toLocaleLowerCase('pt-BR').includes(termo); l.hidden = !ok; if (ok) encontrados++; });
        document.getElementById('resultado-busca-veiculo').textContent = 'Exibindo ' + encontrados + ' de ' + linhas.length + ' veículo(s).';
    }
    document.getElementById('botao-buscar-veiculo').addEventListener('click', buscarVeiculos);
    inputBusca.addEventListener('input', buscarVeiculos);
    document.getElementById('limpar-busca-veiculo').addEventListener('click', () => {inputBusca.value='';buscarVeiculos();});
    buscarVeiculos();
    window.lucide?.createIcons();
</script>

</body>
</html>