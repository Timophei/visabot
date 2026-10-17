<?php
declare(strict_types=1);

/**
 * Извлечение текста из материалов разных форматов.
 *
 * Результат — список сегментов: ['label' => где в источнике, 'text' => текст, 'atomic' => bool].
 *  atomic = true  — сегмент не режется (пара «вопрос-ответ», одна запись);
 *  atomic = false — длинный текст, дальше режется на фрагменты.
 */

const SUPPORTED_EXT = ['pdf', 'docx', 'txt', 'md', 'markdown', 'html', 'htm', 'json', 'jsonl', 'csv', 'tsv', 'xlsx', 'zip'];

function extract_segments(string $path, string $name, int $depth = 0): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf':
            $out = [];
            foreach (pdf_pages($path) as $i => $p) $out[] = seg('стр. ' . ($i + 1), $p);
            return $out;
        case 'docx':     return extract_docx($path);
        case 'txt':      return [seg('', to_utf8((string)file_get_contents($path)))];
        case 'md': case 'markdown': return extract_markdown(to_utf8((string)file_get_contents($path)));
        case 'html': case 'htm': return extract_html(to_utf8((string)file_get_contents($path)));
        case 'json': case 'jsonl': return extract_json_file($path);
        case 'csv': case 'tsv': return extract_csv($path);
        case 'xlsx':     return extract_xlsx($path);
        case 'zip':
            if ($depth > 1) return [];
            return extract_zip($path, $depth);
    }
    throw new RuntimeException("Формат .$ext не поддерживается. Можно: " . implode(', ', SUPPORTED_EXT));
}

function seg(string $label, string $text, bool $atomic = false): array
{
    return ['label' => $label, 'text' => $text, 'atomic' => $atomic];
}

function to_utf8(string $s): string
{
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // BOM
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
    return $s;
}

/* ---------------- ZIP (выгрузки баз знаний, пачки файлов) ---------------- */

function extract_zip(string $path, int $depth): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Не удалось открыть ZIP-архив.');
    $tmpDir = sys_get_temp_dir() . '/visabot_' . bin2hex(random_bytes(6));
    mkdir($tmpDir, 0700, true);
    $out = [];
    $total = 0;
    try {
        for ($i = 0; $i < $zip->numFiles && $i < 5000; $i++) {
            $st = $zip->statIndex($i);
            $entry = $st['name'];
            if (str_ends_with($entry, '/') || str_contains($entry, '..') || str_starts_with($entry, '/')) continue;
            if (preg_match('#(^|/)(__MACOSX|\.)#', $entry)) continue;
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, SUPPORTED_EXT, true)) continue;
            $total += $st['size'];
            if ($total > 500 * 1024 * 1024) throw new RuntimeException('Архив слишком большой (больше 500 МБ в распакованном виде).');

            $tmp = $tmpDir . '/f' . $i . '.' . $ext;
            file_put_contents($tmp, $zip->getFromIndex($i));
            try {
                foreach (extract_segments($tmp, $entry, $depth + 1) as $s) {
                    $s['label'] = trim($entry . ($s['label'] !== '' ? ' › ' . $s['label'] : ''));
                    $out[] = $s;
                }
            } catch (Throwable $e) {
                error_log("[visa-bot] zip: пропущен $entry: " . $e->getMessage());
            }
            @unlink($tmp);
        }
    } finally {
        $zip->close();
        @rmdir($tmpDir);
    }
    if (!$out) throw new RuntimeException('В архиве не найдено файлов поддерживаемых форматов (' . implode(', ', SUPPORTED_EXT) . ').');
    return $out;
}

/* ---------------- Word (.docx) ---------------- */

