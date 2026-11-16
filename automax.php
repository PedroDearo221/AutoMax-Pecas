<?php
declare(strict_types=1);

function axSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}
function axH(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function axCsrf(): string {
    axSession();
    if (empty($_SESSION['ax_csrf'])) $_SESSION['ax_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['ax_csrf'];
}
function axCheckCsrf(): void {
    axSession();
    if (!hash_equals(axCsrf(), (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
}
function axAuthEnabled(): bool { return true;}
function axUser(): ?array {
    axSession();
    return isset($_SESSION['ax_user']) && is_array($_SESSION['ax_user']) ? $_SESSION['ax_user'] : null;
}
function axRequireLogin(bool $force = false): void {
    axSession();
    if (($force || axAuthEnabled()) && axUser() === null) {
        header('Location: login.php?acesso=necessario', true, 303);
        exit;
    }
}
function axRequireAdmin(): void {
    axRequireLogin(true);
    if ((axUser()['tipo'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        exit('Acesso restrito aos administradores.');
    }
}
function axConn() {
    require_once dirname(__DIR__, 2) . '/automax_private/conexao.php';
    return conexaoOracle();
}
function axRun($conn, string $sql, array $params = [], int $mode = OCI_NO_AUTO_COMMIT): int {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) throw new RuntimeException('Erro ao preparar comando SQL.');
    try {
        foreach ($params as $key => &$value) {
            $name = str_starts_with((string)$key, ':') ? (string)$key : ':' . $key;
            if (!oci_bind_by_name($stmt, $name, $value)) {
                throw new RuntimeException('Erro ao vincular parâmetro.');
            }
        }
        unset($value);
        if (!oci_execute($stmt, $mode)) {
            $err = oci_error($stmt);
            error_log('AutoMax Oracle: ' . ($err['message'] ?? 'erro desconhecido'));
            throw new RuntimeException('Operação não concluída no banco de dados.');
        }
        return oci_num_rows($stmt);
    } finally {
        oci_free_statement($stmt);
    }
}
function axQuery($conn, string $sql, array $params = []): array {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) throw new RuntimeException('Erro ao preparar consulta SQL.');
    try {
        foreach ($params as $key => &$value) {
            $name = str_starts_with((string)$key, ':') ? (string)$key : ':' . $key;
            if (!oci_bind_by_name($stmt, $name, $value)) {
                throw new RuntimeException('Erro ao vincular parâmetro.');
            }
        }
        unset($value);
        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmt);
            error_log('AutoMax Oracle: ' . ($err['message'] ?? 'erro desconhecido'));
            throw new RuntimeException('Consulta indisponível.');
        }
        $rows=[];
        while (($row = oci_fetch_array($stmt, OCI_ASSOC|OCI_RETURN_NULLS)) !== false) $rows[]=$row;
        return $rows;
    } finally {
        oci_free_statement($stmt);
    }
}
function axMoney(mixed $valor): string {
    return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.');
}
function axDate(string $valor): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $date !== false && $date->format('Y-m-d') === $valor;
}
