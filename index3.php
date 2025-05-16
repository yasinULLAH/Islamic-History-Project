
<?php
ob_start();
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'islamic_history_app_error.log');

// Configuration
$c = [
    'db_path' => 'islamic_history.db',
    'quran_data' => 'data.AM',
    'lang' => isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en',
    'theme' => isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light',
    'salt' => 'fK2$p9L^v5',
'roles' => ['admin', 'ulama', 'user', 'public']
];

// Language strings
$t = [
'en' => [
'app_name' => 'Islamic History Explorer',
'login' => 'Login',
'register' => 'Register',
'logout' => 'Logout',
'events' => 'Historical Events',
'quran' => 'Quran',
'hadith' => 'Hadith',
'map' => 'Interactive Map',
'profile' => 'My Profile',
'admin' => 'Admin Panel',
'username' => 'Username',
'password' => 'Password',
'email' => 'Email',
'role' => 'Role',
'submit' => 'Submit',
'search' => 'Search',
'add_event' => 'Add Event',
'edit' => 'Edit',
'delete' => 'Delete',
'approve' => 'Approve',
'reject' => 'Reject',
'bookmark' => 'Bookmark',
'date' => 'Date',
'location' => 'Location',
'category' => 'Category',
'description' => 'Description',
'title' => 'Title',
'islamic' => 'Islamic',
'general' => 'General',
'badges' => 'Badges',
'points' => 'Points',
'settings' => 'Settings',
'dark_mode' => 'Dark Mode',
'light_mode' => 'Light Mode',
'language' => 'Language',
'welcome' => 'Welcome to Islamic History Explorer',
'search_placeholder' => 'Search for events, Quran, or Hadith...',
'timeline' => 'Timeline',
'related_content' => 'Related Content',
'no_results' => 'No results found',
'surah' => 'Surah',
'ayah' => 'Ayah',
'arabic' => 'Arabic',
'urdu' => 'Urdu',
'translation' => 'Translation',
'bookmarked' => 'Bookmarked',
'remove_bookmark' => 'Remove Bookmark',
'add_bookmark' => 'Add Bookmark',
'backup' => 'Backup Database',
'restore' => 'Restore Database'
],
'ur' => [
'app_name' => 'اسلامی تاریخ ایکسپلورر',
'login' => 'لاگ ان',
'register' => 'رجسٹر',
'logout' => 'لاگ آؤٹ',
'events' => 'تاریخی واقعات',
'quran' => 'قرآن',
'hadith' => 'حدیث',
'map' => 'انٹرایکٹیو نقشہ',
'profile' => 'میرا پروفائل',
'admin' => 'ایڈمن پینل',
'username' => 'صارف نام',
'password' => 'پاس ورڈ',
'email' => 'ای میل',
'role' => 'رول',
'submit' => 'جمع کرائیں',
'search' => 'تلاش کریں',
'add_event' => 'واقعہ شامل کریں',
'edit' => 'ترمیم',
'delete' => 'حذف',
'approve' => 'منظور',
'reject' => 'مسترد',
'bookmark' => 'بکمارک',
'date' => 'تاریخ',
'location' => 'مقام',
'category' => 'زمرہ',
'description' => 'تفصیل',
'title' => 'عنوان',
'islamic' => 'اسلامی',
'general' => 'عام',
'badges' => 'بیجز',
'points' => 'پوائنٹس',
'settings' => 'ترتیبات',
'dark_mode' => 'ڈارک موڈ',
'light_mode' => 'لائٹ موڈ',
'language' => 'زبان',
'welcome' => 'اسلامی تاریخ ایکسپلورر میں خوش آمدید',
'search_placeholder' => 'واقعات، قرآن یا حدیث تلاش کریں...',
'timeline' => 'ٹائم لائن',
'related_content' => 'متعلقہ مواد',
'no_results' => 'کوئی نتیجہ نہیں ملا',
'surah' => 'سورہ',
'ayah' => 'آیت',
'arabic' => 'عربی',
'urdu' => 'اردو',
'translation' => 'ترجمہ',
'bookmarked' => 'بکمارک شدہ',
'remove_bookmark' => 'بکمارک ہٹائیں',
'add_bookmark' => 'بکمارک کریں',
'backup' => 'ڈیٹابیس بیک اپ',
'restore' => 'ڈیٹابیس بحال کریں'
]
];

// Helper Functions
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function u($s) { return urlencode($s); }
function rdr($url) { header("Location: $url"); exit; }
function j($data) { return json_encode($data); }
function e($msg) { die("<div class='alert alert-danger'>$msg</div>"); }
function s($str) { global $db; return SQLite3::escapeString($str); }
function t($key) { global $t, $c; return isset($t[$c['lang']][$key]) ? $t[$c['lang']][$key] : $key; }
function hash_pwd($pwd) { global $c; return password_hash($pwd . $c['salt'], PASSWORD_BCRYPT); }
function verify_pwd($pwd, $hash) { global $c; return password_verify($pwd . $c['salt'], $hash); }
function is_logged() { return isset($_SESSION['user_id']); }
function has_role($role) { return is_logged() && $_SESSION['role'] === $role; }
function has_access($min_role) {
global $c;
if (!is_logged()) return $min_role === 'public';
$roles = $c['roles'];
$user_idx = array_search($_SESSION['role'], $roles);
$min_idx = array_search($min_role, $roles);
return $user_idx !== false && $min_idx !== false && $user_idx <= $min_idx;
}
function add_points($pts) {
global $db;
if (!is_logged()) return;
$q = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
$q->bindValue(1, $pts, SQLITE3_INTEGER);
$q->bindValue(2, $_SESSION['user_id'], SQLITE3_INTEGER);
$q->execute();
    update_badges($_SESSION['user_id']);
}
function update_badges($uid) {
global $db;
$q = $db->prepare("SELECT points FROM users WHERE id = ?");
$q->bindValue(1, $uid, SQLITE3_INTEGER);
$r = $q->execute()->fetchArray(SQLITE3_ASSOC);
$p = $r['points'];

    $badges = [
        ['name' => 'Beginner', 'points' => 10, 'icon' => 'star'],
        ['name' => 'Explorer', 'points' => 50, 'icon' => 'compass'],
        ['name' => 'Scholar', 'points' => 100, 'icon' => 'book'],
        ['name' => 'Historian', 'points' => 200, 'icon' => 'landmark'],
        ['name' => 'Master', 'points' => 500, 'icon' => 'crown']
    ];
    
    foreach ($badges as $b) {
        if ($p >= $b['points']) {
            $q = $db->prepare("INSERT OR IGNORE INTO badges (user_id, badge_name, badge_icon) VALUES (?, ?, ?)");
            $q->bindValue(1, $uid, SQLITE3_INTEGER);
            $q->bindValue(2, $b['name'], SQLITE3_TEXT);
            $q->bindValue(3, $b['icon'], SQLITE3_TEXT);
            $q->execute();
        }
    }
    }
function get_user_badges($uid) {
global $db;
$q = $db->prepare("SELECT * FROM badges WHERE user_id = ? ORDER BY id DESC");
$q->bindValue(1, $uid, SQLITE3_INTEGER);
$r = $q->execute();
$badges = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
$badges[] = $row;
}
return $badges;
}

// Database Setup
$db = new SQLite3($c['db_path']);
$db->exec('PRAGMA foreign_keys = ON');
if (function_exists('remove_arabic_diacritics')) {
        $db->createFunction('REMOVE_DIACRITICS', 'remove_arabic_diacritics', 1, SQLITE3_DETERMINISTIC);
    }
