<?php
// index.php
require_once 'config.php';
require_once 'auth.php';

if (isset($_SESSION['usuario'])) {
    header('Location: vitrine.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = :email AND ativo = 1");
    $stmt->execute(['email' => $email]);
    $usuario = $stmt->fetch();
    if ($usuario && password_verify($senha, $usuario['senha'])) {
        $_SESSION['usuario'] = $usuario;
        header('Location: vitrine.php');
        exit;
    } else {
        $erro = 'Email ou senha invalidos';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Catalog - Login</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>HQ Catalog</h1>
                <p>Faca login para acessar o sistema</p>
            </div>
            <?php if ($erro): ?>
                <div class="alert alert-error"><?= $erro ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="senha" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Entrar</button>
            </form>
        </div>
    </div>
</body>
</html>