function extract_docx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Не удалось открыть DOCX.');
    $xml = (string)$zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === '') throw new RuntimeException('В DOCX не найден текст.');

    // Абзацы-заголовки (Heading/Заголовок) превращаем в «# …», чтобы резать по разделам
    $xml = preg_replace_callback('#<w:p[ >].*?</w:p>#s', function ($m) {
        $p = $m[0];
        $text = preg_replace(['#<w:tab/>#', '#<w:br[^>]*/>#'], ["\t", "\n"], $p);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (preg_match('#<w:pStyle w:val="(Heading|Title|[^"]*[Зз]аголовок)\D*(\d?)"#u', $p, $h) && trim($text) !== '') {
            return "\n# " . trim($text) . "\n";
        }
        return $text . "\n";
    }, $xml);
    $text = html_entity_decode(strip_tags((string)$xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    return extract_markdown($text);
}

/* ---------------- Markdown / текст с заголовками ---------------- */

function extract_markdown(string $text): array
{
    $parts = preg_split('/^(#{1,3})\s+(.+)$/mu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = [];
    $intro = trim($parts[0]);
    if ($intro !== '') $out[] = seg('', $intro);
    for ($i = 1; $i < count($parts); $i += 3) {
        $title = trim($parts[$i + 1] ?? '');
        $body = trim($parts[$i + 2] ?? '');
        if ($body === '' && $title === '') continue;
        $out[] = seg(mb_substr($title, 0, 120), $title . "\n" . $body);
    }
    return $out ?: [seg('', $text)];
}

/* ---------------- HTML (страницы баз знаний, сохранённые сайты) ---------------- */

function extract_html(string $html): array
{
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m)) $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    if (preg_match('#<h1[^>]*>(.*?)</h1>#si', $html, $m)) $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) ?: $title;

    $html = preg_replace('#<(script|style|noscript|svg|nav|footer|header|form)\b.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<h([1-3])[^>]*>(.*?)</h\1>#si', "\n# $2\n", $html);
    $html = preg_replace('#<li[^>]*>#i', "\n- ", $html);
    $html = preg_replace('#<(br|/p|/div|/tr|/h[4-6]|/section|/article|/table|/ul|/ol)[^>]*>#i', "\n", $html);
    $html = preg_replace('#</t[dh]>#i', ' | ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = preg_replace("/\n\s*\n+/u", "\n\n", $text);

    $segs = extract_markdown(trim($text));
    if ($title !== '') foreach ($segs as &$s) $s['label'] = $s['label'] === '' ? $title : ($s['label'] === $title ? $title : $title . ' › ' . $s['label']);
    return $segs;
}

/* ---------------- CSV / Excel ---------------- */

function rows_to_segment(array $rows, string $label): array
{
    $rows = array_values(array_filter($rows, fn($r) => implode('', array_map('trim', $r)) !== ''));
    if (!$rows) return [];
    $head = array_map(fn($h) => trim((string)$h), $rows[0]);
    $lines = [];
    foreach (array_slice($rows, 1) as $r) {
        $cells = [];
        foreach ($r as $i => $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            $cells[] = (($head[$i] ?? '') !== '' ? $head[$i] . ': ' : '') . $v;
        }
        if ($cells) $lines[] = implode(' | ', $cells);
    }
    if (!$lines) $lines = [implode(' | ', $head)];
    return [seg($label, implode("\n", $lines))];
}

function extract_csv(string $path): array
{
    $raw = to_utf8((string)file_get_contents($path));
    $first = strtok($raw, "\n") ?: '';
    $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = array_key_first($counts);
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) $rows[] = $r;
    fclose($fh);
    return rows_to_segment($rows, '');
}

