<?php
// Индексация больших PDF из консоли (без лимитов веб-сервера):
//   php bin/index.php "/путь/к/учебнику.pdf" "Название учебника"
//   php bin/index.php --list
//   php bin/index.php --delete 3
//   php bin/index.php --search "документы на визу в Италию"
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';

$a = array_slice($argv, 1);
if (!$a) { fwrite(STDERR, "Использование: php bin/index.php file.pdf \"Название\" | --list | --delete ID | --search \"вопрос\"\n"); exit(1); }

if ($a[0] === '--list') {
    foreach (db()->query('SELECT * FROM documents ORDER BY id') as $d)
        printf("%3d  %s  %s стр., %s фрагм.%s\n", $d['id'], $d['title'], $d['pages'], $d['chunks'], $d['active'] ? '' : '  [выкл]');
    exit;
}
if ($a[0] === '--delete') { delete_document((int)$a[1]); echo "Удалено\n"; exit; }
if ($a[0] === '--search') {
    foreach (retrieve($a[1]) as $i => $c) printf("\n[%d] %s, стр. %d\n%s\n", $i + 1, $c['title'], $c['page'], mb_substr($c['text'], 0, 300));
    exit;
}

$src = $a[0];
if (!is_file($src)) { fwrite(STDERR, "Нет файла $src\n"); exit(1); }
$title = $a[1] ?? pathinfo($src, PATHINFO_FILENAME);
$dir = cfg('data_dir') . '/pdf';
if (!is_dir($dir)) mkdir($dir, 0770, true);
$dest = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
copy($src, $dest);
$id = index_pdf($dest, $title, fn($m) => print("$m\n"));
echo "Готово, id=$id\n";
