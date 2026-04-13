<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* database connection ito
   */
$conn = new mysqli("localhost", "root", "", "library_system");
if ($conn->connect_error) {
    die("<div style='font-family:monospace;padding:2rem;color:red'>
        ❌ DB Error: " . $conn->connect_error . "<br>
        Make sure 'library_system' database exists and run setup.sql first.
    </div>");
}
$conn->set_charset("utf8mb4");

function esc($conn, $v) { return mysqli_real_escape_string($conn, trim($v)); }
function intval_safe($v) { return max(0, (int)$v); }

/* for overdue records  */
$conn->query("UPDATE borrows SET status='overdue'
              WHERE status='active' AND due_date < CURDATE() AND returned_at IS NULL");

$flash = ['type'=>'', 'msg'=>''];

/* Login Logout Register */
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

/* Register */
if (isset($_POST['register'])) {
    $u = esc($conn, $_POST['username']);
    $p = md5($_POST['password']);
    if ($conn->query("INSERT INTO users (username,password,role) VALUES ('$u','$p','user')")) {
        $flash = ['type'=>'success','msg'=>'Account created! You can now log in.'];
    } else {
        $flash = ['type'=>'danger','msg'=>'Username already taken.'];
    }
}

/* Login */
if (isset($_POST['login'])) {
    $u = esc($conn, $_POST['username']);
    $r = $conn->query("SELECT * FROM users WHERE username='$u'");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        if (md5($_POST['password']) === $row['password'] || password_verify($_POST['password'], $row['password'])) {
            $_SESSION['user'] = $row;
            header("Location: index.php"); exit;
        }
    }
    $flash = ['type'=>'danger','msg'=>'Invalid username or password.'];
}

/* BOOK CRUD (admin only) */
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$isUser  = ($_SESSION['user']['role'] ?? '') === 'user';

/* Add book */
if (isset($_POST['add_book']) && $isAdmin) {
    $title  = esc($conn, $_POST['title']);
    $author = esc($conn, $_POST['author']);
    $genre  = esc($conn, $_POST['genre'] ?? '');
    $qty    = intval_safe($_POST['quantity']);
    $conn->query("INSERT INTO books (title,author,genre,quantity) VALUES ('$title','$author','$genre',$qty)");
    $flash = ['type'=>'success','msg'=>"Book \"$title\" added."];
}

/* Edit book */
if (isset($_POST['edit_book']) && $isAdmin) {
    $id     = intval_safe($_POST['book_id']);
    $title  = esc($conn, $_POST['title']);
    $author = esc($conn, $_POST['author']);
    $genre  = esc($conn, $_POST['genre'] ?? '');
    $qty    = intval_safe($_POST['quantity']);
    $conn->query("UPDATE books SET title='$title',author='$author',genre='$genre',quantity=$qty WHERE id=$id");
    $flash = ['type'=>'success','msg'=>'Book updated.'];
}

/* Delete book */
if (isset($_GET['delete_book']) && $isAdmin) {
    $id = intval_safe($_GET['delete_book']);
    /* restore quantity from active borrows before deleting */
    $conn->query("UPDATE books SET quantity = quantity +
        (SELECT COUNT(*) FROM borrows WHERE book_id=$id AND status IN ('active','overdue'))
        WHERE id=$id");
    $conn->query("DELETE FROM borrows WHERE book_id=$id");
    $conn->query("DELETE FROM books WHERE id=$id");
    $flash = ['type'=>'success','msg'=>'Book deleted.'];
}

/* BORROW / RETURN (user only)*/
if (isset($_GET['borrow']) && $isUser) {
    $book_id = intval_safe($_GET['borrow']);
    $user_id = intval_safe($_SESSION['user']['id']);
    $due     = date('Y-m-d', strtotime('+7 days'));

    /* nested subquery: check availability & not already borrowed */
    $chk = $conn->query("
        SELECT quantity FROM books
        WHERE id = $book_id
          AND quantity > 0
          AND id NOT IN (
              SELECT book_id FROM borrows
              WHERE user_id = $user_id AND status IN ('active','overdue')
          )
    ");
    if ($chk && $chk->num_rows > 0) {
        $conn->query("UPDATE books SET quantity = quantity - 1 WHERE id=$book_id");
        $conn->query("INSERT INTO borrows (user_id,book_id,borrowed_at,due_date,status)
                      VALUES ($user_id,$book_id,NOW(),'$due','active')");
        $flash = ['type'=>'success','msg'=>"Book borrowed! Due back on $due."];
    } else {
        $flash = ['type'=>'danger','msg'=>'Book unavailable or you already have it borrowed.'];
    }
}

if (isset($_GET['return_book']) && $isUser) {
    $borrow_id = intval_safe($_GET['return_book']);
    $user_id   = intval_safe($_SESSION['user']['id']);
    $r = $conn->query("SELECT book_id FROM borrows
                       WHERE id=$borrow_id AND user_id=$user_id AND returned_at IS NULL");
    if ($r && $r->num_rows > 0) {
        $book_id = $r->fetch_assoc()['book_id'];
        $conn->query("UPDATE borrows SET returned_at=NOW(), status='returned' WHERE id=$borrow_id");
        $conn->query("UPDATE books SET quantity = quantity + 1 WHERE id=$book_id");
        $flash = ['type'=>'success','msg'=>'Book returned successfully. Thank you!'];
    }
}

/* ════════════════════════════════════════════
   ADMIN: ADVANCED QUERIES (computed once)
════════════════════════════════════════════ */
if ($isAdmin) {

    /* ── Stats ── */
    $stat_books   = $conn->query("SELECT COUNT(*) c FROM books")->fetch_assoc()['c'];
    $stat_users   = $conn->query("SELECT COUNT(*) c FROM users WHERE role='user'")->fetch_assoc()['c'];
    $stat_active  = $conn->query("SELECT COUNT(*) c FROM borrows WHERE status IN ('active','overdue')")->fetch_assoc()['c'];
    $stat_overdue = $conn->query("SELECT COUNT(*) c FROM borrows WHERE status='overdue'")->fetch_assoc()['c'];

    /* ── Q1: All active/overdue borrows — JOIN ── */
    $active_borrows = $conn->query("
        SELECT br.id, br.borrowed_at, br.due_date, br.status, br.returned_at,
               u.username, u.id AS uid,
               bk.title, bk.author,
               DATEDIFF(br.due_date, CURDATE()) AS days_remaining
        FROM borrows br
        JOIN users u  ON u.id  = br.user_id
        JOIN books bk ON bk.id = br.book_id
        WHERE br.status IN ('active','overdue')
        ORDER BY br.due_date ASC
    ");

    /* ── Q2: Full borrow history ── */
    $borrow_log = $conn->query("
        SELECT br.id, br.borrowed_at, br.due_date, br.returned_at, br.status,
               u.username, bk.title
        FROM borrows br
        JOIN users u  ON u.id  = br.user_id
        JOIN books bk ON bk.id = br.book_id
        ORDER BY br.borrowed_at DESC
        LIMIT 100
    ");

    /* ── Q3: Per-user stats — GROUP BY + HAVING (borrowed > 0) ── */
    $user_stats = $conn->query("
        SELECT u.id, u.username, u.created_at,
               COUNT(br.id)                              AS total_borrows,
               SUM(br.status = 'returned')               AS returned_count,
               SUM(br.status IN ('active','overdue'))    AS active_count,
               SUM(br.status = 'overdue')                AS overdue_count,
               MAX(br.borrowed_at)                       AS last_borrow
        FROM users u
        LEFT JOIN borrows br ON br.user_id = u.id
        WHERE u.role = 'user'
        GROUP BY u.id, u.username, u.created_at
        HAVING total_borrows > 0
        ORDER BY total_borrows DESC
    ");

    /* ── Q4: Most-borrowed books — correlated subquery + HAVING ── */
    $top_books = $conn->query("
        SELECT bk.id, bk.title, bk.author, bk.quantity,
               (SELECT COUNT(*) FROM borrows WHERE book_id = bk.id)           AS borrow_count,
               (SELECT COUNT(*) FROM borrows WHERE book_id = bk.id
                AND status IN ('active','overdue'))                            AS currently_out
        FROM books bk
        HAVING borrow_count > 0
        ORDER BY borrow_count DESC
        LIMIT 5
    ");

    /* ── Q5: Books never borrowed — NOT EXISTS subquery ── */
    $never_borrowed = $conn->query("
        SELECT bk.id, bk.title, bk.author, bk.quantity
        FROM books bk
        WHERE NOT EXISTS (
            SELECT 1 FROM borrows b WHERE b.book_id = bk.id
        )
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LibraryOS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#060810; --surf:#0d1117; --card:#131820; --border:#1e2535;
  --gold:#f5c842; --teal:#2dd4bf; --rose:#fb7185; --violet:#a78bfa;
  --text:#dde4f0; --muted:#4a5470; --radius:10px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* ── SCROLLBAR ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}

/* ── TOPBAR ── */
.topbar{background:var(--surf);border-bottom:1px solid var(--border);padding:12px 0;position:sticky;top:0;z-index:100;backdrop-filter:blur(12px)}
.brand{font-family:'Syne',sans-serif;font-size:1.3rem;color:var(--gold);text-decoration:none;letter-spacing:-.02em}
.brand span{color:var(--teal)}

/* ── TABS ── */
.side-tabs .nav-link{color:var(--muted);border-radius:8px;padding:9px 14px;font-size:.88rem;font-weight:500;display:flex;align-items:center;gap:8px;transition:.15s}
.side-tabs .nav-link:hover{color:var(--text);background:rgba(255,255,255,.04)}
.side-tabs .nav-link.active{color:var(--gold);background:rgba(245,200,66,.08);border-left:2px solid var(--gold)}
.tab-section{display:none}.tab-section.active{display:block}

/* ── AUTH ── */
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:radial-gradient(ellipse 70% 50% at 30% 20%,rgba(42,130,170,.15) 0%,transparent 60%),
             radial-gradient(ellipse 60% 40% at 80% 80%,rgba(167,139,250,.1) 0%,transparent 60%),
             var(--bg)}
.auth-card{background:var(--surf);border:1px solid var(--border);border-radius:16px;padding:2.4rem;width:100%;max-width:420px;box-shadow:0 30px 60px rgba(0,0,0,.5)}
.auth-title{font-family:'Syne',sans-serif;font-size:1.8rem;color:var(--gold)}

/* ── INPUTS ── */
.form-control,.form-select{background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:8px;font-size:.9rem}
.form-control:focus,.form-select:focus{background:var(--card);border-color:var(--gold);color:var(--text);box-shadow:0 0 0 3px rgba(245,200,66,.12)}
.form-control::placeholder{color:var(--muted)}
.form-label{font-size:.8rem;color:var(--muted);margin-bottom:5px}

/* ── BUTTONS ── */
.btn-gold{background:var(--gold);color:#0a0a0a;font-weight:600;border:none;border-radius:8px}
.btn-gold:hover{background:#e0b435;color:#0a0a0a}
.btn-teal{background:rgba(45,212,191,.15);border:1px solid rgba(45,212,191,.35);color:var(--teal);font-weight:500;border-radius:8px}
.btn-teal:hover{background:rgba(45,212,191,.25);color:var(--teal)}
.btn-rose{background:rgba(251,113,133,.12);border:1px solid rgba(251,113,133,.3);color:var(--rose);border-radius:8px}
.btn-rose:hover{background:rgba(251,113,133,.22);color:var(--rose)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--muted);border-radius:8px}
.btn-ghost:hover{border-color:var(--gold);color:var(--gold)}

/* ── CARDS / PANELS ── */
.panel{background:var(--surf);border:1px solid var(--border);border-radius:var(--radius);padding:1.4rem;margin-bottom:1.4rem}
.section-title{font-family:'Syne',sans-serif;font-size:1.05rem;color:var(--text);margin-bottom:1.1rem}
.section-title small{font-family:'Outfit',sans-serif;font-size:.78rem;color:var(--muted);font-weight:400}

/* ── STAT CARDS ── */
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem 1.3rem}
.stat-val{font-family:'Syne',sans-serif;font-size:2rem;line-height:1}
.stat-lbl{font-size:.78rem;color:var(--muted);margin-top:4px}

/* ── BOOK CARDS ── */
.book-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;height:100%;transition:.18s;cursor:default}
.book-card:hover{border-color:var(--gold);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.35)}
.book-ttl{font-weight:600;font-size:.95rem;line-height:1.35;color:var(--text)}
.book-auth{font-size:.8rem;color:var(--muted);margin-top:3px}

/* ── BADGES ── */
.badge-in   {background:rgba(45,212,191,.12);color:var(--teal);border:1px solid rgba(45,212,191,.25);border-radius:20px;padding:2px 9px;font-size:.75rem;font-weight:600}
.badge-out  {background:rgba(251,113,133,.12);color:var(--rose);border:1px solid rgba(251,113,133,.25);border-radius:20px;padding:2px 9px;font-size:.75rem;font-weight:600}
.badge-due  {background:rgba(245,200,66,.1); color:var(--gold);border:1px solid rgba(245,200,66,.25);border-radius:20px;padding:2px 9px;font-size:.75rem;font-weight:600}
.badge-over {background:rgba(251,113,133,.2);color:var(--rose);border:1px solid rgba(251,113,133,.4);border-radius:20px;padding:2px 9px;font-size:.75rem;font-weight:600;animation:pulse 2s infinite}
.badge-ret  {background:rgba(167,139,250,.1);color:var(--violet);border:1px solid rgba(167,139,250,.25);border-radius:20px;padding:2px 9px;font-size:.75rem;font-weight:600}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

/* ── TABLE ── */
.tbl thead th{background:var(--card);color:var(--muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;border-color:var(--border);font-weight:600}
.tbl tbody td{border-color:var(--border);color:var(--text);vertical-align:middle;font-size:.87rem}
.tbl tbody tr:hover td{background:rgba(255,255,255,.015)}

/* ── DUE DATE PILL ── */
.due-soon{color:var(--gold)}
.due-over{color:var(--rose);font-weight:600}

/* ── MODAL ── */
.modal-content{background:var(--surf);border:1px solid var(--border);color:var(--text)}
.modal-header,.modal-footer{border-color:var(--border)}
.btn-close{filter:invert(.6)}

/* ── ALERT ── */
.flash{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.2rem;font-size:.88rem;display:flex;align-items:center;gap:.6rem}
.flash-success{background:rgba(45,212,191,.1);border:1px solid rgba(45,212,191,.3);color:var(--teal)}
.flash-danger {background:rgba(251,113,133,.1);border:1px solid rgba(251,113,133,.3);color:var(--rose)}

/* ── SEARCH ── */
.search-wrap{position:relative}
.search-wrap .bi{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
.search-wrap input{padding-left:2.1rem}

/* ── BORROW ROW ── */
.borrow-row{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:.8rem 1rem;margin-bottom:.6rem;display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}
.borrow-row.overdue{border-color:rgba(251,113,133,.4);background:rgba(251,113,133,.04)}

/* ── PROGRESS (days remaining) ── */
.days-bar{height:4px;border-radius:2px;background:var(--border);width:80px;display:inline-block;vertical-align:middle}
.days-fill{height:100%;border-radius:2px;transition:width .3s}

/* ── PILL TABS (auth) ── */
.auth-tabs .nav-link{color:var(--muted);border-radius:6px;font-size:.88rem}
.auth-tabs .nav-link.active{background:var(--gold);color:#0a0a0a;font-weight:600}
</style>
</head>
<body>

<?php if (!isset($_SESSION['user'])): ?>
<!-- ════════════════════════════════════════
     AUTH
════════════════════════════════════════ -->
<div class="auth-wrap">
<div class="auth-card">
  <div class="text-center mb-4">
    <div style="font-size:2.2rem">📚</div>
    <div class="auth-title mt-1">LibraryOS</div>
    <div style="color:var(--muted);font-size:.85rem;margin-top:4px">Book borrowing management system</div>
  </div>

  <?php if ($flash['msg']): ?>
    <div class="flash flash-<?= $flash['type'] ?>"><i class="bi bi-<?= $flash['type']==='success'?'check-circle':'exclamation-circle' ?>"></i><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <ul class="nav nav-pills auth-tabs mb-4 p-1 rounded" style="background:var(--card)">
    <li class="nav-item flex-fill"><button class="nav-link w-100 active" data-bs-toggle="pill" data-bs-target="#pLogin">Login</button></li>
    <li class="nav-item flex-fill"><button class="nav-link w-100" data-bs-toggle="pill" data-bs-target="#pReg">Register</button></li>
  </ul>
  <div class="tab-content">
    <div class="tab-pane fade show active" id="pLogin">
      <form method="POST">
        <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required autofocus></div>
        <div class="mb-4"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
        <button name="login" class="btn btn-gold w-100 py-2"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</button>
      </form>
      <div style="text-align:center;margin-top:1.2rem;font-size:.78rem;color:var(--muted)">
        Demo → <code style="color:var(--gold)">admin/admin123</code> &nbsp;|&nbsp; <code style="color:var(--teal)">user/user123</code>
      </div>
    </div>
    <div class="tab-pane fade" id="pReg">
      <form method="POST">
        <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
        <div class="mb-4"><label class="form-label">Password</label><input type="password" name="password" class="form-control" minlength="4" required></div>
        <button name="register" class="btn btn-ghost w-100 py-2"><i class="bi bi-person-plus me-2"></i>Create Account</button>
      </form>
    </div>
  </div>
</div>
</div>

<?php else:
$user = $_SESSION['user'];
$uid  = (int)$user['id'];
?>
<!-- ════════════════════════════════════════
     APP SHELL
════════════════════════════════════════ -->
<nav class="topbar">
  <div class="container-xl d-flex align-items-center justify-content-between">
    <a class="brand" href="index.php">Library<span>OS</span></a>
    <div class="d-flex align-items-center gap-3">
      <span style="font-size:.85rem;color:var(--muted)"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user['username']) ?></span>
      <?php if ($isAdmin): ?>
        <span style="background:rgba(245,200,66,.12);color:var(--gold);border:1px solid rgba(245,200,66,.3);border-radius:20px;padding:2px 10px;font-size:.75rem;font-weight:600">Admin</span>
      <?php else: ?>
        <span style="background:rgba(45,212,191,.1);color:var(--teal);border:1px solid rgba(45,212,191,.25);border-radius:20px;padding:2px 10px;font-size:.75rem;font-weight:600">User</span>
      <?php endif; ?>
      <a href="?logout" class="btn btn-sm btn-ghost"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
    </div>
  </div>
</nav>

<div class="container-xl py-4">
<?php if ($flash['msg']): ?>
  <div class="flash flash-<?= $flash['type'] ?>">
    <i class="bi bi-<?= $flash['type']==='success'?'check-circle-fill':'exclamation-circle-fill' ?>"></i>
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ══════════════════════════════════════
     ADMIN LAYOUT
══════════════════════════════════════ -->
<div class="row g-3">
  <!-- Sidebar nav -->
  <div class="col-lg-2 col-md-3">
    <div class="panel p-2">
      <nav class="nav flex-column side-tabs gap-1" id="adminNav">
        <a class="nav-link active" data-tab="dashboard"  href="#"><i class="bi bi-grid-1x2"></i>Dashboard</a>
        <a class="nav-link"        data-tab="borrows"    href="#"><i class="bi bi-bookmark-check"></i>Active Borrows
          <?php if ($stat_overdue > 0): ?><span class="badge-over ms-auto"><?= $stat_overdue ?></span><?php endif; ?>
        </a>
        <a class="nav-link"        data-tab="books"      href="#"><i class="bi bi-journals"></i>Books</a>
        <a class="nav-link"        data-tab="users"      href="#"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link"        data-tab="history"    href="#"><i class="bi bi-clock-history"></i>History</a>
        <a class="nav-link"        data-tab="analytics"  href="#"><i class="bi bi-bar-chart-line"></i>Analytics</a>
      </nav>
    </div>
  </div>

  <!-- Main content -->
  <div class="col-lg-10 col-md-9">

    <!-- ── DASHBOARD ── -->
    <div class="tab-section active" id="tab-dashboard">
      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-val" style="color:var(--gold)"><?= $stat_books ?></div>
            <div class="stat-lbl"><i class="bi bi-journals me-1"></i>Total Books</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-val" style="color:var(--teal)"><?= $stat_users ?></div>
            <div class="stat-lbl"><i class="bi bi-people me-1"></i>Registered Users</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-val" style="color:var(--violet)"><?= $stat_active ?></div>
            <div class="stat-lbl"><i class="bi bi-bookmark me-1"></i>Active Borrows</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="stat-val" style="color:var(--rose)"><?= $stat_overdue ?></div>
            <div class="stat-lbl"><i class="bi bi-exclamation-triangle me-1"></i>Overdue</div>
          </div>
        </div>
      </div>

      <!-- Quick: Overdue alert -->
      <?php if ($stat_overdue > 0):
        $od = $conn->query("SELECT u.username, bk.title, br.due_date,
                                   DATEDIFF(CURDATE(), br.due_date) AS days_late
                            FROM borrows br
                            JOIN users u  ON u.id  = br.user_id
                            JOIN books bk ON bk.id = br.book_id
                            WHERE br.status='overdue'
                            ORDER BY days_late DESC LIMIT 5");
      ?>
      <div class="panel" style="border-color:rgba(251,113,133,.4)">
        <div class="section-title" style="color:var(--rose)"><i class="bi bi-exclamation-triangle me-2"></i>Overdue Returns <small>(<?= $stat_overdue ?> book<?= $stat_overdue>1?'s':'' ?>)</small></div>
        <?php while ($o = $od->fetch_assoc()): ?>
          <div class="borrow-row overdue">
            <span class="badge-over"><i class="bi bi-exclamation me-1"></i>OVERDUE <?= $o['days_late'] ?>d</span>
            <strong><?= htmlspecialchars($o['title']) ?></strong>
            <span style="color:var(--muted);font-size:.85rem">→ <?= htmlspecialchars($o['username']) ?></span>
            <span style="color:var(--muted);font-size:.82rem;margin-left:auto">Due <?= $o['due_date'] ?></span>
          </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>

      <!-- Quick: due today / this week -->
      <?php
        $due_soon = $conn->query("
            SELECT br.id, br.due_date, u.username, bk.title,
                   DATEDIFF(br.due_date, CURDATE()) AS days_rem
            FROM borrows br
            JOIN users u  ON u.id  = br.user_id
            JOIN books bk ON bk.id = br.book_id
            WHERE br.status='active' AND br.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY br.due_date ASC
        ");
        if ($due_soon && $due_soon->num_rows > 0):
      ?>
      <div class="panel" style="border-color:rgba(245,200,66,.3)">
        <div class="section-title" style="color:var(--gold)"><i class="bi bi-clock me-2"></i>Due Within 3 Days</div>
        <?php while ($d = $due_soon->fetch_assoc()): ?>
          <div class="borrow-row">
            <span class="badge-due"><?= $d['days_rem'] == 0 ? 'TODAY' : 'in '.$d['days_rem'].'d' ?></span>
            <strong><?= htmlspecialchars($d['title']) ?></strong>
            <span style="color:var(--muted);font-size:.85rem">→ <?= htmlspecialchars($d['username']) ?></span>
            <span style="color:var(--muted);font-size:.82rem;margin-left:auto"><?= $d['due_date'] ?></span>
          </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>
    </div><!-- /dashboard -->

    <!-- ── ACTIVE BORROWS ── -->
    <div class="tab-section" id="tab-borrows">
      <div class="panel">
        <div class="section-title"><i class="bi bi-bookmark-check me-2"></i>Active & Overdue Borrows</div>
        <div class="table-responsive">
          <table class="table tbl mb-0">
            <thead><tr><th>User</th><th>Book</th><th>Borrowed</th><th>Due Date</th><th>Status</th><th>Days Left</th></tr></thead>
            <tbody>
            <?php
              if ($active_borrows && $active_borrows->num_rows > 0):
                while ($b = $active_borrows->fetch_assoc()):
                  $dr = (int)$b['days_remaining'];
                  $pct = max(0, min(100, ($dr / 7) * 100));
                  $bar_color = $dr < 0 ? 'var(--rose)' : ($dr <= 2 ? 'var(--gold)' : 'var(--teal)');
            ?>
              <tr>
                <td><i class="bi bi-person me-1" style="color:var(--muted)"></i><?= htmlspecialchars($b['username']) ?></td>
                <td><strong><?= htmlspecialchars($b['title']) ?></strong><div style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($b['author']) ?></div></td>
                <td style="font-size:.82rem;color:var(--muted)"><?= $b['borrowed_at'] ?></td>
                <td class="<?= $dr < 0 ? 'due-over' : ($dr <= 2 ? 'due-soon' : '') ?>"><?= $b['due_date'] ?></td>
                <td>
                  <?php if ($b['status']==='overdue'): ?>
                    <span class="badge-over">Overdue</span>
                  <?php else: ?>
                    <span class="badge-in">Active</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span style="font-size:.82rem;color:<?= $bar_color ?>"><?= $dr < 0 ? abs($dr).'d late' : $dr.'d left' ?></span>
                  <div class="days-bar ms-1"><div class="days-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div></div>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="6" class="text-center py-4" style="color:var(--muted)">No active borrows.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /borrows -->

    <!-- ── BOOKS CRUD ── -->
    <div class="tab-section" id="tab-books">
      <div class="panel">
        <div class="section-title"><i class="bi bi-plus-circle me-2"></i>Add New Book</div>
        <form method="POST" class="row g-2 align-items-end">
          <div class="col-md-4"><label class="form-label">Title</label><input name="title" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Author</label><input name="author" class="form-control" required></div>
          <div class="col-md-2"><label class="form-label">Genre</label><input name="genre" class="form-control" placeholder="Fiction…"></div>
          <div class="col-md-1"><label class="form-label">Qty</label><input name="quantity" type="number" class="form-control" value="1" min="1" required></div>
          <div class="col-md-2"><button name="add_book" class="btn btn-gold w-100 mt-1"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
        </form>
      </div>

      <div class="panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="section-title mb-0"><i class="bi bi-table me-2"></i>All Books</div>
          <div class="search-wrap" style="width:200px"><i class="bi bi-search" style="font-size:.85rem"></i><input id="bookTblSearch" class="form-control form-control-sm" placeholder="Search…"></div>
        </div>
        <div class="table-responsive">
          <table class="table tbl mb-0" id="bookTbl">
            <thead><tr><th>#</th><th>Title</th><th>Author</th><th>Genre</th><th>Qty</th><th>Added</th><th style="width:100px">Actions</th></tr></thead>
            <tbody>
            <?php
              $books_all = $conn->query("SELECT * FROM books ORDER BY title");
              if ($books_all && $books_all->num_rows > 0):
                while ($bk = $books_all->fetch_assoc()):
            ?>
              <tr>
                <td style="color:var(--muted)"><?= $bk['id'] ?></td>
                <td><strong><?= htmlspecialchars($bk['title']) ?></strong></td>
                <td><?= htmlspecialchars($bk['author']) ?></td>
                <td style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars($bk['genre'] ?? '—') ?></td>
                <td><span class="<?= $bk['quantity']>0?'badge-in':'badge-out' ?>"><?= $bk['quantity'] ?></span></td>
                <td style="font-size:.78rem;color:var(--muted)"><?= date('M d, Y', strtotime($bk['created_at'])) ?></td>
                <td>
                  <button class="btn btn-sm btn-ghost me-1"
                    data-bs-toggle="modal" data-bs-target="#editModal"
                    data-id="<?= $bk['id'] ?>" data-title="<?= htmlspecialchars($bk['title'],ENT_QUOTES) ?>"
                    data-author="<?= htmlspecialchars($bk['author'],ENT_QUOTES) ?>"
                    data-genre="<?= htmlspecialchars($bk['genre']??'',ENT_QUOTES) ?>"
                    data-qty="<?= $bk['quantity'] ?>"><i class="bi bi-pencil"></i></button>
                  <a href="?delete_book=<?= $bk['id'] ?>" class="btn btn-sm btn-rose"
                     onclick="return confirm('Delete this book and all its borrow records?')"><i class="bi bi-trash3"></i></a>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="7" class="text-center py-4" style="color:var(--muted)">No books yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /books -->

    <!-- ── USERS ── -->
    <div class="tab-section" id="tab-users">
      <div class="panel">
        <div class="section-title"><i class="bi bi-people me-2"></i>User Activity</div>
        <div class="table-responsive">
          <table class="table tbl mb-0">
            <thead><tr><th>Username</th><th>Total Borrows</th><th>Active</th><th>Overdue</th><th>Returned</th><th>Last Borrow</th></tr></thead>
            <tbody>
            <?php
              if ($user_stats && $user_stats->num_rows > 0):
                while ($us = $user_stats->fetch_assoc()):
            ?>
              <tr>
                <td><i class="bi bi-person me-1" style="color:var(--muted)"></i><strong><?= htmlspecialchars($us['username']) ?></strong></td>
                <td><span style="color:var(--violet);font-weight:600"><?= $us['total_borrows'] ?></span></td>
                <td><?= $us['active_count'] ?></td>
                <td><?= $us['overdue_count'] > 0 ? '<span class="badge-over">'.$us['overdue_count'].'</span>' : '0' ?></td>
                <td><?= $us['returned_count'] ?></td>
                <td style="font-size:.82rem;color:var(--muted)"><?= $us['last_borrow'] ? date('M d, Y H:i', strtotime($us['last_borrow'])) : '—' ?></td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="6" class="text-center py-4" style="color:var(--muted)">No users have borrowed yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /users -->

    <!-- ── HISTORY ── -->
    <div class="tab-section" id="tab-history">
      <div class="panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="section-title mb-0"><i class="bi bi-clock-history me-2"></i>Full Borrow History</div>
          <div class="search-wrap" style="width:200px"><i class="bi bi-search" style="font-size:.85rem"></i><input id="histSearch" class="form-control form-control-sm" placeholder="Search…"></div>
        </div>
        <div class="table-responsive">
          <table class="table tbl mb-0" id="histTbl">
            <thead><tr><th>User</th><th>Book</th><th>Borrowed At</th><th>Due Date</th><th>Returned At</th><th>Status</th></tr></thead>
            <tbody>
            <?php
              if ($borrow_log && $borrow_log->num_rows > 0):
                while ($bl = $borrow_log->fetch_assoc()):
            ?>
              <tr>
                <td><?= htmlspecialchars($bl['username']) ?></td>
                <td><?= htmlspecialchars($bl['title']) ?></td>
                <td style="font-size:.82rem;color:var(--muted)"><?= $bl['borrowed_at'] ?></td>
                <td style="font-size:.82rem"><?= $bl['due_date'] ?></td>
                <td style="font-size:.82rem;color:var(--muted)"><?= $bl['returned_at'] ?? '—' ?></td>
                <td>
                  <?php
                    $cls = match($bl['status']) {
                      'returned' => 'badge-ret',
                      'overdue'  => 'badge-over',
                      default    => 'badge-in'
                    };
                  ?>
                  <span class="<?= $cls ?>"><?= ucfirst($bl['status']) ?></span>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="6" class="text-center py-4" style="color:var(--muted)">No history yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /history -->

    <!-- ── ANALYTICS ── -->
    <div class="tab-section" id="tab-analytics">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="panel h-100">
            <div class="section-title"><i class="bi bi-trophy me-2"></i>Most Borrowed</div>
            <?php if ($top_books && $top_books->num_rows > 0):
              $rank = 1;
              while ($tb = $top_books->fetch_assoc()): ?>
              <div class="d-flex align-items-center gap-3 mb-3">
                <span style="font-family:'Syne',sans-serif;font-size:1.4rem;color:var(--border);min-width:28px"><?= $rank++ ?></span>
                <div class="flex-grow-1">
                  <div style="font-weight:600;font-size:.9rem"><?= htmlspecialchars($tb['title']) ?></div>
                  <div style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($tb['author']) ?></div>
                </div>
                <span style="font-family:'Syne',sans-serif;color:var(--gold);font-size:1.1rem"><?= $tb['borrow_count'] ?>×</span>
              </div>
            <?php endwhile; else: ?>
              <div style="color:var(--muted);font-size:.88rem;padding:1rem 0">No borrow data yet.</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="panel h-100">
            <div class="section-title"><i class="bi bi-journal-x me-2"></i>Never Borrowed</div>
            <?php if ($never_borrowed && $never_borrowed->num_rows > 0):
              while ($nb = $never_borrowed->fetch_assoc()): ?>
              <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background:var(--card)">
                <i class="bi bi-book" style="color:var(--muted)"></i>
                <div>
                  <div style="font-size:.88rem;font-weight:500"><?= htmlspecialchars($nb['title']) ?></div>
                  <div style="font-size:.76rem;color:var(--muted)"><?= htmlspecialchars($nb['author']) ?></div>
                </div>
                <span class="ms-auto" style="font-size:.78rem;color:var(--muted)"><?= $nb['quantity'] ?> in stock</span>
              </div>
            <?php endwhile; else: ?>
              <div style="color:var(--muted);font-size:.88rem;padding:1rem 0">All books have been borrowed at least once!</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div><!-- /analytics -->

  </div><!-- /main col -->
</div><!-- /admin row -->

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:'Syne',sans-serif"><i class="bi bi-pencil me-2"></i>Edit Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="book_id" id="eId">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Title</label><input name="title" id="eTitle" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Author</label><input name="author" id="eAuthor" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Genre</label><input name="genre" id="eGenre" class="form-control"></div>
          <div class="mb-1"><label class="form-label">Quantity</label><input name="quantity" id="eQty" type="number" class="form-control" min="0" required></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button name="edit_book" class="btn btn-gold"><i class="bi bi-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: /* ══ USER VIEW ══ */

  /* My current borrows */
  $my_borrows = $conn->query("
      SELECT br.id, br.borrowed_at, br.due_date, br.status,
             bk.title, bk.author,
             DATEDIFF(br.due_date, CURDATE()) AS days_rem
      FROM borrows br
      JOIN books bk ON bk.id = br.book_id
      WHERE br.user_id = $uid AND br.status IN ('active','overdue')
      ORDER BY br.due_date ASC
  ");
?>
<div class="row g-3">
  <div class="col-12">

    <!-- My Borrowed Books -->
    <?php if ($my_borrows && $my_borrows->num_rows > 0): ?>
    <div class="panel">
      <div class="section-title"><i class="bi bi-bookmark-check me-2"></i>My Borrowed Books</div>
      <div class="row g-2">
      <?php while ($mb = $my_borrows->fetch_assoc()):
        $dr = (int)$mb['days_rem'];
        $over = $mb['status']==='overdue';
      ?>
        <div class="col-sm-6 col-lg-4">
          <div class="p-3 rounded" style="background:var(--card);border:1px solid <?= $over ? 'rgba(251,113,133,.4)' : 'var(--border)' ?>">
            <div style="font-weight:600;font-size:.9rem;margin-bottom:3px"><?= htmlspecialchars($mb['title']) ?></div>
            <div style="font-size:.78rem;color:var(--muted);margin-bottom:.6rem"><?= htmlspecialchars($mb['author']) ?></div>
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
              <div>
                <div style="font-size:.75rem;color:var(--muted)">Due: <strong style="color:<?= $over?'var(--rose)':($dr<=2?'var(--gold)':'var(--text)') ?>"><?= $mb['due_date'] ?></strong></div>
                <?php if ($over): ?>
                  <span class="badge-over" style="margin-top:4px;display:inline-block"><?= abs($dr) ?>d overdue</span>
                <?php elseif ($dr == 0): ?>
                  <span class="badge-due" style="margin-top:4px;display:inline-block">Due today!</span>
                <?php elseif ($dr <= 2): ?>
                  <span class="badge-due" style="margin-top:4px;display:inline-block">Due in <?= $dr ?>d</span>
                <?php endif; ?>
              </div>
              <a href="?return_book=<?= $mb['id'] ?>" class="btn btn-sm btn-teal"
                 onclick="return confirm('Return \'<?= htmlspecialchars(addslashes($mb['title'])) ?>\'?')">
                <i class="bi bi-arrow-return-left me-1"></i>Return
              </a>
            </div>
          </div>
        </div>
      <?php endwhile; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Browse Books -->
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="section-title mb-0"><i class="bi bi-grid me-2"></i>Browse Books</div>
      <div class="search-wrap" style="width:220px"><i class="bi bi-search" style="font-size:.85rem"></i><input id="browseSearch" class="form-control" placeholder="Search…"></div>
    </div>
    <div class="row g-3" id="browseGrid">
    <?php
      $all_books = $conn->query("
          SELECT bk.*,
                 (SELECT COUNT(*) FROM borrows b WHERE b.book_id=bk.id AND b.user_id=$uid AND b.status IN ('active','overdue')) AS i_have_it
          FROM books bk ORDER BY title
      ");
      if ($all_books && $all_books->num_rows > 0):
        while ($bk = $all_books->fetch_assoc()):
    ?>
      <div class="col-sm-6 col-lg-4 browse-item">
        <div class="book-card">
          <?php if (!empty($bk['genre'])): ?>
            <div style="font-size:.72rem;color:var(--teal);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem"><?= htmlspecialchars($bk['genre']) ?></div>
          <?php endif; ?>
          <div class="book-ttl"><?= htmlspecialchars($bk['title']) ?></div>
          <div class="book-auth"><i class="bi bi-person me-1"></i><?= htmlspecialchars($bk['author']) ?></div>
          <div class="d-flex align-items-center justify-content-between mt-3 gap-2">
            <span class="<?= $bk['quantity']>0?'badge-in':'badge-out' ?>">
              <i class="bi bi-<?= $bk['quantity']>0?'check-circle':'x-circle' ?> me-1"></i>
              <?= $bk['quantity']>0 ? $bk['quantity'].' left' : 'Out of stock' ?>
            </span>
            <?php if ($bk['i_have_it']): ?>
              <span style="font-size:.78rem;color:var(--teal)"><i class="bi bi-check2 me-1"></i>Borrowed</span>
            <?php elseif ($bk['quantity'] > 0): ?>
              <a href="?borrow=<?= $bk['id'] ?>" class="btn btn-sm btn-gold"
                 onclick="return confirm('Borrow \'<?= htmlspecialchars(addslashes($bk['title'])) ?>\'?\nDue in 7 days.')">
                <i class="bi bi-book me-1"></i>Borrow
              </a>
            <?php else: ?>
              <button class="btn btn-sm btn-ghost" disabled>Unavailable</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endwhile; else: ?>
      <div class="col-12 text-center py-5" style="color:var(--muted)">
        <i class="bi bi-journal-x" style="font-size:2rem"></i><p class="mt-2">No books in the library yet.</p>
      </div>
    <?php endif; ?>
    </div><!-- /browseGrid -->
  </div>
</div>
<?php endif; /* end user view */ ?>
</div><!-- /container -->
<?php endif; /* end logged-in */ ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Admin sidebar tabs ── */
document.querySelectorAll('#adminNav .nav-link').forEach(link => {
  link.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('#adminNav .nav-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
    link.classList.add('active');
    document.getElementById('tab-' + link.dataset.tab).classList.add('active');
  });
});

/* ── Edit modal ── */
const em = document.getElementById('editModal');
if (em) {
  em.addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('eId').value     = b.dataset.id;
    document.getElementById('eTitle').value  = b.dataset.title;
    document.getElementById('eAuthor').value = b.dataset.author;
    document.getElementById('eGenre').value  = b.dataset.genre;
    document.getElementById('eQty').value    = b.dataset.qty;
  });
}

/* ── Live search helper ── */
function liveSearch(inputId, selector, cellGetter) {
  const el = document.getElementById(inputId);
  if (!el) return;
  el.addEventListener('input', () => {
    const q = el.value.toLowerCase();
    document.querySelectorAll(selector).forEach(row => {
      row.style.display = (cellGetter ? cellGetter(row) : row.textContent).toLowerCase().includes(q) ? '' : 'none';
    });
  });
}
liveSearch('bookTblSearch', '#bookTbl tbody tr');
liveSearch('histSearch',    '#histTbl tbody tr');
liveSearch('browseSearch',  '.browse-item');
liveSearch('adminSearch',   '.book-item');
</script>
</body>
</html>
