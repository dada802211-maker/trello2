<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, mixed $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function id(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = require __DIR__ . '/config.php';
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function currentUser(bool $required = true): ?array
{
    if (empty($_SESSION['user_id'])) {
        if ($required) respond(401, ['message' => 'ログインしてください']);
        return null;
    }
    $q = db()->prepare('SELECT u.id,u.name,u.email,bu.boardId FROM `user` u LEFT JOIN board_user bu ON bu.userId=u.id WHERE u.id=? ORDER BY bu.createdAt LIMIT 1');
    $q->execute([$_SESSION['user_id']]);
    $user = $q->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        if ($required) respond(401, ['message' => 'ログイン状態が無効です']);
        return null;
    }
    return $user;
}

function ownedBoard(string $boardId, string $userId): bool
{
    $q = db()->prepare('SELECT 1 FROM board_user WHERE boardId=? AND userId=?');
    $q->execute([$boardId, $userId]);
    return (bool)$q->fetchColumn();
}

function routePath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/api(?:\.php)?#', '', $path);
    if (!empty($_SERVER['PATH_INFO'])) $path = $_SERVER['PATH_INFO'];
    return '/' . trim($path, '/');
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = routePath();
    $input = body();

    if ($method === 'GET' && $path === '/health') respond(200, ['ok' => true, 'database' => 'trello2']);

    if ($method === 'POST' && $path === '/auth/signup') {
        $name = trim((string)($input['name'] ?? ''));
        $email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($input['password'] ?? '');
        if ($name === '' || !$email || strlen($password) < 8) respond(400, ['message' => '名前、正しいメールアドレス、8文字以上のパスワードを入力してください']);
        $q = $pdo->prepare('SELECT id FROM `user` WHERE email=?'); $q->execute([$email]);
        if ($q->fetch()) respond(409, ['message' => 'このメールアドレスは既に使用されています']);
        $userId = id(); $boardId = id();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO `user` (id,name,email,password) VALUES (?,?,?,?)')->execute([$userId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO board (id,name) VALUES (?,?)')->execute([$boardId,$name]);
        $pdo->prepare('INSERT INTO board_user (id,userId,boardId) VALUES (?,?,?)')->execute([id(),$userId,$boardId]);
        $pdo->commit(); session_regenerate_id(true); $_SESSION['user_id']=$userId;
        respond(201, ['user'=>['id'=>$userId,'name'=>$name,'email'=>$email,'boardId'=>$boardId]]);
    }

    if ($method === 'POST' && $path === '/auth/signin') {
        $q=$pdo->prepare('SELECT u.id,u.name,u.email,u.password,bu.boardId FROM `user` u LEFT JOIN board_user bu ON bu.userId=u.id WHERE u.email=? ORDER BY bu.createdAt LIMIT 1');
        $q->execute([trim((string)($input['email'] ?? ''))]); $user=$q->fetch();
        if (!$user || !password_verify((string)($input['password'] ?? ''),$user['password'])) respond(401,['message'=>'メールアドレスまたはパスワードが正しくありません']);
        session_regenerate_id(true); $_SESSION['user_id']=$user['id']; unset($user['password']);
        respond(200,['user'=>$user]);
    }

    if ($method === 'POST' && $path === '/auth/signout') { $_SESSION=[]; session_regenerate_id(true); respond(200,['ok'=>true]); }
    if ($method === 'GET' && $path === '/auth/me') respond(200,currentUser(false));

    $user=currentUser();
    if ($method === 'PUT' && $path === '/account/profile') {
        $name=trim((string)($input['name'] ?? ''));
        if ($name==='') respond(400,['message'=>'名前を入力してください']);
        $pdo->prepare('UPDATE `user` SET name=?,updatedAt=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$user['id']]);
        $user['name']=$name; respond(200,$user);
    }

    if (preg_match('#^/lists/([^/]+)$#',$path,$m) && $method==='GET') {
        $boardId=$m[1]; if (!ownedBoard($boardId,$user['id'])) respond(403,['message'=>'アクセスできません']);
        $q=$pdo->prepare('SELECT id,title,position,boardId,createdAt,updatedAt FROM `list` WHERE boardId=? ORDER BY position,id'); $q->execute([$boardId]); respond(200,$q->fetchAll());
    }
    if ($path==='/lists' && $method==='POST') {
        $boardId=(string)($input['boardId']??''); $title=trim((string)($input['title']??''));
        if ($title==='' || !ownedBoard($boardId,$user['id'])) respond(400,['message'=>'ボードまたはリストタイトルが不正です']);
        $q=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM `list` WHERE boardId=?'); $q->execute([$boardId]); $pos=(int)$q->fetchColumn(); $newId=id();
        $pdo->prepare('INSERT INTO `list` (id,title,position,boardId) VALUES (?,?,?,?)')->execute([$newId,$title,$pos,$boardId]);
        respond(201,['id'=>$newId,'title'=>$title,'position'=>$pos,'boardId'=>$boardId]);
    }
    if ($path==='/lists' && $method==='PUT') {
        $items=$input['lists']??[]; if (!is_array($items)) $items=[$items]; $out=[];
        foreach($items as $item) { if(empty($item['id'])) continue; $q=$pdo->prepare('SELECT l.* FROM `list` l JOIN board_user b ON b.boardId=l.boardId WHERE l.id=? AND b.userId=?'); $q->execute([$item['id'],$user['id']]); $old=$q->fetch(); if(!$old) continue;
            $title=(string)($item['title']??$old['title']); $pos=(int)($item['position']??$old['position']); $pdo->prepare('UPDATE `list` SET title=?,position=?,updatedAt=CURRENT_TIMESTAMP WHERE id=?')->execute([$title,$pos,$old['id']]); $old['title']=$title;$old['position']=$pos;$out[]=$old; }
        respond(200,$out);
    }
    if (preg_match('#^/lists/([^/]+)$#',$path,$m) && $method==='DELETE') {
        $q=$pdo->prepare('DELETE l FROM `list` l JOIN board_user b ON b.boardId=l.boardId WHERE l.id=? AND b.userId=?'); $q->execute([$m[1],$user['id']]); if(!$q->rowCount()) respond(404,['message'=>'リストが見つかりません']); respond(200,['ok'=>true]);
    }

    if (preg_match('#^/cards/([^/]+)$#',$path,$m) && $method==='GET') {
        $q=$pdo->prepare('SELECT c.id,c.title,c.description,c.position,c.dueDate,c.completed,c.userId,c.listId,c.createdAt,c.updatedAt FROM card c JOIN `list` l ON l.id=c.listId JOIN board_user b ON b.boardId=l.boardId WHERE l.boardId=? AND b.userId=? ORDER BY c.position,c.id'); $q->execute([$m[1],$user['id']]); $cards=$q->fetchAll(); foreach($cards as &$card) { $card['completed']=(bool)$card['completed']; $card['position']=(int)$card['position']; } unset($card); respond(200,$cards);
    }
    if ($path==='/cards' && $method==='POST') {
        $listId=(string)($input['listId']??'');$title=trim((string)($input['title']??''));$q=$pdo->prepare('SELECT l.id FROM `list` l JOIN board_user b ON b.boardId=l.boardId WHERE l.id=? AND b.userId=?');$q->execute([$listId,$user['id']]); if($title===''||!$q->fetch()) respond(400,['message'=>'リストまたはカードタイトルが不正です']);
        $q=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM card WHERE listId=?');$q->execute([$listId]);$pos=(int)$q->fetchColumn();$newId=id();$pdo->prepare('INSERT INTO card (id,title,position,userId,listId) VALUES (?,?,?,?,?)')->execute([$newId,$title,$pos,$user['id'],$listId]); respond(201,['id'=>$newId,'title'=>$title,'position'=>$pos,'completed'=>false,'listId'=>$listId,'userId'=>$user['id']]);
    }
    if ($path==='/cards' && $method==='PUT') {
        $items=$input['cards']??[];if(!is_array($items))$items=[$items];$out=[];
        foreach($items as $item){if(empty($item['id']))continue;$q=$pdo->prepare('SELECT c.* FROM card c JOIN `list` l ON l.id=c.listId JOIN board_user b ON b.boardId=l.boardId WHERE c.id=? AND b.userId=?');$q->execute([$item['id'],$user['id']]);$old=$q->fetch();if(!$old)continue;$title=(string)($item['title']??$old['title']);$description=$item['description']??$old['description'];$dueDate=($item['dueDate']??null)?:null;$completed=(int)(bool)($item['completed']??$old['completed']);$position=(int)($item['position']??$old['position']);$listId=(string)($item['listId']??$old['listId']);$q=$pdo->prepare('SELECT 1 FROM `list` l JOIN board_user b ON b.boardId=l.boardId WHERE l.id=? AND b.userId=?');$q->execute([$listId,$user['id']]);if(!$q->fetch())continue;$pdo->prepare('UPDATE card SET title=?,description=?,dueDate=?,completed=?,position=?,listId=?,updatedAt=CURRENT_TIMESTAMP WHERE id=?')->execute([$title,$description,$dueDate,$completed,$position,$listId,$old['id']]);$old=array_merge($old,['title'=>$title,'description'=>$description,'dueDate'=>$dueDate,'completed'=>(bool)$completed,'position'=>$position,'listId'=>$listId]);$out[]=$old;}
        respond(200,$out);
    }
    if (preg_match('#^/cards/([^/]+)$#',$path,$m) && $method==='DELETE') {
        $q=$pdo->prepare('DELETE c FROM card c JOIN `list` l ON l.id=c.listId JOIN board_user b ON b.boardId=l.boardId WHERE c.id=? AND b.userId=?');$q->execute([$m[1],$user['id']]);if(!$q->rowCount())respond(404,['message'=>'カードが見つかりません']);respond(200,['ok'=>true]);
    }
    respond(404,['message'=>'APIが見つかりません']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log((string)$error);
    respond(500,['message'=>'サーバーエラーが発生しました']);
}