function extract_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Не удалось открыть XLSX.');

    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = simplexml_load_string($ss);
        foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }
    // имена листов и пути к ним
    $sheets = [];
    $wb = simplexml_load_string((string)$zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
    $target = [];
    foreach ($rels->Relationship as $rel) $target[(string)$rel['Id']] = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', (string)$rel['Target']), '/');
    foreach ($wb->sheets->sheet as $s) {
        $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        if (isset($target[$rid])) $sheets[(string)$s['name']] = $target[$rid];
    }

    $out = [];
    foreach ($sheets as $name => $file) {
        $xml = $zip->getFromName($file);
        if ($xml === false) continue;
        $x = simplexml_load_string($xml);
        $rows = [];
        foreach ($x->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
                $col = 0;
                foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + ord($ch) - 64;
                $type = (string)$c['t'];
                $v = $type === 's' ? ($shared[(int)$c->v] ?? '') : ($type === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
                $r[$col - 1] = $v;
            }
            if ($r) {
                $max = max(array_keys($r));
                $rows[] = array_map(fn($i) => $r[$i] ?? '', range(0, $max));
            }
        }
        foreach (rows_to_segment($rows, 'лист «' . $name . '»') as $s) $out[] = $s;
    }
    $zip->close();
    return $out;
}

/* ---------------- JSON / JSONL ---------------- */

function extract_json_file(string $path): array
{
    $raw = to_utf8((string)file_get_contents($path));
    $data = json_decode($raw, true);
    if ($data === null) {
        // JSONL: по объекту на строку
        $data = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            if (trim($line) === '') continue;
            $row = json_decode($line, true);
            if ($row === null) throw new RuntimeException('Файл не похож на JSON: ' . json_last_error_msg());
            $data[] = $row;
        }
    }
    $out = json_segments($data);
    if (!$out) throw new RuntimeException('В JSON не найдено текста.');
    return $out;
}

/** Ключ из списка вариантов (без учёта регистра). */
function jkey(array $o, array $names): ?string
{
    foreach ($o as $k => $v) {
        if (in_array(mb_strtolower((string)$k), $names, true) && (is_string($v) || is_numeric($v)) && trim((string)$v) !== '') return (string)$v;
    }
    return null;
}

function json_text($v): string
{
    if (is_string($v)) return $v;
    if (is_array($v)) {
        // content: [{type: text, text: …}] / parts: ["…"]
        $t = [];
        foreach ($v as $p) $t[] = is_array($p) ? (string)($p['text'] ?? '') : (string)$p;
        return trim(implode("\n", $t));
    }
    return (string)$v;
}

/** Пары «вопрос → ответ» из последовательности сообщений чата. */
function chat_pairs(array $msgs, string $label): array
{
    $out = [];
    $q = null;
    foreach ($msgs as $m) {
        if ($m['text'] === '') continue;
        if ($m['role'] === 'user') { $q = $m['text']; continue; }
        if ($m['role'] === 'assistant' && $q !== null) {
            $out[] = seg($label, "Вопрос: $q\nОтвет: " . $m['text'], true);
            $q = null;
        }
    }
    return $out;
}

function norm_role(string $r): string
{
    $r = strtolower($r);
    if (in_array($r, ['user', 'human', 'client', 'клиент', 'customer'], true)) return 'user';
    if (in_array($r, ['assistant', 'model', 'bot', 'ai', 'gpt', 'бот', 'консультант'], true)) return 'assistant';
    return $r;
}

