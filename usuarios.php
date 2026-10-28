<?php
// usuarios.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'];
    $email = $_POST['email'];
    $senha = $_POST['senha'];
    $tipo = $_POST['tipo'];
    $ativo = $_POST['ativo'] ?? 1;
    $id = $_POST['id'] ?? 0;

    if ($id) {
        if ($senha) {
            $db->prepare("UPDATE usuarios SET nome=?, email=?, senha=?, tipo=?, ativo=? WHERE id=?")
               ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $tipo, $ativo, $id]);
        } else {
            $db->prepare("UPDATE usuarios SET nome=?, email=?, tipo=?, ativo=? WHERE id=?")
               ->execute([$nome, $email, $tipo, $ativo, $id]);
        }
    } else {
        $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?,?,?,?,?)")
           ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $tipo, $ativo]);
    }
    header('Location: usuarios.php');
    exit;
}

$usuarios = $db->query("SELECT * FROM usuarios ORDER BY nome")->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header"><h2>Cadastro de Usuários</h2></div>

        <div class="form-section">
            <h3><?= $edit ? 'Editar' : 'Novo' ?> Usuário</h3>
            <form method="POST">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="form-group"><label>Nome</label><input type="text" name="nome" value="<?= htmlspecialchars($edit['nome'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($edit['email'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Senha <?= $edit ? '(deixe em branco para manter)' : '' ?></label><input type="password" name="senha" <?= !$edit ? 'required' : '' ?>></div>
                    <div class="form-group"><label>Tipo</label><select name="tipo"><option value="administrador" <?= ($edit['tipo']??'')=='administrador'?'selected':'' ?>>Administrador</option><option value="jornaleiro" <?= ($edit['tipo']??'')=='jornaleiro'?'selected':'' ?>>Jornaleiro</option></select></div>
                    <div class="form-group"><label>Ativo</label><select name="ativo"><option value="1" <?= ($edit['ativo']??1)==1?'selected':'' ?>>Sim</option><option value="0" <?= ($edit['ativo']??1)==0?'selected':'' ?>>Não</option></select></div>
                </div>
                <div class="form-actions">
                    <?php if ($edit): ?><a href="usuarios.php" class="btn btn-outline">Cancelar</a><?php endif; ?>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>

        <table class="data-table" style="margin-top:20px">
            <thead><tr><th>Nome</th><th>Email</th><th>Tipo</th><th>Ativo</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr style="<?= !$u['ativo']?'opacity:0.5':'' ?>">
                    <td><?= htmlspecialchars($u['nome']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= $u['tipo'] ?></td>
                    <td><?= $u['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td><a href="usuarios.php?edit=<?= $u['id'] ?>" class="btn-icon">??</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>