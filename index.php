<?php
/**
 * Inventory Barang - versi PHP (satu file, tanpa framework, tanpa database).
 *
 * Dua peran, satu data:
 *   - Admin   : kelola data barang, catat barang masuk dan keluar.
 *   - Pemilik : dashboard baca-saja (stok dan riwayat).
 *
 * Akun : admin / admin123  |  pemilik / pemilik123
 * Data : inventory_barang.json (satu folder dengan file ini).
 * Kode memakai fitur PHP 8+ saja, tanpa API yang sudah deprecated, sehingga
 * siap dipakai di versi PHP berikutnya.
 */
declare(strict_types=1);

session_start();

const DATA_FILE = __DIR__ . '/inventory_barang.json';
const ACCOUNTS = [
    'admin'   => ['pw' => 'admin123',   'name' => 'Rina Set',      'role' => 'admin'],
    'pemilik' => ['pw' => 'pemilik123', 'name' => 'Hendra Wijaya', 'role' => 'owner'],
];

/* ---------- Helper ---------- */

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt(int $n): string
{
    return number_format($n, 0, ',', '.');
}

function seed(): array
{
    return [
        'items' => [
            ['id' => 'i1', 'name' => 'Kaos Polos Hitam', 'unit' => 'pcs',    'qty' => 42, 'min' => 20],
            ['id' => 'i2', 'name' => 'Sepatu Sneakers',  'unit' => 'pasang', 'qty' => 5,  'min' => 8],
            ['id' => 'i3', 'name' => 'Tas Ransel',       'unit' => 'pcs',    'qty' => 30, 'min' => 10],
            ['id' => 'i4', 'name' => 'Topi Baseball',    'unit' => 'pcs',    'qty' => 18, 'min' => 10],
            ['id' => 'i5', 'name' => 'Jaket Hoodie',     'unit' => 'pcs',    'qty' => 12, 'min' => 6],
            ['id' => 'i6', 'name' => 'Kemeja Flanel',    'unit' => 'pcs',    'qty' => 0,  'min' => 5],
        ],
        'log' => [
            ['date' => '2026-10-12', 'type' => 'in',  'item' => 'i6', 'qty' => 6,  'note' => 'Surat jalan SJ-0412', 'by' => 'admin'],
            ['date' => '2026-10-14', 'type' => 'out', 'item' => 'i1', 'qty' => 8,  'note' => '',                    'by' => 'admin'],
            ['date' => '2026-10-15', 'type' => 'in',  'item' => 'i5', 'qty' => 10, 'note' => '',                    'by' => 'pemilik'],
            ['date' => '2026-10-16', 'type' => 'in',  'item' => 'i3', 'qty' => 12, 'note' => '',                    'by' => 'admin'],
            ['date' => '2026-10-16', 'type' => 'out', 'item' => 'i4', 'qty' => 3,  'note' => '',                    'by' => 'admin'],
            ['date' => '2026-10-17', 'type' => 'out', 'item' => 'i2', 'qty' => 6,  'note' => 'Pesanan online',      'by' => 'admin'],
            ['date' => '2026-10-17', 'type' => 'in',  'item' => 'i1', 'qty' => 24, 'note' => '',                    'by' => 'admin'],
        ],
    ];
}

function load_data(): array
{
    if (!is_file(DATA_FILE)) {
        return seed();
    }
    $data = json_decode((string) file_get_contents(DATA_FILE), true);
    return is_array($data) && isset($data['items'], $data['log']) ? $data : seed();
}

function save_data(array $data): void
{
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function find_item(array $data, string $id): ?int
{
    foreach ($data['items'] as $i => $it) {
        if ($it['id'] === $id) {
            return $i;
        }
    }
    return null;
}

function month_totals(array $data): array
{
    $prefix = date('Y-m');
    $t = ['in' => 0, 'out' => 0];
    foreach ($data['log'] as $l) {
        if (str_starts_with($l['date'], $prefix)) {
            $t[$l['type']] += $l['qty'];
        }
    }
    return $t;
}

function recent(array $data, ?string $type, int $n): array
{
    $rows = array_values(array_filter($data['log'], fn($l) => $type === null || $l['type'] === $type));
    usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));
    return array_slice($rows, 0, $n);
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $kind];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function go(string $view = 'dashboard'): never
{
    header('Location: ?v=' . urlencode($view));
    exit;
}

/* ---------- Aksi (POST) ---------- */

