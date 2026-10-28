<?php
// editoras.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'];
    $obs = $_POST['observacao'] ?? '';
    $id = $_POST['id'] ?? 0;
    if ($id) {
        $db->prepare("UPDATE editoras SET nome=?, observacao=? WHERE id=?")->execute([$nome, $obs, $id]);
    } else {
        $db->prepare("INSERT INTO editoras (nome, observacao) VALUES (?,?)")->execute([$nome, $obs]);
    }
    header('Location: editoras.php');
    exit;
}

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM editoras WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editoras</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header"><h2>Cadastro de Editoras</h2></div>

        <div class="form-section">
            <h3><?= $edit ? 'Editar' : 'Nova' ?> Editora</h3>
            <form method="POST">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                <div class="form-group"><label>Nome</label><input type="text" name="nome" value="<?= htmlspecialchars($edit['nome'] ?? '') ?>" required></div>
                <div class="form-group"><label>Observação</label><textarea name="observacao" rows="2"><?= htmlspecialchars($edit['observacao'] ?? '') ?></textarea></div>
                <div class="form-actions">
                    <?php if ($edit): ?><a href="editoras.php" class="btn btn-outline">Cancelar</a><?php endif; ?>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>

        <table class="data-table" style="margin-top:20px">
            <thead><tr><th>Nome</th><th>Observação</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($editoras as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['nome']) ?></td>
                    <td><?= htmlspecialchars($e['observacao']) ?></td>
                    <td><a href="editoras.php?edit=<?= $e['id'] ?>" class="btn-icon">??</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>