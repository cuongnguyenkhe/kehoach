<?php
/*
 * api.php – lưu file Excel KHSX trên host.
 * Đặt cùng thư mục với index.html. Thư mục data/ sẽ tự được tạo (cần quyền ghi).
 * Mỗi lần upload file mới, file cũ trong data/ tự động bị xóa.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$PASSWORD = '';                 // Đặt mật khẩu để chỉ người biết mới upload/xóa được. Để trống = ai cũng upload được.
$MAX_BYTES = 25 * 1024 * 1024;  // Giới hạn dung lượng file (còn phụ thuộc upload_max_filesize của host)
$DIR = __DIR__ . '/data';

function out($code, $arr){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

if(!is_dir($DIR)) @mkdir($DIR, 0755, true);
if(!is_dir($DIR) || !is_writable($DIR)) out(500, ['ok'=>false, 'error'=>'Thư mục data/ không ghi được, hãy cấp quyền ghi (755/775).']);
$ht = $DIR . '/.htaccess';
if(!is_file($ht)) @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

/* Đọc dữ liệu đã lưu */
if($action === 'get'){
  $f = $DIR . '/data.json';
  if(!is_file($f)) out(200, ['empty'=>true]);
  readfile($f);
  exit;
}

/* Các thao tác ghi: chỉ POST + kiểm tra mật khẩu */
if($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok'=>false, 'error'=>'Chỉ chấp nhận POST']);
if($PASSWORD !== '' && !hash_equals($PASSWORD, (string)($_POST['key'] ?? ''))) out(401, ['ok'=>false, 'error'=>'Sai mật khẩu']);

function wipe($dir, $keep = []){
  foreach(glob($dir . '/*') ?: [] as $f){
    if(is_file($f) && !in_array(basename($f), $keep, true)) @unlink($f);
  }
}

if($action === 'clear'){
  wipe($DIR);
  out(200, ['ok'=>true]);
}

if($action === 'save'){
  if(empty($_FILES) && empty($_POST)) out(413, ['ok'=>false, 'error'=>'File quá lớn so với cấu hình host (post_max_size).']);
  if(!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK){
    $e = $_FILES['file']['error'] ?? -1;
    out(400, ['ok'=>false, 'error'=> $e === UPLOAD_ERR_INI_SIZE ? 'File vượt upload_max_filesize của host.' : 'Upload file thất bại (mã '.$e.').']);
  }
  $name = $_FILES['file']['name'];
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if(!in_array($ext, ['xlsx','xlsm','xls'], true)) out(400, ['ok'=>false, 'error'=>'Chỉ nhận file .xlsx, .xlsm, .xls']);
  if($_FILES['file']['size'] > $MAX_BYTES) out(400, ['ok'=>false, 'error'=>'File quá lớn.']);
  $j = json_decode($_POST['json'] ?? '', true);
  if(!is_array($j) || !isset($j['sheets']) || !is_array($j['sheets'])) out(400, ['ok'=>false, 'error'=>'Dữ liệu đọc từ Excel không hợp lệ.']);

  $newFile = 'khsx_' . date('Ymd_His') . '.' . $ext;
  $tmpJson = $DIR . '/data.json.tmp';
  if(!move_uploaded_file($_FILES['file']['tmp_name'], $DIR . '/' . $newFile)) out(500, ['ok'=>false, 'error'=>'Không lưu được file lên host.']);
  $j['file'] = $name;
  $j['savedAt'] = round(microtime(true) * 1000);
  if(file_put_contents($tmpJson, json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) out(500, ['ok'=>false, 'error'=>'Không ghi được data.json.']);

  /* Xóa file Excel cũ + data cũ, chỉ giữ file mới */
  wipe($DIR, [$newFile, 'data.json.tmp']);
  rename($tmpJson, $DIR . '/data.json');
  out(200, ['ok'=>true, 'file'=>$name, 'savedAt'=>$j['savedAt']]);
}

out(400, ['ok'=>false, 'error'=>'Hành động không hợp lệ']);
