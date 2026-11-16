<?php
declare(strict_types=1);
require_once __DIR__ . '/automax.php';
axSession();
$erro = '';
$usuario = axUser();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        axCheckCsrf();
        $acao = (string)($_POST['acao'] ?? 'login');
        if ($acao === 'sair') {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: login.php?saiu=1', true, 303);
            exit;
        }
        if ($acao !== 'login') throw new RuntimeException('Operação inválida.');
        if (($_SESSION['ax_tentar_apos'] ?? 0) > time()) {
            throw new RuntimeException('Muitas tentativas. Aguarde alguns minutos.');
        }
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $senha = (string)($_POST['senha'] ?? '');
        if (mb_strlen($email)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || $senha==='') {
            throw new RuntimeException('E-mail ou senha inválidos.');
        }
        $conn = axConn();
        try {
            $rows = axQuery($conn, 'SELECT ID_USUARIO,NOME,EMAIL,SENHA,TIPO,ATIVO FROM USUARIO WHERE LOWER(EMAIL)=:email', ['email'=>$email]);
        } finally { oci_close($conn); }
        $registro = $rows[0] ?? null;
        if (!$registro || $registro['ATIVO'] !== 'S' || !password_verify($senha,(string)$registro['SENHA'])) {
            $_SESSION['ax_falhas']=(int)($_SESSION['ax_falhas'] ?? 0)+1;
            if ($_SESSION['ax_falhas'] >= 5) {
                $_SESSION['ax_tentar_apos']=time()+180;
                $_SESSION['ax_falhas']=0;
            }
            throw new RuntimeException('E-mail ou senha inválidos.');
        }
        session_regenerate_id(true);
        $_SESSION['ax_user']=['id'=>(int)$registro['ID_USUARIO'],'nome'=>(string)$registro['NOME'],'tipo'=>(string)$registro['TIPO']];
        unset($_SESSION['ax_falhas'],$_SESSION['ax_tentar_apos']);
        header('Location: index.php', true, 303);
        exit;
    } catch (Throwable $e) {
        error_log('AutoMax login: ' . $e->getMessage());
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível acessar a conta.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | AutoMax-Peças</title><style>
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
            display: flex;
            font-family: Arial, sans-serif;
            background: var(--fundo);
            color: var(--azul);
        }

        .apresentacao {
            width: 50%;
            min-height: 100vh;
            padding: 45px 7%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: white;
            background: radial-gradient(circle at 80% 20%, #3b4654, transparent 40%),
                        linear-gradient(145deg, #202936, #101722);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icone {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--laranja);
            font-size: 26px;
            font-weight: bold;
        }

        .logo h1 {
            font-size: 25px;
        }

        .logo h1 span {
            color: var(--laranja);
        }

        .logo small {
            display: block;
            margin-top: 4px;
            color: #b9c2cf;
            font-size: 11px;
            letter-spacing: 1px;
        }

        .texto-apresentacao {
            max-width: 480px;
            margin: 45px 0;
        }

        .etiqueta {
            color: #ffbd78;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .texto-apresentacao h2 {
            margin: 22px 0;
            font-size: clamp(34px, 4vw, 52px);
            line-height: 1.15;
        }

        .texto-apresentacao h2 span {
            color: var(--laranja);
        }

        .texto-apresentacao p {
            color: #c1c9d4;
            font-size: 15px;
            line-height: 1.8;
        }

        .beneficios {
            margin-top: 32px;
            display: grid;
            gap: 18px;
            color: #e1e6ed;
            font-size: 14px;
        }

        .apresentacao footer {
            color: #929eae;
            font-size: 12px;
        }

        .area-login {
            width: 50%;
            min-height: 100vh;
            padding: 30px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .formulario {
            width: 100%;
            max-width: 410px;
        }

        .formulario h2 {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .subtitulo {
            color: var(--cinza);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .campo {
            margin-bottom: 20px;
        }

        .campo label {
            display: block;
            margin-bottom: 9px;
            font-size: 13px;
            font-weight: bold;
        }

        .campo input {
            width: 100%;
            height: 49px;
            padding: 0 14px;
            border: 1px solid #dce2ea;
            border-radius: 9px;
            outline: none;
            background: white;
            font-size: 14px;
        }

        .campo input:focus {
            border-color: var(--laranja);
            box-shadow: 0 0 0 3px #f28c2820;
        }

        .senha {
            position: relative;
        }

        .senha input {
            padding-right: 75px;
        }

        .mostrar {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: none;
            color: #69778a;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
        }

        .opcoes {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin: 4px 0 25px;
            font-size: 12px;
        }

        .lembrar {
            display: flex;
            align-items: center;
            gap: 7px;
            color: #586579;
        }

        a {
            color: var(--laranja-hover);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .botao {
            width: 100%;
            height: 50px;
            border: 0;
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
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 8px;
            background: #fff0df;
            color: #8c4d0c;
            font-size: 13px;
            line-height: 1.6;
        }

        .cadastro {
            margin-top: 28px;
            text-align: center;
            color: var(--cinza);
            font-size: 13px;
        }

        .ajuda {
            margin-top: 35px;
            text-align: center;
            color: var(--cinza);
            font-size: 12px;
        }

        @media (max-width: 800px) {
            .apresentacao {
                display: none;
            }

            .area-login {
                width: 100%;
            }
        }

.mensagem {display:block; background:#fff0f0; color:#a52b2b; border-radius:8px; padding:12px; margin-bottom:18px;}
</style></head><body>
<section class="apresentacao">
 <div class="logo"><span class="logo-icone">A</span><div><h1>Auto<span>Peças</span></h1><small>SISTEMA DE GESTÃO</small></div></div>
 <div class="texto-apresentacao"><span class="etiqueta">SISTEMA DE GESTÃO</span><h2>Gerencie suas <span>autopeças.</span></h2>
 <p>Clientes, veículos, produtos, estoque e orçamentos integrados ao Oracle FreeSQL.</p></div>
 <footer>© 2026 AutoMax-Peças · Projeto acadêmico</footer>
</section>
<main class="area-login"><div class="formulario"><h2>Entrar</h2><p class="subtitulo">Acesse o sistema com um usuário cadastrado na tabela USUARIO.</p>
<?php if ($erro): ?><div class="mensagem" role="alert"><?= axH($erro) ?></div><?php endif; ?>
<?php if (isset($_GET['acesso'])): ?><div class="mensagem">Faça login para acessar esta página.</div><?php endif; ?>
<?php if (isset($_GET['saiu'])): ?><p class="subtitulo">Sessão encerrada.</p><?php endif; ?>
<?php if ($usuario): ?>
 <p class="subtitulo">Conectado como <strong><?= axH($usuario['nome']) ?></strong> (<?= axH($usuario['tipo']) ?>).</p>
 <form method="post" action="login.php"><input type="hidden" name="csrf" value="<?= axH(axCsrf()) ?>"><input type="hidden" name="acao" value="sair"><button type="submit" class="botao">Sair do sistema</button></form>
 <p class="cadastro"><a href="index.php">Voltar ao Dashboard</a></p>
<?php else: ?>
 <form method="post" action="login.php">
 <input type="hidden" name="csrf" value="<?= axH(axCsrf()) ?>">
 <input type="hidden" name="acao" value="login">
 <div class="campo"><label for="email">E-mail</label><input type="email" name="email" id="email" maxlength="100" autocomplete="username" required value="<?= axH($_POST['email'] ?? '') ?>"></div>
 <div class="campo"><label for="senha">Senha</label><input type="password" name="senha" id="senha" autocomplete="current-password" required></div>
 <button class="botao" type="submit">Entrar no sistema</button>
 </form>
 <div class="cadastro">Ainda não existe administrador? Execute o script <code>database/criar_admin.php</code> no terminal do XAMPP.</div>
<?php endif; ?>
</div></main></body></html>
