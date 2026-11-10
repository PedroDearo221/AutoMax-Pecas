
<?php

function conexaoOracle() {

    $usuario = getenv('ORACLE_USER');
    $senha = getenv('ORACLE_PASSWORD');
    $servico = getenv('ORACLE_DSN');

    if (!$usuario || !$senha || !$servico) {
        error_log('AutoMax: configuracao Oracle ausente.');

        throw new RuntimeException(
            'Banco de dados não configurado.'
        );
    }

    $conn = oci_connect(
        $usuario,
        $senha,
        $servico,
        'AL32UTF8'
    );

    if (!$conn) {
        error_log('AutoMax: falha na conexão Oracle.');

        throw new RuntimeException(
            'Não foi possível conectar ao banco de dados.'
        );
    }

    return $conn;
}