// Create tables if they don't exist
$db->exec("
CREATE TABLE IF NOT EXISTS users (
id INTEGER PRIMARY KEY AUTOINCREMENT,
username TEXT UNIQUE NOT NULL,
password TEXT NOT NULL,
email TEXT UNIQUE NOT NULL,
role TEXT NOT NULL DEFAULT 'user',
points INTEGER DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
id INTEGER PRIMARY KEY AUTOINCREMENT,
title TEXT NOT NULL,
description TEXT NOT NULL,
date TEXT NOT NULL,
category TEXT NOT NULL,
latitude REAL,
longitude REAL,
location TEXT,
user_id INTEGER,
approved INTEGER DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS quran (
id INTEGER PRIMARY KEY AUTOINCREMENT,
surah INTEGER NOT NULL,
ayah INTEGER NOT NULL,
arabic TEXT NOT NULL,
urdu TEXT NOT NULL,
UNIQUE(surah, ayah)
);

CREATE TABLE IF NOT EXISTS hadith (
id INTEGER PRIMARY KEY AUTOINCREMENT,
collection TEXT NOT NULL,
book_number INTEGER NOT NULL,
hadith_number INTEGER NOT NULL,
text TEXT NOT NULL,
narrator TEXT NOT NULL,
grading TEXT,
UNIQUE(collection, book_number, hadith_number)
);

CREATE TABLE IF NOT EXISTS bookmarks (
id INTEGER PRIMARY KEY AUTOINCREMENT,
user_id INTEGER NOT NULL,
content_type TEXT NOT NULL,
content_id INTEGER NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
UNIQUE(user_id, content_type, content_id),
FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS badges (
id INTEGER PRIMARY KEY AUTOINCREMENT,
user_id INTEGER NOT NULL,
badge_name TEXT NOT NULL,
badge_icon TEXT NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
UNIQUE(user_id, badge_name),
FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS content_links (
id INTEGER PRIMARY KEY AUTOINCREMENT,
event_id INTEGER NOT NULL,
content_type TEXT NOT NULL,
content_id INTEGER NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
UNIQUE(event_id, content_type, content_id),
FOREIGN KEY (event_id) REFERENCES events(id)
);

CREATE TABLE IF NOT EXISTS logs (
id INTEGER PRIMARY KEY AUTOINCREMENT,
user_id INTEGER,
action TEXT NOT NULL,
details TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (user_id) REFERENCES users(id)
);
");

// Create admin user if none exists
$admin = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetchArray();
if ($admin['count'] == 0) {
$db->exec("INSERT INTO users (username, password, email, role) VALUES ('admin', '" . hash_pwd('admin123') . "', 'admin@example.com', 'admin')");
}

// Load Quran data if not already loaded
function load_quran_data() {
global $db, $c;
$count = $db->query("SELECT COUNT(*) as count FROM quran")->fetchArray()['count'];
    if ($count == 0 && file_exists($c['quran_data'])) {
try {
$data = file_get_contents($c['quran_data']);
$lines = explode("\n", $data);
$db->exec('BEGIN TRANSACTION');

            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                
                // Parse format: [Arabic Ayah] ترجمہ: [Urdu Translation]<br/>س [3-digit Surah] آ [3-digit Ayah]
                preg_match('/^(.*?) ترجمہ: (.*?)<br\/>س (\d{1,3}) آ (\d{1,3})$/u', $line, $matches);
                
                if (count($matches) === 5) {
                    $arabic = trim($matches[1]);
                    $urdu = trim($matches[2]);
                    $surah = intval($matches[3]);
                    $ayah = intval($matches[4]);
                    
                    $q = $db->prepare("INSERT OR IGNORE INTO quran (surah, ayah, arabic, urdu) VALUES (?, ?, ?, ?)");
                    $q->bindValue(1, $surah, SQLITE3_INTEGER);
                    $q->bindValue(2, $ayah, SQLITE3_INTEGER);
                    $q->bindValue(3, $arabic, SQLITE3_TEXT);
                    $q->bindValue(4, $urdu, SQLITE3_TEXT);
                    $q->execute();
                }
            }
            $db->exec('COMMIT');
            log_action(null, 'system', 'Quran data loaded successfully');
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            log_action(null, 'error', 'Failed to load Quran data: ' . $e->getMessage());
        }
    }
    }

// Insert some sample hadith if none exist
function load_sample_hadith() {
global $db;
$count = $db->query("SELECT COUNT(*) as count FROM hadith")->fetchArray()['count'];
    if ($count == 0) {
$sample_hadith = [
['Bukhari', 1, 1, 'The reward of deeds depends upon the intentions', 'Umar ibn Al-Khattab', 'Sahih'],
['Muslim', 1, 1, 'Islam is built upon five pillars', 'Abdullah ibn Umar', 'Sahih'],
['Tirmidhi', 1, 1, 'The best of you is he who learns the Quran and teaches it', 'Uthman ibn Affan', 'Sahih'],
];

        $db->exec('BEGIN TRANSACTION');
        foreach ($sample_hadith as $h) {
            $q = $db->prepare("INSERT INTO hadith (collection, book_number, hadith_number, text, narrator, grading) VALUES (?, ?, ?, ?, ?, ?)");
            $q->bindValue(1, $h[0], SQLITE3_TEXT);
            $q->bindValue(2, $h[1], SQLITE3_INTEGER);
            $q->bindValue(3, $h[2], SQLITE3_INTEGER);
            $q->bindValue(4, $h[3], SQLITE3_TEXT);
            $q->bindValue(5, $h[4], SQLITE3_TEXT);
            $q->bindValue(6, $h[5], SQLITE3_TEXT);
            $q->execute();
        }
        $db->exec('COMMIT');
    }
    }

// Insert sample events if none exist
function load_sample_events() {
global $db;
$count = $db->query("SELECT COUNT(*) as count FROM events")->fetchArray()['count'];
    if ($count == 0) {
$sample_events = [
['Battle of Badr', 'First major battle between Muslims and Quraysh', '624-03-13', 'Islamic', 23.9798, 38.7822, 'Badr, Saudi Arabia', 1, 1],
['Conquest of Makkah', 'Peaceful conquest of Makkah by Muslims', '630-01-10', 'Islamic', 21.4225, 39.8262, 'Makkah, Saudi Arabia', 1, 1],
['Treaty of Hudaybiyyah', 'Peace treaty between Muslims and Quraysh', '628-03-01', 'Islamic', 21.4225, 39.7262, 'Hudaybiyyah', 1, 1]
];

        $db->exec('BEGIN TRANSACTION');
        foreach ($sample_events as $e) {
            $q = $db->prepare("INSERT INTO events (title, description, date, category, latitude, longitude, location, user_id, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $q->bindValue(1, $e[0], SQLITE3_TEXT);
            $q->bindValue(2, $e[1], SQLITE3_TEXT);
            $q->bindValue(3, $e[2], SQLITE3_TEXT);
            $q->bindValue(4, $e[3], SQLITE3_TEXT);
            $q->bindValue(5, $e[4], SQLITE3_FLOAT);
            $q->bindValue(6, $e[5], SQLITE3_FLOAT);
            $q->bindValue(7, $e[6], SQLITE3_TEXT);
            $q->bindValue(8, $e[7], SQLITE3_INTEGER);
            $q->bindValue(9, $e[8], SQLITE3_INTEGER);
            $q->execute();
        }
        $db->exec('COMMIT');

    }
    }

function log_action($user_id, $action, $details = null) {
global $db;
$q = $db->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)");
$q->bindValue(1, $user_id, $user_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
$q->bindValue(2, $action, SQLITE3_TEXT);
$q->bindValue(3, $details, $details === null ? SQLITE3_NULL : SQLITE3_TEXT);
$q->execute();
}

// Load initial data
load_quran_data();
load_sample_hadith();
load_sample_events();

// Handle Actions
$action = isset($_GET['action']) ? $_GET['action'] : '';
// Set default page
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Update page based on action if needed
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
$page = 'login';
} else if ($action === 'register' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
$page = 'register';
}
// Route handler
switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            // Force setting the page to login
            $page = 'login';
        } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

            $q = $db->prepare("SELECT * FROM users WHERE username = ?");
            $q->bindValue(1, $username, SQLITE3_TEXT);
            $result = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if ($result && verify_pwd($password, $result['password'])) {
                $_SESSION['user_id'] = $result['id'];
                $_SESSION['username'] = $result['username'];
                $_SESSION['role'] = $result['role'];
                log_action($result['id'], 'login');
                rdr('?');
            } else {
                $error = "Invalid username or password";
            }
        }
        break;
        
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            // Force setting the page to register
            $page = 'register';
        } else {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $email = $_POST['email'] ?? '';
            
            if (strlen($username) < 3 || strlen($password) < 6) {
                $error = "Username must be at least 3 characters and password at least 6 characters";
                break;
            }
            
            try {
                $q = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'user')");
                $q->bindValue(1, $username, SQLITE3_TEXT);
                $q->bindValue(2, hash_pwd($password), SQLITE3_TEXT);
                $q->bindValue(3, $email, SQLITE3_TEXT);
                $q->execute();
                
                $user_id = $db->lastInsertRowID();
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = 'user';
                log_action($user_id, 'register');
                rdr('?');
            } catch (Exception $e) {
                $error = "Registration failed: Username or email already exists";
            }
        }
        break;
        
    case 'logout':
        log_action($_SESSION['user_id'] ?? null, 'logout');
        session_destroy();
        rdr('?');
        break;
        
    case 'add_event':
        if (!is_logged()) rdr('?action=login');
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $date = $_POST['date'] ?? '';
            $category = $_POST['category'] ?? '';
            $location = $_POST['location'] ?? '';
            $lat = $_POST['latitude'] ?? null;
            $lng = $_POST['longitude'] ?? null;
            
            $approved = has_role('admin') || has_role('ulama') ? 1 : 0;
            
            $q = $db->prepare("INSERT INTO events (title, description, date, category, latitude, longitude, location, user_id, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $q->bindValue(1, $title, SQLITE3_TEXT);
            $q->bindValue(2, $description, SQLITE3_TEXT);
            $q->bindValue(3, $date, SQLITE3_TEXT);
            $q->bindValue(4, $category, SQLITE3_TEXT);
            $q->bindValue(5, $lat, $lat === null ? SQLITE3_NULL : SQLITE3_FLOAT);
            $q->bindValue(6, $lng, $lng === null ? SQLITE3_NULL : SQLITE3_FLOAT);
            $q->bindValue(7, $location, SQLITE3_TEXT);
            $q->bindValue(8, $_SESSION['user_id'], SQLITE3_INTEGER);
            $q->bindValue(9, $approved, SQLITE3_INTEGER);
            $q->execute();
            
            add_points(10);
            log_action($_SESSION['user_id'], 'add_event', "Added event: $title");
            rdr('?page=events');
        }
        break;
        
    case 'edit_event':
        if (!is_logged()) rdr('?action=login');
        
        $event_id = $_GET['id'] ?? 0;
        $event = NULL;
        
        if ($event_id) {
            $q = $db->prepare("SELECT * FROM events WHERE id = ?");
            $q->bindValue(1, $event_id, SQLITE3_INTEGER);
            $event = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if (!$event || (!has_role('admin') && !has_role('ulama') && $event['user_id'] != $_SESSION['user_id'])) {
                rdr('?page=events');
            }
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $date = $_POST['date'] ?? '';
            $category = $_POST['category'] ?? '';
            $location = $_POST['location'] ?? '';
            $lat = $_POST['latitude'] ?? null;
            $lng = $_POST['longitude'] ?? null;
            
            $q = $db->prepare("UPDATE events SET title = ?, description = ?, date = ?, category = ?, latitude = ?, longitude = ?, location = ? WHERE id = ?");
            $q->bindValue(1, $title, SQLITE3_TEXT);
            $q->bindValue(2, $description, SQLITE3_TEXT);
            $q->bindValue(3, $date, SQLITE3_TEXT);
            $q->bindValue(4, $category, SQLITE3_TEXT);
            $q->bindValue(5, $lat, $lat === null ? SQLITE3_NULL : SQLITE3_FLOAT);
            $q->bindValue(6, $lng, $lng === null ? SQLITE3_NULL : SQLITE3_FLOAT);
            $q->bindValue(7, $location, SQLITE3_TEXT);
            $q->bindValue(8, $event_id, SQLITE3_INTEGER);
            $q->execute();
            
            log_action($_SESSION['user_id'], 'edit_event', "Edited event: $title");
            rdr('?page=events');
        }
        break;
        
    case 'delete_event':
        if (!is_logged()) rdr('?action=login');
        
        $event_id = $_GET['id'] ?? 0;
        
        if ($event_id && (has_role('admin') || has_role('ulama'))) {
            $q = $db->prepare("SELECT title FROM events WHERE id = ?");
            $q->bindValue(1, $event_id, SQLITE3_INTEGER);
            $event = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if ($event) {
                $db->exec('BEGIN TRANSACTION');
                
                // Delete related content links
                $q = $db->prepare("DELETE FROM content_links WHERE event_id = ?");
                $q->bindValue(1, $event_id, SQLITE3_INTEGER);
                $q->execute();
                
                // Delete bookmarks for this event
                $q = $db->prepare("DELETE FROM bookmarks WHERE content_type = 'event' AND content_id = ?");
                $q->bindValue(1, $event_id, SQLITE3_INTEGER);
                $q->execute();
                
                // Delete the event
                $q = $db->prepare("DELETE FROM events WHERE id = ?");
                $q->bindValue(1, $event_id, SQLITE3_INTEGER);
                $q->execute();
                
                $db->exec('COMMIT');
                log_action($_SESSION['user_id'], 'delete_event', "Deleted event: {$event['title']}");
            }
        }
        rdr('?page=events');
        break;
        
    case 'approve_event':
        if (!is_logged() || (!has_role('admin') && !has_role('ulama'))) rdr('?action=login');
        
        $event_id = $_GET['id'] ?? 0;
        
        if ($event_id) {
            $q = $db->prepare("SELECT title, user_id FROM events WHERE id = ?");
            $q->bindValue(1, $event_id, SQLITE3_INTEGER);
            $event = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if ($event) {
                $q = $db->prepare("UPDATE events SET approved = 1 WHERE id = ?");
                $q->bindValue(1, $event_id, SQLITE3_INTEGER);
                $q->execute();
                
                // Award points to the user who created the event
                if ($event['user_id']) {
                    $q = $db->prepare("UPDATE users SET points = points + 20 WHERE id = ?");
                    $q->bindValue(1, $event['user_id'], SQLITE3_INTEGER);
                    $q->execute();
                    update_badges($event['user_id']);
                }
                
                log_action($_SESSION['user_id'], 'approve_event', "Approved event: {$event['title']}");
            }
        }
        rdr('?page=admin');
        break;
        
    case 'reject_event':
        if (!is_logged() || (!has_role('admin') && !has_role('ulama'))) rdr('?action=login');
        
        $event_id = $_GET['id'] ?? 0;
        
        if ($event_id) {
            $q = $db->prepare("SELECT title FROM events WHERE id = ?");
            $q->bindValue(1, $event_id, SQLITE3_INTEGER);
            $event = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if ($event) {
                $q = $db->prepare("DELETE FROM events WHERE id = ?");
                $q->bindValue(1, $event_id, SQLITE3_INTEGER);
                $q->execute();
                
                log_action($_SESSION['user_id'], 'reject_event', "Rejected event: {$event['title']}");
            }
        }
        rdr('?page=admin');
        break;
        
    case 'toggle_bookmark':
        if (!is_logged()) rdr('?action=login');
        
        $content_type = $_GET['type'] ?? '';
        $content_id = $_GET['id'] ?? 0;
        $redirect = $_GET['redirect'] ?? '?';
        
        if ($content_type && $content_id) {
            $q = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND content_type = ? AND content_id = ?");
            $q->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
            $q->bindValue(2, $content_type, SQLITE3_TEXT);
            $q->bindValue(3, $content_id, SQLITE3_INTEGER);
            $bookmark = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if ($bookmark) {
                $q = $db->prepare("DELETE FROM bookmarks WHERE id = ?");
                $q->bindValue(1, $bookmark['id'], SQLITE3_INTEGER);
                $q->execute();
                log_action($_SESSION['user_id'], 'remove_bookmark', "Removed $content_type bookmark: $content_id");
            } else {
                $q = $db->prepare("INSERT INTO bookmarks (user_id, content_type, content_id) VALUES (?, ?, ?)");
                $q->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
                $q->bindValue(2, $content_type, SQLITE3_TEXT);
                $q->bindValue(3, $content_id, SQLITE3_INTEGER);
                $q->execute();
                add_points(2);
                log_action($_SESSION['user_id'], 'add_bookmark', "Added $content_type bookmark: $content_id");
            }
        }
        rdr($redirect);
        break;
        
    case 'link_content':
        if (!is_logged() || (!has_role('admin') && !has_role('ulama'))) rdr('?action=login');
        
        $event_id = $_GET['event_id'] ?? 0;
        $content_type = $_GET['content_type'] ?? '';
        $content_id = $_GET['content_id'] ?? 0;
        
        if ($event_id && $content_type && $content_id) {
            $q = $db->prepare("INSERT OR IGNORE INTO content_links (event_id, content_type, content_id) VALUES (?, ?, ?)");
            $q->bindValue(1, $event_id, SQLITE3_INTEGER);
            $q->bindValue(2, $content_type, SQLITE3_TEXT);
            $q->bindValue(3, $content_id, SQLITE3_INTEGER);
            $q->execute();
            
            log_action($_SESSION['user_id'], 'link_content', "Linked $content_type:$content_id to event:$event_id");
        }
        rdr("?page=event&id=$event_id");
        break;
        
    case 'unlink_content':
        if (!is_logged() || (!has_role('admin') && !has_role('ulama'))) rdr('?action=login');
        
        $link_id = $_GET['id'] ?? 0;
        $event_id = $_GET['event_id'] ?? 0;
        
        if ($link_id) {
            $q = $db->prepare("DELETE FROM content_links WHERE id = ?");
            $q->bindValue(1, $link_id, SQLITE3_INTEGER);
            $q->execute();
            
            log_action($_SESSION['user_id'], 'unlink_content', "Removed content link: $link_id");
        }
        rdr("?page=event&id=$event_id");
        break;
        
    case 'toggle_theme':
        $_SESSION['theme'] = $_SESSION['theme'] === 'dark' ? 'light' : 'dark';
        $c['theme'] = $_SESSION['theme'];
        if (is_logged()) {
            log_action($_SESSION['user_id'], 'toggle_theme', "Changed theme to: {$_SESSION['theme']}");
        }
        rdr($_SERVER['HTTP_REFERER'] ?? '?');
        break;
        
    case 'change_language':
        $lang = $_GET['lang'] ?? 'en';
        if ($lang === 'en' || $lang === 'ur') {
            $_SESSION['lang'] = $lang;
            $c['lang'] = $lang;
            if (is_logged()) {
                log_action($_SESSION['user_id'], 'change_language', "Changed language to: $lang");
            }
        }
        rdr($_SERVER['HTTP_REFERER'] ?? '?');
        break;
        
    case 'backup':
        if (!is_logged() || !has_role('admin')) rdr('?action=login');
        
        $backup_file = 'islamic_history_backup_' . date('Y-m-d_H-i-s') . '.db';
        copy($c['db_path'], $backup_file);
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($backup_file));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backup_file));
        ob_clean();
        flush();
        readfile($backup_file);
        unlink($backup_file);
        log_action($_SESSION['user_id'], 'backup', "Created database backup");
        exit;
        
    case 'restore':
        if (!is_logged() || !has_role('admin')) rdr('?action=login');
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
            if ($_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $db->close();
                $backup_temp = $_FILES['backup_file']['tmp_name'];
                if (copy($backup_temp, $c['db_path'])) {
                    log_action($_SESSION['user_id'], 'restore', "Restored database from backup");
                    $db = new SQLite3($c['db_path']);
                    $success_msg = "Database restored successfully";
                } else {
                    $error = "Failed to restore database";
                }
            } else {
                $error = "Error uploading backup file";
            }
        }
        break;
    }

