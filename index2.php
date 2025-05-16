<?php
// Islamic History App - Single PHP File Implementation

// Database Configuration
define('DB_NAME', 'islamic_history.db');

// Initialize SQLite Database
function initDB() {
    if (!file_exists(DB_NAME)) {
        $db = new SQLite3(DB_NAME);
        
        // Create tables
        $db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password TEXT,
            role TEXT,
            language TEXT DEFAULT "en",
            points INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        $db->exec('CREATE TABLE events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            description TEXT,
            category TEXT,
            year INTEGER,
            month INTEGER,
            day INTEGER,
            latitude REAL,
            longitude REAL,
            ayah_ref TEXT,
            hadith_ref TEXT,
            created_by INTEGER,
            approved BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by) REFERENCES users(id)
        )');
        
        $db->exec('CREATE TABLE ayahs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            surah TEXT,
            ayah INTEGER,
            text TEXT,
            translation_en TEXT,
            translation_ur TEXT,
            context TEXT
        )');
        
        $db->exec('CREATE TABLE hadiths (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            collection TEXT,
            book TEXT,
            number TEXT,
            text TEXT,
            translation_en TEXT,
            translation_ur TEXT,
            grade TEXT,
            context TEXT
        )');
        
        $db->exec('CREATE TABLE bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            event_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(event_id) REFERENCES events(id)
        )');
        
        $db->exec('CREATE TABLE badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            description TEXT,
            points_required INTEGER
        )');
        
        $db->exec('CREATE TABLE user_badges (
            user_id INTEGER,
            badge_id INTEGER,
            earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id, badge_id),
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(badge_id) REFERENCES badges(id)
        )');
        
        // Create admin user
        $stmt = $db->prepare('INSERT INTO users (username, password, role) VALUES (:username, :password, :role)');
        $stmt->bindValue(':username', 'admin', SQLITE3_TEXT);
        $stmt->bindValue(':password', password_hash('admin123', PASSWORD_DEFAULT), SQLITE3_TEXT);
        $stmt->bindValue(':role', 'admin', SQLITE3_TEXT);
        $stmt->execute();
        
        // Insert sample badges
        $badges = [
            ['History Explorer', 'Visited 10 historical sites', 100],
            ['Quran Scholar', 'Linked 5 Quranic verses to events', 200],
            ['Hadith Expert', 'Contributed 5 authenticated hadiths', 200],
            ['Community Leader', 'Started 10 discussions', 150]
        ];
        
        foreach ($badges as $badge) {
            $stmt = $db->prepare('INSERT INTO badges (name, description, points_required) VALUES (?, ?, ?)');
            $stmt->bindValue(1, $badge[0], SQLITE3_TEXT);
            $stmt->bindValue(2, $badge[1], SQLITE3_TEXT);
            $stmt->bindValue(3, $badge[2], SQLITE3_INTEGER);
            $stmt->execute();
        }
        loadQuranAyahs($db);
        //loadHadiths($db);
        $db->close();
    }
}

// Initialize the database
initDB();

// Connect to database
$db = new SQLite3(DB_NAME);

// Session management
session_start();

// Language management
$current_lang = 'en';
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = in_array($_GET['lang'], ['en', 'ur']) ? $_GET['lang'] : 'en';
}
if (isset($_SESSION['lang'])) {
    $current_lang = $_SESSION['lang'];
}
function loadQuranAyahs($db) {
    $dataFile = 'data.AM';
    
    // Check if ayahs already exist in database
    $ayahCount = $db->querySingle("SELECT COUNT(*) FROM ayahs");
    if ($ayahCount > 0) {
        return; // Ayahs already loaded, skip
    }
    
    // Check if data file exists
    if (!file_exists($dataFile)) {
        error_log("Quran data file ($dataFile) not found");
        return;
    }
    
    // Read and parse the data file
    $lines = file($dataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) {
        error_log("Quran data file is empty");
        return;
    }
    
    // Prepare SQL statement for insertion
    $stmt = $db->prepare("INSERT INTO ayahs (surah, ayah, text, translation_ur) VALUES (?, ?, ?, ?)");
    
    // Begin transaction for faster bulk insert
    $db->exec('BEGIN TRANSACTION');
    
    try {
        foreach ($lines as $line) {
            // Parse each line according to the specified format
            $parts = explode(' ترجمہ: ', $line);
            if (count($parts) !== 2) continue;
            
            $arabic = $parts[0];
            $remaining = $parts[1];
            
            // Extract Urdu translation and metadata
            $translationParts = explode('<br/>س ', $remaining);
            if (count($translationParts) !== 2) continue;
            
            $urdu = $translationParts[0];
            $metaParts = explode(' آ ', $translationParts[1]);
            if (count($metaParts) !== 2) continue;
            
            $surah = ltrim($metaParts[0], '0');
            $ayah = ltrim($metaParts[1], '0');
            
            // Insert into database
            $stmt->bindValue(1, $surah, SQLITE3_INTEGER);
            $stmt->bindValue(2, $ayah, SQLITE3_INTEGER);
            $stmt->bindValue(3, trim($arabic), SQLITE3_TEXT);
            $stmt->bindValue(4, trim($urdu), SQLITE3_TEXT);
            $stmt->execute();
        }
        
        $db->exec('COMMIT');
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        error_log("Error loading Quran ayahs: " . $e->getMessage());
    }
}

