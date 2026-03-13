<?php
// ================================================================
//  NARUA RPG — API Backend
//  Arquivo: api.php  →  coloque na mesma pasta do index.html
// ================================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CONFIGURAÇÕES ──────────────────────────────────────────────
// Caminho absoluto onde o SA-MP salva os arquivos de jogador (DOF2)
// Exemplo se o site e o SA-MP estão na mesma máquina:
define('PLAYERS_PATH', '/home/container/scriptfiles/Players/');
// Arquivo JSON gerado pelo SA-MP com jogadores online em tempo real
define('ONLINE_JSON',  '/home/container/scriptfiles/online_players.json');
// Chave secreta para gerar/verificar tokens de sessão
define('SECRET_KEY',   'NARUA_SECRET_2025_troque_isso');
// ──────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':    handleLogin();    break;
    case 'perfil':   handlePerfil();   break;
    case 'online':   handleOnline();   break;
    case 'verify':   handleVerify();   break;
    default:         json(['error' => 'Ação inválida'], 400);
}

// ── LOGIN ──────────────────────────────────────────────────────
function handleLogin() {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $usuario = trim($data['usuario'] ?? '');
    $senha   = trim($data['senha']   ?? '');

    if (!$usuario || !$senha) {
        json(['error' => 'Preencha usuário e senha'], 400); return;
    }

    // Sanitiza nome do arquivo (evita path traversal)
    $usuario = preg_replace('/[^a-zA-Z0-9_\-\[\]]/', '', $usuario);
    $file    = PLAYERS_PATH . $usuario . '.ini';

    if (!file_exists($file)) {
        json(['error' => 'Conta não encontrada no servidor'], 404); return;
    }

    $dados = parseIni($file);

    // Suporte a senha em texto puro OU MD5 (detecta automaticamente)
    $senhaArquivo = $dados['Senha'] ?? $dados['Password'] ?? $dados['Password2'] ?? '';
    $ok = false;
    if (strlen($senhaArquivo) === 32) {
        // MD5
        $ok = (md5($senha) === strtolower($senhaArquivo));
    } else {
        $ok = ($senha === $senhaArquivo);
    }

    if (!$ok) {
        json(['error' => 'Senha incorreta'], 401); return;
    }

    // Gera token simples (sem banco de dados)
    $payload = base64_encode(json_encode([
        'u'   => $usuario,
        'exp' => time() + 3600,
        'sig' => hash_hmac('sha256', $usuario . time(), SECRET_KEY)
    ]));

    $skin  = (int)($dados['Skin'] ?? $dados['skin'] ?? 0);
    $level = (int)($dados['Level'] ?? $dados['Nivel'] ?? $dados['level'] ?? 1);
    $dinheiro = (int)($dados['Money'] ?? $dados['Dinheiro'] ?? 0);
    $cargo = $dados['Cargo'] ?? $dados['Job'] ?? 'Sem cargo';

    json([
        'ok'      => true,
        'token'   => $payload,
        'usuario' => $usuario,
        'skin'    => $skin,
        'level'   => $level,
        'dinheiro'=> $dinheiro,
        'cargo'   => $cargo,
    ]);
}

// ── PERFIL (requer token) ──────────────────────────────────────
function handlePerfil() {
    $token   = $_GET['token'] ?? '';
    $usuario = verifyToken($token);
    if (!$usuario) { json(['error' => 'Token inválido ou expirado'], 401); return; }

    $file  = PLAYERS_PATH . $usuario . '.ini';
    if (!file_exists($file)) { json(['error' => 'Jogador não encontrado'], 404); return; }

    $dados = parseIni($file);

    // Verifica se está online agora
    $online   = isOnline($usuario);
    $skin     = (int)($dados['Skin'] ?? $dados['skin'] ?? 0);
    $level    = (int)($dados['Level'] ?? $dados['Nivel'] ?? 1);
    $dinheiro = (int)($dados['Money'] ?? $dados['Dinheiro'] ?? 0);
    $cargo    = $dados['Cargo'] ?? $dados['Job'] ?? 'Sem cargo';
    $admin    = (int)($dados['Admin'] ?? $dados['pAdmin'] ?? 0);
    $vip      = (int)($dados['VIP'] ?? $dados['vip'] ?? 0);
    $kills    = (int)($dados['Kills'] ?? $dados['kills'] ?? 0);
    $deaths   = (int)($dados['Deaths'] ?? $dados['deaths'] ?? 0);
    $horas    = (int)($dados['HorasJogadas'] ?? $dados['PlayTime'] ?? 0);

    // Se online, pega skin em tempo real do JSON
    if ($online && file_exists(ONLINE_JSON)) {
        $onlineData = json_decode(file_get_contents(ONLINE_JSON), true) ?? [];
        foreach ($onlineData['players'] ?? [] as $p) {
            if (strtolower($p['nome']) === strtolower($usuario)) {
                $skin = (int)$p['skin'];
                break;
            }
        }
    }

    json([
        'ok'       => true,
        'usuario'  => $usuario,
        'online'   => $online,
        'skin'     => $skin,
        'level'    => $level,
        'dinheiro' => $dinheiro,
        'cargo'    => $cargo,
        'admin'    => $admin,
        'vip'      => $vip,
        'kills'    => $kills,
        'deaths'   => $deaths,
        'horas'    => $horas,
    ]);
}

// ── ONLINE (lista pública) ─────────────────────────────────────
function handleOnline() {
    if (!file_exists(ONLINE_JSON)) {
        json(['players' => [], 'total' => 0, 'atualizado' => 0]); return;
    }
    $data = json_decode(file_get_contents(ONLINE_JSON), true) ?? [];
    // Remove senha se existir por acidente
    if (isset($data['players'])) {
        foreach ($data['players'] as &$p) { unset($p['senha'], $p['password']); }
    }
    json($data);
}

// ── VERIFY TOKEN ──────────────────────────────────────────────
function handleVerify() {
    $token   = $_GET['token'] ?? (json_decode(file_get_contents('php://input'), true)['token'] ?? '');
    $usuario = verifyToken($token);
    if (!$usuario) { json(['ok' => false]); return; }
    json(['ok' => true, 'usuario' => $usuario]);
}

// ── HELPERS ───────────────────────────────────────────────────
function parseIni($file) {
    $result = [];
    $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '[' || $line[0] === ';') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $result[trim($key)] = trim($val);
    }
    return $result;
}

function verifyToken($token) {
    if (!$token) return null;
    try {
        $payload = json_decode(base64_decode($token), true);
        if (!$payload) return null;
        if (($payload['exp'] ?? 0) < time()) return null;
        return $payload['u'] ?? null;
    } catch (Exception $e) { return null; }
}

function isOnline($usuario) {
    if (!file_exists(ONLINE_JSON)) return false;
    $data = json_decode(file_get_contents(ONLINE_JSON), true) ?? [];
    $atualizado = $data['atualizado'] ?? 0;
    if (time() - $atualizado > 120) return false; // arquivo muito antigo = offline
    foreach ($data['players'] ?? [] as $p) {
        if (strtolower($p['nome'] ?? '') === strtolower($usuario)) return true;
    }
    return false;
}

function json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