// Page Content
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
function remove_arabic_diacritics($text) {
    if ($text === null) {
        return null;
    }
    // Unicode characters for Arabic diacritics & Tatweel
    $diacritics = [
        "\u{064B}", // FATHATAN
        "\u{064C}", // DAMMATAN
        "\u{064D}", // KASRATAN
        "\u{064E}", // FATHA
        "\u{064F}", // DAMMA
        "\u{0650}", // KASRA
        "\u{0651}", // SHADDA
        "\u{0652}", // SUKUN
        "\u{0653}", // MADDAH ABOVE
        "\u{0654}", // HAMZA ABOVE
        "\u{0655}", // HAMZA BELOW
        "\u{0656}", // SUBSCRIPT ALEF
        "\u{0657}", // INVERTED DAMMA
        "\u{0658}", // MARK NOON GHUNNA
        "\u{0659}", // ZWARAKAY
        "\u{065A}", // VOWEL SIGN SMALL V ABOVE
        "\u{065B}", // VOWEL SIGN INVERTED SMALL V ABOVE
        "\u{065C}", // VOWEL SIGN DOT BELOW
        "\u{065D}", // REVERSED DAMMA
        "\u{065E}", // FATHA WITH TWO DOTS
        "\u{065F}", // WAVY HAMZA BELOW
        "\u{0670}", // ARABIC LETTER SUPERSCRIPT ALEF (Dagger Alif)
        "\u{0640}", // Tatweel (Kashida)
    ];
    return str_replace($diacritics, '', $text);
}
// Header
function show_header() {
global $c, $t;
$lang_class = $c['lang'] === 'ur' ? 'rtl' : 'ltr';
$theme_class = $c['theme'];

    echo '<!DOCTYPE html>
    <html lang="' . $c['lang'] . '" dir="' . $lang_class . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Yasin\'s Islamic History Explorer </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --primary-color: #43a047;
            --secondary-color: #1b5e20;
            --accent-color: #8bc34a;
        }
        body.light {
            background-color: #f8f9fa;
            color: #212529;
        }
        body.dark {
            background-color: #212529;
            color: #f8f9fa;
        }
        body.dark .card, body.dark .navbar, body.dark .modal-content {
            background-color: #343a40;
            color: #f8f9fa;
            border-color: #495057;
        }
        body.dark .form-control, body.dark .form-select {
            background-color: #495057;
            color: #f8f9fa;
            border-color: #6c757d;
        }
        body.dark .table {
            color: #f8f9fa;
        }
        body.dark .table-striped > tbody > tr:nth-of-type(odd) {
            background-color: rgba(255, 255, 255, 0.05);
        }
        .navbar-brand, .nav-link {
            font-weight: bold;
        }
        .navbar-brand {
            color: var(--primary-color);
        }
        .navbar-brand:hover {
            color: var(--secondary-color);
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        .rtl {
            direction: rtl;
            text-align: right;
        }
        .ltr {
            direction: ltr;
            text-align: left;
        }
        .arabic-text {
            font-family: "Traditional Arabic", "Scheherazade", serif;
            font-size: 1.5rem;
            line-height: 2.5rem;
        }
        .badge-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        #map-container {
            height: 500px;
            width: 100%;
            margin-bottom: 1rem;
        }
        .timeline {
            position: relative;
            max-width: 1200px;
            margin: 0 auto;
        }
        .timeline::after {
            content: "";
            position: absolute;
            width: 6px;
            background-color: var(--primary-color);
            top: 0;
            bottom: 0;
            left: 50%;
            margin-left: -3px;
        }
        .timeline-item {
            padding: 10px 40px;
            position: relative;
            width: 50%;
        }
        .timeline-item::after {
            content: "";
            position: absolute;
            width: 25px;
            height: 25px;
            right: -12px;
            background-color: white;
            border: 4px solid var(--primary-color);
            top: 15px;
            border-radius: 50%;
            z-index: 1;
        }
        .timeline-item-left {
            left: 0;
        }
        .timeline-item-right {
            left: 50%;
        }
        .timeline-item-left::after {
            right: -12px;
        }
        .timeline-item-right::after {
            left: -12px;
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
                left: 19px;
            }
            .timeline-item-left::after,
            .timeline-item-right::after {
                left: 19px;
            }
            .timeline-item-right {
                left: 0;
            }
        }
            a.btn.btn-sm.btn-primary.mt-2 {
    color: white !important;
}
    </style>
</head>
<body class="' . $theme_class . '">

<nav class="navbar navbar-expand-lg navbar-' . ($theme_class === 'dark' ? 'dark' : 'light') . ' bg-' . ($theme_class === 'dark' ? 'dark' : 'light') . ' mb-4">
    <div class="container">
        <a class="navbar-brand" href="?">
            <i class="bi bi-book"></i> ' . t('app_name') . '
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="?page=events"><i class="bi bi-calendar-event"></i> ' . t('events') . '</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?page=map"><i class="bi bi-geo-alt"></i> ' . t('map') . '</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?page=quran"><i class="bi bi-book"></i> ' . t('quran') . '</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?page=hadith"><i class="bi bi-chat-quote"></i> ' . t('hadith') . '</a>
                </li>';
    
    if (is_logged()) {
        echo '
                <li class="nav-item">
                    <a class="nav-link" href="?page=profile"><i class="bi bi-person"></i> ' . t('profile') . '</a>
                </li>';
        
        if (has_role('admin') || has_role('ulama')) {
            echo '
                <li class="nav-item">
                    <a class="nav-link" href="?page=admin"><i class="bi bi-gear"></i> ' . t('admin') . '</a>
                </li>';
        }
    }
    
    echo '
            </ul>
            <div class="d-flex">
                <div class="dropdown me-2">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-globe"></i> ' . strtoupper($c['lang']) . '
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="?action=change_language&lang=en">English</a></li>
                        <li><a class="dropdown-item" href="?action=change_language&lang=ur">اردو</a></li>
                    </ul>
                </div>
                <a href="?action=toggle_theme" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-' . ($c['theme'] === 'dark' ? 'sun' : 'moon') . '"></i>
                </a>';
    
    if (is_logged()) {
        echo '
                <div class="dropdown">
                    <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> ' . h($_SESSION['username']) . '
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="?page=profile"><i class="bi bi-person"></i> ' . t('profile') . '</a></li>
                        <li><a class="dropdown-item" href="?page=settings"><i class="bi bi-gear"></i> ' . t('settings') . '</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="?action=logout"><i class="bi bi-box-arrow-right"></i> ' . t('logout') . '</a></li>
                    </ul>
                </div>';
    } else {
        echo '<a href="?page=login" class="btn btn-sm btn-outline-primary me-2">
                <i class="bi bi-box-arrow-in-right"></i> ' . t('login') . '
            </a>
            <a href="?page=register" class="btn btn-sm btn-primary">
                <i class="bi bi-person-plus"></i> ' . t('register') . '
            </a>';
    }
    
    echo '
            </div>
        </div>
    </div>
