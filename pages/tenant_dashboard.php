<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/room_photo.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = (int) $_SESSION['user_id'];

$booking = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT b.*, r.room_number, r.room_type, r.floor, r.price_per_month, IFNULL(r.photo_path,'') AS room_photo_db,
           bu.name as building_name, bu.address, bu.city
    FROM booking b
    JOIN room r      ON b.room_id      = r.room_id
    JOIN building bu ON r.building_id  = bu.building_id
    WHERE b.tenant_id = $tenant_id
    ORDER BY b.booking_date DESC, b.booking_id DESC LIMIT 1
"));
$bk = is_array($booking) ? $booking : [];
$has_booking = isset($bk['room_number']) && $bk['room_number'] !== '';
$my_room_photo = $has_booking
    ? kostify_resolve_room_photo((string) $bk['room_number'], $bk['room_photo_db'] ?? '')
    : '';

$room_gallery_res = mysqli_query($conn, "
    SELECT r.room_id, r.room_number, r.room_type, r.floor, r.price_per_month, r.status, IFNULL(r.photo_path,'') AS photo_path,
           bu.name AS building_name, bu.city, bu.address
    FROM room r
    JOIN building bu ON r.building_id = bu.building_id
    ORDER BY bu.name, r.floor, r.room_number
");
$gallery_rows = [];
if ($room_gallery_res) {
    while ($gr = mysqli_fetch_assoc($room_gallery_res)) {
        $resolved = kostify_resolve_room_photo((string) $gr['room_number'], $gr['photo_path'] ?? '');
        if ($resolved === '') {
            continue;
        }
        $gr['photo_resolved'] = $resolved;
        $gallery_rows[] = $gr;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — My Dashboard</title>
    <link rel="stylesheet" href="../css/tenant_dashboard.css?v=<?= (int)@filemtime(__DIR__ . '/../css/tenant_dashboard.css') ?>">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php" class="active">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php">💳 Payments</a>
        <a href="tenant_maintenance.php">🔧 Maintenance</a>
        <a href="tenant_profile.php">👤 My Profile</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="user-info">
            <div class="user-avatar">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                <div class="user-role">Tenant</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="main">

    <div class="topbar">
        <div>
            <h1>My Dashboard</h1>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?>!</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <?php if (!empty($_GET['booked'])): ?>
    <div class="td-alert-success">✅ Your booking and payment have been saved. You can see your room details above.</div>
    <?php endif; ?>

    <?php if ($has_booking): ?>
    <div class="room-card">
        <?php if ($my_room_photo !== ''): ?>
        <div class="room-card-thumb">
            <img src="../<?= htmlspecialchars($my_room_photo) ?>" alt="Your room">
        </div>
        <?php endif; ?>
        <div class="room-card-text">
            <h2>
                Room <?= htmlspecialchars($bk['room_number']) ?> —
                <?= htmlspecialchars($bk['room_type'] ?? '') ?>
            </h2>

            <p>
                🏢 <?= htmlspecialchars($bk['building_name'] ?? '-') ?>,
                <?= htmlspecialchars($bk['city'] ?? '-') ?>
            </p>

            <p>
                📍 <?= htmlspecialchars($bk['address'] ?? '-') ?> ·
                Floor <?= htmlspecialchars((string)($bk['floor'] ?? '-')) ?>
            </p>

            <p style="margin-top:12px">
                <?php
                $st = strtolower(trim((string)($bk['status'] ?? 'active')));
                $allowed = ['active', 'pending', 'confirmed', 'completed', 'cancelled'];
                $badge_class = in_array($st, $allowed, true) ? $st : 'pending';
                ?>
                <span class="badge badge-<?= htmlspecialchars($badge_class) ?>">
                    <?= htmlspecialchars($bk['status'] ?? 'Active') ?>
                </span>
            </p>
        </div>

        <div class="room-card-pricing">
            <div class="room-price">
                Rp <?= isset($bk['price_per_month'])
                    ? number_format((float)$bk['price_per_month'], 0, ',', '.')
                    : '0' ?>
                <span>/mo</span>
            </div>

            <p style="margin-top:8px; font-size:13px; color:rgba(255,255,255,.6)">
                📅 <?= htmlspecialchars($bk['start_date'] ?? '-') ?> → <?= htmlspecialchars($bk['end_date'] ?? '-') ?>
            </p>
        </div>
    </div>

    <?php else: ?>
    <div class="no-booking">
        <h2 style="color:var(--dark);font-family:'Playfair Display',serif;font-size:22px;margin-bottom:8px">No active booking yet</h2>
        <p>Every tenant sees the same property gallery below. Open a room photo to view details and book an available room.</p>
        <p style="margin-top:16px"><a href="tenant_room.php" class="td-link-dashboard">Go to My Room</a></p>
    </div>
    <?php endif; ?>

    <section class="room-gallery-section card">
        <div class="card-head">
            <h3>Room photos</h3>
            <span class="room-gallery-sub">Tap a photo for full details</span>
        </div>
        <div class="room-gallery-body">
            <?php if (count($gallery_rows) > 0): ?>
                <div class="room-gallery-grid">
                    <?php foreach ($gallery_rows as $gr):
                        $modal = [
                            'room_id'         => (int)$gr['room_id'],
                            'room_number'     => $gr['room_number'],
                            'room_type'       => $gr['room_type'],
                            'floor'           => (int)$gr['floor'],
                            'price_per_month' => (float)$gr['price_per_month'],
                            'status'          => $gr['status'],
                            'building_name'   => $gr['building_name'],
                            'city'            => $gr['city'],
                            'address'         => $gr['address'],
                            'photo_path'      => $gr['photo_resolved'],
                        ];
                    ?>
                    <button type="button" class="room-gallery-tile" onclick='openRoomGalleryModal(<?= json_encode($modal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
                        <span class="room-gallery-img-wrap">
                            <img src="../<?= htmlspecialchars($gr['photo_resolved']) ?>" alt="Room <?= htmlspecialchars($gr['room_number']) ?>">
                            <?php
                            $gst_raw = strtolower(trim((string)($gr['status'] ?? '')));
                            $gst_slug = preg_replace('/[^a-z0-9]+/', '', $gst_raw);
                            if (!in_array($gst_slug, ['available', 'occupied', 'maintenance'], true)) {
                                $gst_slug = 'other';
                            }
                            ?>
                            <span class="room-gallery-pill room-gallery-pill--status room-gallery-pill--<?= htmlspecialchars($gst_slug) ?>" aria-hidden="true"><?= htmlspecialchars($gr['status']) ?></span>
                            <span class="room-gallery-pill room-gallery-pill--building" aria-hidden="true"><?= htmlspecialchars($gr['building_name']) ?></span>
                        </span>
                        <span class="room-gallery-meta">
                            <span class="room-gallery-subline">Room <?= htmlspecialchars($gr['room_number']) ?></span>
                            <span class="room-gallery-price">Rp <?= number_format((float)$gr['price_per_month'], 0, ',', '.') ?><span class="room-gallery-price-suffix">/mo</span></span>
                        </span>
                    </button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="room-gallery-empty">No room photos yet. Add images under <code>roompics/</code> (e.g. <code>room 101.jpg</code>) or set a photo in admin Rooms.</p>
            <?php endif; ?>
        </div>
    </section>

</div>

<!-- Room detail modal: same layout idea as admin Edit Room, read-only -->
<div class="td-modal-backdrop" id="roomGalleryModal" aria-hidden="true">
    <div class="td-room-modal" role="dialog" aria-labelledby="roomModalTitle" aria-modal="true">
        <div class="td-room-modal-header">
            <h3 id="roomModalTitle">Room details</h3>
            <button type="button" class="td-room-modal-close" onclick="closeRoomGalleryModal()" aria-label="Close">✕</button>
        </div>
        <div class="td-room-modal-body">
            <div class="td-form-group">
                <label>Building</label>
                <div class="td-readonly" id="rgBuilding"></div>
            </div>
            <div class="td-form-group">
                <label>Address</label>
                <div class="td-readonly" id="rgAddress"></div>
            </div>
            <div class="td-form-row">
                <div class="td-form-group">
                    <label>Room number</label>
                    <div class="td-readonly" id="rgRoomNum"></div>
                </div>
                <div class="td-form-group">
                    <label>Floor</label>
                    <div class="td-readonly" id="rgFloor"></div>
                </div>
            </div>
            <div class="td-form-group">
                <label>Room type</label>
                <div class="td-readonly" id="rgType"></div>
            </div>
            <div class="td-form-row">
                <div class="td-form-group">
                    <label>Price / month (Rp)</label>
                    <div class="td-readonly" id="rgPrice"></div>
                </div>
                <div class="td-form-group">
                    <label>Status</label>
                    <div class="td-readonly" id="rgStatus"></div>
                </div>
            </div>
            <div class="td-form-group td-book-wrap" id="rgBookWrap" hidden>
                <a class="td-btn-book" id="rgBookLink" href="#">Book room</a>
            </div>
            <div class="td-form-group">
                <label>Room photo</label>
                <p class="td-readonly-hint">Photo provided by your property manager.</p>
                <div class="td-readonly-photo-wrap">
                    <img src="" alt="" id="rgModalImg" class="td-readonly-photo">
                </div>
            </div>
        </div>
        <div class="td-room-modal-footer">
            <button type="button" class="td-btn-modal-close" onclick="closeRoomGalleryModal()">Close</button>
        </div>
    </div>
</div>

<script>
function openRoomGalleryModal(d) {
    document.getElementById('roomModalTitle').textContent = 'Room details';
    var bline = (d.building_name || '—') + (d.city ? ' (' + d.city + ')' : '');
    document.getElementById('rgBuilding').textContent = bline;
    document.getElementById('rgAddress').textContent = d.address || '—';
    document.getElementById('rgRoomNum').textContent = d.room_number || '—';
    document.getElementById('rgFloor').textContent = d.floor != null ? String(d.floor) : '—';
    document.getElementById('rgType').textContent = d.room_type || '—';
    document.getElementById('rgPrice').textContent = 'Rp ' + Number(d.price_per_month || 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    document.getElementById('rgStatus').textContent = d.status || '—';
    var img = document.getElementById('rgModalImg');
    if (d.photo_path) {
        img.src = '../' + d.photo_path;
        img.style.display = '';
    } else {
        img.removeAttribute('src');
        img.style.display = 'none';
    }
    img.alt = 'Room ' + (d.room_number || '');
    var bw = document.getElementById('rgBookWrap');
    var bl = document.getElementById('rgBookLink');
    if (String(d.status || '').toLowerCase() === 'available' && d.room_id) {
        bw.hidden = false;
        bl.href = 'tenant_booking.php?room_id=' + encodeURIComponent(d.room_id);
    } else {
        bw.hidden = true;
        bl.removeAttribute('href');
    }
    var m = document.getElementById('roomGalleryModal');
    m.classList.add('open');
    m.setAttribute('aria-hidden', 'false');
}
function closeRoomGalleryModal() {
    var m = document.getElementById('roomGalleryModal');
    m.classList.remove('open');
    m.setAttribute('aria-hidden', 'true');
    var im = document.getElementById('rgModalImg');
    im.removeAttribute('src');
    im.style.display = '';
    document.getElementById('rgBookWrap').hidden = true;
}
document.getElementById('roomGalleryModal').addEventListener('click', function (e) {
    if (e.target === this) { closeRoomGalleryModal(); }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeRoomGalleryModal();
});
</script>

</body>
</html>