<?php
declare(strict_types=1);
require_once __DIR__ . '/automax.php';
axRequireLogin();
$erro=''; $mensagem=''; $orcamentos=[]; $todos=[];
$statusAceitos=['PENDENTE','APROVADO','RECUSADO','CANCELADO'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $conn=null;
    try {
        axRequireLogin(true);
        axCheckCsrf();
        $id=filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new RuntimeException('Orçamento inválido.');
        $acao=(string)($_POST['acao'] ?? '');
        $conn=axConn();
        $existente=axQuery($conn,'SELECT ID_ORCAMENTO FROM ORCAMENTO WHERE ID_ORCAMENTO=:id',['id'=>$id]);
        if (!$existente) throw new RuntimeException('Orçamento não encontrado.');
        if ($acao==='alterar_status') {
            $status=(string)($_POST['status'] ?? '');
            if (!in_array($status,$statusAceitos,true)) throw new RuntimeException('Status inválido.');
            axRun($conn,'UPDATE ORCAMENTO SET STATUS=:status WHERE ID_ORCAMENTO=:id',['status'=>$status,'id'=>$id]);
        } elseif ($acao==='excluir') {
            axRun($conn,'DELETE FROM ITEM_ORCAMENTO WHERE ID_ORCAMENTO=:id',['id'=>$id]);
            axRun($conn,'DELETE FROM ORCAMENTO WHERE ID_ORCAMENTO=:id',['id'=>$id]);
        } else throw new RuntimeException('Ação desconhecida.');
        if (!oci_commit($conn)) throw new RuntimeException('Não foi possível confirmar operação.');
        header('Location: orcamentos.php?ok=1',true,303);
        exit;
    } catch (Throwable $e) {
        if ($conn) oci_rollback($conn);
        error_log('AutoMax orcamentos: '.$e->getMessage());
        $erro=$e instanceof RuntimeException ? $e->getMessage() : 'Erro ao modificar orçamento.';
    } finally { if ($conn) oci_close($conn); }
}
$conn=null;
try {
    $conn=axConn();
    $todos=axQuery($conn,"SELECT O.ID_ORCAMENTO,O.ID_CLIENTE,C.NOME AS CLIENTE,O.STATUS,
       O.DATA_ORCAMENTO, O.VALIDADE,O.VALOR_TOTAL,
       TO_CHAR(O.DATA_ORCAMENTO,'DD/MM/YYYY') AS DATA_BR,
       TO_CHAR(O.VALIDADE,'DD/MM/YYYY') AS VALIDADE_BR,
       CASE WHEN O.STATUS='PENDENTE' AND O.VALIDADE < TRUNC(SYSDATE) THEN 'VENCIDO' ELSE O.STATUS END AS SITUACAO
       FROM ORCAMENTO O JOIN CLIENTE C ON C.ID_CLIENTE=O.ID_CLIENTE
       ORDER BY O.ID_ORCAMENTO DESC");
} catch (Throwable $e) {
    error_log('AutoMax listar orcamentos: '.$e->getMessage());
    $erro='Não foi possível consultar os orçamentos.';
} finally { if ($conn) oci_close($conn); }
$busca=mb_substr(trim((string)($_GET['busca'] ?? '')),0,80);
$statusFiltro=(string)($_GET['status'] ?? '');
if (!in_array($statusFiltro,['','PENDENTE','APROVADO','RECUSADO','CANCELADO','VENCIDO'],true)) $statusFiltro='';
$orcamentos=array_values(array_filter($todos,static function ($o) use ($busca,$statusFiltro): bool {
    return (!$statusFiltro || $o['SITUACAO']===$statusFiltro) && ($busca==='' ||
        mb_stripos((string)$o['ID_ORCAMENTO'].' '.(string)$o['CLIENTE'],$busca,0,'UTF-8')!==false);
}));
$total=count($todos);
$pendentes=count(array_filter($todos,static fn($o)=>$o['STATUS']==='PENDENTE'));
$valorTotal=array_reduce($todos,static fn($s,$o)=>$s+(float)$o['VALOR_TOTAL'],0.0);
$confirmarId=filter_var($_GET['excluir'] ?? null,FILTER_VALIDATE_INT);
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Orçamentos | AutoMax-Peças</title>
<link rel="stylesheet" href="css/estilo.css"><style>

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


        .resumo-orcamentos {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .resumo-card {
            background: #fff;
            border: 1px solid #e1e7ef;
            border-radius: 12px;
            padding: 22px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .resumo-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(20, 40, 70, 0.08);
        }

        .resumo-card p {
            color: #78879b;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .resumo-card h3 {
            color: #25364b;
            font-size: 28px;
            overflow-wrap: anywhere;
        }

        .resumo-topo {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .resumo-icone {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            flex-shrink: 0;
        }

        .resumo-icone i {
            width: 22px;
            height: 22px;
        }

        .resumo-icone.azul {
            color: #3478d4;
            background: #e8f1ff;
        }

        .resumo-icone.laranja {
            color: #d98216;
            background: #fff2d8;
        }

        .resumo-icone.verde {
            color: #18864b;
            background: #e0f7e9;
        }


        .status-orcamento {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .pendente {
            background: #fff2d8;
            color: #a86a09;
        }

        .aprovado {
            background: #e0f7e9;
            color: #18864b;
        }

        .recusado {
            background: #fde7e7;
            color: #bd3030;
        }

        .vencido {
            background: #eee7ff;
            color: #7043b5;
        }

        .acoes-tabela {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .botao-pequeno {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 10px;
            border: 1px solid #dce4ee;
            border-radius: 6px;
            background: #fff;
            color: #354357;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .botao-pequeno:hover {
            background: #f3f6fa;
            border-color: #b9c7d8;
        }

        .botao-pequeno i {
            width: 15px;
            height: 15px;
        }

        .botao-excluir {
            color: #bd3030;
        }

        .botao-excluir:hover {
            background: #fff0f0;
            border-color: #efb6b6;
        }

        .filtros-orcamento {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .filtros-orcamento input,
        .filtros-orcamento select {
            min-height: 43px;
            padding: 10px 12px;
            border: 1px solid #dce4ee;
            border-radius: 8px;
            background: #fff;
            font: inherit;
            color: #354357;
        }

        .filtros-orcamento input {
            flex: 1;
            min-width: 0;
        }

        .filtros-orcamento input:focus,
        .filtros-orcamento select:focus {
            outline: 2px solid rgba(242, 140, 40, 0.25);
            border-color: #f28c28;
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

        .tabela-container td {
            vertical-align: middle;
        }

        .vazio {
            padding: 30px;
            text-align: center;
            color: #78879b;
        }

        .vazio[hidden] {
            display: none;
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

        @media (max-width: 760px) {
            .resumo-orcamentos {
                grid-template-columns: 1fr;
            }

            .filtros-orcamento {
                flex-direction: column;
            }

            .acoes-tabela {
                flex-direction: column;
            }
        }

.botao-botao {display:inline-block;padding:9px 13px;border-radius:7px; text-decoration:none;}
.confirmar {background:#fff2e5; padding:15px; margin:18px 0; border-radius:10px;}
.filtros-orcamento {align-items:center;flex-wrap:wrap;}
</style></head><body><aside class="sidebar"><div class="logo"><div class="logo-icon">A</div><div><h2>Auto<span>Peças</span></h2><small>Sistema de Gestão</small></div></div>
<p class="menu-titulo">MENU PRINCIPAL</p><nav class="menu">
<a href="index.php">Dashboard</a><a href="clientes.php">Clientes</a><a href="veiculos.php">Veículos</a><a href="produtos.php">Produtos</a><a href="estoque.php">Estoque</a><a href="orcamentos.php" class="ativo">Orçamentos</a><a href="relatorios.php">Relatórios</a>
</nav><div class="sidebar-rodape"><span class="status-bolinha"></span><span>Sistema operacional</span></div></aside>
<main class="conteudo"><section class="painel-controle"><div class="painel-texto"><span class="painel-etiqueta">GESTÃO COMERCIAL</span><h2>Orçamentos</h2><p>Registros e valores reais do Oracle FreeSQL.</p></div></section>
<?php if ($erro): ?><div class="confirmar" role="alert"><?= axH($erro) ?></div><?php endif; ?>
<?php if (isset($_GET['ok'])): ?><p role="status">Operação confirmada no Oracle FreeSQL.</p><?php endif; ?>
<section class="resumo-orcamentos">
<article class="resumo-card"><p>Total de orçamentos</p><h3><?= $total ?></h3></article>
<article class="resumo-card"><p>Pendentes</p><h3><?= $pendentes ?></h3></article>
<article class="resumo-card"><p>Valor total cadastrado</p><h3><?= axMoney($valorTotal) ?></h3></article>
</section>
<?php if ($confirmarId): ?>
<section class="confirmar"><h2>Confirmar exclusão</h2><p>A exclusão do orçamento #<?= (int)$confirmarId ?> e de seus itens será definitiva.</p>
<form method="post" action="orcamentos.php">
<input type="hidden" name="csrf" value="<?= axH(axCsrf()) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$confirmarId ?>">
<button type="submit" class="botao botao-principal">Sim, excluir</button> <a href="orcamentos.php" class="botao botao-secundario">Cancelar</a></form></section>
<?php endif; ?>
<section class="secao"><div class="secao-cabecalho"><div><h2>Lista de orçamentos</h2><p>Consulte, edite e altere situações.</p></div><a href="novo-orcamento.php" class="botao botao-principal">Novo orçamento</a></div>
<form class="filtros-orcamento" method="get" action="orcamentos.php">
<input type="search" name="busca" value="<?= axH($busca) ?>" placeholder="Número ou nome do cliente" aria-label="Buscar orçamento">
<select name="status" aria-label="Filtrar situação"><option value="">Todas as situações</option>
<?php foreach (['PENDENTE'=>'Pendente','APROVADO'=>'Aprovado','RECUSADO'=>'Recusado','CANCELADO'=>'Cancelado','VENCIDO'=>'Vencido'] as $codigo=>$rotulo): ?>
<option value="<?= $codigo ?>" <?= $statusFiltro===$codigo?'selected':'' ?>><?= $rotulo ?></option><?php endforeach; ?></select>
<button type="submit" class="botao botao-principal">Filtrar</button><a href="orcamentos.php" class="botao botao-secundario">Limpar</a></form>
<div class="tabela-container"><table><thead><tr><th>Número</th><th>Cliente</th><th>Data</th><th>Validade</th><th>Valor total</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($orcamentos as $o): ?>
<tr><td>#<?= (int)$o['ID_ORCAMENTO'] ?></td><td><?= axH($o['CLIENTE']) ?></td><td><?= axH($o['DATA_BR']) ?></td><td><?= axH($o['VALIDADE_BR'] ?? '—') ?></td>
<td><?= axMoney($o['VALOR_TOTAL']) ?></td>
<td><span class="status-orcamento <?= axH(strtolower((string)$o['STATUS'])) ?>"><?= axH($o['SITUACAO']) ?></span></td>
<td><div class="acoes-tabela"><a class="botao-pequeno" href="novo-orcamento.php?editar=<?= (int)$o['ID_ORCAMENTO'] ?>">Editar</a>
<a class="botao-pequeno botao-excluir" href="orcamentos.php?excluir=<?= (int)$o['ID_ORCAMENTO'] ?>">Excluir</a></div>
<form method="post" action="orcamentos.php" style="display:flex;gap:5px;margin-top:8px;align-items:center;">
<input type="hidden" name="csrf" value="<?= axH(axCsrf()) ?>"><input type="hidden" name="acao" value="alterar_status"><input type="hidden" name="id" value="<?= (int)$o['ID_ORCAMENTO'] ?>">
<select name="status" aria-label="Alterar status"><option value="PENDENTE" <?= $o['STATUS']==='PENDENTE'?'selected':'' ?>>Pendente</option>
<option value="APROVADO" <?= $o['STATUS']==='APROVADO'?'selected':'' ?>>Aprovado</option>
<option value="RECUSADO" <?= $o['STATUS']==='RECUSADO'?'selected':'' ?>>Recusado</option>
<option value="CANCELADO" <?= $o['STATUS']==='CANCELADO'?'selected':'' ?>>Cancelado</option></select>
<button type="submit" class="botao-pequeno">Salvar status</button></form></td></tr>
<?php endforeach; ?>
<?php if (!$orcamentos): ?><tr><td colspan="7" class="vazio">Nenhum orçamento encontrado.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="vazio">Exibindo <?= count($orcamentos) ?> de <?= $total ?> orçamento(s).</p>
</section><footer class="rodape">AutoMax-Peças · Gestão comercial</footer></main></body></html>