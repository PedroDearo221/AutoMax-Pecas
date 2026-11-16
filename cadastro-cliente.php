<?php
declare(strict_types=1);
require_once __DIR__ . '/automax.php';
axRequireAdmin();
$erro=''; $ok='';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $conn=null;
    try {
        axCheckCsrf();
        $nome=trim((string)($_POST['nome'] ?? ''));
        $email=mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $tipo=(string)($_POST['tipo'] ?? 'VENDEDOR');
        $senha=(string)($_POST['senha'] ?? '');
        $confirmar=(string)($_POST['confirmar'] ?? '');
        if ($nome==='' || mb_strlen($nome)>100) throw new RuntimeException('Informe um nome de até 100 caracteres.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>100) throw new RuntimeException('E-mail inválido.');
        if (!in_array($tipo,['ADMIN','GERENTE','VENDEDOR'],true)) throw new RuntimeException('Tipo de usuário inválido.');
        if (strlen($senha)<12 || strlen($senha)>72 || $senha!==$confirmar) throw new RuntimeException('Use de 12 a 72 caracteres e confirme a mesma senha.');
        $conn=axConn();
        $ja=axQuery($conn,'SELECT COUNT(*) AS TOTAL FROM USUARIO WHERE LOWER(EMAIL)=:email',['email'=>$email]);
        if ((int)$ja[0]['TOTAL']!==0) throw new RuntimeException('E-mail já cadastrado.');
        axRun($conn, "INSERT INTO USUARIO (ID_USUARIO,NOME,EMAIL,SENHA,TIPO,ATIVO) VALUES (SEQ_AUTOMAX_USUARIO.NEXTVAL,:nome,:email,:senha,:tipo,'S')", [
            'nome'=>$nome,'email'=>$email,'senha'=>password_hash($senha,PASSWORD_DEFAULT),'tipo'=>$tipo
        ]);
        if (!oci_commit($conn)) throw new RuntimeException('Não foi possível confirmar o cadastro.');
        $ok='Usuário criado com sucesso.';
    } catch (Throwable $e) {
        if ($conn) oci_rollback($conn);
        error_log('AutoMax usuarios: '.$e->getMessage());
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao cadastrar usuário.';
    } finally { if ($conn) oci_close($conn); }
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Novo usuário | AutoMax-Peças</title><style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --laranja: #f28c28;
            --laranja-hover: #dc7615;
            --azul: #202936;
            --fundo: #f4f6f9;
            --cinza: #7a8698;
        }

        body {
            min-height: 100vh;
            padding: 35px 18px;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at top right, #fbe6d0, transparent 35%),
                var(--fundo);
            color: var(--azul);
        }

        .logo {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        .logo-icone {
            width: 45px;
            height: 45px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--laranja);
            color: white;
            font-size: 25px;
            font-weight: bold;
        }

        .logo h1 {
            font-size: 24px;
        }

        .logo h1 span {
            color: var(--laranja);
        }

        .logo small {
            display: block;
            margin-top: 3px;
            color: var(--cinza);
            font-size: 10px;
            letter-spacing: 1.5px;
        }

        .container {
            width: 100%;
            max-width: 780px;
            margin: 0 auto;
            padding: 32px;
            border: 1px solid #e7ebf0;
            border-radius: 16px;
            background: white;
            box-shadow: 0 12px 40px #2029360b;
        }

        .cabecalho {
            margin-bottom: 28px;
        }

        .cabecalho h2 {
            margin-bottom: 10px;
            font-size: 28px;
        }

        .cabecalho p {
            color: var(--cinza);
            font-size: 14px;
            line-height: 1.6;
        }

        .secao-titulo {
            margin: 27px 0 17px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9edf2;
            font-size: 15px;
        }

        .grade {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .campo {
            min-width: 0;
        }

        .campo.completo {
            grid-column: 1 / -1;
        }

        .campo label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: bold;
        }

        .campo input,
        .campo select {
            width: 100%;
            height: 46px;
            padding: 0 12px;
            border: 1px solid #dce2ea;
            border-radius: 8px;
            outline: none;
            background: white;
            color: var(--azul);
            font-size: 14px;
        }

        .campo input:focus,
        .campo select:focus {
            border-color: var(--laranja);
            box-shadow: 0 0 0 3px #f28c281c;
        }

        .campo small {
            display: block;
            margin-top: 6px;
            color: var(--cinza);
            font-size: 11px;
        }

        .senha-container {
            position: relative;
        }

        .senha-container input {
            padding-right: 75px;
        }

        .mostrar {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #69778a;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
        }

        .termos {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 25px;
            color: #59677a;
            font-size: 12px;
            line-height: 1.6;
        }

        .termos input {
            margin-top: 3px;
            accent-color: var(--laranja);
        }

        .botao {
            width: 100%;
            height: 49px;
            margin-top: 23px;
            border: none;
            border-radius: 9px;
            background: var(--laranja);
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .botao:hover {
            background: var(--laranja-hover);
        }

        .mensagem {
            display: none;
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 8px;
            background: #fff0df;
            color: #8c4d0c;
            font-size: 13px;
            line-height: 1.6;
        }

        .mensagem.sucesso {
            background: #e3f7e9;
            color: #207b43;
        }

        .login-link {
            margin-top: 23px;
            text-align: center;
            color: var(--cinza);
            font-size: 13px;
        }

        a {
            color: var(--laranja-hover);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .voltar {
            display: block;
            margin-top: 22px;
            text-align: center;
            color: var(--cinza);
            font-size: 12px;
        }

        @media (max-width: 600px) {
            .container {
                padding: 22px 17px;
            }

            .grade {
                grid-template-columns: 1fr;
            }

            .campo.completo {
                grid-column: auto;
            }

            .cabecalho h2 {
                font-size: 24px;
            }
        }

.mensagem {display:block; padding:12px; margin-bottom:15px; background:#fff0f0; border-radius:8px; color:#a52b2b;}
.mensagem.sucesso {background:#ecfdf3; color:#187142;}
</style></head><body>
<header class="logo"><span class="logo-icone">A</span><div><h1>Auto<span>Peças</span></h1><small>GESTÃO DE ACESSOS</small></div></header>
<main class="container">
<div class="cabecalho"><h2>Cadastrar usuário</h2><p>Esta página cria contas de funcionários na tabela USUARIO. Acesso exclusivo para administradores.</p></div>
<?php if ($erro): ?><div class="mensagem" role="alert"><?= axH($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="mensagem sucesso" role="status"><?= axH($ok) ?></div><?php endif; ?>
<form method="post" action="cadastro-cliente.php">
<input type="hidden" name="csrf" value="<?= axH(axCsrf()) ?>">
<div class="grade">
<div class="campo completo"><label for="nome">Nome completo *</label><input type="text" id="nome" name="nome" maxlength="100" required value="<?= axH($_POST['nome'] ?? '') ?>"></div>
<div class="campo completo"><label for="email">E-mail *</label><input type="email" id="email" name="email" maxlength="100" required value="<?= axH($_POST['email'] ?? '') ?>"></div>
<div class="campo completo"><label for="tipo">Perfil *</label><select id="tipo" name="tipo" required>
<?php foreach (['VENDEDOR'=>'Vendedor','GERENTE'=>'Gerente','ADMIN'=>'Administrador'] as $valor=>$rotulo): ?>
<option value="<?= axH($valor) ?>" <?= ($_POST['tipo'] ?? 'VENDEDOR')===$valor?'selected':'' ?>><?= axH($rotulo) ?></option>
<?php endforeach; ?></select></div>
<div class="campo completo"><label for="senha">Senha *</label><input type="password" id="senha" name="senha" minlength="12" maxlength="72" autocomplete="new-password" required><small>No mínimo 12 caracteres.</small></div>
<div class="campo completo"><label for="confirmar">Confirmar senha *</label><input type="password" id="confirmar" name="confirmar" minlength="12" maxlength="72" autocomplete="new-password" required></div>
</div><button class="botao" type="submit">Criar usuário</button></form>
<p class="login-link"><a href="index.php">Voltar ao Dashboard</a></p>
</main></body></html>
