
<?php
/**
 * AutoMax-Peças
 * Exemplo de configuração de conexão Oracle.
 *
 * Copie este arquivo para:
 * C:\xampp\automax_private\conexao.php
 *
 * Preencha com as credenciais da sua conta Oracle.
 */

function conexaoOracle() {
    $usuario = 'SEU_USUARIO_ORACLE';
    $senha = 'SUA_SENHA_ORACLE';
    $servico = 'tcps://db.freesql.com:2484/SEU_SERVICO';

    $conn = oci_connect(
        $usuario,
        $senha,
        $servico,
        'AL32UTF8'
    );

    if (!$conn) {
        error_log('AutoMax: falha na conexão com Oracle.');
        throw new RuntimeException(
            'Não foi possível conectar ao banco de dados.'
        );
    }

    return $conn;
}