$data = load_data();
$me = $_SESSION['user'] ?? null;
$isOwner = $me !== null && ACCOUNTS[$me]['role'] === 'owner';
$view = $_GET['v'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Token tidak valid.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $u = strtolower(trim((string) ($_POST['user'] ?? '')));
        $acc = ACCOUNTS[$u] ?? null;
        $wantOwner = ($_POST['role'] ?? 'admin') === 'owner';
        if ($acc === null || !hash_equals($acc['pw'], (string) ($_POST['pw'] ?? ''))) {
            flash('Username atau password salah.', 'err');
        } elseif (($acc['role'] === 'owner') !== $wantOwner) {
            flash('Akun ini untuk login ' . ($acc['role'] === 'owner' ? 'Pemilik' : 'Admin') . '.', 'err');
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
        }
        go();
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ?');
        exit;
    }

    // Semua aksi di bawah ini hanya untuk Admin.
    if ($me === null || $isOwner) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    if ($action === 'add_item') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $qty = filter_var($_POST['qty'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $min = filter_var($_POST['min'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($name === '') {
            flash('Nama barang wajib diisi.', 'err');
        } elseif ($qty === false || $min === false) {
            flash('Stok harus berupa angka 0 atau lebih.', 'err');
        } else {
            $data['items'][] = [
                'id' => 'i' . bin2hex(random_bytes(4)), 'code' => trim((string) ($_POST['code'] ?? '')), 'name' => $name,
                'category' => trim((string) ($_POST['category'] ?? '')), 'location' => trim((string) ($_POST['location'] ?? '')),
                'unit' => trim((string) ($_POST['unit'] ?? '')) ?: 'pcs', 'qty' => $qty, 'min' => $min,
            ];
            save_data($data);
            flash("Barang \"$name\" ditambahkan.");
        }
        go('items');
    }

    if ($action === 'del_item') {
        $idx = find_item($data, (string) ($_POST['id'] ?? ''));
        if ($idx !== null) {
            array_splice($data['items'], $idx, 1);
            save_data($data);
            flash('Barang dihapus.');
        }
        go('items');
    }

    if ($action === 'move') {
        $type = ($_POST['type'] ?? '') === 'out' ? 'out' : 'in';
        $idx = find_item($data, (string) ($_POST['item'] ?? ''));
        $qty = filter_var($_POST['qty'] ?? 0, FILTER_VALIDATE_INT);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_POST['date'] ?? ''));
        if ($idx === null) {
            flash('Pilih barang terlebih dahulu.', 'err');
        } elseif ($qty === false || $qty <= 0) {
            flash('Jumlah harus lebih dari 0.', 'err');
        } elseif ($d === false || $d->format('Y-m-d') !== $_POST['date']) {
            flash('Tanggal tidak valid.', 'err');
        } elseif ($type === 'out' && $qty > $data['items'][$idx]['qty']) {
            $it = $data['items'][$idx];
            flash("Stok {$it['name']} hanya " . fmt($it['qty']) . " {$it['unit']}.", 'err');
        } else {
            $it = &$data['items'][$idx];
            $it['qty'] += $type === 'in' ? $qty : -$qty;
            $data['log'][] = [
                'date' => $d->format('Y-m-d'), 'type' => $type, 'item' => $it['id'], 'qty' => $qty,
                'supplier' => $type === 'in' ? trim((string) ($_POST['supplier'] ?? '')) : '',
                'note' => trim((string) ($_POST['note'] ?? '')), 'by' => $me,
            ];
            save_data($data);
            flash('Tersimpan: ' . ($type === 'in' ? 'masuk ' : 'keluar ') . fmt($qty) . " {$it['unit']} {$it['name']}.");
            unset($it);
        }
        go($type);
    }
    go();
}

/* ---------- Tampilan ---------- */

function kpi(string $label, int $value, string $unit = ''): string
{
    return '<div class="kpi"><span>' . e($label) . '</span><b>' . fmt($value)
        . ($unit !== '' ? '<em>' . e($unit) . '</em>' : '') . '</b></div>';
}

function stock_pill(array $it): string
{
    return $it['qty'] <= $it['min']
        ? '<span class="pill low">Menipis</span>' : '<span class="pill ok">Aman</span>';
}

