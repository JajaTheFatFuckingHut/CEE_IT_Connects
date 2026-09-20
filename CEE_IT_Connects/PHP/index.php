<?php
// session_set_cookie_params([
//     'lifetime' => 0,
//     'path' => '/',
//     'secure' => true,
//     'httponly' => true,
//     'samesite' => 'Lax',
// ]);
require 'db.php';
require 'auth.php';
$stmt = $pdo->query("SELECT * FROM internships");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page = 'home';
date_default_timezone_set('Asia/Manila');
$now = new DateTime();


// $userId = $_SESSION['user_id'];
// $role = strtolower(trim($_SESSION['role']));
// if ($role === 'student') {
//     $stmt = $pdo->prepare("SELECT id FROM students WHERE id = :id");
//     $stmt->execute(['id' => $userId]);
//     if ($stmt->fetch()) {
//         header('Location: index.php');
//         exit;
//     }
// }
// // ADVISER
// elseif ($role === 'hte_adviser') {
//     $stmt = $pdo->prepare("SELECT id FROM advisers WHERE id = :id");
//     $stmt->execute(['id' => $userId]);
//     if ($stmt->fetch()) {
//         header('Location: hte-ui.php');
//         exit;
//     }
// } elseif ($role === 'internship_adviser') {
//     $stmt = $pdo->prepare("SELECT id FROM advisers WHERE id = :id");
//     $stmt->execute(['id' => $userId]);
//     if ($stmt->fetch()) {
//         header('Location: ojt-rooms.php');
//         exit;
//     }
// }

// // ADMIN
// elseif ($role === 'superadmin') {
//     $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = :id");
//     $stmt->execute(['id' => $userId]);
//     if ($stmt->fetch()) {
//         header('Location: superadmin.php');
//         exit;
//     }
// } elseif ($role === 'internship_admin') {
//     $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = :id");
//     $stmt->execute(['id' => $userId]);
//     if ($stmt->fetch()) {
//         header('Location: internship-ui.php');
//         exit;
//     }
// }

