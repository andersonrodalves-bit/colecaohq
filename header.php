<?php
// header.php
$current = basename($_SERVER['PHP_SELF']);
$theme = $_COOKIE['theme'] ?? 'light';
?>
<header class="dashboard-header">
    <a href="vitrine.php" class="header-center">
        <h1>HQ Catalog</h1>
    </a>
    <div class="header-right">
        <button class="theme-toggle" onclick="toggleTheme()" title="Alternar tema">
            <i id="themeIcon" class="fas <?= $theme == 'dark' ? 'fa-lightbulb' : 'fa-moon' ?>"></i>
        </button>
        <span><?= $_SESSION['usuario']['nome'] ?></span>
        <a href="logout.php" class="btn btn-outline btn-sm">Sair</a>
    </div>
</header>

<div class="dashboard-body">
    <nav class="sidebar">
        <a href="vitrine.php" class="sidebar-link <?= $current=='vitrine.php'?'active':'' ?>"><i class="fas fa-home"></i> Vitrine</a>
        <?php if (isAdmin()): ?>
            <a href="colecoes.php" class="sidebar-link <?= in_array($current,['colecoes.php','colecao_editar.php','colecao_detalhe.php'])?'active':'' ?>"><i class="fas fa-layer-group"></i> Cole&ccedil;&otilde;es</a>
            <a href="hqs_lista.php" class="sidebar-link <?= in_array($current,['hqs_lista.php','hqs_cadastro.php','hqs_editar.php','hq_detalhe.php'])?'active':'' ?>"><i class="fas fa-book"></i> HQs</a>
            <a href="usuarios.php" class="sidebar-link <?= $current=='usuarios.php'?'active':'' ?>"><i class="fas fa-users"></i> Usu&aacute;rios</a>
            <a href="editoras.php" class="sidebar-link <?= $current=='editoras.php'?'active':'' ?>"><i class="fas fa-building"></i> Editoras</a>
            <a href="formatos.php" class="sidebar-link <?= $current=='formatos.php'?'active':'' ?>"><i class="fas fa-th"></i> Formatos</a>
        <?php endif; ?>
    </nav>

<script>
function toggleTheme() {
    const body = document.documentElement;
    const current = body.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    body.setAttribute('data-theme', next);
    document.cookie = 'theme=' + next + ';path=/;max-age=31536000';
    document.getElementById('themeIcon').className = next === 'dark' ? 'fas fa-lightbulb' : 'fas fa-moon';
}
(function() {
    const theme = '<?= $theme ?>';
    if (theme) document.documentElement.setAttribute('data-theme', theme);
})();
</script>