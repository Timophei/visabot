<?php
// Работа с материалами из консоли (для больших файлов — без лимитов веб-сервера):
//   php bin/index.php файл.pdf|docx|json|xlsx|zip|… "Название" [--aux]
//   php bin/index.php --preview файл.json        — показать, как файл будет разрезан, ничего не сохраняя
//   php bin/index.php --list
//   php bin/index.php --delete 3
//   php bin/index.php --search "документы на визу в Италию"
// --aux — вспомогательный источник (например, ответы других нейросетей)
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';

$a = array_slice($argv, 1);
$aux = in_array('--aux', $a, true);
$a = array_values(array_filter($a, fn($x) => $x !== '--aux'));
if (!$a) { fwrite(STDERR, "Использование: php bin/index.php файл \"Название\" [--aux] | --preview файл | --list | --delete ID | --search \"вопрос\"\nФорматы: " . implode(', ', SUPPORTED_EXT) . "\n"); exit(1); }

if ($a[0] === '--list') {
    foreach (db()->query('SELECT * FROM documents ORDER BY id') as $d)
        printf("%3d  %-5s %s  %s разд., %s фрагм.%s%s\n", $d['id'], strtoupper($d['format']), $d['title'], $d['pages'], $d['chunks'], $d['trust'] === 'aux' ? '  [вспомогательный]' : '', $d['active'] ? '' : '  [выкл]');
    exit;
}
if ($a[0] === '--delete') { delete_document((int)$a[1]); echo "Удалено\n"; exit; }
if ($a[0] === '--search') {
    foreach (retrieve($a[1]) as $i => $c) printf("\n[%d] %s\n%s\n", $i + 1, chunk_label($c), mb_substr($c['text'], 0, 300));
    exit;
}
if ($a[0] === '--preview') {
    $chunks = make_chunks(extract_segments($a[1], basename($a[1])));
    printf("%d фрагментов\n", count($chunks));
    foreach (array_slice($chunks, 0, 8) as $i => $c) printf("\n[%d] %s\n%s\n", $i + 1, $c['source'], mb_substr($c['text'], 0, 300));
    exit;
}

$src = $a[0];
if (!is_file($src)) { fwrite(STDERR, "Нет файла $src\n"); exit(1); }
$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
$title = $a[1] ?? pathinfo($src, PATHINFO_FILENAME);
$dir = cfg('data_dir') . '/files';
if (!is_dir($dir)) mkdir($dir, 0770, true);
$dest = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
copy($src, $dest);
$id = index_file($dest, basename($src), $title, $aux ? 'aux' : 'main', fn($m) => print("$m\n"));
echo "Готово, id=$id\n";
