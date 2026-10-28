<?php
// auth.php
session_start();

function requireLogin() {
    if (!isset($_SESSION['usuario'])) {
        header('Location: index.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['usuario']['tipo'] !== 'administrador') {
        header('Location: vitrine.php');
        exit;
    }
}

function isAdmin() {
    return isset($_SESSION['usuario']) && $_SESSION['usuario']['tipo'] === 'administrador';
}