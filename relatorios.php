<?php
// Dashboard de relatórios: somente leitura. Nenhuma alteração no banco.
require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';

function h($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function moeda($valor): string {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}
function consultar($conn, string $sql): array {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) { throw new RuntimeException('Falha ao preparar consulta.'); }
    if (!oci_execute($stmt)) {
        $erro = oci_error($stmt);
        error_log('AutoMax relatório OCI: ' . ($erro['message'] ?? 'Erro desconhecido'));
        oci_free_statement($stmt);
        throw new RuntimeException('Não foi possível consultar os dados do Oracle.');
    }
    $dados = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) { $dados[] = $row; }
    oci_free_statement($stmt);
    return $dados;
}

$erroPagina = null;
$totais = ['CLIENTES'=>0,'PRODUTOS'=>0,'VEICULOS'=>0,'CATEGORIAS'=>0];
$produtos = []; $porCategoria = []; $porMarca = []; $veiculos = [];
$conn = null;
try {
    $conn = conexaoOracle();
    $contagens = consultar($conn, "SELECT
        (SELECT COUNT(*) FROM CLIENTE) AS CLIENTES,
        (SELECT COUNT(*) FROM PRODUTO) AS PRODUTOS,
        (SELECT COUNT(*) FROM VEICULO) AS VEICULOS,
        (SELECT COUNT(*) FROM CATEGORIA) AS CATEGORIAS
        FROM DUAL");
    if ($contagens) { $totais = $contagens[0]; }
    $porCategoria = consultar($conn, "SELECT C.NOME AS CATEGORIA, COUNT(P.ID_PRODUTO) AS TOTAL
        FROM CATEGORIA C LEFT JOIN PRODUTO P ON P.ID_CATEGORIA=C.ID_CATEGORIA
        GROUP BY C.ID_CATEGORIA,C.NOME ORDER BY C.NOME");
    $porMarca = consultar($conn, "SELECT M.NOME AS MARCA, COUNT(P.ID_PRODUTO) AS TOTAL
        FROM MARCA M LEFT JOIN PRODUTO P ON P.ID_MARCA=M.ID_MARCA
        GROUP BY M.ID_MARCA,M.NOME ORDER BY M.NOME");
    $produtos = consultar($conn, "SELECT P.CODIGO,P.NOME,P.PRECO,C.NOME AS CATEGORIA,M.NOME AS MARCA
        FROM PRODUTO P JOIN CATEGORIA C ON P.ID_CATEGORIA=C.ID_CATEGORIA
        JOIN MARCA M ON P.ID_MARCA=M.ID_MARCA ORDER BY P.NOME");
    $veiculos = consultar($conn, "SELECT V.PLACA,V.MARCA,V.MODELO,V.ANO,C.NOME AS CLIENTE
        FROM VEICULO V JOIN CLIENTE C ON V.ID_CLIENTE=C.ID_CLIENTE
        ORDER BY C.NOME,V.MARCA,V.MODELO");
} catch (Throwable $e) {
    error_log('AutoMax relatórios: ' . $e->getMessage());
    $erroPagina = 'Não foi possível carregar os relatórios do Oracle FreeSQL. Verifique a conexão.';
} finally {
    if ($conn) { oci_close($conn); }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios | AutoPeças</title>

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

        .painel-icone {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .painel-icone i {
            width: 42px;
            height: 42px;
            stroke-width: 1.7;
        }


        .relatorios-topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .relatorios-topo h2 {
            margin: 0 0 6px;
            color: #202936;
        }

        .relatorios-topo p {
            margin: 0;
            color: #778397;
            font-size: 14px;
        }

        .relatorios-acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .botao-com-icone {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .botao-com-icone i {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }


        .filtros-relatorio {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            align-items: end;
        }

        .filtros-relatorio .campo {
            min-width: 0;
        }

        .filtros-relatorio input,
        .filtros-relatorio select {
            width: 100%;
            box-sizing: border-box;
        }


        .cards-relatorio {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            margin: 24px 0;
        }

        .card-relatorio {
            background: #fff;
            border: 1px solid #e6eaf0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(31, 45, 61, .04);
            min-width: 0;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .card-relatorio:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(31, 45, 61, .08);
        }

        .card-relatorio .card-titulo {
            color: #778397;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .card-relatorio .card-valor {
            color: #202936;
            font-size: 27px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .card-relatorio .card-descricao {
            color: #8a95a5;
            font-size: 12px;
            margin-top: 9px;
        }

        .card-icone {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .card-icone i {
            width: 22px;
            height: 22px;
            stroke-width: 1.8;
        }

        .icone-laranja {
            background: #fff0df;
            color: #e7831c;
        }

        .icone-azul {
            background: #e4f1ff;
            color: #3478c5;
        }

        .icone-verde {
            background: #e3f7e9;
            color: #279653;
        }

        .icone-roxo {
            background: #eee8ff;
            color: #7953c7;
        }

    
        .relatorio-grafico {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(260px, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .grafico-area {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 20px 4px 0;
    min-height: 0;
}
        

        .barra-linha {
            display: grid;
            grid-template-columns: 105px minmax(0, 1fr) 100px;
            gap: 12px;
            align-items: center;
            font-size: 13px;
        }

        .barra-label {
            color: #526176;
            overflow-wrap: anywhere;
        }

        .barra-fundo {
            height: 12px;
            background: #edf0f4;
            border-radius: 20px;
            overflow: hidden;
        }

        .barra-preenchida {
            height: 100%;
            width: 0;
            background: #f28c28;
            border-radius: 20px;
            transition: width .3s ease;
        }

        .barra-valor {
            text-align: right;
            color: #202936;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

    
        .status-resumo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 15px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .status-resumo:last-child {
            border-bottom: 0;
        }

        .status-nome {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #526176;
            font-size: 14px;
        }

        .status-ponto {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #aab4c2;
            flex-shrink: 0;
        }

        .ponto-pendente {
            background: #e9a23b;
        }

        .ponto-aprovado {
            background: #29a35a;
        }

        .ponto-recusado {
            background: #d94c4c;
        }

        .ponto-vencido {
            background: #7b61b5;
        }

        .status-quantidade {
            font-weight: 700;
            color: #202936;
        }

    
        .tabela-container {
            width: 100%;
            overflow-x: auto;
        }

        .tabela-container table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        .tabela-container th,
        .tabela-container td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #edf0f4;
            font-size: 13px;
        }

        .tabela-container th {
            color: #778397;
            background: #f8fafc;
            font-weight: 600;
        }

        .tabela-container td {
            color: #39485b;
            vertical-align: middle;
        }

        .etiqueta-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #edf0f4;
            color: #526176;
            white-space: nowrap;
        }

        .etiqueta-pendente {
            background: #fff2d8;
            color: #9a6508;
        }

        .etiqueta-aprovado {
            background: #def5e6;
            color: #207b43;
        }

        .etiqueta-recusado {
            background: #fde5e5;
            color: #b72e2e;
        }

        .etiqueta-vencido {
            background: #eee8ff;
            color: #6e4ab2;
        }

        .sem-dados {
            padding: 32px 16px !important;
            text-align: center !important;
            color: #8994a3 !important;
        }


        .mensagem-info {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            background: #edf5ff;
            color: #315d91;
            font-size: 13px;
            line-height: 1.5;
        }


        .rodape-relatorio {
            margin-top: 22px;
            color: #8a95a5;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .titulo-com-icone {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .titulo-com-icone i {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        @media (max-width: 1100px) {
            .cards-relatorio {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filtros-relatorio {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .relatorio-grafico {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .conteudo {
                padding: 18px;
            }

            .cards-relatorio,
            .filtros-relatorio {
                grid-template-columns: 1fr;
            }

            .barra-linha {
                grid-template-columns: 85px minmax(0, 1fr);
            }

            .barra-valor {
                grid-column: 2;
            }

            .relatorios-acoes {
                width: 100%;
            }

            .relatorios-acoes button {
                flex: 1;
            }
        }

        @media print {
            .sidebar,
            .relatorios-acoes,
            .filtros-secao,
            .painel-icone,
            .botao {
                display: none !important;
            }

            .conteudo {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            body {
                background: #fff !important;
            }

            .card-relatorio,
            .secao {
                box-shadow: none !important;
                break-inside: avoid;
            }
        }
        tr[hidden] { display: none !important; }
        .relatorio-lista { margin-top: 24px; }
        .aviso-pendente { color:#6b7280; padding:12px 0; }
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
                <span>Dashboard</span>
            </a>

            <a href="clientes.php">
                <i data-lucide="users-round"></i>
                <span>Clientes</span>
            </a>

            <a href="veiculos.php">
                <i data-lucide="car-front"></i>
                <span>Veículos</span>
            </a>

            <a href="produtos.php">
                <i data-lucide="package"></i>
                <span>Produtos</span>
            </a>

            <a href="estoque.php">
                <i data-lucide="boxes"></i>
                <span>Estoque</span>
            </a>

            <a href="orcamentos.php">
                <i data-lucide="file-text"></i>
                <span>Orçamentos</span>
            </a>

            <a href="relatorios.php" class="ativo">
                <i data-lucide="chart-no-axes-column"></i>
                <span>Relatórios</span>
            </a>
        </nav>

        <div class="sidebar-rodape">
            <span class="status-bolinha"></span>

            <div>
                <p>Sistema operacional</p>
                <small>AutoPeças Gestão</small>
            </div>
        </div>
    </aside>

    <main class="conteudo">
  <section class="painel-controle">
    <div class="painel-texto">
      <span class="painel-etiqueta">ANÁLISE DO NEGÓCIO</span>
      <h2>Relatórios</h2>
      <p>Indicadores atualizados diretamente pelo Oracle FreeSQL.</p>
    </div>
  </section>

  <div class="relatorios-topo">
    <div><h2>Visão geral</h2><p>Dados reais do banco AutoMax-Peças.</p></div>
    <div class="relatorios-acoes">
      <button type="button" class="botao botao-secundario botao-com-icone" id="atualizar"><i data-lucide="refresh-cw"></i>Atualizar</button>
      <button type="button" class="botao botao-principal botao-com-icone" id="imprimir"><i data-lucide="printer"></i>Imprimir relatório</button>
    </div>
  </div>
  <?php if ($erroPagina): ?>
    <div class="mensagem-info" role="alert"><?= h($erroPagina) ?></div>
  <?php endif; ?>

  <section class="cards-relatorio">
    <article class="card-relatorio"><div class="card-icone icone-laranja"><i data-lucide="users-round"></i></div><div class="card-titulo">Clientes cadastrados</div><div class="card-valor"><?= h($erroPagina ? '—' : $totais['CLIENTES']) ?></div><div class="card-descricao">Registros em CLIENTE</div></article>
    <article class="card-relatorio"><div class="card-icone icone-azul"><i data-lucide="package"></i></div><div class="card-titulo">Produtos cadastrados</div><div class="card-valor"><?= h($erroPagina ? '—' : $totais['PRODUTOS']) ?></div><div class="card-descricao">Registros em PRODUTO</div></article>
    <article class="card-relatorio"><div class="card-icone icone-verde"><i data-lucide="car-front"></i></div><div class="card-titulo">Veículos cadastrados</div><div class="card-valor"><?= h($erroPagina ? '—' : $totais['VEICULOS']) ?></div><div class="card-descricao">Registros em VEICULO</div></article>
    <article class="card-relatorio"><div class="card-icone icone-roxo"><i data-lucide="tags"></i></div><div class="card-titulo">Categorias cadastradas</div><div class="card-valor"><?= h($erroPagina ? '—' : $totais['CATEGORIAS']) ?></div><div class="card-descricao">Registros em CATEGORIA</div></article>
  </section>

  <section class="relatorio-grafico">
    <div class="secao">
      <div class="secao-cabecalho"><div><h2 class="titulo-com-icone"><i data-lucide="chart-no-axes-column"></i>Produtos por categoria</h2><p>Quantidade de produtos cadastrados por categoria.</p></div></div>
      <div class="grafico-area">
        <?php $maiorCat = max(1, ...array_map(fn($v)=>(int)$v['TOTAL'], $porCategoria)); ?>
        <?php foreach ($porCategoria as $r): ?>
          <div class="barra-linha"><span class="barra-label"><?= h($r['CATEGORIA']) ?></span><div class="barra-fundo"><div class="barra-preenchida" style="width:<?= round(100*(int)$r['TOTAL']/$maiorCat,2) ?>%"></div></div><span class="barra-valor"><?= (int)$r['TOTAL'] ?></span></div>
        <?php endforeach; ?>
        <?php if (!$porCategoria): ?><p class="aviso-pendente">Nenhuma categoria encontrada.</p><?php endif; ?>
      </div>
    </div>
    <div class="secao">
      <div class="secao-cabecalho"><div><h2 class="titulo-com-icone"><i data-lucide="tag"></i>Produtos por marca</h2><p>Distribuição do catálogo por fabricante.</p></div></div>
      <?php foreach ($porMarca as $r): ?>
        <div class="status-resumo"><div class="status-nome"><span class="status-ponto ponto-aprovado"></span><?= h($r['MARCA']) ?></div><strong class="status-quantidade"><?= (int)$r['TOTAL'] ?></strong></div>
      <?php endforeach; ?>
      <?php if (!$porMarca): ?><p class="aviso-pendente">Nenhuma marca encontrada.</p><?php endif; ?>
    </div>
  </section>

  <section class="secao relatorio-lista">
    <div class="secao-cabecalho"><div><h2 class="titulo-com-icone"><i data-lucide="package"></i>Catálogo de produtos</h2><p>Preços, categorias e marcas cadastrados no Oracle.</p></div></div>
    <div class="filtros-relatorio" style="grid-template-columns: minmax(220px, 1fr); max-width:500px; margin-bottom:16px">
      <div class="campo"><label for="buscaProdutos">Pesquisar produtos</label><input type="search" id="buscaProdutos" placeholder="Código, nome, categoria ou marca..."></div>
    </div>
    <p id="resultadoProdutos" class="resultado-busca" aria-live="polite"></p>
    <div class="tabela-container"><table id="tabelaProdutos"><thead><tr><th>Código</th><th>Produto</th><th>Categoria</th><th>Marca</th><th>Preço</th></tr></thead><tbody>
      <?php foreach ($produtos as $p): ?>
      <tr data-pesquisa="<?= h(implode(' ', [$p['CODIGO'],$p['NOME'],$p['CATEGORIA'],$p['MARCA']])) ?>"><td><?= h($p['CODIGO']) ?></td><td><?= h($p['NOME']) ?></td><td><?= h($p['CATEGORIA']) ?></td><td><?= h($p['MARCA']) ?></td><td><?= h(moeda($p['PRECO'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$produtos): ?><tr><td colspan="5" class="sem-dados"><?= $erroPagina ? 'Consulta indisponível.' : 'Nenhum produto cadastrado.' ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="secao relatorio-lista">
    <div class="secao-cabecalho"><div><h2 class="titulo-com-icone"><i data-lucide="car-front"></i>Veículos por cliente</h2><p>Relacionamento entre os veículos e seus proprietários cadastrados.</p></div></div>
    <div class="tabela-container"><table><thead><tr><th>Cliente</th><th>Placa</th><th>Marca</th><th>Modelo</th><th>Ano</th></tr></thead><tbody>
      <?php foreach ($veiculos as $v): ?>
        <tr><td><?= h($v['CLIENTE']) ?></td><td><?= h($v['PLACA']) ?></td><td><?= h($v['MARCA']) ?></td><td><?= h($v['MODELO']) ?></td><td><?= h($v['ANO']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$veiculos): ?><tr><td colspan="5" class="sem-dados"><?= $erroPagina ? 'Consulta indisponível.' : 'Nenhum veículo cadastrado.' ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="secao relatorio-lista">
    <div class="secao-cabecalho"><div><h2 class="titulo-com-icone"><i data-lucide="clock"></i>Relatórios pendentes</h2><p>Disponíveis quando os módulos restantes forem implementados.</p></div></div>
    <div class="mensagem-info">Estoque e orçamentos ainda não possuem tabelas implementadas no FreeSQL. Por isso, não exibimos quantidades ou valores fictícios.</div>
  </section>
  <div class="rodape-relatorio"><span>Relatório de consulta — sem alterações no banco</span><span>Gerado em <?= h(date('d/m/Y H:i')) ?></span></div>
    </main>
    <script>
      if (window.lucide) lucide.createIcons();
      document.getElementById('atualizar').addEventListener('click', () => location.reload());
      document.getElementById('imprimir').addEventListener('click', () => window.print());
      const busca = document.getElementById('buscaProdutos');
      const linhas = [...document.querySelectorAll('#tabelaProdutos tbody tr[data-pesquisa]')];
      const filtrar = () => {
        const q = busca.value.trim().toLocaleLowerCase('pt-BR');
        let n = 0;
        linhas.forEach(linha => {
          const ok = linha.dataset.pesquisa.toLocaleLowerCase('pt-BR').includes(q);
          linha.hidden = !ok;
          if (ok) n++;
        });
        document.getElementById('resultadoProdutos').textContent = `${n} produto(s) encontrado(s)`;
      };
      busca.addEventListener('input', filtrar);
      filtrar();
    </script>
</body>
</html>
