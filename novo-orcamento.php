<?php
declare(strict_types=1);
require_once __DIR__ . '/automax.php';
axRequireLogin();
$erro=''; $clientes=[]; $veiculos=[]; $produtos=[]; $existente=null; $itensExistentes=[];
$editarId=filter_var($_GET['editar'] ?? $_POST['editar_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$linhas=max(1,min(10,(int)($_GET['linhas'] ?? 3)));
$dados=['cliente'=>'','veiculo'=>'','data'=>date('Y-m-d'),'validade'=>date('Y-m-d',strtotime('+30 days')),'observacao'=>''];
$conn=null;
try {
    $conn=axConn();
    $clientes=axQuery($conn,'SELECT ID_CLIENTE,NOME,CPF FROM CLIENTE ORDER BY NOME');
    $veiculos=axQuery($conn,'SELECT V.ID_VEICULO,V.ID_CLIENTE,V.PLACA,V.MARCA,V.MODELO,C.NOME AS CLIENTE FROM VEICULO V JOIN CLIENTE C ON C.ID_CLIENTE=V.ID_CLIENTE ORDER BY C.NOME,V.MODELO');
    $produtos=axQuery($conn,'SELECT ID_PRODUTO,CODIGO,NOME,PRECO FROM PRODUTO ORDER BY NOME');
    if ($editarId) {
        $row=axQuery($conn,'SELECT ID_ORCAMENTO,ID_CLIENTE,ID_VEICULO,ID_USUARIO,DATA_ORCAMENTO,VALIDADE,OBSERVACAO,STATUS,TO_CHAR(DATA_ORCAMENTO,\'YYYY-MM-DD\') AS DATA_FORM,TO_CHAR(VALIDADE,\'YYYY-MM-DD\') AS VALIDADE_FORM FROM ORCAMENTO WHERE ID_ORCAMENTO=:id',['id'=>$editarId]);
        if (!$row) throw new RuntimeException('Orçamento para edição não encontrado.');
        $existente=$row[0];
        $dados=['cliente'=>(string)$existente['ID_CLIENTE'],'veiculo'=>(string)$existente['ID_VEICULO'],'data'=>(string)$existente['DATA_FORM'],'validade'=>(string)($existente['VALIDADE_FORM'] ?? ''),'observacao'=>(string)($existente['OBSERVACAO'] ?? '')];
        $itensExistentes=axQuery($conn,'SELECT ID_ITEM,ID_PRODUTO,QUANTIDADE,VALOR_UNITARIO FROM ITEM_ORCAMENTO WHERE ID_ORCAMENTO=:id ORDER BY ID_ITEM',['id'=>$editarId]);
        $linhas=max($linhas,count($itensExistentes));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        axRequireLogin(true);
        axCheckCsrf();
        $idCliente=filter_var($_POST['cliente'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $idVeiculo=filter_var($_POST['veiculo'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $data=(string)($_POST['data'] ?? '');
        $validade=(string)($_POST['validade'] ?? '');
        $obs=trim((string)($_POST['observacao'] ?? ''));
        if (!$idCliente || !$idVeiculo) throw new RuntimeException('Selecione cliente e veículo.');
        if (!axDate($data) || !axDate($validade) || $validade<$data) throw new RuntimeException('Informe datas válidas (validade não pode anteceder a data do orçamento).');
        if (mb_strlen($obs)>500) throw new RuntimeException('Observações ultrapassam 500 caracteres.');
        $liga=axQuery($conn,'SELECT COUNT(*) AS TOTAL FROM VEICULO WHERE ID_VEICULO=:veiculo AND ID_CLIENTE=:cliente',['veiculo'=>$idVeiculo,'cliente'=>$idCliente]);
        if ((int)$liga[0]['TOTAL']!==1) throw new RuntimeException('O veículo não pertence ao cliente selecionado.');
        $pids=(array)($_POST['produto'] ?? []);
        $quantidades=(array)($_POST['quantidade'] ?? []);
        $idsItens=(array)($_POST['item_id'] ?? []);
        if (count($pids)>10 || count($quantidades)>10 || count($idsItens)>10) throw new RuntimeException('Limite de 10 linhas por envio.');
        $prodMap=[]; foreach ($produtos as $p) $prodMap[(int)$p['ID_PRODUTO']]=$p;
        $anteriores=[]; foreach ($itensExistentes as $i) $anteriores[(int)$i['ID_ITEM']]=$i;
        $itens=[];
        for ($i=0;$i<count($pids);$i++) {
            $pid=filter_var($pids[$i],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $q=filter_var($quantidades[$i] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $itemId=filter_var($idsItens[$i] ?? 0,FILTER_VALIDATE_INT);
            if (($pids[$i] ?? '')==='' && ($quantidades[$i] ?? '')==='') continue;
            if (!$pid || !$q || $q>9999999999 || !isset($prodMap[$pid])) throw new RuntimeException('Confira o produto e a quantidade na linha '.($i+1).'.');
            // O preco original e preservado na edicao do mesmo item/produto.
            $preco=isset($anteriores[$itemId]) && (int)$anteriores[$itemId]['ID_PRODUTO']===$pid ? (string)$anteriores[$itemId]['VALOR_UNITARIO'] : (string)$prodMap[$pid]['PRECO'];
            if ((float)$preco<0 || (float)$preco>99999999) throw new RuntimeException('Preço de produto inválido.');
            $itens[]=['produto'=>$pid,'quantidade'=>$q,'preco'=>$preco];
        }
        if (!$itens) throw new RuntimeException('Inclua pelo menos um produto e quantidade.');
        $usuario=axUser();
        $usuarioAtual=axQuery($conn,"SELECT ID_USUARIO FROM USUARIO WHERE ID_USUARIO=:id AND ATIVO='S'",['id'=>(int)$usuario['id']]);
        if (!$usuarioAtual) throw new RuntimeException('Usuário inativo. Entre novamente.');
        // Nenhuma operacao de escrita ocorre antes de todas as validacoes acima.
        if (!$editarId) {
            $sequencia=axQuery($conn,'SELECT SEQ_AUTOMAX_ORCAMENTO.NEXTVAL AS ID FROM DUAL');
            $editarId=(int)$sequencia[0]['ID'];
            axRun($conn,"INSERT INTO ORCAMENTO (ID_ORCAMENTO,ID_CLIENTE,ID_VEICULO,ID_USUARIO,DATA_ORCAMENTO,VALIDADE,STATUS,VALOR_TOTAL,OBSERVACAO)
                VALUES (:id,:cliente,:veiculo,:usuario,TO_DATE(:data,'YYYY-MM-DD'),TO_DATE(:validade,'YYYY-MM-DD'),'PENDENTE',0,:obs)",
                ['id'=>$editarId,'cliente'=>$idCliente,'veiculo'=>$idVeiculo,'usuario'=>(int)$usuario['id'],'data'=>$data,'validade'=>$validade,'obs'=>$obs]);
        } else {
            axRun($conn,"UPDATE ORCAMENTO SET ID_CLIENTE=:cliente,ID_VEICULO=:veiculo,DATA_ORCAMENTO=TO_DATE(:data,'YYYY-MM-DD'),VALIDADE=TO_DATE(:validade,'YYYY-MM-DD'),OBSERVACAO=:obs WHERE ID_ORCAMENTO=:id",
                ['cliente'=>$idCliente,'veiculo'=>$idVeiculo,'data'=>$data,'validade'=>$validade,'obs'=>$obs,'id'=>$editarId]);
            axRun($conn,'DELETE FROM ITEM_ORCAMENTO WHERE ID_ORCAMENTO=:id',['id'=>$editarId]);
        }
        foreach ($itens as $i) {
            axRun($conn,"INSERT INTO ITEM_ORCAMENTO (ID_ITEM,ID_ORCAMENTO,ID_PRODUTO,QUANTIDADE,VALOR_UNITARIO,SUBTOTAL)
                VALUES (SEQ_AUTOMAX_ITEM_ORCAMENTO.NEXTVAL,:orc,:produto,:q,:preco,0)",
                ['orc'=>$editarId,'produto'=>$i['produto'],'q'=>$i['quantidade'],'preco'=>$i['preco']]);
        }
        if (!oci_commit($conn)) throw new RuntimeException('Não foi possível confirmar o orçamento.');
        header('Location: orcamentos.php?ok=1',true,303);
        exit;
    }
} catch (Throwable $e) {
    if ($conn) oci_rollback($conn);
    error_log('AutoMax salvar orcamento: '.$e->getMessage());
    $erro=$e instanceof RuntimeException?$e->getMessage():'Erro ao consultar ou salvar orçamento.';
} finally { if ($conn) oci_close($conn); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    foreach (['cliente','veiculo','data','validade','observacao'] as $k) if (isset($_POST[$k])) $dados[$k]=(string)$_POST[$k];
    $linhas=max($linhas, min(10,count((array)($_POST['produto'] ?? []))));
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Novo orçamento | AutoMax-Peças</title>
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
            width: 34px;
            height: 34px;
            stroke-width: 1.8;
        }


        .botao {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .botao i {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }


        .linha-produto {
            display: grid;
            grid-template-columns: minmax(190px, 2fr) minmax(90px, .7fr) minmax(120px, 1fr) minmax(120px, 1fr) 44px;
            gap: 12px;
            align-items: end;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid #e1e7ef;
            border-radius: 10px;
            background: #fff;
        }

        .linha-produto .campo {
            min-width: 0;
        }

        .linha-produto .campo input,
        .linha-produto .campo select {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .remover-item {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 43px;
            border: 1px solid #f2caca;
            background: #fff3f3;
            color: #bd3030;
            border-radius: 8px;
            cursor: pointer;
            transition: background .2s, transform .2s;
        }

        .remover-item:hover {
            background: #ffe1e1;
            transform: scale(1.03);
        }

        .remover-item i {
            width: 18px;
            height: 18px;
        }

        .linha-produto .campo label {
            line-height: 1.3;
        }


        .totais {
            margin-left: auto;
            width: min(100%, 360px);
            padding: 20px;
            border: 1px solid #e1e7ef;
            border-radius: 12px;
            background: #f8fafc;
            box-sizing: border-box;
        }

        .total-linha {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 12px;
            color: #526176;
            font-size: 14px;
        }

        .total-final {
            padding-top: 14px;
            margin-top: 14px;
            border-top: 1px solid #dce4ee;
            color: #25364b;
            font-size: 20px;
            font-weight: 700;
        }


        .aviso {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 8px;
            background: #fff7e8;
            color: #80520c;
            font-size: 13px;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .aviso i {
            width: 19px;
            height: 19px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .mensagem {
            display: none;
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 8px;
            background: #e0f7e9;
            color: #18864b;
            font-size: 13px;
        }

        .mensagem.erro {
            background: #fde7e7;
            color: #bd3030;
        }

        .acoes-final {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }

        @media (max-width: 1050px) {
            .linha-produto {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .remover-item {
                width: 100%;
            }
        }

        @media (max-width: 760px) {
            .linha-produto {
                grid-template-columns: 1fr;
            }

            .totais {
                width: 100%;
            }

            .acoes-final {
                flex-direction: column-reverse;
            }

            .acoes-final .botao {
                width: 100%;
            }
        }

.linha-produto {grid-template-columns:minmax(210px,2fr) 100px 155px;}
.msg-erro {padding:14px;border-radius:8px;background:#fde7e7;color:#a52b2b;margin:18px 0;}
.filtros-linhas {display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:10px 0 20px;}
</style></head><body><aside class="sidebar"><div class="logo"><div class="logo-icon">A</div><div><h2>Auto<span>Peças</span></h2><small>Sistema de Gestão</small></div></div>
<p class="menu-titulo">MENU PRINCIPAL</p><nav class="menu">
<a href="index.php">Dashboard</a><a href="clientes.php">Clientes</a><a href="veiculos.php">Veículos</a><a href="produtos.php">Produtos</a><a href="estoque.php">Estoque</a><a href="orcamentos.php" class="ativo">Orçamentos</a><a href="relatorios.php">Relatórios</a>
</nav><div class="sidebar-rodape"><span class="status-bolinha"></span><span>Sistema operacional</span></div></aside>
<main class="conteudo"><section class="painel-controle"><div class="painel-texto"><span class="painel-etiqueta">GESTÃO COMERCIAL</span>
<h2><?= $existente ? 'Editar orçamento #'.(int)$existente['ID_ORCAMENTO'] : 'Novo orçamento' ?></h2>
<p>Os valores são calculados no Oracle a partir dos produtos selecionados.</p></div></section>
<?php if ($erro): ?><div class="msg-erro" role="alert"><?= axH($erro) ?></div><?php endif; ?>
<?php if (!axUser()): ?><div class="msg-erro">É necessário <a href="login.php">entrar no sistema</a> para salvar orçamentos.</div><?php endif; ?>
<form class="filtros-linhas" method="get" action="novo-orcamento.php">
<?php if ($editarId): ?><input type="hidden" name="editar" value="<?= (int)$editarId ?>"><?php endif; ?>
<label for="linhas">Quantidade de linhas de produto</label><input type="number" id="linhas" name="linhas" min="1" max="10" value="<?= $linhas ?>">
<button type="submit" class="botao botao-secundario">Aplicar quantidade</button>
</form>
<form method="post" action="novo-orcamento.php" id="formOrcamento">
<input type="hidden" name="csrf" value="<?= axH(axCsrf()) ?>">
<input type="hidden" name="editar_id" value="<?= (int)($editarId ?: 0) ?>">
<section class="secao"><div class="secao-cabecalho"><div><h2>Informações do orçamento</h2><p>Selecione um veículo pertencente ao cliente indicado.</p></div></div>
<div class="form-grid">
<div class="campo"><label for="cliente">Cliente *</label><select name="cliente" id="cliente" required><option value="">Selecione o cliente</option>
<?php foreach ($clientes as $c): ?><option value="<?= (int)$c['ID_CLIENTE'] ?>" <?= (string)$dados['cliente']===(string)$c['ID_CLIENTE']?'selected':'' ?>><?= axH($c['NOME']) ?> (CPF final <?= axH(substr((string)$c['CPF'],-2)) ?>)</option><?php endforeach; ?></select></div>
<div class="campo"><label for="veiculo">Veículo *</label><select name="veiculo" id="veiculo" required><option value="">Selecione o veículo</option>
<?php foreach ($veiculos as $v): ?><option value="<?= (int)$v['ID_VEICULO'] ?>" <?= (string)$dados['veiculo']===(string)$v['ID_VEICULO']?'selected':'' ?>><?= axH($v['CLIENTE'].' — '.$v['MARCA'].' '.$v['MODELO'].' — '.$v['PLACA']) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="data">Data *</label><input type="date" name="data" id="data" value="<?= axH($dados['data']) ?>" required></div>
<div class="campo"><label for="validade">Validade *</label><input type="date" name="validade" id="validade" value="<?= axH($dados['validade']) ?>" required></div>
<div class="campo campo-completo"><label for="observacao">Observações</label><textarea name="observacao" id="observacao" maxlength="500" rows="3"><?= axH($dados['observacao']) ?></textarea></div>
</div></section>
<section class="secao"><div class="secao-cabecalho"><div><h2>Produtos do orçamento</h2>
<p>Preços carregados do catálogo. Ao editar, o valor histórico do item é preservado quando o produto não muda.</p></div></div>
<?php for ($n=0;$n<$linhas;$n++): ?>
<?php $item=$itensExistentes[$n] ?? null;
    $selId=(string)($_POST['produto'][$n] ?? $item['ID_PRODUTO'] ?? '');
    $qtd=(string)($_POST['quantidade'][$n] ?? $item['QUANTIDADE'] ?? '');
    $itemId=(int)($_POST['item_id'][$n] ?? $item['ID_ITEM'] ?? 0);
?>
<div class="linha-produto"><div class="campo"><label for="produto<?= $n ?>">Produto <?= $n+1 ?></label>
<select name="produto[]" id="produto<?= $n ?>"><option value="">Sem item nesta linha</option>
<?php foreach ($produtos as $p): ?><option value="<?= (int)$p['ID_PRODUTO'] ?>" <?= $selId===(string)$p['ID_PRODUTO']?'selected':'' ?>><?= axH($p['CODIGO'].' — '.$p['NOME'].' ('.axMoney($p['PRECO']).')') ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="qtd<?= $n ?>">Quantidade</label><input type="number" id="qtd<?= $n ?>" name="quantidade[]" min="1" max="9999999999" step="1" value="<?= axH($qtd) ?>"></div>
<div class="campo"><label>Valor unitário</label><p class="texto-secundario">Definido pelo Oracle</p><input type="hidden" name="item_id[]" value="<?= $itemId ?>"></div></div>
<?php endfor; ?>
<p class="aviso">O valor total será calculado pelas triggers do Oracle após salvar. O orçamento não movimenta o estoque automaticamente.</p>
<div class="acoes-final"><a href="orcamentos.php" class="botao botao-secundario">Cancelar</a>
<button type="submit" class="botao botao-principal" <?= axUser()?'':'disabled' ?>>Salvar orçamento</button></div>
</section></form>
<footer class="rodape">AutoMax-Peças · Cadastro de orçamento</footer></main></body></html>