function history_table(array $data, array $rows): string
{
    if (!$rows) {
        return '<p class="empty">Belum ada riwayat.</p>';
    }
    $html = '<table><thead><tr><th>Tanggal</th><th>Barang</th><th>Jenis</th><th class="r">Jumlah</th><th>Supplier</th><th>Catatan</th></tr></thead><tbody>';
    foreach ($rows as $l) {
        $i = find_item($data, $l['item']);
        $name = $i !== null ? $data['items'][$i]['name'] : '(barang dihapus)';
        $unit = $i !== null ? $data['items'][$i]['unit'] : 'pcs';
        $in = $l['type'] === 'in';
        $html .= '<tr><td>' . e($l['date']) . '</td><td>' . e($name) . '</td><td><span class="pill '
            . ($in ? 'ok' : 'low') . '">' . ($in ? 'Masuk' : 'Keluar') . '</span></td><td class="r">'
            . fmt($l['qty']) . ' ' . e($unit) . '</td><td>' . e($l['supplier'] ?? '') . '</td><td>' . e($l['note']) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

function stock_table(array $data, bool $deletable): string
{
    if (!$data['items']) {
        return '<p class="empty">Belum ada barang.</p>';
    }
    $html = '<div class="pad" style="padding-bottom:0"><input type="search" id="q" placeholder="Cari kode, nama, kategori, atau lokasi..." oninput="filterRows(this.value)"></div>'
        . '<table id="stock"><thead><tr><th>Kode</th><th>Barang</th><th>Kategori</th><th>Lokasi</th><th class="r">Stok</th><th class="r">Minimum</th><th>Status</th>'
        . ($deletable ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($data['items'] as $it) {
        $html .= '<tr><td>' . e($it['code'] ?? '') . '</td><td>' . e($it['name']) . '</td><td>' . e($it['category'] ?? '')
            . '</td><td>' . e($it['location'] ?? '') . '</td><td class="r">' . fmt($it['qty']) . ' ' . e($it['unit'])
            . '</td><td class="r">' . fmt($it['min']) . ' ' . e($it['unit']) . '</td><td>' . stock_pill($it) . '</td>';
        if ($deletable) {
            $html .= '<td class="r"><form method="post" onsubmit="return confirm(\'Hapus ' . e(addslashes($it['name']))
                . '?\')"><input type="hidden" name="csrf" value="' . csrf_token()
                . '"><input type="hidden" name="action" value="del_item"><input type="hidden" name="id" value="'
                . e($it['id']) . '"><button class="btn sm danger">Hapus</button></form></td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

$flash = flash();
$t = month_totals($data);
$roleLogin = ($_GET['role'] ?? 'admin') === 'owner' ? 'owner' : 'admin';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Barang</title>
<style>
:root{--bg:#F3F4F6;--surface:#fff;--ink:#111827;--muted:#6B7280;--line:#E5E7EB;--accent:#0F766E;--accent-soft:#E6F4F2;
--orange:#C2410C;--orange-soft:#FDEBDD;--green:#15803D;--green-soft:#E3F4E8}
body.owner{--accent:#1E3A5F;--accent-soft:#E8EDF5}
@media (prefers-color-scheme:dark){:root{--bg:#0E1116;--surface:#171B22;--ink:#E5E7EB;--muted:#9CA3AF;--line:#2A303A;
--orange:#FB923C;--orange-soft:#3A2415;--green:#4ADE80;--green-soft:#14291C;--accent:#2DD4BF;--accent-soft:#15312E}
body.owner{--accent:#93B4E0;--accent-soft:#1B2638}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,sans-serif}
h1{font-size:22px;margin:0}h3{margin:0}
.wrap{max-width:1180px;margin:0 auto;padding:20px 16px 40px}
.top{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
.brand{font-weight:700;font-size:16px;color:var(--accent)}
nav{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px}
nav a{padding:8px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-weight:600}
nav a.on{background:var(--accent-soft);color:var(--accent)}
.sub{color:var(--muted);margin:2px 0 0}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:20px}
.kpi,.card{background:var(--surface);border:1px solid var(--line);border-radius:12px}
.kpi{padding:14px 18px}.kpi span{color:var(--muted);font-size:12px;font-weight:600}
.kpi b{display:block;font-size:24px;margin-top:4px}.kpi em{font-style:normal;font-size:13px;color:var(--muted);margin-left:4px}
.card{overflow:auto;margin-bottom:20px}.card>h3{padding:14px 18px;border-bottom:1px solid var(--line)}
.pad{padding:18px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;overflow-wrap:anywhere}
th:first-child,td:first-child{padding-left:18px}th:last-child,td:last-child{padding-right:18px}
td.r,th.r,td:has(.pill),td:has(.btn){white-space:nowrap;overflow-wrap:normal}
th{color:var(--muted);font-size:12px}tr:last-child td{border-bottom:0}.r{text-align:right}
.pill{padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600}
.pill.ok{background:var(--green-soft);color:var(--green)}.pill.low{background:var(--orange-soft);color:var(--orange)}
.empty{padding:18px;color:var(--muted);margin:0}
label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin:12px 0 4px}
input,select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);font:inherit}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{padding:9px 14px;border-radius:8px;border:1px solid var(--line);background:var(--surface);color:var(--ink);font:600 14px inherit;cursor:pointer}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--surface)}
.btn.sm{padding:4px 10px;font-size:12px}.btn.danger{color:var(--orange)}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-weight:600}
.msg.ok{background:var(--green-soft);color:var(--green)}.msg.err{background:var(--orange-soft);color:var(--orange)}
.login{max-width:380px;margin:6vh auto 0}
.logo{display:block;width:100%;max-width:240px;height:auto;margin:0 auto 14px;border-radius:8px}
.seg{display:flex;background:var(--bg);border-radius:9px;padding:3px;margin:16px 0}
.seg a{flex:1;text-align:center;padding:8px;border-radius:7px;color:var(--muted);font-weight:600;text-decoration:none}
.seg a.on{background:var(--surface);color:var(--ink)}
.actions{text-align:right;margin-top:14px}
</style>
</head>
<body class="<?= $me !== null ? ($isOwner ? 'owner' : 'admin') : ($roleLogin === 'owner' ? 'owner' : 'admin') ?>">
<div class="wrap">

<?php if ($flash && $me === null): ?><div class="msg <?= e($flash[1]) ?>"><?= e($flash[0]) ?></div><?php endif; ?>

<?php if ($me === null): ?>
  <form method="post" class="card pad login">
    <img src="logo.jpg" alt="Logo Fakultas Kedokteran Universitas Muhammadiyah Palu" class="logo">
    <div class="brand" style="text-align:center">Inventory Barang</div>
    <div class="seg">
      <a href="?role=admin" class="<?= $roleLogin === 'admin' ? 'on' : '' ?>">Admin</a>
      <a href="?role=owner" class="<?= $roleLogin === 'owner' ? 'on' : '' ?>">Pemilik</a>
    </div>
    <h3><?= $roleLogin === 'owner' ? 'Login Pemilik' : 'Login Admin' ?></h3>
    <p class="sub"><?= $roleLogin === 'owner' ? 'Masuk untuk memantau stok dan riwayat barang.' : 'Masuk untuk mencatat barang masuk dan keluar.' ?></p>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="role" value="<?= e($roleLogin) ?>">
    <label for="user">Username</label><input id="user" name="user" autocomplete="username" required autofocus>
    <label for="pw">Password</label><input id="pw" name="pw" type="password" autocomplete="current-password" required>
    <div class="actions"><button class="btn primary" style="width:100%">Masuk</button></div>
    <p class="sub" style="text-align:center;margin-top:14px">Akun contoh: admin / admin123 · pemilik / pemilik123</p>
  </form>

<?php else: ?>
  <div class="top">
    <div class="brand">Inventory Barang<?= $isOwner ? ' · Pemilik' : '' ?></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="logout">
      <button class="btn sm"><?= e(ACCOUNTS[$me]['name']) ?> · Keluar</button></form>
  </div>

  <?php if (!$isOwner): ?>
  <nav>
    <?php foreach (['dashboard' => 'Dashboard', 'items' => 'Data Barang', 'in' => 'Barang Masuk', 'out' => 'Barang Keluar'] as $k => $label): ?>
      <a href="?v=<?= $k ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if ($flash): ?><div class="msg <?= e($flash[1]) ?>"><?= e($flash[0]) ?></div><?php endif; ?>

  <?php if ($isOwner): ?>
    <h1>Dashboard Pemilik</h1><p class="sub" style="margin-bottom:16px">Pantau stok dan riwayat barang</p>
    <div class="kpis"><?= kpi('Total Jenis Barang', count($data['items'])) . kpi('Masuk Bulan Ini', $t['in'], 'pcs') . kpi('Keluar Bulan Ini', $t['out'], 'pcs') ?></div>
    <section class="card"><h3>Stok Barang Saat Ini</h3><?= stock_table($data, false) ?></section>
    <section class="card"><h3>Riwayat Terbaru</h3><?= history_table($data, recent($data, null, 5)) ?></section>

  <?php elseif ($view === 'items'): ?>
    <h1>Data Barang</h1><p class="sub" style="margin-bottom:16px"><?= count($data['items']) ?> jenis barang terdaftar</p>
    <section class="card"><h3>Daftar Barang</h3><?= stock_table($data, true) ?></section>
    <form method="post" class="card pad">
      <h3 style="padding:0 0 4px;border:0">Tambah barang</h3>
      <p class="sub">Stok awal bisa diisi di sini. Perubahan berikutnya dicatat lewat barang masuk dan keluar.</p>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="add_item">
      <div class="two">
        <div><label for="code">Kode barang</label><input id="code" name="code" placeholder="Contoh: ATK-040"></div>
        <div><label for="category">Kategori</label><input id="category" name="category" placeholder="ATK / Rumah Tangga"></div>
      </div>
      <label for="name">Nama barang</label><input id="name" name="name" placeholder="Contoh: Kertas A4" required>
      <label for="location">Lokasi penyimpanan</label><input id="location" name="location" placeholder="Contoh: Gudang ATK">
      <div class="two">
        <div><label for="unit">Satuan</label><input id="unit" name="unit" value="pcs"></div>
        <div><label for="qty">Stok awal</label><input id="qty" name="qty" type="number" min="0" value="0"></div>
      </div>
      <label for="min">Stok minimum</label><input id="min" name="min" type="number" min="0" value="5">
      <div class="actions"><button class="btn primary">Simpan</button></div>
    </form>

  <?php elseif ($view === 'in' || $view === 'out'): ?>
    <?php $label = $view === 'in' ? 'Barang Masuk' : 'Barang Keluar'; ?>
    <h1><?= $label ?></h1><p class="sub" style="margin-bottom:16px"><?= $view === 'in' ? 'Catat barang yang masuk ke gudang' : 'Catat barang yang keluar dari gudang' ?></p>
    <form method="post" class="card pad">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="move">
      <input type="hidden" name="type" value="<?= e($view) ?>">
      <label for="item">Barang</label>
      <select id="item" name="item">
        <?php foreach ($data['items'] as $it): ?>
          <option value="<?= e($it['id']) ?>"><?= e(($it['code'] ?? '') !== '' ? $it['code'] . ' - ' : '') ?><?= e($it['name']) ?> (stok <?= fmt($it['qty']) ?> <?= e($it['unit']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <div class="two">
        <div><label for="mq">Jumlah</label><input id="mq" name="qty" type="number" min="1" value="1"></div>
        <div><label for="md">Tanggal</label><input id="md" name="date" type="date" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <?php if ($view === 'in'): ?>
      <label for="supplier">Supplier</label><input id="supplier" name="supplier" placeholder="Opsional">
      <?php endif; ?>
      <label for="note">Catatan</label><input id="note" name="note" placeholder="Opsional, misalnya nomor surat jalan">
      <div class="actions"><button class="btn primary">Simpan <?= $label ?></button></div>
    </form>
    <section class="card"><h3>Riwayat <?= $view === 'in' ? 'masuk' : 'keluar' ?></h3><?= history_table($data, recent($data, $view, 6)) ?></section>

  <?php else: ?>
    <div class="top"><div><h1>Dashboard Admin</h1><p class="sub">Catat barang masuk dan keluar</p></div>
      <div><a class="btn primary" href="?v=in">+ Barang Masuk</a> <a class="btn" href="?v=out">+ Barang Keluar</a></div></div>
    <div class="kpis"><?= kpi('Total Jenis Barang', count($data['items'])) . kpi('Masuk Bulan Ini', $t['in'], 'pcs') . kpi('Keluar Bulan Ini', $t['out'], 'pcs') ?></div>
    <section class="card"><h3>Riwayat Terbaru</h3><?= history_table($data, recent($data, null, 5)) ?></section>
  <?php endif; ?>
<?php endif; ?>

</div>
<script>
function filterRows(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#stock tbody tr').forEach(function (tr) {
    tr.hidden = q !== '' && !tr.textContent.toLowerCase().includes(q);
  });
}
</script>
</body>
</html>
