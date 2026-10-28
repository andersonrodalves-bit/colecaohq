<?php
// hqs_restaurar.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$id = $_GET['id'] ?? 0;
$db = getDB();
$db->prepare("UPDATE hqs SET status='ativo' WHERE id=?")->execute([$id]);
$db->prepare("DELETE FROM hqs_excluidas WHERE id=?")->execute([$id]);
header('Location: hqs_lista.php?status=excluido');