function json_segments($data, string $label = ''): array
{
    if (!is_array($data)) return is_scalar($data) && trim((string)$data) !== '' ? [seg($label, (string)$data)] : [];

    $isList = array_is_list($data);
    if (!$isList) {
        $o = $data;
        $title = jkey($o, ['title', 'name', 'заголовок', 'название', 'тема', 'topic', 'country', 'страна', 'service', 'услуга', 'city', 'город']) ?? $label;

        // 1) пара вопрос–ответ
        $q = jkey($o, ['question', 'q', 'вопрос', 'prompt', 'instruction', 'query', 'input']);
        $a = jkey($o, ['answer', 'a', 'ответ', 'response', 'completion', 'output', 'reply']);
        if ($q !== null && $a !== null) {
            $ctx = jkey($o, ['category', 'topic', 'тема', 'категория', 'context', 'country', 'страна']);
            return [seg(mb_substr($q, 0, 100), ($ctx ? "Тема: $ctx\n" : '') . "Вопрос: $q\nОтвет: $a", true)];
        }
        // 2) чат в формате OpenAI: messages: [{role, content}]
        if (isset($o['messages']) && is_array($o['messages']) && array_is_list($o['messages']) && isset($o['messages'][0]['role'])) {
            $msgs = array_map(fn($m) => ['role' => norm_role((string)($m['role'] ?? '')), 'text' => trim(json_text($m['content'] ?? ''))], $o['messages']);
            return chat_pairs($msgs, $title);
        }
        // 3) экспорт ChatGPT: mapping: {id: {message: {author: {role}, content: {parts}}}}
        if (isset($o['mapping']) && is_array($o['mapping'])) {
            $msgs = [];
            foreach ($o['mapping'] as $node) {
                $m = $node['message'] ?? null;
                if (!$m) continue;
                $msgs[] = ['t' => (float)($m['create_time'] ?? 0), 'role' => norm_role((string)($m['author']['role'] ?? '')), 'text' => trim(json_text($m['content']['parts'] ?? ($m['content']['text'] ?? '')))];
            }
            usort($msgs, fn($x, $y) => $x['t'] <=> $y['t']);
            return chat_pairs($msgs, $title);
        }
        // 4) экспорт Claude: chat_messages: [{sender, text}]
        if (isset($o['chat_messages']) && is_array($o['chat_messages'])) {
            $msgs = array_map(fn($m) => ['role' => norm_role((string)($m['sender'] ?? '')), 'text' => trim(json_text($m['text'] ?? ($m['content'] ?? '')))], $o['chat_messages']);
            return chat_pairs($msgs, $title);
        }
        // 5) объект со списком записей внутри ({"items": [...], "version": ...}) — разбираем список
        $lists = array_filter($o, fn($v) => is_array($v) && array_is_list($v) && count($v) > 1 && is_array($v[0] ?? null));
        if ($lists) {
            uasort($lists, fn($x, $y) => count($y) <=> count($x));
            $key = array_key_first($lists);
            $rest = $o;
            unset($rest[$key]);
            $out = [];
            $head = trim(json_render($rest));
            if ($head !== '' && mb_strlen($head) > 40) $out[] = seg($title, $head);
            return array_merge($out, json_segments($o[$key], $title !== '' ? $title : (string)$key));
        }
        // 6) просто объект — читаемый текст
        $text = trim(json_render($o));
        return $text === '' ? [] : [seg($title, $text, mb_strlen($text) <= 2500)];
    }

    // Список
    $out = [];
    foreach ($data as $i => $item) {
        if (is_array($item)) {
            foreach (json_segments($item, $label !== '' ? $label . ' #' . ($i + 1) : '#' . ($i + 1)) as $s) $out[] = $s;
        } elseif (is_scalar($item) && trim((string)$item) !== '') {
            $out[] = seg($label, (string)$item, mb_strlen((string)$item) <= 2500);
        }
    }
    return $out;
}

/** Объект → читаемый текст «ключ: значение» с вложенностью. */
function json_render($v, int $indent = 0): string
{
    $pad = str_repeat('  ', $indent);
    if (!is_array($v)) return (string)(is_bool($v) ? ($v ? 'да' : 'нет') : $v);
    if (array_is_list($v) && !array_filter($v, 'is_array')) return implode(', ', array_map(fn($x) => json_render($x), $v));
    $lines = [];
    foreach ($v as $k => $x) {
        if ($x === null || $x === '' || $x === []) continue;
        $name = is_int($k) ? '-' : str_replace('_', ' ', (string)$k) . ':';
        if (is_array($x) && !(array_is_list($x) && !array_filter($x, 'is_array'))) {
            $lines[] = $pad . $name . "\n" . json_render($x, $indent + 1);
        } else {
            $lines[] = $pad . $name . ' ' . json_render($x, $indent + 1);
        }
    }
    return implode("\n", $lines);
}