</nav>

<div class="container mb-4">';

    if (isset($error)) {
        echo '<div class="alert alert-danger">' . h($error) . '</div>';
    }
    
    if (isset($success_msg)) {
        echo '<div class="alert alert-success">' . h($success_msg) . '</div>';
    }
}

// Footer
function show_footer() {
    echo '
</div>

<footer class="bg-dark text-light py-4 mt-5">
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <h5>' . t('app_name') . '</h5>
                <p class="small">© ' . date('Y') . ' Islamic History Explorer</p>
            </div>
            <div class="col-md-6 text-end">
                <div class="btn-group">
                    <a href="?action=change_language&lang=en" class="btn btn-sm btn-outline-light">English</a>
                    <a href="?action=change_language&lang=ur" class="btn btn-sm btn-outline-light">اردو</a>
                </div>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll(\'[data-bs-toggle="tooltip"]\');
    tooltips.forEach(tooltip => new bootstrap.Tooltip(tooltip));
    
    // Initialize map if container exists
    const mapContainer = document.getElementById("map-container");
    if (mapContainer) {
        const map = L.map("map-container").setView([24.5, 39.5], 5);
        
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: \'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors\'
        }).addTo(map);
        
        // Add markers if they exist in the page data
        if (typeof mapMarkers !== "undefined") {
            mapMarkers.forEach(marker => {
                L.marker([marker.lat, marker.lng])
                    .addTo(map)
                    .bindPopup(`<b>${marker.title}</b><br>${marker.desc}<br><a href="?page=event&id=${marker.id}" class="btn btn-sm btn-primary mt-2">View Details</a>`);
            });
        }
        
        // For event creation/editing
        if (document.getElementById("event-form")) {
            let marker;
            
            // Check if we have predefined coordinates
            const latInput = document.getElementById("latitude");
            const lngInput = document.getElementById("longitude");
            
            if (latInput.value && lngInput.value) {
                const lat = parseFloat(latInput.value);
                const lng = parseFloat(lngInput.value);
                
                map.setView([lat, lng], 8);
                marker = L.marker([lat, lng]).addTo(map);
            }
            
            map.on("click", function(e) {
                if (marker) {
                    map.removeLayer(marker);
                }
                
                marker = L.marker(e.latlng).addTo(map);
                
                latInput.value = e.latlng.lat.toFixed(6);
                lngInput.value = e.latlng.lng.toFixed(6);
            });
        }
    }
    
    // Initialize timeline chart if container exists
    const timelineCtx = document.getElementById("timeline-chart");
    if (timelineCtx && typeof timelineData !== "undefined") {
        const labels = timelineData.map(item => item.year);
        const islamicData = timelineData.map(item => item.islamic);
        const generalData = timelineData.map(item => item.general);
        
        new Chart(timelineCtx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [
                    {
                        label: "' . t('islamic') . '",
                        data: islamicData,
                        borderColor: "#43a047",
                        backgroundColor: "rgba(67, 160, 71, 0.1)",
                        tension: 0.3
                    },
                    {
                        label: "' . t('general') . '",
                        data: generalData,
                        borderColor: "#1976d2",
                        backgroundColor: "rgba(25, 118, 210, 0.1)",
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: "top",
                    },
                    title: {
                        display: true,
                        text: "' . t('timeline') . '"
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>';
}

// Show page content based on request
show_header();

switch ($page) {
case 'home':
// Featured events
$events = $db->query("
SELECT e.*, u.username
FROM events e
JOIN users u ON e.user_id = u.id
WHERE e.approved = 1
ORDER BY e.created_at DESC
LIMIT 4
");

        // Latest Quran ayahs
       $ayahs = $db->query("
            SELECT * 
            FROM quran 
            ORDER BY id DESC 
            LIMIT 3
        ");
                
        echo '<div class="row">
            <div class="col-12 text-center mb-5">
                <h1>' . t('welcome') . '</h1>
                <p class="lead">' . t('search_placeholder') . '</p>
                <form action="?page=search" method="get" class="mt-4">
                    <input type="hidden" name="page" value="search">
                    <div class="input-group mb-3">
                        <input type="text" name="q" class="form-control" placeholder="' . t('search_placeholder') . '">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> ' . t('search') . '</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="bi bi-calendar-event"></i> ' . t('events') . '</h5>
                        <a href="?page=events" class="btn btn-sm btn-primary">' . t('view_all') . '</a>
                    </div>
                    <div class="card-body">
                        <div class="list-group">';
        
        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
            echo '<a href="?page=event&id=' . $event['id'] . '" class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between">
                    <h5 class="mb-1">' . h($event['title']) . '</h5>
                    <small>' . h($event['date']) . '</small>
                </div>
                <p class="mb-1">' . h(substr($event['description'], 0, 100)) . '...</p>
                <small><i class="bi bi-tag"></i> ' . h($event['category']) . ' &middot; <i class="bi bi-geo-alt"></i> ' . h($event['location']) . '</small>
            </a>';
        }
        
        echo '</div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-map"></i> ' . t('map') . '</h5>
                    </div>
                    <div class="card-body">
                        <div id="map-container"></div>
                    </div>
                </div>
                
                <script>
                const mapMarkers = [';
        
        $map_events = $db->query("
            SELECT id, title, description, latitude, longitude 
            FROM events 
            WHERE approved = 1 
            AND latitude IS NOT NULL 
            AND longitude IS NOT NULL 
            LIMIT 10
        ");
        
        $markers = [];
        while ($marker = $map_events->fetchArray(SQLITE3_ASSOC)) {
            $markers[] = '{id: ' . $marker['id'] . ', lat: ' . $marker['latitude'] . ', lng: ' . $marker['longitude'] . ', title: "' . addslashes(h($marker['title'])) . '", desc: "' . addslashes(h(substr($marker['description'], 0, 50))) . '..."}';
        }
        
        echo implode(",\n", $markers);
        
        echo '];
                </script>
            </div>
            
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="bi bi-book"></i> ' . t('quran') . '</h5>
                        <a href="?page=quran" class="btn btn-sm btn-primary">' . t('view_all') . '</a>
                    </div>
                    <div class="card-body">';
        
        // When displaying ayahs:
$ayah_count = 0;
$total_ayahs = 3; // Or however many you're displaying
while ($ayah = $ayahs->fetchArray(SQLITE3_ASSOC)) {
    $ayah_count++;
    echo '<div class="mb-4">
        <p class="arabic-text text-center mb-1">' . h($ayah['arabic']) . '</p>
        <p class="text-muted text-center">' . h($ayah['urdu']) . '</p>
        <p class="text-center"><small>' . t('surah') . ' ' . $ayah['surah'] . ', ' . t('ayah') . ' ' . $ayah['ayah'] . '</small></p>
        <div class="text-center">
            <a href="?page=quran&surah=' . $ayah['surah'] . '&ayah=' . $ayah['ayah'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>';
            
    if (is_logged()) {
        // Bookmark code here
    }
            
    echo '</div>
    </div>';
    
    // Only add horizontal rule if not the last item
    if ($ayah_count < $total_ayahs) {
        echo '<hr>';
    }
}
        
        echo '</div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-trophy"></i> ' . t('badges') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">';
        
        $badges = $db->query("
            SELECT b.*, u.username 
            FROM badges b 
            JOIN users u ON b.user_id = u.id 
            ORDER BY b.created_at DESC 
            LIMIT 5
        ");
        
        while ($badge = $badges->fetchArray(SQLITE3_ASSOC)) {
            echo '<div class="list-group-item">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-' . h($badge['badge_icon']) . ' display-6 text-warning"></i>
                    </div>
                    <div>
                        <h6 class="mb-0">' . h($badge['badge_name']) . '</h6>
                        <small class="text-muted">' . h($badge['username']) . '</small>
                    </div>
                </div>
            </div>';
        }
        
        echo '</div>
                    </div>
                </div>
            </div>
        </div>';
        break;
        
    case 'login':
        echo '<div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-box-arrow-in-right"></i> ' . t('login') . '</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="?action=login">
                            <div class="mb-3">
                                <label for="username" class="form-label">' . t('username') . '</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">' . t('password') . '</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary">' . t('login') . '</button>
                        </form>
                        <div class="mt-3">
                            <p>' . t('no_account') . ' <a href="?action=register">' . t('register') . '</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
        break;
        
    case 'register':
        echo '<div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-person-plus"></i> ' . t('register') . '</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="?action=register">
                            <div class="mb-3">
                                <label for="username" class="form-label">' . t('username') . '</label>
                                <input type="text" class="form-control" id="username" name="username" required minlength="3">
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">' . t('email') . '</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">' . t('password') . '</label>
                                <input type="password" class="form-control" id="password" name="password" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary">' . t('register') . '</button>
                        </form>
                        <div class="mt-3">
                            <p>' . t('have_account') . ' <a href="?action=login">' . t('login') . '</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
        break;
        
    case 'events':
        $category = isset($_GET['category']) ? $_GET['category'] : '';
        $where = "WHERE approved = 1";
        if ($category) {
            $where .= " AND category = '" . s($category) . "'";
        }
        
        $events = $db->query("
            SELECT e.*, u.username 
            FROM events e 
            JOIN users u ON e.user_id = u.id 
            $where
            ORDER BY e.date DESC
        ");
        
        echo '<div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-calendar-event"></i> ' . t('events') . '</h2>';
            
        if (is_logged()) {
            echo '<a href="?page=add_event" class="btn btn-primary"><i class="bi bi-plus-circle"></i> ' . t('add_event') . '</a>';
        }
            
        echo '</div>
        
        <div class="mb-4">
            <div class="btn-group">
                <a href="?page=events" class="btn ' . (empty($category) ? 'btn-primary' : 'btn-outline-primary') . '">' . t('all') . '</a>
                <a href="?page=events&category=Islamic" class="btn ' . ($category == 'Islamic' ? 'btn-primary' : 'btn-outline-primary') . '">' . t('islamic') . '</a>
                <a href="?page=events&category=General" class="btn ' . ($category == 'General' ? 'btn-primary' : 'btn-outline-primary') . '">' . t('general') . '</a>
            </div>
        </div>
        
        <div class="timeline">';
        
        $count = 0;
        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
            $class = $count % 2 == 0 ? 'timeline-item-left' : 'timeline-item-right';
            
            echo '<div class="timeline-item ' . $class . '">
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <h5>' . h($event['title']) . '</h5>
                        <span class="badge bg-' . ($event['category'] == 'Islamic' ? 'success' : 'primary') . '">' . h($event['category']) . '</span>
                    </div>
                    <div class="card-body">
                        <p>' . h($event['description']) . '</p>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-calendar"></i> ' . h($event['date']) . '
                                <i class="bi bi-geo-alt ms-2"></i> ' . h($event['location']) . '
                            </div>
                            <a href="?page=event&id=' . $event['id'] . '" class="btn btn-sm btn-primary">' . t('view_details') . '</a>
                        </div>
                    </div>
                    <div class="card-footer text-muted">
                        ' . t('added_by') . ': ' . h($event['username']) . '
                    </div>
                </div>
            </div>';
            
            $count++;
        }
        
        echo '</div>';
        
        // Show timeline chart
        echo '<div class="card mt-5">
            <div class="card-header">
                <h5><i class="bi bi-graph-up"></i> ' . t('timeline') . '</h5>
            </div>
            <div class="card-body">
                <canvas id="timeline-chart"></canvas>
            </div>
        </div>
        
        <script>';
        
        // Generate timeline data
        $timeline_data = $db->query("
            SELECT 
                strftime('%Y', date) as year,
                COUNT(CASE WHEN category = 'Islamic' THEN 1 END) as islamic,
                COUNT(CASE WHEN category = 'General' THEN 1 END) as general
            FROM events
            WHERE approved = 1
            GROUP BY strftime('%Y', date)
            ORDER BY year
        ");
        
        echo 'const timelineData = [';
        while ($data = $timeline_data->fetchArray(SQLITE3_ASSOC)) {
            echo '{year: "' . $data['year'] . '", islamic: ' . $data['islamic'] . ', general: ' . $data['general'] . '},';
        }
        echo '];
        </script>';
        break;
        
    case 'event':
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        $q = $db->prepare("
            SELECT e.*, u.username 
            FROM events e 
            JOIN users u ON e.user_id = u.id 
            WHERE e.id = ?
        ");
        $q->bindValue(1, $id, SQLITE3_INTEGER);
        $event = $q->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$event || ($event['approved'] == 0 && !has_role('admin') && !has_role('ulama') && $event['user_id'] != $_SESSION['user_id'])) {
            echo '<div class="alert alert-danger">' . t('event_not_found') . '</div>';
            break;
        }
        
        // Check if event is bookmarked
        $is_bookmarked = false;
        if (is_logged()) {
            $q = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND content_type = 'event' AND content_id = ?");
            $q->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
            $q->bindValue(2, $id, SQLITE3_INTEGER);
            $bookmark = $q->execute()->fetchArray(SQLITE3_ASSOC);
            $is_bookmarked = $bookmark ? true : false;
        }
        
        // Get related content
        $q = $db->prepare("
            SELECT cl.*, q.surah, q.ayah, q.arabic, q.urdu, h.collection, h.book_number, h.hadith_number, h.text
            FROM content_links cl
            LEFT JOIN quran q ON cl.content_type = 'quran' AND cl.content_id = q.id
            LEFT JOIN hadith h ON cl.content_type = 'hadith' AND cl.content_id = h.id
            WHERE cl.event_id = ?
        ");
        $q->bindValue(1, $id, SQLITE3_INTEGER);
        $related_content = $q->execute();
        
        echo '<div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4>' . h($event['title']) . '</h4>
                        <span class="badge bg-' . ($event['category'] == 'Islamic' ? 'success' : 'primary') . '">' . h($event['category']) . '</span>
                    </div>
                    <div class="card-body">
                        <p class="lead">' . h($event['description']) . '</p>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <p><i class="bi bi-calendar"></i> <strong>' . t('date') . ':</strong> ' . h($event['date']) . '</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-geo-alt"></i> <strong>' . t('location') . ':</strong> ' . h($event['location']) . '</p>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <p><strong>' . t('added_by') . ':</strong> ' . h($event['username']) . '</p>
                        </div>';
        
        if ($event['latitude'] && $event['longitude']) {
            echo '<div id="map-container" class="mt-4"></div>
                  <script>
                  const mapMarkers = [{
                      id: ' . $event['id'] . ',
                      lat: ' . $event['latitude'] . ',
                      lng: ' . $event['longitude'] . ',
                      title: "' . addslashes(h($event['title'])) . '",
                      desc: "' . addslashes(h(substr($event['description'], 0, 50))) . '..."
                  }];
                  </script>';
        }
        
        echo '</div>
                    <div class="card-footer">
                        <div class="d-flex justify-content-between">';
        
        if (is_logged()) {
            echo '<a href="?action=toggle_bookmark&type=event&id=' . $event['id'] . '&redirect=' . u('?page=event&id=' . $event['id']) . '" class="btn ' . ($is_bookmarked ? 'btn-warning' : 'btn-outline-warning') . '">
                <i class="bi bi-bookmark' . ($is_bookmarked ? '-fill' : '') . '"></i> ' . ($is_bookmarked ? t('remove_bookmark') : t('add_bookmark')) . '
            </a>';
            
            if ($event['user_id'] == $_SESSION['user_id'] || has_role('admin') || has_role('ulama')) {
                echo '<div>
                    <a href="?page=edit_event&id=' . $event['id'] . '" class="btn btn-primary"><i class="bi bi-pencil"></i> ' . t('edit') . '</a>';
                
                if (has_role('admin') || has_role('ulama')) {
                    echo ' <a href="?action=delete_event&id=' . $event['id'] . '" class="btn btn-danger" onclick="return confirm(\'' . t('confirm_delete') . '\')"><i class="bi bi-trash"></i> ' . t('delete') . '</a>';
                }
                
                echo '</div>';
            }
        } else {
            echo '<a href="?action=login" class="btn btn-outline-primary">' . t('login_to_bookmark') . '</a>';
        }
        
        echo '</div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="bi bi-link"></i> ' . t('related_content') . '</h5>';
                
        if (has_role('admin') || has_role('ulama')) {
            echo '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#linkContentModal">
                <i class="bi bi-plus"></i> ' . t('add') . '
            </button>';
        }
                
        echo '</div>
                    <div class="card-body">';
        
        $has_related = false;
        while ($content = $related_content->fetchArray(SQLITE3_ASSOC)) {
            $has_related = true;
            
            if ($content['content_type'] == 'quran' && $content['surah']) {
                echo '<div class="mb-3 p-2 border rounded">
                    <div class="mb-2">
                        <span class="badge bg-success">' . t('quran') . '</span>
                        <span class="ms-1">' . t('surah') . ' ' . $content['surah'] . ', ' . t('ayah') . ' ' . $content['ayah'] . '</span>
                    </div>
                    <p class="arabic-text text-center">' . h($content['arabic']) . '</p>
                    <p class="text-muted">' . h($content['urdu']) . '</p>
                    <div class="d-flex justify-content-between">
                        <a href="?page=quran&surah=' . $content['surah'] . '&ayah=' . $content['ayah'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>';
                
                if (has_role('admin') || has_role('ulama')) {
                    echo '<a href="?action=unlink_content&id=' . $content['id'] . '&event_id=' . $event['id'] . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'' . t('confirm_unlink') . '\')">
                        <i class="bi bi-unlink"></i>
                    </a>';
                }
                
                echo '</div>
                </div>';
            } else if ($content['content_type'] == 'hadith' && $content['collection']) {
                echo '<div class="mb-3 p-2 border rounded">
                    <div class="mb-2">
                        <span class="badge bg-info">' . t('hadith') . '</span>
                        <span class="ms-1">' . h($content['collection']) . ' ' . $content['book_number'] . ':' . $content['hadith_number'] . '</span>
                    </div>
                    <p>' . h($content['text']) . '</p>
                    <div class="d-flex justify-content-between">
                        <a href="?page=hadith&id=' . $content['content_id'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>';
                
                if (has_role('admin') || has_role('ulama')) {
                    echo '<a href="?action=unlink_content&id=' . $content['id'] . '&event_id=' . $event['id'] . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'' . t('confirm_unlink') . '\')">
                        <i class="bi bi-unlink"></i>
                    </a>';
                }
                
                echo '</div>
                </div>';
            }
        }
        
        if (!$has_related) {
            echo '<p class="text-center text-muted">' . t('no_related_content') . '</p>';
        }
        
        echo '</div>
                </div>
            </div>
        </div>';
        
        // Add link content modal for admin/ulama
        if (has_role('admin') || has_role('ulama')) {
            echo '<div class="modal fade" id="linkContentModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">' . t('link_content') . '</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <ul class="nav nav-tabs" id="linkContentTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="quran-tab" data-bs-toggle="tab" data-bs-target="#quran-tab-pane" type="button" role="tab">
                                        ' . t('quran') . '
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="hadith-tab" data-bs-toggle="tab" data-bs-target="#hadith-tab-pane" type="button" role="tab">
                                        ' . t('hadith') . '
                                    </button>
                                </li>
                            </ul>
                            <div class="tab-content mt-3" id="linkContentTabContent">
                                <div class="tab-pane fade show active" id="quran-tab-pane" role="tabpanel" aria-labelledby="quran-tab" tabindex="0">
                                    <form action="?action=link_content" method="get">
                                        <input type="hidden" name="action" value="link_content">
                                        <input type="hidden" name="content_type" value="quran">
                                        <input type="hidden" name="event_id" value="' . $event['id'] . '">
                                        
                                        <div class="mb-3">
                                            <label for="quran_select" class="form-label">' . t('select_ayah') . '</label>
                                            <select name="content_id" id="quran_select" class="form-select" required>
                                                <option value="">' . t('select') . '...</option>';
            
            $quran_options = $db->query("SELECT id, surah, ayah, substr(urdu, 1, 50) as text FROM quran ORDER BY surah, ayah");
            while ($q = $quran_options->fetchArray(SQLITE3_ASSOC)) {
                echo '<option value="' . $q['id'] . '">' . t('surah') . ' ' . $q['surah'] . ', ' . t('ayah') . ' ' . $q['ayah'] . ' - ' . h(substr($q['text'], 0, 50)) . '...</option>';
            }
            
            echo '</select>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">' . t('link') . '</button>
                                    </form>
                                </div>
                                <div class="tab-pane fade" id="hadith-tab-pane" role="tabpanel" aria-labelledby="hadith-tab" tabindex="0">
                                    <form action="?action=link_content" method="get">
                                        <input type="hidden" name="action" value="link_content">
                                        <input type="hidden" name="content_type" value="hadith">
                                        <input type="hidden" name="event_id" value="' . $event['id'] . '">
                                        
                                        <div class="mb-3">
                                            <label for="hadith_select" class="form-label">' . t('select_hadith') . '</label>
                                            <select name="content_id" id="hadith_select" class="form-select" required>
                                                <option value="">' . t('select') . '...</option>';
            
            $hadith_options = $db->query("SELECT id, collection, book_number, hadith_number, substr(text, 1, 50) as text FROM hadith ORDER BY collection, book_number, hadith_number");
            while ($h = $hadith_options->fetchArray(SQLITE3_ASSOC)) {
                echo '<option value="' . $h['id'] . '">' . h($h['collection']) . ' ' . $h['book_number'] . ':' . $h['hadith_number'] . ' - ' . h(substr($h['text'], 0, 50)) . '...</option>';
            }
            
            echo '</select>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">' . t('link') . '</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
        }
        break;
        
    case 'add_event':
    case 'edit_event':
        if (!is_logged()) rdr('?action=login');
        
        $event = NULL;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($id) {
            $q = $db->prepare("SELECT * FROM events WHERE id = ?");
            $q->bindValue(1, $id, SQLITE3_INTEGER);
            $event = $q->execute()->fetchArray(SQLITE3_ASSOC);
            
            if (!$event || (!has_role('admin') && !has_role('ulama') && $event['user_id'] != $_SESSION['user_id'])) {
                rdr('?page=events');
            }
        }
        
        $is_edit = $event !== NULL;
        
        echo '<div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>' . ($is_edit ? '<i class="bi bi-pencil"></i> ' . t('edit_event') : '<i class="bi bi-plus-circle"></i> ' . t('add_event')) . '</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="?action=' . ($is_edit ? 'edit_event&id=' . $id : 'add_event') . '" id="event-form">
                            <div class="mb-3">
                                <label for="title" class="form-label">' . t('title') . '</label>
                                <input type="text" class="form-control" id="title" name="title" required value="' . ($is_edit ? h($event['title']) : '') . '">
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">' . t('description') . '</label>
                                <textarea class="form-control" id="description" name="description" rows="4" required>' . ($is_edit ? h($event['description']) : '') . '</textarea>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="date" class="form-label">' . t('date') . '</label>
                                    <input type="date" class="form-control" id="date" name="date" required value="' . ($is_edit ? h($event['date']) : date('Y-m-d')) . '">
                                </div>
                                <div class="col-md-6">
                                    <label for="category" class="form-label">' . t('category') . '</label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="Islamic" ' . ($is_edit && $event['category'] == 'Islamic' ? 'selected' : '') . '>' . t('islamic') . '</option>
                                        <option value="General" ' . ($is_edit && $event['category'] == 'General' ? 'selected' : '') . '>' . t('general') . '</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="location" class="form-label">' . t('location') . '</label>
                                <input type="text" class="form-control" id="location" name="location" required value="' . ($is_edit ? h($event['location']) : '') . '">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">' . t('map_location') . '</label>
                                <div id="map-container"></div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label for="latitude" class="form-label">' . t('latitude') . '</label>
                                        <input type="text" class="form-control" id="latitude" name="latitude" value="' . ($is_edit && $event['latitude'] ? h($event['latitude']) : '') . '">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="longitude" class="form-label">' . t('longitude') . '</label>
                                        <input type="text" class="form-control" id="longitude" name="longitude" value="' . ($is_edit && $event['longitude'] ? h($event['longitude']) : '') . '">
                                    </div>
                                </div>
                                <small class="form-text text-muted">' . t('click_map') . '</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">' . t('submit') . '</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>';
        break;
        
    case 'quran':
        $surah = isset($_GET['surah']) ? intval($_GET['surah']) : null;
        $ayah = isset($_GET['ayah']) ? intval($_GET['ayah']) : null;
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        
        $where = '';
        $params = [];
        
        case 'quran':
            $surah = isset($_GET['surah']) ? intval($_GET['surah']) : null;
            $ayah_num_filter = isset($_GET['ayah']) ? intval($_GET['ayah']) : null; // Renamed from $ayah to avoid conflict
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';

            $conditions = [];
            $current_params = []; // Renamed from $params
            $apply_limit = true;

            if ($surah !== null) {
                $conditions[] = "surah = ?";
                $current_params[] = $surah;
                $apply_limit = false; // Typically show all ayahs of a surah
                if ($ayah_num_filter !== null) {
                    $conditions[] = "ayah = ?";
                    $current_params[] = $ayah_num_filter;
                    // $apply_limit remains false
                }
            } elseif (!empty($search)) {
                $stripped_search = remove_arabic_diacritics($search);
                $conditions[] = "(REMOVE_DIACRITICS(arabic) LIKE ? OR urdu LIKE ?)";
                $current_params[] = "%" . $stripped_search . "%";
                $current_params[] = "%" . $search . "%";
                // $apply_limit remains true for search results
            }
        
        echo '<div class="row mb-4">
            <div class="col-md-6">
                <h2><i class="bi bi-book"></i> ' . t('quran') . '</h2>
            </div>
            <div class="col-md-6">
                <form action="?page=quran" method="get" class="d-flex">
                    <input type="hidden" name="page" value="quran">
                    <input type="text" name="search" class="form-control me-2" placeholder="' . t('search') . '..." value="' . h($search) . '">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                </form>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5>' . t('browse_by_surah') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="row row-cols-3 g-2">';
        
        $surahs = $db->query("SELECT DISTINCT surah FROM quran ORDER BY surah");
        while ($s = $surahs->fetchArray(SQLITE3_ASSOC)) {
            $surah_num = $s['surah'];
            echo '<div class="col">
                <a href="?page=quran&surah=' . $surah_num . '" class="btn btn-outline-primary w-100">' . $surah_num . '</a>
            </div>';
        }
        
        echo '</div>
                    </div>
                </div>
            </div>';
        
        if ($surah) {
            echo '<div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>' . t('surah') . ' ' . $surah . ' - ' . t('ayahs') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">';
            
            $ayahs_list = $db->query("SELECT ayah FROM quran WHERE surah = $surah ORDER BY ayah");
            while ($a = $ayahs_list->fetchArray(SQLITE3_ASSOC)) {
                $ayah_num = $a['ayah'];
                echo '<a href="?page=quran&surah=' . $surah . '&ayah=' . $ayah_num . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    ' . t('ayah') . ' ' . $ayah_num . '
                    <span class="badge bg-primary rounded-pill"><i class="bi bi-chevron-right"></i></span>
                </a>';
            }
            
            echo '</div>
                    </div>
                </div>
            </div>';
        }
        
        echo '</div>';
        
        $q = $db->prepare("SELECT * FROM quran $where ORDER BY surah, ayah LIMIT 300");
        foreach ($params as $i => $param) {
            $q->bindValue($i + 1, $param);
        }
        $result = $q->execute();
        
        while ($ayah = $result->fetchArray(SQLITE3_ASSOC)) {
            // Check if ayah is bookmarked by current user
            $is_bookmarked = false;
            if (is_logged()) {
                $bq = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND content_type = 'quran' AND content_id = ?");
                $bq->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
                $bq->bindValue(2, $ayah['id'], SQLITE3_INTEGER);
                $bookmark = $bq->execute()->fetchArray(SQLITE3_ASSOC);
                $is_bookmarked = $bookmark ? true : false;
            }
            
            echo '<div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>' . t('surah') . ' ' . $ayah['surah'] . ', ' . t('ayah') . ' ' . $ayah['ayah'] . '</h5>';
            
            if (is_logged()) {
                echo '<a href="?action=toggle_bookmark&type=quran&id=' . $ayah['id'] . '&redirect=' . u('?page=quran&surah=' . $ayah['surah'] . '&ayah=' . $ayah['ayah']) . '" class="btn ' . ($is_bookmarked ? 'btn-warning' : 'btn-outline-warning') . '">
                    <i class="bi bi-bookmark' . ($is_bookmarked ? '-fill' : '') . '"></i>
                </a>';
            }
            
            echo '</div>
                <div class="card-body">
                    <div class="arabic-text text-center mb-4">' . h($ayah['arabic']) . '</div>
                    <div class="text-center' . ($c['lang'] === 'ur' ? ' rtl' : '') . '">' . h($ayah['urdu']) . '</div>
                </div>';
            
            // Get related events
            $eq = $db->prepare("
                SELECT e.id, e.title 
                FROM events e
                JOIN content_links cl ON e.id = cl.event_id
                WHERE cl.content_type = 'quran' AND cl.content_id = ? AND e.approved = 1
            ");
            $eq->bindValue(1, $ayah['id'], SQLITE3_INTEGER);
            $related_events = $eq->execute();
            
            $has_related = false;
            while ($event = $related_events->fetchArray(SQLITE3_ASSOC)) {
                if (!$has_related) {
                    echo '<div class="card-footer">
                        <h6>' . t('related_events') . ':</h6>
                        <ul class="list-group list-group-flush">';
                    $has_related = true;
                }
                
                echo '<li class="list-group-item">
                    <a href="?page=event&id=' . $event['id'] . '">' . h($event['title']) . '</a>
                </li>';
            }
            
            if ($has_related) {
                echo '</ul></div>';
            }
            
            echo '</div>';
        }
        break;
        
    case 'hadith':
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        $collection = isset($_GET['collection']) ? $_GET['collection'] : null;
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        
        $where = '';
        $params = [];
        
        if ($id) {
            $where .= "WHERE id = ?";
            $params[] = $id;
        } elseif ($collection) {
            $where .= "WHERE collection = ?";
            $params[] = $collection;
        } elseif ($search) {
            $where .= "WHERE text LIKE ? OR narrator LIKE ?";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        echo '<div class="row mb-4">
            <div class="col-md-6">
                <h2><i class="bi bi-chat-quote"></i> ' . t('hadith') . '</h2>
            </div>
            <div class="col-md-6">
                <form action="?page=hadith" method="get" class="d-flex">
                    <input type="hidden" name="page" value="hadith">
                    <input type="text" name="search" class="form-control me-2" placeholder="' . t('search') . '..." value="' . h($search) . '">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                </form>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5>' . t('collections') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2">';
        
        $collections = $db->query("SELECT DISTINCT collection FROM hadith ORDER BY collection");
        while ($c = $collections->fetchArray(SQLITE3_ASSOC)) {
            $coll = $c['collection'];
            echo '<a href="?page=hadith&collection=' . u($coll) . '" class="btn btn-outline-primary">' . h($coll) . '</a>';
        }
        
        echo '</div>
                    </div>
                </div>
            </div>
        </div>';
        
        $q = $db->prepare("SELECT * FROM hadith $where ORDER BY collection, book_number, hadith_number LIMIT 20");
        foreach ($params as $i => $param) {
            $q->bindValue($i + 1, $param);
        }
        $result = $q->execute();
        
        while ($hadith = $result->fetchArray(SQLITE3_ASSOC)) {
            // Check if hadith is bookmarked by current user
            $is_bookmarked = false;
            if (is_logged()) {
                $bq = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND content_type = 'hadith' AND content_id = ?");
                $bq->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
                $bq->bindValue(2, $hadith['id'], SQLITE3_INTEGER);
                $bookmark = $bq->execute()->fetchArray(SQLITE3_ASSOC);
                $is_bookmarked = $bookmark ? true : false;
            }
            
            echo '<div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>' . h($hadith['collection']) . ' ' . $hadith['book_number'] . ':' . $hadith['hadith_number'] . '</h5>';
            
            if (is_logged()) {
                echo '<a href="?action=toggle_bookmark&type=hadith&id=' . $hadith['id'] . '&redirect=' . u('?page=hadith&id=' . $hadith['id']) . '" class="btn ' . ($is_bookmarked ? 'btn-warning' : 'btn-outline-warning') . '">
                    <i class="bi bi-bookmark' . ($is_bookmarked ? '-fill' : '') . '"></i>
                </a>';
            }
            
            echo '</div>
                <div class="card-body">
                    <blockquote class="blockquote mb-0">
                        <p>' . h($hadith['text']) . '</p>
                        <footer class="blockquote-footer">' . h($hadith['narrator']) . '</footer>
                    </blockquote>';
            
            if ($hadith['grading']) {
                echo '<div class="mt-3">
                    <span class="badge bg-info">' . h($hadith['grading']) . '</span>
                </div>';
            }
            
            echo '</div>';
            
            // Get related events
            $eq = $db->prepare("
                SELECT e.id, e.title 
                FROM events e
                JOIN content_links cl ON e.id = cl.event_id
                WHERE cl.content_type = 'hadith' AND cl.content_id = ? AND e.approved = 1
            ");
            $eq->bindValue(1, $hadith['id'], SQLITE3_INTEGER);
            $related_events = $eq->execute();
            
            $has_related = false;
            while ($event = $related_events->fetchArray(SQLITE3_ASSOC)) {
                if (!$has_related) {
                    echo '<div class="card-footer">
                        <h6>' . t('related_events') . ':</h6>
                        <ul class="list-group list-group-flush">';
                    $has_related = true;
                }
                
                echo '<li class="list-group-item">
                    <a href="?page=event&id=' . $event['id'] . '">' . h($event['title']) . '</a>
                </li>';
            }
            
            if ($has_related) {
                echo '</ul></div>';
            }
            
            echo '</div>';
        }
        break;
        
    case 'map':
        echo '<div class="row">
            <div class="col-12">
                <h2><i class="bi bi-geo-alt"></i> ' . t('interactive_map') . '</h2>
                <div class="card">
                    <div class="card-body">
                        <div id="map-container"></div>
                    </div>
                </div>
                
                <script>';
        
        $map_events = $db->query("
            SELECT id, title, description, latitude, longitude, category 
            FROM events 
            WHERE approved = 1 
            AND latitude IS NOT NULL 
            AND longitude IS NOT NULL
        ");
        
        echo 'const mapMarkers = [';
        while ($marker = $map_events->fetchArray(SQLITE3_ASSOC)) {
            echo '{id: ' . $marker['id'] . ', lat: ' . $marker['latitude'] . ', lng: ' . $marker['longitude'] . ', title: "' . addslashes(h($marker['title'])) . '", desc: "' . addslashes(h(substr($marker['description'], 0, 100))) . '...", category: "' . $marker['category'] . '"},';
        }
        echo '];
                </script>
            </div>
        </div>';
        break;
        
    case 'profile':
        if (!is_logged()) rdr('?action=login');
        
        $user_id = $_SESSION['user_id'];
        
        $q = $db->prepare("SELECT * FROM users WHERE id = ?");
        $q->bindValue(1, $user_id, SQLITE3_INTEGER);
        $user = $q->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$user) rdr('?');
        
        $q = $db->prepare("
            SELECT b.*, 
                CASE 
                    WHEN b.content_type = 'event' THEN e.title 
                    WHEN b.content_type = 'quran' THEN (SELECT 'Surah ' || q.surah || ', Ayah ' || q.ayah FROM quran q WHERE q.id = b.content_id)
                    WHEN b.content_type = 'hadith' THEN (SELECT h.collection || ' ' || h.book_number || ':' || h.hadith_number FROM hadith h WHERE h.id = b.content_id)
                END as content_title,
                CASE
                    WHEN b.content_type = 'event' THEN '?page=event&id=' || b.content_id
                    WHEN b.content_type = 'quran' THEN '?page=quran&surah=' || (SELECT q.surah FROM quran q WHERE q.id = b.content_id) || '&ayah=' || (SELECT q.ayah FROM quran q WHERE q.id = b.content_id)
                    WHEN b.content_type = 'hadith' THEN '?page=hadith&id=' || b.content_id
                END as content_url
            FROM bookmarks b
            LEFT JOIN events e ON b.content_type = 'event' AND b.content_id = e.id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC
        ");
        $q->bindValue(1, $user_id, SQLITE3_INTEGER);
        $bookmarks = $q->execute();
        
        $badges = get_user_badges($user_id);
        
        // User events
        $q = $db->prepare("
            SELECT * FROM events 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ");
        $q->bindValue(1, $user_id, SQLITE3_INTEGER);
        $events = $q->execute();
        
        echo '<div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-person"></i> ' . t('profile') . '</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-3">
                            <i class="bi bi-person-circle display-1"></i>
                        </div>
                        <h4>' . h($user['username']) . '</h4>
                        <p class="text-muted">' . t('role') . ': ' . h($user['role']) . '</p>
                        <div class="d-flex justify-content-center">
                            <div class="badge bg-primary p-2 mx-1">
                                <i class="bi bi-star-fill"></i> ' . t('points') . ': ' . $user['points'] . '
                            </div>
                            <div class="badge bg-success p-2 mx-1">
                                <i class="bi bi-calendar-check"></i> ' . t('member_since') . ': ' . date('M Y', strtotime($user['created_at'])) . '
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-trophy"></i> ' . t('badges') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">';
                        
        if (count($badges) > 0) {
            foreach ($badges as $badge) {
                echo '<div class="col-4 text-center mb-3">
                    <div class="badge-icon">
                        <i class="bi bi-' . h($badge['badge_icon']) . ' text-warning"></i>
                    </div>
                    <div>' . h($badge['badge_name']) . '</div>
                </div>';
            }
        } else {
            echo '<div class="col-12 text-center text-muted py-3">
                <p>' . t('no_badges_yet') . '</p>
                <small>' . t('earn_badges_by_contributing') . '</small>
            </div>';
        }
                        
        echo '</div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-calendar-event"></i> ' . t('your_events') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">';
        
        $event_count = 0;
        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
            $event_count++;
            echo '<div class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between">
                    <h5 class="mb-1">' . h($event['title']) . '</h5>
                    <small>' . date('M d, Y', strtotime($event['date'])) . '</small>
                </div>
                <p class="mb-1">' . h(substr($event['description'], 0, 100)) . '...</p>
                <div class="d-flex justify-content-between align-items-center">
                    <small>
                        <span class="badge bg-' . ($event['category'] == 'Islamic' ? 'success' : 'primary') . '">' . h($event['category']) . '</span>
                        <span class="badge bg-' . ($event['approved'] ? 'success' : 'warning') . '">' . ($event['approved'] ? t('approved') : t('pending_approval')) . '</span>
                    </small>
                    <div>
                        <a href="?page=event&id=' . $event['id'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>
                        <a href="?action=edit_event&id=' . $event['id'] . '" class="btn btn-sm btn-outline-secondary">' . t('edit') . '</a>
                    </div>
                </div>
            </div>';
        }
        
        if ($event_count == 0) {
            echo '<div class="text-center text-muted py-3">
                <p>' . t('no_events_yet') . '</p>
                <a href="?page=add_event" class="btn btn-primary mt-2"><i class="bi bi-plus-circle"></i> ' . t('add_event') . '</a>
            </div>';
        }
        
        echo '</div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-bookmark"></i> ' . t('bookmarks') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">';
        
        $bookmark_count = 0;
        while ($bookmark = $bookmarks->fetchArray(SQLITE3_ASSOC)) {
            $bookmark_count++;
            $icon_class = '';
            
            switch ($bookmark['content_type']) {
                case 'event':
                    $icon_class = 'calendar-event';
                    break;
                case 'quran':
                    $icon_class = 'book';
                    break;
                case 'hadith':
                    $icon_class = 'chat-quote';
                    break;
            }
            
            echo '<div class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between">
                    <div>
                        <i class="bi bi-' . $icon_class . '"></i>
                        <strong>' . t($bookmark['content_type']) . ':</strong> ' . h($bookmark['content_title']) . '
                    </div>
                    <small>' . date('M d, Y', strtotime($bookmark['created_at'])) . '</small>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="text-muted">
                        ' . t('bookmarked') . ': ' . date('F j, Y', strtotime($bookmark['created_at'])) . '
                    </small>
                    <div>
                        <a href="' . $bookmark['content_url'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>
                        <a href="?action=toggle_bookmark&type=' . $bookmark['content_type'] . '&id=' . $bookmark['content_id'] . '&redirect=' . u('?page=profile') . '" class="btn btn-sm btn-outline-danger">' . t('remove') . '</a>
                    </div>
                </div>
            </div>';
        }
        
        if ($bookmark_count == 0) {
            echo '<div class="text-center text-muted py-3">
                <p>' . t('no_bookmarks_yet') . '</p>
                <small>' . t('bookmark_content_by_clicking') . '</small>
            </div>';
        }
        
        echo '</div>
                    </div>
                </div>
            </div>
        </div>';
        break;
        
    case 'admin':
        if (!is_logged() || (!has_role('admin') && !has_role('ulama'))) rdr('?action=login');
        
        // Pending events
        $pending = $db->query("
            SELECT e.*, u.username 
            FROM events e 
            JOIN users u ON e.user_id = u.id 
            WHERE e.approved = 0 
            ORDER BY e.created_at DESC
        ");
        
        // Recent users
        $users = $db->query("
            SELECT * FROM users
            ORDER BY created_at DESC
            LIMIT 10
        ");
        
        // System logs
        $logs = $db->query("
            SELECT l.*, u.username 
            FROM logs l
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.created_at DESC
            LIMIT 20
        ");
        
        echo '<div class="card mb-4">
    <div class="card-header">
        <h5><i class="bi bi-people-gear"></i> ' . t('manage_users') . '</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>' . t('username') . '</th>
                        <th>' . t('email') . '</th>
                        <th>' . t('role') . '</th>
                        <th>' . t('points') . '</th>
                        <th>' . t('actions') . '</th>
                    </tr>
                </thead>
                <tbody>';
                
$all_users = $db->query("SELECT * FROM users ORDER BY id");
while ($u = $all_users->fetchArray(SQLITE3_ASSOC)) {
    echo '<tr>
        <td>' . $u['id'] . '</td>
        <td>' . h($u['username']) . '</td>
        <td>' . h($u['email']) . '</td>
        <td>' . h($u['role']) . '</td>
        <td>' . $u['points'] . '</td>
        <td>';
    
    if (has_role('admin') && $u['id'] != $_SESSION['user_id']) {
        echo '<form method="post" action="?page=change_role" class="d-inline">
            <input type="hidden" name="user_id" value="' . $u['id'] . '">
            <select name="new_role" class="form-select form-select-sm d-inline-block w-auto me-2">
                <option value="user" ' . ($u['role'] == 'user' ? 'selected' : '') . '>user</option>
                <option value="ulama" ' . ($u['role'] == 'ulama' ? 'selected' : '') . '>ulama</option>';
        
        if (has_role('admin')) {
            echo '<option value="admin" ' . ($u['role'] == 'admin' ? 'selected' : '') . '>admin</option>';
        }
        
        echo '</select>
            <button type="submit" class="btn btn-sm btn-primary">' . t('update') . '</button>
        </form>';
    }
    
    echo '</td>
    </tr>';
}

echo '</tbody>
            </table>
        </div>
    </div>
</div>';
        
        echo '<div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-hourglass"></i> ' . t('pending_approval') . '</h5>
                    </div>
                    <div class="card-body">';
        
        $pending_count = 0;
        while ($event = $pending->fetchArray(SQLITE3_ASSOC)) {
            $pending_count++;
            
            echo '<div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6>' . h($event['title']) . '</h6>
                    <span class="badge bg-' . ($event['category'] == 'Islamic' ? 'success' : 'primary') . '">' . h($event['category']) . '</span>
                </div>
                <div class="card-body">
                    <p>' . h(substr($event['description'], 0, 200)) . '...</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <small>
                            <i class="bi bi-person"></i> ' . h($event['username']) . ' &middot;
                            <i class="bi bi-calendar"></i> ' . h($event['date']) . ' &middot;
                            <i class="bi bi-geo-alt"></i> ' . h($event['location']) . '
                        </small>
                        <div>
                            <a href="?page=event&id=' . $event['id'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>
                            <a href="?action=approve_event&id=' . $event['id'] . '" class="btn btn-sm btn-success">' . t('approve') . '</a>
                            <a href="?action=reject_event&id=' . $event['id'] . '" class="btn btn-sm btn-danger" onclick="return confirm(\'' . t('confirm_reject') . '\')">' . t('reject') . '</a>
                        </div>
                    </div>
                </div>
            </div>';
        }
        
        if ($pending_count == 0) {
            echo '<div class="alert alert-success">
                <i class="bi bi-check-circle"></i> ' . t('no_pending_events') . '
            </div>';
        }
        
        echo '</div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-list-ul"></i> ' . t('system_logs') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>' . t('time') . '</th>
                                        <th>' . t('user') . '</th>
                                        <th>' . t('action') . '</th>
                                        <th>' . t('details') . '</th>
                                    </tr>
                                </thead>
                                <tbody>';
        
        while ($log = $logs->fetchArray(SQLITE3_ASSOC)) {
            echo '<tr>
                <td>' . date('Y-m-d H:i', strtotime($log['created_at'])) . '</td>
                <td>' . ($log['username'] ? h($log['username']) : '<em>system</em>') . '</td>
                <td>' . h($log['action']) . '</td>
                <td>' . h($log['details'] ?? '') . '</td>
            </tr>';
        }
        
        echo '</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-people"></i> ' . t('recent_users') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">';
        
        while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
            echo '<div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <div>
                    <strong>' . h($user['username']) . '</strong>
                    <br>
                    <small class="text-muted">' . h($user['email']) . '</small>
                </div>
                <div>
                    <span class="badge bg-' . ($user['role'] == 'admin' ? 'danger' : ($user['role'] == 'ulama' ? 'warning' : 'primary')) . '">' . h($user['role']) . '</span>
                </div>
            </div>';
        }
        
        echo '</div>
                    </div>
                </div>';
                
        if (has_role('admin')) {
            echo '<div class="card mb-4">
                <div class="card-header">
                    <h5><i class="bi bi-database"></i> ' . t('database_management') . '</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="?action=backup" class="btn btn-primary">
                            <i class="bi bi-download"></i> ' . t('backup') . '
                        </a>
                        
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#restoreModal">
                            <i class="bi bi-upload"></i> ' . t('restore') . '
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Restore Database Modal -->
            <div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">' . t('restore_database') . '</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> ' . t('restore_warning') . '
                            </div>
                            <form action="?action=restore" method="post" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="backup_file" class="form-label">' . t('select_backup_file') . '</label>
                                    <input class="form-control" type="file" id="backup_file" name="backup_file" accept=".db" required>
                                </div>
                                <button type="submit" class="btn btn-warning">' . t('restore') . '</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>';
        }
        
        echo '</div>
        </div>';
        break;
    case 'change_role':
    if (!is_logged() || !has_role('admin')) rdr('?page=login');
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $new_role = isset($_POST['new_role']) ? $_POST['new_role'] : '';
        
        // Validate role
        if ($new_role !== 'admin' && $new_role !== 'ulama' && $new_role !== 'user') {
            $error = "Invalid role";
            rdr('?page=admin');
        }
        
        // Don't allow changing your own role
        if ($user_id == $_SESSION['user_id']) {
            $error = "Cannot change your own role";
            rdr('?page=admin');
        }
        
        $q = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
        $q->bindValue(1, $new_role, SQLITE3_TEXT);
        $q->bindValue(2, $user_id, SQLITE3_INTEGER);
        $q->execute();
        
        log_action($_SESSION['user_id'], 'change_role', "Changed user ID $user_id role to $new_role");
        $success_msg = "User role updated successfully";
        rdr('?page=admin');
    }
    break;    
    case 'settings':
        if (!is_logged()) rdr('?action=login');
        
        echo '<div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-gear"></i> ' . t('settings') . '</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6>' . t('theme') . '</h6>
                            <div class="btn-group">
                                <a href="?action=toggle_theme" class="btn ' . ($c['theme'] === 'light' ? 'btn-primary' : 'btn-outline-primary') . '">
                                    <i class="bi bi-sun"></i> ' . t('light_mode') . '
                                </a>
                                <a href="?action=toggle_theme" class="btn ' . ($c['theme'] === 'dark' ? 'btn-primary' : 'btn-outline-primary') . '">
                                    <i class="bi bi-moon"></i> ' . t('dark_mode') . '
                                </a>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <h6>' . t('language') . '</h6>
                            <div class="btn-group">
                                <a href="?action=change_language&lang=en" class="btn ' . ($c['lang'] === 'en' ? 'btn-primary' : 'btn-outline-primary') . '">
                                    English
                                </a>
                                <a href="?action=change_language&lang=ur" class="btn ' . ($c['lang'] === 'ur' ? 'btn-primary' : 'btn-outline-primary') . '">
                                    اردو
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
        break;
        
    case 'search':
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        
        if (empty($q)) {
            rdr('?');
        }
        
        echo '<div class="row">
            <div class="col-12 mb-4">
                <h2><i class="bi bi-search"></i> ' . t('search_results') . ': "' . h($q) . '"</h2>
                <form action="?page=search" method="get" class="mt-3">
                    <input type="hidden" name="page" value="search">
                    <div class="input-group mb-3">
                        <input type="text" name="q" class="form-control" placeholder="' . t('search_placeholder') . '" value="' . h($q) . '">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> ' . t('search') . '</button>
                    </div>
                </form>
            </div>
        </div>';
        
        // Search in events
        $sq = $db->prepare("
            SELECT e.*, u.username 
            FROM events e 
            JOIN users u ON e.user_id = u.id 
            WHERE e.approved = 1 
            AND (e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)
            ORDER BY e.date DESC
            LIMIT 10
        ");
        $sq->bindValue(1, "%$q%", SQLITE3_TEXT);
        $sq->bindValue(2, "%$q%", SQLITE3_TEXT);
        $sq->bindValue(3, "%$q%", SQLITE3_TEXT);
        $events = $sq->execute();
        
        // Search in Quran
        $sq = $db->prepare("
            SELECT * FROM quran
            WHERE arabic LIKE ? OR urdu LIKE ?
            ORDER BY surah, ayah
            LIMIT 10
        ");
        $sq->bindValue(1, "%$q%", SQLITE3_TEXT);
        $sq->bindValue(2, "%$q%", SQLITE3_TEXT);
        $quran = $sq->execute();
        
        // Search in Hadith
        $sq = $db->prepare("
            SELECT * FROM hadith
            WHERE text LIKE ? OR narrator LIKE ? OR collection LIKE ?
            ORDER BY collection, book_number, hadith_number
            LIMIT 10
        ");
        $sq->bindValue(1, "%$q%", SQLITE3_TEXT);
        $sq->bindValue(2, "%$q%", SQLITE3_TEXT);
        $sq->bindValue(3, "%$q%", SQLITE3_TEXT);
        $hadith = $sq->execute();
        
        // Show results
        echo '<div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-calendar-event"></i> ' . t('events') . '</h5>
                    </div>
                    <div class="card-body">';
        
        $event_count = 0;
        while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
            $event_count++;
            
            echo '<div class="mb-3 pb-3 border-bottom">
                <h5><a href="?page=event&id=' . $event['id'] . '">' . h($event['title']) . '</a></h5>
                <p>' . h(substr($event['description'], 0, 150)) . '...</p>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-' . ($event['category'] == 'Islamic' ? 'success' : 'primary') . '">' . h($event['category']) . '</span>
                        <small class="text-muted ms-2">
                            <i class="bi bi-calendar"></i> ' . h($event['date']) . ' &middot;
                            <i class="bi bi-geo-alt"></i> ' . h($event['location']) . '
                        </small>
                    </div>
                    <a href="?page=event&id=' . $event['id'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>
                </div>
            </div>';
        }
        
        if ($event_count == 0) {
            echo '<p class="text-center text-muted">' . t('no_matching_events') . '</p>';
        }
        
        echo '</div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-book"></i> ' . t('quran') . '</h5>
                    </div>
                    <div class="card-body">';
        
        $quran_count = 0;
        while ($ayah = $quran->fetchArray(SQLITE3_ASSOC)) {
            $quran_count++;
            
            echo '<div class="mb-3 pb-3 border-bottom">
                <h6>' . t('surah') . ' ' . $ayah['surah'] . ', ' . t('ayah') . ' ' . $ayah['ayah'] . '</h6>
                <div class="arabic-text text-center mb-2">' . h($ayah['arabic']) . '</div>
                <p class="text-center' . ($c['lang'] === 'ur' ? ' rtl' : '') . '">' . h($ayah['urdu']) . '</p>
                <div class="text-center">
                    <a href="?page=quran&surah=' . $ayah['surah'] . '&ayah=' . $ayah['ayah'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>';
                    
            if (is_logged()) {
                $bq = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND content_type = 'quran' AND content_id = ?");
                $bq->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
                $bq->bindValue(2, $ayah['id'], SQLITE3_INTEGER);
                $bookmark = $bq->execute()->fetchArray(SQLITE3_ASSOC);
                
                echo ' <a href="?action=toggle_bookmark&type=quran&id=' . $ayah['id'] . '&redirect=' . u("?page=search&q=$q") . '" class="btn btn-sm ' . ($bookmark ? 'btn-warning' : 'btn-outline-warning') . '">
                        <i class="bi bi-bookmark' . ($bookmark ? '-fill' : '') . '"></i>
                      </a>';
            }
            
            echo '</div>
            </div>';
        }
        
        if ($quran_count == 0) {
            echo '<p class="text-center text-muted">' . t('no_matching_ayahs') . '</p>';
        }
        
        echo '</div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-chat-quote"></i> ' . t('hadith') . '</h5>
                    </div>
                    <div class="card-body">';
        
        $hadith_count = 0;
        while ($h = $hadith->fetchArray(SQLITE3_ASSOC)) {
            $hadith_count++;
            
            echo '<div class="mb-3 pb-3 border-bottom">
                <h6>' . h($h['collection']) . ' ' . $h['book_number'] . ':' . $h['hadith_number'] . '</h6>
                <blockquote class="blockquote mb-0">
                    <p>' . h($h['text']) . '</p>
                    <footer class="blockquote-footer">' . h($h['narrator']) . '</footer>
                </blockquote>
                <div class="mt-3">';
                
            if ($h['grading']) {
                echo '<span class="badge bg-info">' . h($h['grading']) . '</span>';
            }
                
            echo '<div class="mt-2">
                    <a href="?page=hadith&id=' . $h['id'] . '" class="btn btn-sm btn-outline-primary">' . t('view') . '</a>';
                    
            if (is_logged()) {
                $bq = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND content_type = 'hadith' AND content_id = ?");
                $bq->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
                $bq->bindValue(2, $h['id'], SQLITE3_INTEGER);
                $bookmark = $bq->execute()->fetchArray(SQLITE3_ASSOC);
                
                echo ' <a href="?action=toggle_bookmark&type=hadith&id=' . $h['id'] . '&redirect=' . u("?page=search&q=$q") . '" class="btn btn-sm ' . ($bookmark ? 'btn-warning' : 'btn-outline-warning') . '">
                        <i class="bi bi-bookmark' . ($bookmark ? '-fill' : '') . '"></i>
                      </a>';
            }
            
            echo '</div>
                </div>
            </div>';
        }
        
        if ($hadith_count == 0) {
            echo '<p class="text-center text-muted">' . t('no_matching_hadith') . '</p>';
        }
        
        echo '</div>
                </div>
            </div>
        </div>';
        
        if ($event_count == 0 && $quran_count == 0 && $hadith_count == 0) {
            echo '<div class="alert alert-info">
                <i class="bi bi-info-circle"></i> ' . t('no_results_found') . ' "' . h($q) . '"
            </div>';
        }
        break;
        
    default:
        echo '<div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> ' . t('page_not_found') . '
        </div>';
        break;
    }

show_footer();
?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>