function loadHadiths($db) {
    $dataFile = 'datah.am';
    
    // Check if hadiths already exist in database
    $hadithCount = $db->querySingle("SELECT COUNT(*) FROM hadiths");
    if ($hadithCount > 0) {
        return; // Hadiths already loaded, skip
    }
    
    // Check if data file exists
    if (!file_exists($dataFile)) {
        error_log("Hadith data file ($dataFile) not found");
        return;
    }
    
    // Read and parse the data file
    $lines = file($dataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) {
        error_log("Hadith data file is empty");
        return;
    }
    
    // Prepare SQL statement for insertion
    $stmt = $db->prepare("INSERT INTO hadiths (text, translation_ur, collection, book, number, grade) VALUES (?, ?, ?, ?, ?, ?)");
    
    // Begin transaction for faster bulk insert
    $db->exec('BEGIN TRANSACTION');
    
    try {
        foreach ($lines as $line) {
            // Parse each line according to the specified format
            $parts = explode(' ترجمہ: ', $line);
            if (count($parts) !== 2) continue;
            
            $arabic = trim($parts[0]);
            $urdu = trim($parts[1]);
            
            // Insert into database with default values for other fields
            $stmt->bindValue(1, "", SQLITE3_TEXT);
            $stmt->bindValue(2, "", SQLITE3_TEXT);
            $stmt->bindValue(3, $arabic, SQLITE3_TEXT); // Default collection
            $stmt->bindValue(4, $urdu, SQLITE3_TEXT);       // Default book
            $stmt->bindValue(5, '', SQLITE3_TEXT);            // Default number
            $stmt->bindValue(6, '', SQLITE3_TEXT);        // Default grade
            $stmt->execute();
        }
        
        $db->exec('COMMIT');
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        error_log("Error loading Hadiths: " . $e->getMessage());
    }
}
// Translations
$translations = [
    'en' => [
        'title' => 'Islamic History Explorer',
        'login' => 'Login',
        'register' => 'Register',
        'logout' => 'Logout',
        'username' => 'Username',
        'password' => 'Password',
        'role' => 'Role',
        'admin_panel' => 'Admin Panel',
        'events' => 'Historical Events',
        'map' => 'Interactive Map',
        'timeline' => 'Timeline',
        'quran' => 'Quranic Verses',
        'hadith' => 'Hadiths',
        'add_event' => 'Add Event',
        'approve_events' => 'Approve Events',
        'manage_users' => 'Manage Users',
        'manage_content' => 'Manage Content',
        'backup' => 'Backup Database',
        'search' => 'Search',
        'filter' => 'Filter',
        'category' => 'Category',
        'islamic' => 'Islamic',
        'general' => 'General',
        'title' => 'Title',
        'description' => 'Description',
        'year' => 'Year',
        'location' => 'Location',
        'submit' => 'Submit',
        'bookmark' => 'Bookmark',
        'view_details' => 'View Details',
        'related_ayahs' => 'Related Quranic Verses',
        'related_hadiths' => 'Related Hadiths',
        'dashboard' => 'Dashboard',
        'points' => 'Points',
        'badges' => 'Badges',
        'welcome' => 'Welcome',
        'profile' => 'Profile',
        'settings' => 'Settings',
        'language' => 'Language'
    ],
    'ur' => [
        'title' => 'اسلامی تاریخ کا جائزہ',
        'login' => 'لاگ ان',
        'register' => 'رجسٹر کریں',
        'logout' => 'لاگ آؤٹ',
        'username' => 'صارف نام',
        'password' => 'پاس ورڈ',
        'role' => 'کردار',
        'admin_panel' => 'ایڈمن پینل',
        'events' => 'تاریخی واقعات',
        'map' => 'انٹرایکٹو نقشہ',
        'timeline' => 'ٹائم لائن',
        'quran' => 'قرآنی آیات',
        'hadith' => 'احادیث',
        'add_event' => 'واقعہ شامل کریں',
        'approve_events' => 'واقعات کی منظوری',
        'manage_users' => 'صارفین کا انتظام',
        'manage_content' => 'مواد کا انتظام',
        'backup' => 'ڈیٹا بیس کا بیک اپ',
        'search' => 'تلاش',
        'filter' => 'فلٹر',
        'category' => 'زمرہ',
        'islamic' => 'اسلامی',
        'general' => 'عام',
        'title' => 'عنوان',
        'description' => 'تفصیل',
        'year' => 'سال',
        'location' => 'مقام',
        'submit' => 'جمع کروائیں',
        'bookmark' => 'بک مارک',
        'view_details' => 'تفصیلات دیکھیں',
        'related_ayahs' => 'متعلقہ قرآنی آیات',
        'related_hadiths' => 'متعلقہ احادیث',
        'dashboard' => 'ڈیش بورڈ',
        'points' => 'پوائنٹس',
        'badges' => 'بیجز',
        'welcome' => 'خوش آمدید',
        'profile' => 'پروفائل',
        'settings' => 'ترتیبات',
        'language' => 'زبان'
    ]
];

function t($key) {
    global $translations, $current_lang;
    return $translations[$current_lang][$key] ?? $key;
}

// Authentication functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['user_role'] ?? 'public';
}