// session_unset();
// session_destroy();
// header('Location: index.php');
// exit;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | CEE IT Connects</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../CSS/index-style.css">

    <style>
        body {
            margin: 0;
            background:
                linear-gradient(90deg,
                    rgba(255, 182, 47, 0.85) 10%,
                    rgba(228, 87, 46, 0.85) 70%),
                url("../Sources/CEIT Building facade.png");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .listing {
            position: relative;
        }

        .main {
            display: flex;
            width: 100%;
        }

        .panel {
            width: 40%;
        }

        #map {
            width: 60%;
            height: 542px;
        }

        .phone-wrap {
            position: relative;
            display: inline-block;
        }

        .phone-dropdown {
            display: none;
            position: fixed;
            /* positioned by JS, relative to the viewport */
            z-index: 10000;
            min-width: 140px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
            padding: 4px 0;
        }

        .phone-dropdown.show {
            display: block;
        }

        .phone-dropdown a {
            display: block;
            padding: 8px 14px;
            white-space: nowrap;
            text-decoration: none;
            color: #222;
        }

        .phone-dropdown a:hover {
            background: #f2f2f2;
        }

        .phone-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            z-index: 9999;
        }

        .phone-backdrop.show {
            display: block;
        }

        .phone-backdrop.show {
            display: block;
        }
    </style>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <!-- HERO SECTION -->
    <div class="hero-wrapper">
        <div class="container-fluid px-5">
            <div class="row align-items-center">
                <div class="col-lg-9 hero-content">
                    <div class="welcome-overlay animate-on-scroll animate-scale">
                        <h5 class="welcome-text animate-on-scroll animate-left">Welcome to</h5>
                        <h1 class="main-heading animate-on-scroll animate-right">CEE IT Connects</h1>
                        <div class="description-box animate-on-scroll animate-scale">
                            <p>
                                &nbsp;&nbsp;&nbsp;&nbsp;CEE IT Connects is a web-based internship coordination platform
                                developed for the College of Engineering and Information Technology
                                (CEIT) of Pamantasan ng Lungsod ng Valenzuela (PLV). Designed to
                                streamline and modernize the On-the-Job Training (OJT) process,
                                CEE IT Connects bridges the gap between students, internship
                                advisers, Human Training Establishment (HTE) advisers, and
                                partner institutions.
                            </p>
                        </div>
                        <a href="applied-Internship-programs.php" class="btn-find animate-on-scroll animate-scale"
                            style="color:white; background-color: #ff673a;">
                            Browse for Internships
                        </a>
                    </div>
                </div>
                <div class="col-lg-6"></div>
            </div>
        </div>
        <img src="../Sources/suhay husay.png" class="statue-overlay animate-on-scroll animate-right">
    </div>

    <!-- FEATURES -->
    <section class="features-section">
        <div class="container text-center">
            <h2 class="features-title animate-on-scroll animate-left"
                style="font-family: 'Geogrotesque TRIAL', sans-serif; color: #ff673a; font-size: 3rem;">
                CEE IT Connects Features
            </h2>
            <div class="row g-5 mt-2">
                <div class="col-md-4">
                    <div class="feature-item animate-on-scroll animate-scale">
                        <i class="fa-solid fa-filter feature-icon"></i>
                        <h5>Smart Internship Matching</h5>
                        <p>Find internship opportunities tailored to your program, qualifications,
                            and preferred location through powerful filtering and automated listings.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-item animate-on-scroll animate-scale">
                        <i class="fa-regular fa-clock feature-icon"></i>
                        <h5>Real-Time Application Tracking</h5>
                        <p>Track your application status from submission to approval with
                            instant updates and automated notifications.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-item animate-on-scroll animate-scale">
                        <i class="fa-regular fa-rectangle-list feature-icon"></i>
                        <h5>Virtual Rooms & OJT Monitoring</h5>
                        <p>Advisers and HTE supervisors can monitor progress, evaluations,
                            and internship logs through a structured virtual workspace.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- MAP SECTION -->
    <section class="map-section">
        <div class="map-section-wrapper">
            <div class="main">
                <div class="panel">

                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search for an internship listing"
                            onkeyup="filterListings()">
                    </div>

                    <?php foreach ($locations as $loc):
                        $openTime = new DateTime($loc['time_open']);
                        $closeTime = new DateTime($loc['time_close']);
                        $status = ($now >= $openTime && $now <= $closeTime) ? 'OPEN' : 'CLOSED';
                        $statusClass = strtolower($status);
                        ?>
                        <div class="listing" data-title="<?= strtolower(htmlspecialchars($loc['title'])) ?>"
                            data-id="<?= $loc['id'] ?>">
                            <div class="listing-header">
                                <h3>
                                    <?= htmlspecialchars($loc['title']) ?>
                                    <span class="status <?= $statusClass ?>"
                                        data-hours="<?= $openTime->format('H:i') ?>-<?= $closeTime->format('H:i') ?>">
                                        <?= $status ?>
                                    </span>
                                </h3>
                            </div>
                            <p>Opens daily: <?= $openTime->format('h:i A') ?> - <?= $closeTime->format('h:i A') ?></p>
                            <p class="distance">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= htmlspecialchars($loc['location']) ?>
                            </p>
                            <p><?= htmlspecialchars($loc['address'] ?? $loc['location']) ?></p>
                            <div class="icons">
                                <i class="fas fa-phone"
                                    onclick="toggleNumbers(event, this, '<?= htmlspecialchars($loc['phone_numbers'], ENT_QUOTES) ?>')">
                                </i>
                                <i class="fas fa-location-arrow"
                                    onclick="getDirections(<?= $loc['latitude'] ?>, <?= $loc['longtitude'] ?>)">
                                </i>
                            </div>

                        </div>
                    <?php endforeach; ?>

                </div>
                <div id="map"></div>
            </div>
        </div>
    </section>

    <!-- ABOUT -->
    <section class="about-section">
        <div class="container text-center">
            <h2 class="about-title animate-on-scroll animate-left">About CEE IT Connects</h2>
            <p class="about-text animate-on-scroll animate-right">
                &nbsp;&nbsp;&nbsp;&nbsp;CEE IT Connects is an internship coordination platform developed
                to support students from the College of Engineering and Information
                Technology of Pamantasan ng Lungsod ng Valenzuela. The system
                simplifies the internship process by connecting students,
                advisers, and partner companies through a centralized digital platform.
            </p>
        </div>
        <div class="about-info-bar">
            <div class="container">
                <div class="row text-center align-items-center">
                    <div class="col-md-4" style="font-size: 1.15rem;"><i class="fas fa-map-marker-alt"></i> Tongco St.,
                        Maysan, Valenzuela City
                        <br><i class="fab fa-facebook"></i> CEE IT Connects
                    </div>
                    <div class="col-md-4" style="color: #fff; font-weight: bolder; font-size: 1.25rem;"><strong>CEE
                            IT CONNECTS</strong>
                        <br>©2026 CEE IT Connects. All rights reserved
                    </div>
                    <div class="col-md-4" style="font-size: 1.15rem;"><i class="fas fa-envelope"></i>
                        ceeitconnects@gmail.com
                        <br><i class="fas fa-phone"></i> 09123456789
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div id="phoneBackdrop" class="phone-backdrop"></div>
    <div id="phoneDropdown" class="phone-dropdown"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

    <script>
        let map;
        let markers = {};


        // ========================================
        // CUSTOM LEAFLET MARKER ICONS
        // ========================================

        const greenIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',

            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34],
            shadowSize: [41, 41]
        });

        const redIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',

            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34],
            shadowSize: [41, 41]
        });


        // ========================================
        // INITIALIZE MAP
        // ========================================

        function initMap() {

            console.log("Initializing Leaflet map...");

            // Check that Leaflet loaded
            if (typeof L === 'undefined') {
                console.error("Leaflet is NOT loaded.");
                return;
            }

            // Check map container
            const mapElement = document.getElementById('map');

            if (!mapElement) {
                console.error("Map element #map was not found.");
                return;
            }


            // Create map
            map = L.map('map').setView(
                [14.70, 120.98],
                10
            );


            // OpenStreetMap tiles
            L.tileLayer(
                'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                {
                    maxZoom: 19,

                    attribution:
                        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }
            ).addTo(map);


            // ========================================
            // GET PHP LOCATIONS
            // ========================================

            const locations = <?php echo json_encode($locations); ?>;

            console.log("Locations:", locations);


            const now = new Date();


            // ========================================
            // CREATE MARKERS
            // ========================================

            locations.forEach(function (loc) {

                const lat = parseFloat(loc.latitude);
                const lng = parseFloat(loc.longtitude);


                // Skip invalid coordinates
                if (isNaN(lat) || isNaN(lng)) {

                    console.warn(
                        "Invalid coordinates for:",
                        loc.title,
                        loc.latitude,
                        loc.longtitude
                    );

                    return;
                }


                // ========================================
                // CALCULATE OPEN / CLOSED
                // ========================================

                const openTime = new Date();
                const closeTime = new Date();


                const openParts = loc.time_open.split(':');
                const closeParts = loc.time_close.split(':');


                openTime.setHours(
                    parseInt(openParts[0]),
                    parseInt(openParts[1]),
                    0,
                    0
                );


                closeTime.setHours(
                    parseInt(closeParts[0]),
                    parseInt(closeParts[1]),
                    0,
                    0
                );


                const isOpen =
                    now >= openTime &&
                    now <= closeTime;


                // ========================================
                // SELECT MARKER ICON
                // ========================================

                const markerIcon =
                    isOpen ? greenIcon : redIcon;


                // ========================================
                // CREATE MARKER
                // ========================================

                const marker = L.marker(
                    [lat, lng],
                    {
                        icon: markerIcon,
                        title: loc.title
                    }
                ).addTo(map);


                // Store marker
                markers[loc.id] = marker;

                const statusText =
                    isOpen ? 'OPEN' : 'CLOSED';


                const statusColor =
                    isOpen ? '#198754' : '#dc3545';


                const popupContent = `
            <div div style = "min-width:180px;" >
                
                <strong>
                    ${escapeHtml(loc.title)}
                </strong>

                <br>

                ${escapeHtml(loc.company)}

                <br>

                ${escapeHtml(loc.location)}

                <br><br>

                <span style="
                    display:inline-block;
                    padding:3px 8px;
                    border-radius:4px;
                    background:${statusColor};
                    color:white;
                    font-size:12px;
                    font-weight:bold;
                ">
                    ${statusText}
                </span>

            </div>
        `;


                marker.bindPopup(
                    popupContent
                );

            });


            // ========================================
            // FIX LEAFLET MAP SIZE
            // ========================================

            setTimeout(function () {

                map.invalidateSize();

            }, 300);


            console.log("Leaflet map initialized successfully.");

        }


        // ========================================
        // ESCAPE HTML
        // ========================================

        function escapeHtml(text) {

            if (text === null || text === undefined) {
                return '';
            }

            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

        }


        // ========================================
        // INITIALIZE AFTER PAGE LOAD
        // ========================================

        document.addEventListener(
            'DOMContentLoaded',
            function () {

                initMap();

            }
        );


        function filterListings() {
            const input = document.getElementById('searchInput').value.toLowerCase();
            document.querySelectorAll('.listing').forEach(listing => {
                listing.style.display = listing.dataset.title.includes(input) ? 'block' : 'none';
            });
        }

        const phoneDropdown = document.getElementById('phoneDropdown');
        const phoneBackdrop = document.getElementById('phoneBackdrop');
        let phoneAnchor = null;

        function closePhoneDropdown() {
            phoneDropdown.classList.remove('show');
            phoneBackdrop.classList.remove('show');
            phoneAnchor = null;
        }

        function toggleNumbers(event, iconEl, numbersStr) {
            event.stopPropagation();

            // clicking the same icon again closes it
            if (phoneAnchor === iconEl) {
                closePhoneDropdown();
                return;
            }

            const numbers = numbersStr
                .split(/\s*[,;]\s*|\s+\/\s+/)
                .map(n => n.trim())
                .filter(Boolean);

            phoneDropdown.replaceChildren(...numbers.map(num => {
                const a = document.createElement('a');
                a.href = 'tel:' + num.replace(/[^\d+]/g, '');
                a.textContent = num;
                return a;
            }));

            // show first so it can be measured, then position
            phoneDropdown.classList.add('show');
            phoneBackdrop.classList.add('show');
            phoneAnchor = iconEl;

            const r = iconEl.getBoundingClientRect();
            const d = phoneDropdown.getBoundingClientRect();

            let top = r.top - d.height - 8;                 // prefer above the icon
            if (top < 8) top = r.bottom + 8;                // flip below if no room
            const left = Math.max(8, Math.min(r.left, window.innerWidth - d.width - 8));

            phoneDropdown.style.top = top + 'px';
            phoneDropdown.style.left = left + 'px';
        }

        // close on outside click, scroll (including the panel's), or resize
        document.addEventListener('click', closePhoneDropdown);
        window.addEventListener('scroll', closePhoneDropdown, true);
        window.addEventListener('resize', closePhoneDropdown);

        function copyNumber(number) {
            navigator.clipboard.writeText(number);
        }

        function getDirections(lat, lng) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(pos => {
                    const userLat = pos.coords.latitude;
                    const userLng = pos.coords.longitude;
                    window.open(
                        `https://www.google.com/maps/dir/?api=1&origin=${userLat},${userLng}&destination=${lat},${lng}`,
                        '_blank'
                    );
                }, () => alert("Cannot get your location for directions."));
            } else {
                alert("Geolocation not supported by your browser");
            }
        }

        // Scroll animations
        document.addEventListener('DOMContentLoaded', () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) entry.target.classList.add('visible');
                    else entry.target.classList.remove('visible');
                });
            }, { threshold: 0.2 });

            document.querySelectorAll('.animate-on-scroll').forEach(el => observer.observe(el));
        });
    </script>
</body>

</html>