function hasPermission($requiredRole) {
    $userRole = getUserRole();
    $roles = ['public' => 0, 'user' => 1, 'ulama' => 2, 'admin' => 3];
    return ($roles[$userRole] ?? 0) >= ($roles[$requiredRole] ?? 0);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                
                $stmt = $db->prepare('SELECT id, username, password, role, language FROM users WHERE username = :username');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $result = $stmt->execute();
                $user = $result->fetchArray(SQLITE3_ASSOC);
                
                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['language'] = $user['language'];
                    $current_lang = $user['language'];
                    header('Location: ?page=home');
                    exit;
                } else {
                    $error = t('login_failed');
                }
                break;
                
            case 'register':
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                $role = 'user'; // Default role
                
                // Check if username exists
                $stmt = $db->prepare('SELECT id FROM users WHERE username = :username');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $result = $stmt->execute();
                
                if ($result->fetchArray()) {
                    $error = t('username_taken');
                } else {
                    $stmt = $db->prepare('INSERT INTO users (username, password, role) VALUES (:username, :password, :role)');
                    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                    $stmt->bindValue(':password', password_hash($password, PASSWORD_DEFAULT), SQLITE3_TEXT);
                    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
                    
                    if ($stmt->execute()) {
                        $success = t('registration_success');
                    } else {
                        $error = t('registration_failed');
                    }
                }
                break;
                
            case 'add_event':
                if (hasPermission('user')) {
                    $title = $_POST['title'] ?? '';
                    $description = $_POST['description'] ?? '';
                    $category = $_POST['category'] ?? 'islamic';
                    $year = $_POST['year'] ?? 0;
                    $month = $_POST['month'] ?? 0;
                    $day = $_POST['day'] ?? 0;
                    $latitude = $_POST['latitude'] ?? 0;
                    $longitude = $_POST['longitude'] ?? 0;
                    $ayah_ref = $_POST['ayah_ref'] ?? '';
                    $hadith_ref = $_POST['hadith_ref'] ?? '';
                    
                    $approved = hasPermission('ulama') ? 1 : 0;
                    
                    $stmt = $db->prepare('INSERT INTO events (title, description, category, year, month, day, latitude, longitude, ayah_ref, hadith_ref, created_by, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bindValue(1, $title, SQLITE3_TEXT);
                    $stmt->bindValue(2, $description, SQLITE3_TEXT);
                    $stmt->bindValue(3, $category, SQLITE3_TEXT);
                    $stmt->bindValue(4, $year, SQLITE3_INTEGER);
                    $stmt->bindValue(5, $month, SQLITE3_INTEGER);
                    $stmt->bindValue(6, $day, SQLITE3_INTEGER);
                    $stmt->bindValue(7, $latitude, SQLITE3_FLOAT);
                    $stmt->bindValue(8, $longitude, SQLITE3_FLOAT);
                    $stmt->bindValue(9, $ayah_ref, SQLITE3_TEXT);
                    $stmt->bindValue(10, $hadith_ref, SQLITE3_TEXT);
                    $stmt->bindValue(11, $_SESSION['user_id'], SQLITE3_INTEGER);
                    $stmt->bindValue(12, $approved, SQLITE3_INTEGER);
                    
                    if ($stmt->execute()) {
                        // Add points for contribution
                        $db->exec("UPDATE users SET points = points + 10 WHERE id = {$_SESSION['user_id']}");
                        $success = t('event_added');
                    } else {
                        $error = t('event_add_failed');
                    }
                }
                break;
                
            case 'approve_event':
                if (hasPermission('ulama')) {
                    $event_id = $_POST['event_id'] ?? 0;
                    $db->exec("UPDATE events SET approved = 1 WHERE id = $event_id");
                    // Add points to the event creator
                    $creator = $db->querySingle("SELECT created_by FROM events WHERE id = $event_id");
                    $db->exec("UPDATE users SET points = points + 5 WHERE id = $creator");
                    $success = t('event_approved');
                }
                break;
                
            case 'add_ayah':
                if (hasPermission('ulama')) {
                    $surah = $_POST['surah'] ?? '';
                    $ayah = $_POST['ayah'] ?? '';
                    $text = $_POST['text'] ?? '';
                    $translation_en = $_POST['translation_en'] ?? '';
                    $translation_ur = $_POST['translation_ur'] ?? '';
                    $context = $_POST['context'] ?? '';
                    
                    $stmt = $db->prepare('INSERT INTO ayahs (surah, ayah, text, translation_en, translation_ur, context) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->bindValue(1, $surah, SQLITE3_TEXT);
                    $stmt->bindValue(2, $ayah, SQLITE3_INTEGER);
                    $stmt->bindValue(3, $text, SQLITE3_TEXT);
                    $stmt->bindValue(4, $translation_en, SQLITE3_TEXT);
                    $stmt->bindValue(5, $translation_ur, SQLITE3_TEXT);
                    $stmt->bindValue(6, $context, SQLITE3_TEXT);
                    
                    if ($stmt->execute()) {
                        $success = t('ayah_added');
                    } else {
                        $error = t('ayah_add_failed');
                    }
                }
                break;
                
            case 'add_hadith':
                if (hasPermission('ulama')) {
                    $collection = $_POST['collection'] ?? '';
                    $book = $_POST['book'] ?? '';
                    $number = $_POST['number'] ?? '';
                    $text = $_POST['text'] ?? '';
                    $translation_en = $_POST['translation_en'] ?? '';
                    $translation_ur = $_POST['translation_ur'] ?? '';
                    $grade = $_POST['grade'] ?? '';
                    $context = $_POST['context'] ?? '';
                    
                    $stmt = $db->prepare('INSERT INTO hadiths (collection, book, number, text, translation_en, translation_ur, grade, context) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bindValue(1, $collection, SQLITE3_TEXT);
                    $stmt->bindValue(2, $book, SQLITE3_TEXT);
                    $stmt->bindValue(3, $number, SQLITE3_TEXT);
                    $stmt->bindValue(4, $text, SQLITE3_TEXT);
                    $stmt->bindValue(5, $translation_en, SQLITE3_TEXT);
                    $stmt->bindValue(6, $translation_ur, SQLITE3_TEXT);
                    $stmt->bindValue(7, $grade, SQLITE3_TEXT);
                    $stmt->bindValue(8, $context, SQLITE3_TEXT);
                    
                    if ($stmt->execute()) {
                        $success = t('hadith_added');
                    } else {
                        $error = t('hadith_add_failed');
                    }
                }
                break;
                
            case 'bookmark':
                if (isLoggedIn()) {
                    $event_id = $_POST['event_id'] ?? 0;
                    
                    // Check if already bookmarked
                    $exists = $db->querySingle("SELECT id FROM bookmarks WHERE user_id = {$_SESSION['user_id']} AND event_id = $event_id");
                    
                    if (!$exists) {
                        $stmt = $db->prepare('INSERT INTO bookmarks (user_id, event_id) VALUES (?, ?)');
                        $stmt->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
                        $stmt->bindValue(2, $event_id, SQLITE3_INTEGER);
                        
                        if ($stmt->execute()) {
                            // Add points for bookmarking
                            $db->exec("UPDATE users SET points = points + 2 WHERE id = {$_SESSION['user_id']}");
                            $success = t('bookmark_added');
                        } else {
                            $error = t('bookmark_failed');
                        }
                    }
                }
                break;
                
            case 'update_profile':
                if (isLoggedIn()) {
                    $language = $_POST['language'] ?? 'en';
                    
                    $stmt = $db->prepare('UPDATE users SET language = ? WHERE id = ?');
                    $stmt->bindValue(1, $language, SQLITE3_TEXT);
                    $stmt->bindValue(2, $_SESSION['user_id'], SQLITE3_INTEGER);
                    
                    if ($stmt->execute()) {
                        $_SESSION['language'] = $language;
                        $current_lang = $language;
                        $success = t('profile_updated');
                    } else {
                        $error = t('update_failed');
                    }
                }
                break;
                
            case 'backup_db':
                if (hasPermission('admin')) {
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="islamic_history_backup_'.date('Y-m-d').'.db"');
                    readfile(DB_NAME);
                    exit;
                }
                break;
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?page=home');
    exit;
}

// Determine current page
$page = $_GET['page'] ?? 'home';
$allowed_pages = ['home', 'map', 'timeline', 'events', 'quran', 'hadith', 'login', 'register', 'profile', 'dashboard', 'admin'];
if (!in_array($page, $allowed_pages) || ($page === 'admin' && !hasPermission('admin'))) {
    $page = 'home';
}

// HTML Output
echo '<!DOCTYPE html>';
echo '<html lang="'.$current_lang.'">';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
echo '<title>'.t('title').'</title>';
echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />';
echo '<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '<style>
    body {
        font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f5f5f5;
        color: #333;
    }
    .islamic-theme {
        background-color: #f8f3e6;
        color: #2c3e50;
    }
    .dark-theme {
        background-color: #1a1a2e;
        color: #e6e6e6;
    }
    .urdu-font {
        font-family: \'Jameel Noori Nastaleeq\', \'Noto Nastaliq Urdu\', serif;
        font-size: 1.2em;
        direction: rtl;
    }
    .navbar {
        background-color: #2c3e50;
    }
    .navbar-brand, .nav-link {
        color: #f8f9fa !important;
    }
    .card {
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
        transition: transform 0.3s;
    }
    .card:hover {
        transform: translateY(-5px);
    }
    .event-card {
        background-color: #fff;
        border-left: 5px solid #3498db;
    }
    .islamic-event {
        border-left-color: #27ae60;
    }
    .general-event {
        border-left-color: #e74c3c;
    }
    #map {
        height: 500px;
        border-radius: 10px;
    }
    .timeline-container {
        position: relative;
        max-width: 1200px;
        margin: 0 auto;
    }
    .timeline::after {
        content: \'\';
        position: absolute;
        width: 6px;
        background-color: #3498db;
        top: 0;
        bottom: 0;
        left: 50%;
        margin-left: -3px;
    }
    .timeline-item {
        padding: 10px 40px;
        position: relative;
        width: 50%;
        box-sizing: border-box;
    }
    .timeline-item::after {
        content: \'\';
        position: absolute;
        width: 25px;
        height: 25px;
        background-color: #fff;
        border: 4px solid #3498db;
        border-radius: 50%;
        top: 15px;
        z-index: 1;
    }
    .left {
        left: 0;
    }
    .right {
        left: 50%;
    }
    .left::after {
        right: -12px;
    }
    .right::after {
        left: -12px;
    }
    .timeline-content {
        padding: 20px;
        background-color: #fff;
        border-radius: 6px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    .badge-icon {
        width: 50px;
        height: 50px;
        margin-right: 10px;
    }
    .rtl {
        direction: rtl;
        text-align: right;
    }
    @media screen and (max-width: 768px) {
        .timeline::after {
            left: 31px;
        }
        .timeline-item {
            width: 100%;
            padding-left: 70px;
            padding-right: 25px;
        }
        .timeline-item::after {
            left: 18px;
        }
        .left::after, .right::after {
            left: 18px;
        }
        .right {
            left: 0%;
        }
    }
        ul.nav.nav-tabs.mb-4 {
    background: #383e5e;
}
    a.nav-link.active {
    background: #4240a3 !important;
}
</style>';
echo '</head>';

// Body with theme class
$theme_class = $current_lang === 'ur' ? 'urdu-font' : '';
echo '<body class="'.$theme_class.'">';

// Navigation Bar
echo '<nav class="navbar navbar-expand-lg navbar-dark mb-4">';
echo '<div class="container">';
echo '<a class="navbar-brand" href="?page=home">'.t('title').'</a>';
echo '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">';
echo '<span class="navbar-toggler-icon"></span>';
echo '</button>';
echo '<div class="collapse navbar-collapse" id="navbarNav">';
echo '<ul class="navbar-nav me-auto">';
echo '<li class="nav-item"><a class="nav-link'.($page === 'home' ? ' active' : '').'" href="?page=home">'.t('dashboard').'</a></li>';
echo '<li class="nav-item"><a class="nav-link'.($page === 'map' ? ' active' : '').'" href="?page=map">'.t('map').'</a></li>';
echo '<li class="nav-item"><a class="nav-link'.($page === 'timeline' ? ' active' : '').'" href="?page=timeline">'.t('timeline').'</a></li>';
echo '<li class="nav-item"><a class="nav-link'.($page === 'events' ? ' active' : '').'" href="?page=events">'.t('events').'</a></li>';
echo '<li class="nav-item"><a class="nav-link'.($page === 'quran' ? ' active' : '').'" href="?page=quran">'.t('quran').'</a></li>';
echo '<li class="nav-item"><a class="nav-link'.($page === 'hadith' ? ' active' : '').'" href="?page=hadith">'.t('hadith').'</a></li>';

if (hasPermission('admin') || hasPermission('ulama')) {
    echo '<li class="nav-item"><a class="nav-link'.($page === 'admin' ? ' active' : '').'" href="?page=admin">'.t('admin_panel').'</a></li>';
}
echo '</ul>';

echo '<ul class="navbar-nav">';
if (isLoggedIn()) {
    echo '<li class="nav-item"><a class="nav-link'.($page === 'profile' ? ' active' : '').'" href="?page=profile">'.t('profile').'</a></li>';
    echo '<li class="nav-item"><a class="nav-link" href="?logout=1">'.t('logout').'</a></li>';
} else {
    echo '<li class="nav-item"><a class="nav-link'.($page === 'login' ? ' active' : '').'" href="?page=login">'.t('login').'</a></li>';
    echo '<li class="nav-item"><a class="nav-link'.($page === 'register' ? ' active' : '').'" href="?page=register">'.t('register').'</a></li>';
}

// Language switcher
echo '<li class="nav-item dropdown">';
echo '<a class="nav-link dropdown-toggle" href="#" id="languageDropdown" role="button" data-bs-toggle="dropdown">';
echo t('language');
echo '</a>';
echo '<ul class="dropdown-menu">';
echo '<li><a class="dropdown-item" href="?lang=en">English</a></li>';
echo '<li><a class="dropdown-item" href="?lang=ur">اردو</a></li>';
echo '</ul>';
echo '</li>';
echo '</ul>';
echo '</div>';
echo '</div>';
echo '</nav>';

// Main Content
echo '<div class="container">';

// Display errors/success
if (isset($error)) {
    echo '<div class="alert alert-danger">'.$error.'</div>';
}
if (isset($success)) {
    echo '<div class="alert alert-success">'.$success.'</div>';
}

// Page content
switch ($page) {
    case 'home':
        echo '<h2 class="mb-4">'.t('welcome').', '.($_SESSION['username'] ?? t('guest')).'</h2>';
        
        if (isLoggedIn()) {
            // User dashboard with stats
            $user_id = $_SESSION['user_id'];
            $points = $db->querySingle("SELECT points FROM users WHERE id = $user_id");
            $bookmarks = $db->querySingle("SELECT COUNT(*) FROM bookmarks WHERE user_id = $user_id");
            $events_added = $db->querySingle("SELECT COUNT(*) FROM events WHERE created_by = $user_id");
            
            echo '<div class="row mb-4">';
            echo '<div class="col-md-4">';
            echo '<div class="card text-white bg-primary">';
            echo '<div class="card-body">';
            echo '<h5 class="card-title">'.t('points').'</h5>';
            echo '<p class="card-text display-4">'.$points.'</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div class="col-md-4">';
            echo '<div class="card text-white bg-success">';
            echo '<div class="card-body">';
            echo '<h5 class="card-title">'.t('bookmark').'</h5>';
            echo '<p class="card-text display-4">'.$bookmarks.'</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div class="col-md-4">';
            echo '<div class="card text-white bg-info">';
            echo '<div class="card-body">';
            echo '<h5 class="card-title">'.t('events').'</h5>';
            echo '<p class="card-text display-4">'.$events_added.'</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            
            // Badges earned
            $badges = $db->query("SELECT b.name, b.description FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = $user_id");
            if ($badges) {
                echo '<h4 class="mb-3">'.t('badges').'</h4>';
                echo '<div class="row">';
                while ($badge = $badges->fetchArray(SQLITE3_ASSOC)) {
                    echo '<div class="col-md-3 mb-3">';
                    echo '<div class="card">';
                    echo '<div class="card-body text-center">';
                    echo '<img src="https://via.placeholder.com/50" class="badge-icon" alt="Badge">';
                    echo '<h5>'.$badge['name'].'</h5>';
                    echo '<p class="text-muted">'.$badge['description'].'</p>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }
        }
        
        // Recent approved events
        $events = $db->query("SELECT * FROM events WHERE approved = 1 ORDER BY created_at DESC LIMIT 5");
        if ($events) {
            echo '<h4 class="mb-3">'.t('events').'</h4>';
            while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
                echo '<div class="card mb-3 event-card '.$event['category'].'-event">';
                echo '<div class="card-body">';
                echo '<h5 class="card-title">'.$event['title'].'</h5>';
                echo '<p class="card-text">'.$event['description'].'</p>';
                echo '<p class="text-muted">'.t('year').': '.$event['year'].'</p>';
                echo '<a href="?page=events&id='.$event['id'].'" class="btn btn-primary">'.t('view_details').'</a>';
                if (isLoggedIn()) {
                    echo '<form method="post" action="" class="d-inline">';
                    echo '<input type="hidden" name="action" value="bookmark">';
                    echo '<input type="hidden" name="event_id" value="'.$event['id'].'">';
                    echo '<button type="submit" class="btn btn-outline-secondary ms-2">'.t('bookmark').'</button>';
                    echo '</form>';
                }
                echo '</div>';
                echo '</div>';
            }
            echo '<a href="?page=events" class="btn btn-outline-primary">'.t('view_all').'</a>';
        }
        break;
        
    case 'map':
        echo '<h2 class="mb-4">'.t('map').'</h2>';
        echo '<div class="card mb-4">';
        echo '<div class="card-body">';
        echo '<div id="map"></div>';
        echo '</div>';
        echo '</div>';
        
        // JavaScript for Leaflet map
        echo '<script>
            var map = L.map("map").setView([24.0, 45.0], 3);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: \'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors\'
            }).addTo(map);
            
            // Add markers for events
            ';
        
        $events = $db->query("SELECT * FROM events WHERE approved = 1 AND latitude != 0 AND longitude != 0");
        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
            echo 'L.marker(['.$event['latitude'].', '.$event['longitude'].'])
                .addTo(map)
                .bindPopup("<b>'.$event['title'].'</b><br>'.$event['description'].'<br><a href=\'?page=events&id='.$event['id'].'\'>'.t('view_details').'</a>");';
        }
        
        echo '</script>';
        break;
        
    case 'timeline':
        echo '<h2 class="mb-4">'.t('timeline').'</h2>';
        echo '<div class="timeline-container">';
        echo '<div class="timeline">';
        
        $events = $db->query("SELECT * FROM events WHERE approved = 1 ORDER BY year, month, day");
        $count = 0;
        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
            $position = ($count % 2 == 0) ? 'left' : 'right';
            echo '<div class="timeline-item '.$position.'">';
            echo '<div class="timeline-content">';
            echo '<h3>'.$event['title'].'</h3>';
            echo '<p>'.$event['description'].'</p>';
            echo '<p class="text-muted">'.$event['year'].'</p>';
            echo '<a href="?page=events&id='.$event['id'].'" class="btn btn-sm btn-primary">'.t('view_details').'</a>';
            echo '</div>';
            echo '</div>';
            $count++;
        }
        
        echo '</div>';
        echo '</div>';
        break;
        
    case 'events':
        if (isset($_GET['id'])) {
            // Single event view
            $event_id = intval($_GET['id']);
            $event = $db->querySingle("SELECT * FROM events WHERE id = $event_id", true);
            
            if ($event) {
                echo '<h2 class="mb-4">'.$event['title'].'</h2>';
                echo '<div class="card mb-4">';
                echo '<div class="card-body">';
                echo '<p>'.$event['description'].'</p>';
                echo '<p><strong>'.t('year').':</strong> '.$event['year'].'</p>';
                echo '<p><strong>'.t('category').':</strong> '.t($event['category']).'</p>';
                
                if ($event['latitude'] && $event['longitude']) {
                    echo '<div id="eventMap" style="height: 300px; width: 100%;" class="mb-3"></div>';
                    echo '<script>
                        var map = L.map("eventMap").setView(['.$event['latitude'].', '.$event['longitude'].'], 10);
                        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png").addTo(map);
                        L.marker(['.$event['latitude'].', '.$event['longitude'].']).addTo(map);
                    </script>';
                }
                
                if (isLoggedIn()) {
                    echo '<form method="post" action="">';
                    echo '<input type="hidden" name="action" value="bookmark">';
                    echo '<input type="hidden" name="event_id" value="'.$event['id'].'">';
                    echo '<button type="submit" class="btn btn-primary">'.t('bookmark').'</button>';
                    echo '</form>';
                }
                echo '</div>';
                echo '</div>';
                
                // Related ayahs
                if ($event['ayah_ref']) {
                    echo '<h4 class="mb-3">'.t('related_ayahs').'</h4>';
                    $ayahs = explode(',', $event['ayah_ref']);
                    foreach ($ayahs as $ref) {
                        $ref = trim($ref);
                        $ayah = $db->querySingle("SELECT * FROM ayahs WHERE surah || ':' || ayah = '$ref'", true);
                        if ($ayah) {
                            echo '<div class="card mb-3">';
                            echo '<div class="card-body">';
                            echo '<h5>'.$ayah['surah'].':'.$ayah['ayah'].'</h5>';
                            echo '<p class="rtl" style="font-size: 1.5em; text-align: right;">'.$ayah['text'].'</p>';
                            echo '<p>'.($current_lang === 'ur' ? $ayah['translation_ur'] : $ayah['translation_en']).'</p>';
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                }
                
                // Related hadiths
                if ($event['hadith_ref']) {
                    echo '<h4 class="mb-3">'.t('related_hadiths').'</h4>';
                    $hadiths = explode(',', $event['hadith_ref']);
                    foreach ($hadiths as $ref) {
                        $ref = trim($ref);
                        $hadith = $db->querySingle("SELECT * FROM hadiths WHERE collection || ' ' || book || ':' || number = '$ref'", true);
                        if ($hadith) {
                            echo '<div class="card mb-3">';
                            echo '<div class="card-body">';
                            echo '<h5>'.$hadith['collection'].' '.$hadith['book'].':'.$hadith['number'].'</h5>';
                            echo '<p>'.($current_lang === 'ur' ? $hadith['translation_ur'] : $hadith['translation_en']).'</p>';
                            echo '<p class="text-muted">'.t('grade').': '.$hadith['grade'].'</p>';
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                }
            } else {
                echo '<div class="alert alert-warning">'.t('event_not_found').'</div>';
            }
        } else {
            // Events list
            echo '<h2 class="mb-4">'.t('events').'</h2>';
            
            // Add event button for users
            if (hasPermission('user')) {
                echo '<a href="#addEventModal" class="btn btn-primary mb-3" data-bs-toggle="modal">'.t('add_event').'</a>';
            }
            
            // Filter form
            echo '<form method="get" action="" class="mb-4">';
            echo '<input type="hidden" name="page" value="events">';
            echo '<div class="row">';
            echo '<div class="col-md-4">';
            echo '<select name="category" class="form-select">';
            echo '<option value="">'.t('all_categories').'</option>';
            echo '<option value="islamic"'.(($_GET['category'] ?? '') === 'islamic' ? ' selected' : '').'>'.t('islamic').'</option>';
            echo '<option value="general"'.(($_GET['category'] ?? '') === 'general' ? ' selected' : '').'>'.t('general').'</option>';
            echo '</select>';
            echo '</div>';
            echo '<div class="col-md-4">';
            echo '<input type="text" name="search" class="form-control" placeholder="'.t('search').'" value="'.($_GET['search'] ?? '').'">';
            echo '</div>';
            echo '<div class="col-md-4">';
            echo '<button type="submit" class="btn btn-primary">'.t('filter').'</button>';
            echo '</div>';
            echo '</div>';
            echo '</form>';
            
            // Build query
            $where = ['approved = 1'];
            if (!empty($_GET['category'])) {
                $where[] = "category = '".SQLite3::escapeString($_GET['category'])."'";
            }
            if (!empty($_GET['search'])) {
                $search = SQLite3::escapeString($_GET['search']);
                $where[] = "(title LIKE '%$search%' OR description LIKE '%$search%')";
            }
            $query = "SELECT * FROM events";
            if (!empty($where)) {
                $query .= " WHERE ".implode(' AND ', $where);
            }
            $query .= " ORDER BY year DESC";
            
            $events = $db->query($query);
            if ($events) {
                while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
                    echo '<div class="card mb-3 event-card '.$event['category'].'-event">';
                    echo '<div class="card-body">';
                    echo '<h5 class="card-title">'.$event['title'].'</h5>';
                    echo '<p class="card-text">'.$event['description'].'</p>';
                    echo '<p class="text-muted">'.t('year').': '.$event['year'].' | '.t('category').': '.t($event['category']).'</p>';
                    echo '<a href="?page=events&id='.$event['id'].'" class="btn btn-primary">'.t('view_details').'</a>';
                    if (isLoggedIn()) {
                        echo '<form method="post" action="" class="d-inline">';
                        echo '<input type="hidden" name="action" value="bookmark">';
                        echo '<input type="hidden" name="event_id" value="'.$event['id'].'">';
                        echo '<button type="submit" class="btn btn-outline-secondary ms-2">'.t('bookmark').'</button>';
                        echo '</form>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="alert alert-info">'.t('no_events_found').'</div>';
            }
        }
        break;
        
    case 'quran':
        echo '<h2 class="mb-4">'.t('quran').'</h2>';
        
        // Search form
        echo '<form method="get" action="" class="mb-4">';
        echo '<input type="hidden" name="page" value="quran">';
        echo '<div class="row">';
        echo '<div class="col-md-8">';
        echo '<input type="text" name="search" class="form-control" placeholder="'.t('search').'" value="'.($_GET['search'] ?? '').'">';
        echo '</div>';
        echo '<div class="col-md-4">';
        echo '<button type="submit" class="btn btn-primary">'.t('search').'</button>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        
        // Add ayah button for Ulama
        if (hasPermission('ulama')) {
            echo '<a href="#addAyahModal" class="btn btn-primary mb-3" data-bs-toggle="modal">'.t('add_ayah').'</a>';
        }
        
        // Build query
        $where = [];
        if (!empty($_GET['search'])) {
            $search = SQLite3::escapeString($_GET['search']);
            $where[] = "(text LIKE '%$search%' OR translation_en LIKE '%$search%' OR translation_ur LIKE '%$search%')";
        }
        $query = "SELECT * FROM ayahs";
        if (!empty($where)) {
            $query .= " WHERE ".implode(' AND ', $where);
        }
        $query .= " ORDER BY surah, ayah";
        
        $ayahs = $db->query($query);
        if ($ayahs) {
            while ($ayah = $ayahs->fetchArray(SQLITE3_ASSOC)) {
                echo '<div class="card mb-3">';
                echo '<div class="card-body">';
                echo '<h5>'.$ayah['surah'].':'.$ayah['ayah'].'</h5>';
                echo '<p class="rtl" style="font-size: 1.5em; text-align: right;">'.$ayah['text'].'</p>';
                echo '<p>'.($current_lang === 'ur' ? $ayah['translation_ur'] : $ayah['translation_en']).'</p>';
                if ($ayah['context']) {
                    echo '<p class="text-muted"><strong>'.t('context').':</strong> '.$ayah['context'].'</p>';
                }
                echo '</div>';
                echo '</div>';
            }
        } else {
            echo '<div class="alert alert-info">'.t('no_ayahs_found').'</div>';
        }
        break;
        
    case 'hadith':
        echo '<h2 class="mb-4">'.t('hadith').'</h2>';
        
        // Search form
        echo '<form method="get" action="" class="mb-4">';
        echo '<input type="hidden" name="page" value="hadith">';
        echo '<div class="row">';
        echo '<div class="col-md-8">';
        echo '<input type="text" name="search" class="form-control" placeholder="'.t('search').'" value="'.($_GET['search'] ?? '').'">';
        echo '</div>';
        echo '<div class="col-md-4">';
        echo '<button type="submit" class="btn btn-primary">'.t('search').'</button>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        
        // Add hadith button for Ulama
        if (hasPermission('ulama')) {
            echo '<a href="#addHadithModal" class="btn btn-primary mb-3" data-bs-toggle="modal">'.t('add_hadith').'</a>';
        }
        
        // Build query
        $where = [];
        if (!empty($_GET['search'])) {
            $search = SQLite3::escapeString($_GET['search']);
            $where[] = "(text LIKE '%$search%' OR translation_en LIKE '%$search%' OR translation_ur LIKE '%$search%')";
        }
        $query = "SELECT * FROM hadiths";
        if (!empty($where)) {
            $query .= " WHERE ".implode(' AND ', $where);
        }
        $query .= " ORDER BY collection, book, number";
        
        $hadiths = $db->query($query);
        if ($hadiths) {
            while ($hadith = $hadiths->fetchArray(SQLITE3_ASSOC)) {
                echo '<div class="card mb-3">';
                echo '<div class="card-body">';
                echo '<h5>'.$hadith['collection'].' '.$hadith['book'].':'.$hadith['number'].'</h5>';
                echo '<p>'.($current_lang === 'ur' ? $hadith['translation_ur'] : $hadith['translation_en']).'</p>';
                echo '<p class="text-muted"><strong>'.t('grade').':</strong> '.$hadith['grade'].'</p>';
                if ($hadith['context']) {
                    echo '<p class="text-muted"><strong>'.t('context').':</strong> '.$hadith['context'].'</p>';
                }
                echo '</div>';
                echo '</div>';
            }
        } else {
            echo '<div class="alert alert-info">'.t('no_hadiths_found').'</div>';
        }
        break;
        
    case 'login':
        if (isLoggedIn()) {
            header('Location: ?page=home');
            exit;
        }
        
        echo '<h2 class="mb-4">'.t('login').'</h2>';
        echo '<div class="row justify-content-center">';
        echo '<div class="col-md-6">';
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="login">';
        echo '<div class="mb-3">';
        echo '<label for="username" class="form-label">'.t('username').'</label>';
        echo '<input type="text" class="form-control" id="username" name="username" required>';
        echo '</div>';
        echo '<div class="mb-3">';
        echo '<label for="password" class="form-label">'.t('password').'</label>';
        echo '<input type="password" class="form-control" id="password" name="password" required>';
        echo '</div>';
        echo '<button type="submit" class="btn btn-primary">'.t('login').'</button>';
        echo '</form>';
        echo '<p class="mt-3">'.t('no_account').' <a href="?page=register">'.t('register_here').'</a></p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        break;
        
    case 'register':
        if (isLoggedIn()) {
            header('Location: ?page=home');
            exit;
        }
        
        echo '<h2 class="mb-4">'.t('register').'</h2>';
        echo '<div class="row justify-content-center">';
        echo '<div class="col-md-6">';
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="register">';
        echo '<div class="mb-3">';
        echo '<label for="username" class="form-label">'.t('username').'</label>';
        echo '<input type="text" class="form-control" id="username" name="username" required>';
        echo '</div>';
        echo '<div class="mb-3">';
        echo '<label for="password" class="form-label">'.t('password').'</label>';
        echo '<input type="password" class="form-control" id="password" name="password" required>';
        echo '</div>';
        echo '<button type="submit" class="btn btn-primary">'.t('register').'</button>';
        echo '</form>';
        echo '<p class="mt-3">'.t('have_account').' <a href="?page=login">'.t('login_here').'</a></p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        break;
        
    case 'profile':
        if (!isLoggedIn()) {
            header('Location: ?page=login');
            exit;
        }
        
        $user_id = $_SESSION['user_id'];
        $user = $db->querySingle("SELECT username, role, language, points FROM users WHERE id = $user_id", true);
        
        echo '<h2 class="mb-4">'.t('profile').'</h2>';
        echo '<div class="row">';
        echo '<div class="col-md-6">';
        echo '<div class="card mb-4">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">'.t('user_info').'</h5>';
        echo '<p><strong>'.t('username').':</strong> '.$user['username'].'</p>';
        echo '<p><strong>'.t('role').':</strong> '.$user['role'].'</p>';
        echo '<p><strong>'.t('points').':</strong> '.$user['points'].'</p>';
        echo '</div>';
        echo '</div>';
        
        // Bookmarked events
        $bookmarks = $db->query("SELECT e.id, e.title FROM bookmarks b JOIN events e ON b.event_id = e.id WHERE b.user_id = $user_id");
        if ($bookmarks) {
            echo '<div class="card">';
            echo '<div class="card-body">';
            echo '<h5 class="card-title">'.t('bookmarks').'</h5>';
            echo '<ul class="list-group">';
            while ($bookmark = $bookmarks->fetchArray(SQLITE3_ASSOC)) {
                echo '<li class="list-group-item"><a href="?page=events&id='.$bookmark['id'].'">'.$bookmark['title'].'</a></li>';
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '<div class="col-md-6">';
        // Update profile form
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">'.t('settings').'</h5>';
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="update_profile">';
        echo '<div class="mb-3">';
        echo '<label for="language" class="form-label">'.t('language').'</label>';
        echo '<select class="form-select" id="language" name="language">';
        echo '<option value="en"'.($user['language'] === 'en' ? ' selected' : '').'>English</option>';
        echo '<option value="ur"'.($user['language'] === 'ur' ? ' selected' : '').'>اردو</option>';
        echo '</select>';
        echo '</div>';
        echo '<button type="submit" class="btn btn-primary">'.t('update').'</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        break;
        
    case 'admin':
        if (!hasPermission('admin') && !hasPermission('ulama')) {
            header('Location: ?page=home');
            exit;
        }
        
        echo '<h2 class="mb-4">'.t('admin_panel').'</h2>';
        
        // Admin tabs
        echo '<ul class="nav nav-tabs mb-4">';
        if (hasPermission('admin')) {
            echo '<li class="nav-item"><a class="nav-link'.(empty($_GET['tab']) || $_GET['tab'] === 'users' ? ' active' : '').'" href="?page=admin&tab=users">'.t('manage_users').'</a></li>';
        }
        if (hasPermission('ulama')) {
            echo '<li class="nav-item"><a class="nav-link'.(isset($_GET['tab']) && $_GET['tab'] === 'approve' ? ' active' : '').'" href="?page=admin&tab=approve">'.t('approve_events').'</a></li>';
        }
        if (hasPermission('admin')) {
            echo '<li class="nav-item"><a class="nav-link'.(isset($_GET['tab']) && $_GET['tab'] === 'backup' ? ' active' : '').'" href="?page=admin&tab=backup">'.t('backup').'</a></li>';
        }
        echo '</ul>';
        
        // Admin content
        $tab = $_GET['tab'] ?? (hasPermission('admin') ? 'users' : 'approve');
        
        switch ($tab) {
            case 'users':
                if (hasPermission('admin')) {
                    // Users management
                    $users = $db->query("SELECT id, username, role, created_at FROM users ORDER BY role, username");
                    if ($users) {
                        echo '<div class="table-responsive">';
                        echo '<table class="table table-striped">';
                        echo '<thead>';
                        echo '<tr>';
                        echo '<th>'.t('username').'</th>';
                        echo '<th>'.t('role').'</th>';
                        echo '<th>'.t('created_at').'</th>';
                        echo '<th>'.t('actions').'</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
                            echo '<tr>';
                            echo '<td>'.$user['username'].'</td>';
                            echo '<td>'.$user['role'].'</td>';
                            echo '<td>'.$user['created_at'].'</td>';
                            echo '<td>';
                            echo '<a href="?page=admin&tab=users&edit='.$user['id'].'" class="btn btn-sm btn-primary">'.t('edit').'</a>';
                            if ($user['username'] !== 'admin') {
                                echo '<a href="?page=admin&tab=users&delete='.$user['id'].'" class="btn btn-sm btn-danger ms-1" onclick="return confirm(\''.t('confirm_delete').'\')">'.t('delete').'</a>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody>';
                        echo '</table>';
                        echo '</div>';
                    }
                }
                break;
                
            case 'approve':
                if (hasPermission('ulama')) {
                    // Approve events
                    $events = $db->query("SELECT e.*, u.username FROM events e LEFT JOIN users u ON e.created_by = u.id WHERE e.approved = 0 ORDER BY e.created_at");
                    if ($events) {
                        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
                            echo '<div class="card mb-3">';
                            echo '<div class="card-body">';
                            echo '<h5>'.$event['title'].'</h5>';
                            echo '<p>'.$event['description'].'</p>';
                            echo '<p class="text-muted">'.t('year').': '.$event['year'].' | '.t('category').': '.t($event['category']).' | '.t('created_by').': '.$event['username'].'</p>';
                            echo '<form method="post" action="">';
                            echo '<input type="hidden" name="action" value="approve_event">';
                            echo '<input type="hidden" name="event_id" value="'.$event['id'].'">';
                            echo '<button type="submit" class="btn btn-success">'.t('approve').'</button>';
                            echo '</form>';
                            echo '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="alert alert-info">'.t('no_events_to_approve').'</div>';
                    }
                }
                break;
                
            case 'backup':
                if (hasPermission('admin')) {
                    echo '<div class="card">';
                    echo '<div class="card-body">';
                    echo '<h5 class="card-title">'.t('backup').'</h5>';
                    echo '<p class="card-text">'.t('backup_description').'</p>';
                    echo '<form method="post" action="">';
                    echo '<input type="hidden" name="action" value="backup_db">';
                    echo '<button type="submit" class="btn btn-primary">'.t('download_backup').'</button>';
                    echo '</form>';
                    echo '</div>';
                    echo '</div>';
                }
                break;
        }
        break;
}

// Modals
if (hasPermission('user')) {
    // Add Event Modal
    echo '<div class="modal fade" id="addEventModal" tabindex="-1">';
    echo '<div class="modal-dialog">';
    echo '<div class="modal-content">';
    echo '<div class="modal-header">';
    echo '<h5 class="modal-title">'.t('add_event').'</h5>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>';
    echo '</div>';
    echo '<div class="modal-body">';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="add_event">';
    echo '<div class="mb-3">';
    echo '<label for="title" class="form-label">'.t('title').'</label>';
    echo '<input type="text" class="form-control" id="title" name="title" required>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="description" class="form-label">'.t('description').'</label>';
    echo '<textarea class="form-control" id="description" name="description" rows="3" required></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="category" class="form-label">'.t('category').'</label>';
    echo '<select class="form-select" id="category" name="category" required>';
    echo '<option value="islamic">'.t('islamic').'</option>';
    echo '<option value="general">'.t('general').'</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="row mb-3">';
    echo '<div class="col">';
    echo '<label for="year" class="form-label">'.t('year').'</label>';
    echo '<input type="number" class="form-control" id="year" name="year" required>';
    echo '</div>';
    echo '<div class="col">';
    echo '<label for="month" class="form-label">'.t('month').'</label>';
    echo '<input type="number" class="form-control" id="month" name="month" min="1" max="12">';
    echo '</div>';
    echo '<div class="col">';
    echo '<label for="day" class="form-label">'.t('day').'</label>';
    echo '<input type="number" class="form-control" id="day" name="day" min="1" max="31">';
    echo '</div>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="location" class="form-label">'.t('location').'</label>';
    echo '<div class="input-group">';
    echo '<input type="text" class="form-control" id="locationSearch" placeholder="'.t('search_location').'">';
    echo '<button class="btn btn-outline-secondary" type="button" id="locateMe">'.t('locate_me').'</button>';
    echo '</div>';
    echo '<div id="locationMap" style="height: 200px; width: 100%; margin-top: 10px;"></div>';
    echo '<input type="hidden" id="latitude" name="latitude">';
    echo '<input type="hidden" id="longitude" name="longitude">';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="ayah_ref" class="form-label">'.t('related_ayahs').'</label>';
    echo '<input type="text" class="form-control" id="ayah_ref" name="ayah_ref" placeholder="e.g., 2:255, 3:104">';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="hadith_ref" class="form-label">'.t('related_hadiths').'</label>';
    echo '<input type="text" class="form-control" id="hadith_ref" name="hadith_ref" placeholder="e.g., Bukhari 1:1, Muslim 2:3">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">'.t('submit').'</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // JavaScript for location picker
    echo '<script>
        var locationMap = L.map("locationMap").setView([24.0, 45.0], 3);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png").addTo(locationMap);
        var marker;
        
        locationMap.on("click", function(e) {
            if (marker) {
                locationMap.removeLayer(marker);
            }
            marker = L.marker(e.latlng).addTo(locationMap);
            document.getElementById("latitude").value = e.latlng.lat;
            document.getElementById("longitude").value = e.latlng.lng;
        });
        
        document.getElementById("locateMe").addEventListener("click", function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    var latlng = L.latLng(position.coords.latitude, position.coords.longitude);
                    locationMap.setView(latlng, 13);
                    if (marker) {
                        locationMap.removeLayer(marker);
                    }
                    marker = L.marker(latlng).addTo(locationMap);
                    document.getElementById("latitude").value = position.coords.latitude;
                    document.getElementById("longitude").value = position.coords.longitude;
                });
            }
        });
    </script>';
}

if (hasPermission('ulama')) {
    // Add Ayah Modal
    echo '<div class="modal fade" id="addAyahModal" tabindex="-1">';
    echo '<div class="modal-dialog">';
    echo '<div class="modal-content">';
    echo '<div class="modal-header">';
    echo '<h5 class="modal-title">'.t('add_ayah').'</h5>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>';
    echo '</div>';
    echo '<div class="modal-body">';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="add_ayah">';
    echo '<div class="row mb-3">';
    echo '<div class="col">';
    echo '<label for="surah" class="form-label">'.t('surah').'</label>';
    echo '<input type="text" class="form-control" id="surah" name="surah" required>';
    echo '</div>';
    echo '<div class="col">';
    echo '<label for="ayah" class="form-label">'.t('ayah').'</label>';
    echo '<input type="number" class="form-control" id="ayah" name="ayah" required>';
    echo '</div>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="text" class="form-label">'.t('arabic_text').'</label>';
    echo '<textarea class="form-control rtl" id="text" name="text" rows="2" required style="text-align: right; font-size: 1.2em;"></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="translation_en" class="form-label">'.t('english_translation').'</label>';
    echo '<textarea class="form-control" id="translation_en" name="translation_en" rows="2" required></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="translation_ur" class="form-label">'.t('urdu_translation').'</label>';
    echo '<textarea class="form-control" id="translation_ur" name="translation_ur" rows="2" required></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="context" class="form-label">'.t('context').'</label>';
    echo '<textarea class="form-control" id="context" name="context" rows="2"></textarea>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">'.t('submit').'</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Add Hadith Modal
    echo '<div class="modal fade" id="addHadithModal" tabindex="-1">';
    echo '<div class="modal-dialog">';
    echo '<div class="modal-content">';
    echo '<div class="modal-header">';
    echo '<h5 class="modal-title">'.t('add_hadith').'</h5>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>';
    echo '</div>';
    echo '<div class="modal-body">';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="add_hadith">';
    echo '<div class="mb-3">';
    echo '<label for="collection" class="form-label">'.t('collection').'</label>';
    echo '<input type="text" class="form-control" id="collection" name="collection" required>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="book" class="form-label">'.t('book').'</label>';
    echo '<input type="text" class="form-control" id="book" name="book" required>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="number" class="form-label">'.t('number').'</label>';
    echo '<input type="text" class="form-control" id="number" name="number" required>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="text" class="form-label">'.t('arabic_text').'</label>';
    echo '<textarea class="form-control rtl" id="text" name="text" rows="2" style="text-align: right; font-size: 1.2em;"></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="translation_en" class="form-label">'.t('english_translation').'</label>';
    echo '<textarea class="form-control" id="translation_en" name="translation_en" rows="2" required></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="translation_ur" class="form-label">'.t('urdu_translation').'</label>';
    echo '<textarea class="form-control" id="translation_ur" name="translation_ur" rows="2" required></textarea>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="grade" class="form-label">'.t('grade').'</label>';
    echo '<input type="text" class="form-control" id="grade" name="grade">';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label for="context" class="form-label">'.t('context').'</label>';
    echo '<textarea class="form-control" id="context" name="context" rows="2"></textarea>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">'.t('submit').'</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

echo '</div>'; // Close container

// Footer
echo '<footer class="bg-dark text-white mt-5 py-4">';
echo '<div class="container">';
echo '<div class="row">';
echo '<div class="col-md-6">';
echo '<h5>'.t('title').'</h5>';
echo '<p>'.t('footer_description').'</p>';
echo '</div>';
echo '<div class="col-md-3">';
echo '<h5>'.t('links').'</h5>';
echo '<ul class="list-unstyled">';
echo '<li><a href="?page=home" class="text-white">'.t('home').'</a></li>';
echo '<li><a href="?page=map" class="text-white">'.t('map').'</a></li>';
echo '<li><a href="?page=timeline" class="text-white">'.t('timeline').'</a></li>';
echo '</ul>';
echo '</div>';
echo '<div class="col-md-3">';
echo '<h5>'.t('resources').'</h5>';
echo '<ul class="list-unstyled">';
echo '<li><a href="?page=quran" class="text-white">'.t('quran').'</a></li>';
echo '<li><a href="?page=hadith" class="text-white">'.t('hadith').'</a></li>';
echo '</ul>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</footer>';

// Bootstrap JS
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>';

echo '</body>';
echo '</html>';

// Close database connection
$db->close();
?>
