<?php
// Author: Yasin Ullah
// Pakistani
// Islamic History Web Application
// Single File Application

// ============== PHP CONFIGURATION AND SETUP ==============
@ini_set('display_errors', 0); // Disable error display for production, enable for development
@ini_set('log_errors', 1);
@ini_set('error_log', 'error.log'); // Ensure this file is writable by the web server
error_reporting(E_ALL);

session_start();
ob_start(); // Output buffering

// --- CONSTANTS ---
define('DB_FILE', __DIR__ . '/islamic_history_app.sqlite');
define('SITE_NAME', 'Islamic History Portal');
define('QURAN_DATA_FILE', __DIR__ . '/data.AM');
define('ITEMS_PER_PAGE', 10);
define('APP_VERSION', '1.0.0');

// --- GLOBAL VARIABLES ---
$db = null;
$current_user = null;
$current_lang = 'en'; // Default language

// Determine current language
if (isset($_GET['lang']) && ($_GET['lang'] == 'en' || $_GET['lang'] == 'ur')) {
    $_SESSION['lang'] = $_GET['lang'];
    // Redirect to remove lang from URL query string but keep other params
    $query_params = $_GET;
    unset($query_params['lang']);
    $redirect_url = $_SERVER['PHP_SELF'];
    if (!empty($query_params)) {
        $redirect_url .= '?' . http_build_query($query_params);
    }
    header("Location: " . $redirect_url);
    exit;
}
if (isset($_SESSION['lang'])) {
    $current_lang = $_SESSION['lang'];
}


// ============== DATABASE SETUP AND CONNECTION ==============
function getDB() {
    global $db;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . DB_FILE);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('PRAGMA foreign_keys = ON;');
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check server logs or contact the administrator.");
        }
    }
    return $db;
}

function initializeDatabase() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user', -- 'admin', 'ulama', 'user'
        points INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title_en TEXT NOT NULL,
        title_ur TEXT NOT NULL,
        description_en TEXT,
        description_ur TEXT,
        event_date DATE NOT NULL,
        category TEXT NOT NULL, -- 'islamic', 'general'
        latitude REAL,
        longitude REAL,
        user_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_by INTEGER,
        approved_at DATETIME,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (approved_by) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ayahs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        surah_number INTEGER NOT NULL,
        ayah_number INTEGER NOT NULL,
        arabic_text TEXT NOT NULL,
        urdu_translation TEXT NOT NULL,
        UNIQUE (surah_number, ayah_number)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS hadiths (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        text_en TEXT NOT NULL,
        text_ur TEXT NOT NULL,
        source_en TEXT,
        source_ur TEXT,
        narrator_en TEXT,
        narrator_ur TEXT,
        user_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_by INTEGER,
        approved_at DATETIME,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (approved_by) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS bookmarks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        item_type TEXT NOT NULL, -- 'event', 'ayah', 'hadith'
        item_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE (user_id, item_type, item_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS badges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        name_ur TEXT NOT NULL,
        description_en TEXT,
        description_ur TEXT,
        icon_class TEXT, -- e.g., Bootstrap icon class
        points_required INTEGER NOT NULL UNIQUE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_badges (
        user_id INTEGER NOT NULL,
        badge_id INTEGER NOT NULL,
        awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, badge_id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (badge_id) REFERENCES badges(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS content_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        linked_item_type TEXT NOT NULL, -- 'ayah', 'hadith'
        linked_item_id INTEGER NOT NULL,
        FOREIGN KEY (event_id) REFERENCES events(id)
        -- Note: No direct FK to ayahs/hadiths to simplify, managed by application logic
    )");
    
    // Check if admin user exists, if not, create one
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
    $stmt->execute();
    $admin_exists = $stmt->fetchColumn();

    if ($admin_exists == 0) {
        $admin_username = 'admin';
        $admin_email = 'admin@example.com';
        $admin_password = 'password123'; // Default password, should be changed
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$admin_username, $admin_email, $hashed_password]);
        // Log or display this default admin credential for the first run
        error_log("Default admin created: username '{$admin_username}', password '{$admin_password}'. Please change this password immediately.");
    }
    
    // Seed initial data if tables are empty (for badges and sample content)
    seedInitialData();
}
?>
<?php
// Place this code block in your index.php, for example, after:
// initializeDatabase();
// loadQuranDataFromFile(); 
// $current_user = getCurrentUser(); 

// --- BEGIN ONE-TIME DATA SEEDING SCRIPT ---

function add_more_sample_data_once() {
    $db = getDB(); // Assuming getDB() is available globally or can be called

    // Check if we've already added a good amount of data to prevent re-running
    // Adjust the '7' if your initial seed + this seed results in a different base number
    $stmt_check = $db->query("SELECT COUNT(*) FROM events");
    $event_count = $stmt_check->fetchColumn();
    if ($event_count > 40) { // If more than 40 events (initial 4 + ~50 new ones), assume it ran
        // error_log("Additional sample data already seems to be present. Skipping.");
        return;
    }

    error_log("Attempting to add more sample data...");

    // Get user IDs for seeding
    $user1_id = $db->query("SELECT id FROM users WHERE username = 'user1'")->fetchColumn();
    $ulama1_id = $db->query("SELECT id FROM users WHERE username = 'ulama1'")->fetchColumn();
    $admin_id = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchColumn();

    if (!$user1_id) $user1_id = $admin_id; // Fallback
    if (!$ulama1_id) $ulama1_id = $admin_id; // Fallback
    if (!$admin_id) {
        error_log("Admin user not found. Cannot seed additional data effectively.");
        return; // Critical user missing
    }

    $db->beginTransaction();
    try {
        $stmt_insert_event = $db->prepare("INSERT INTO events (title_en, title_ur, description_en, description_ur, event_date, category, latitude, longitude, user_id, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert_hadith = $db->prepare("INSERT INTO hadiths (text_en, text_ur, source_en, source_ur, narrator_en, narrator_ur, user_id, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $current_timestamp = date('Y-m-d H:i:s');

        // More Islamic Events
        $islamic_events_data = [
            ['Battle of Uhud', 'غزوہ احد', 'A significant battle where Muslims faced a setback but learned valuable lessons.', 'ایک اہم جنگ جہاں مسلمانوں کو دھچکا لگا لیکن قیمتی اسباق سیکھے۔', '0625-03-23', 'islamic', 24.5008, 39.6136, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Treaty of Hudaybiyyah', 'صلح حدیبیہ', 'A pivotal treaty between Prophet Muhammad and the Quraysh of Mecca.', 'نبی اکرم صلی اللہ علیہ وسلم اور قریش مکہ کے درمیان ایک اہم معاہدہ۔', '0628-03-01', 'islamic', 21.4225, 39.8262, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Battle of the Trench (Khandaq)', 'غزوہ خندق', 'A defensive battle where Muslims dug a trench around Medina.', 'ایک دفاعی جنگ جس میں مسلمانوں نے مدینہ کے گرد خندق کھودی۔', '0627-02-01', 'islamic', 24.4686, 39.6142, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Caliphate of Abu Bakr', 'خلافت ابو بکر', 'The period of the first Caliph after Prophet Muhammad (PBUH).', 'نبی اکرم صلی اللہ علیہ وسلم کے بعد پہلے خلیفہ کا دور۔', '0632-06-08', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Caliphate of Umar ibn al-Khattab', 'خلافت عمر بن الخطاب', 'The expansive period of the second Caliph.', 'دوسرے خلیفہ کا وسیع دور۔', '0634-08-23', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Compilation of the Quran', 'قرآن کی تدوین', 'The process of collecting and standardizing the Quranic text during Uthman\'s caliphate.', 'حضرت عثمان کی خلافت کے دوران قرآنی متن کو جمع اور معیاری بنانے کا عمل۔', '0650-01-01', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Battle of Yarmouk', 'جنگ یرموک', 'A major battle between the Rashidun Caliphate and the Byzantine Empire.', 'خلافت راشدہ اور بازنطینی سلطنت کے درمیان ایک بڑی جنگ۔', '0636-08-15', 'islamic', 32.8167, 35.9500, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Conquest of Jerusalem by Umar', 'حضرت عمر کے ہاتھوں یروشلم کی فتح', 'The peaceful conquest of Jerusalem by Caliph Umar.', 'خلیفہ عمر کے ہاتھوں یروشلم کی پرامن فتح۔', '0637-04-01', 'islamic', 31.7683, 35.2137, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Umayyad Caliphate Established', 'امیہ خلافت کا قیام', 'Beginning of the Umayyad dynasty.', 'امیہ خاندان کا آغاز۔', '0661-01-01', 'islamic', 33.5138, 36.2765, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Building of the Dome of the Rock', 'قبۃ الصخرہ کی تعمیر', 'Construction of the iconic Islamic shrine in Jerusalem.', 'یروشلم میں مشہور اسلامی مزار کی تعمیر۔', '0691-01-01', 'islamic', 31.7780, 35.2354, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Abbasid Revolution', 'عباسی انقلاب', 'The overthrow of the Umayyad Caliphate by the Abbasids.', 'عباسیوں کے ہاتھوں امیہ خلافت کا تختہ الٹنا۔', '0750-01-01', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Founding of Baghdad', 'بغداد کی بنیاد', 'Caliph Al-Mansur founds Baghdad as the Abbasid capital.', 'خلیفہ المنصور نے بغداد کو عباسی دارالحکومت کے طور پر قائم کیا۔', '0762-07-30', 'islamic', 33.3152, 44.3661, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['House of Wisdom (Bayt al-Hikmah)', 'بیت الحکمت', 'A major intellectual center during the Islamic Golden Age in Baghdad.', 'بغداد میں اسلامی سنہری دور کا ایک بڑا علمی مرکز۔', '0830-01-01', 'islamic', 33.3152, 44.3661, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Works of Al-Khwarizmi', 'الخوارزمی کے کام', 'Pioneering contributions to algebra and algorithms.', 'الجبرا اور الگورتھم میں اہم شراکتیں۔', '0820-01-01', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Al-Azhar University Founded', 'جامعہ الازہر کا قیام', 'One of the oldest universities in the world, founded in Cairo.', 'قاہرہ میں قائم ہونے والی دنیا کی قدیم ترین یونیورسٹیوں میں سے ایک۔', '0972-01-01', 'islamic', 30.0459, 31.2621, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['The Crusades Begin', 'صلیبی جنگوں کا آغاز', 'A series of religious wars initiated by the Latin Church.', 'لاطینی چرچ کی طرف سے شروع کی گئی مذہبی جنگوں کا ایک سلسلہ۔', '1096-01-01', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Saladin reclaims Jerusalem', 'صلاح الدین ایوبی نے یروشلم واپس لیا', 'Salahuddin Ayyubi recaptures Jerusalem from the Crusaders.', 'صلاح الدین ایوبی نے صلیبیوں سے یروشلم واپس لے لیا۔', '1187-10-02', 'islamic', 31.7683, 35.2137, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Mongol Siege of Baghdad', 'منگولوں کا بغداد پر حملہ', 'The sack of Baghdad by the Mongols, ending the Abbasid Caliphate.', 'منگولوں کے ہاتھوں بغداد کی تباہی، عباسی خلافت کا خاتمہ۔', '1258-02-10', 'islamic', 33.3152, 44.3661, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Ottoman Empire Founded', 'سلطنت عثمانیہ کا قیام', 'The beginning of the Ottoman state.', 'عثمانی ریاست کا آغاز۔', '1299-01-01', 'islamic', 40.1826, 29.0669, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Ibn Battuta\'s Travels', 'ابن بطوطہ کے سفر', 'Extensive travels of the famous Moroccan explorer.', 'مشہور مراکشی سیاح کے وسیع سفر۔', '1325-01-01', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Spread of Islam in Southeast Asia', 'جنوب مشرقی ایشیا میں اسلام کا پھیلاؤ', 'Gradual spread of Islam through trade and missionary work.', 'تجارت اور تبلیغی کام کے ذریعے اسلام کا بتدریج پھیلاؤ۔', '1300-01-01', 'islamic', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Timurid Empire', 'تیموری سلطنت', 'Empire founded by Timur (Tamerlane).', 'تیمور (تیمور لنگ) کی قائم کردہ سلطنت۔', '1370-01-01', 'islamic', 36.7756, 67.1756, $user1_id, 'approved', $admin_id, $current_timestamp],
        ];

        // More General World Events
        $general_events_data = [
            ['Roman Empire Splits', 'رومی سلطنت کی تقسیم', 'The Roman Empire is formally divided into Western and Eastern halves.', 'رومی سلطنت باضابطہ طور پر مغربی اور مشرقی حصوں میں تقسیم ہو گئی۔', '0395-01-17', 'general', 41.9028, 12.4964, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Fall of the Western Roman Empire', 'مغربی رومی سلطنت کا زوال', 'Often cited as the end of ancient history and beginning of the Middle Ages.', 'اکثر قدیم تاریخ کے خاتمے اور قرون وسطی کے آغاز کے طور پر حوالہ دیا جاتا ہے۔', '0476-09-04', 'general', 41.9028, 12.4964, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Justinian Code Compiled', 'جسٹنین کوڈ کی تالیف', 'Corpus Juris Civilis, a major reform of Byzantine law.', 'بازنطینی قانون کی ایک بڑی اصلاح۔', '0534-01-01', 'general', 41.0082, 28.9784, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Charlemagne Crowned Emperor', 'شارلمین کی تاجپوشی', 'Pope Leo III crowns Charlemagne Emperor of the Romans.', 'پوپ لیو سوم نے شارلمین کو رومیوں کا شہنشاہ مقرر کیا۔', '0800-12-25', 'general', 50.6330, 5.5667, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Viking Age Begins', 'وائکنگ دور کا آغاز', 'Scandinavians begin raiding and trading across Europe.', 'اسکینڈینیوین نے یورپ بھر میں چھاپے مارنے اور تجارت شروع کی۔', '0793-06-08', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Battle of Hastings', 'ہیسٹنگز کی جنگ', 'Norman conquest of England.', 'انگلینڈ پر نارمن فتح۔', '1066-10-14', 'general', 50.9107, 0.4861, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Magna Carta Signed', 'میگنا کارٹا پر دستخط', 'A charter of rights agreed to by King John of England.', 'انگلینڈ کے بادشاہ جان کی طرف سے منظور شدہ حقوق کا چارٹر۔', '1215-06-15', 'general', 51.3762, -0.5348, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Travels of Marco Polo', 'مارکو پولو کے سفر', 'Venetian merchant Marco Polo travels to Asia.', 'وینس کا تاجر مارکو پولو ایشیا کا سفر کرتا ہے۔', '1271-01-01', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Black Death Pandemic', 'سیاہ موت کی وبا', 'One of the most devastating pandemics in human history.', 'انسانی تاریخ کی سب سے تباہ کن وباؤں میں سے ایک۔', '1347-01-01', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Hundred Years\' War', 'سو سالہ جنگ', 'A series of conflicts between England and France.', 'انگلینڈ اور فرانس کے درمیان تنازعات کا ایک سلسلہ۔', '1337-01-01', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Renaissance Begins in Italy', 'اٹلی میں نشاۃ ثانیہ کا آغاز', 'A period of great cultural change and achievement in Europe.', 'یورپ میں عظیم ثقافتی تبدیلی اور کامیابی کا دور۔', '1400-01-01', 'general', 43.7696, 11.2558, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Joan of Arc Leads French Army', 'جون آف آرک فرانسیسی فوج کی قیادت کرتی ہے', 'Played a key role in the Hundred Years\' War.', 'سو سالہ جنگ میں اہم کردار ادا کیا۔', '1429-05-07', 'general', 47.8481, 1.9031, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Age of Discovery Begins', 'دریافتوں کے دور کا آغاز', 'European exploration of the world by sea.', 'سمندر کے ذریعے دنیا کی یورپی تلاش۔', '1400-01-01', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Columbus Reaches the Americas', 'کولمبس امریکہ پہنچا', 'Christopher Columbus makes his first voyage to the Americas.', 'کرسٹوفر کولمبس نے امریکہ کا پہلا سفر کیا۔', '1492-10-12', 'general', 24.0000, -74.5000, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Protestant Reformation Begins', 'پروٹسٹنٹ اصلاحات کا آغاز', 'Martin Luther posts his Ninety-five Theses.', 'مارٹن لوتھر نے اپنے پچانوے مقالے شائع کیے۔', '1517-10-31', 'general', 51.8386, 12.6421, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Mughal Empire Founded in India', 'ہندوستان میں مغل سلطنت کا قیام', 'Babur establishes the Mughal dynasty.', 'بابر نے مغل خاندان کی بنیاد رکھی۔', '1526-04-21', 'general', 28.6139, 77.2090, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Copernican Heliocentrism Published', 'کوپرنیکس کا شمسی مرکزیت کا نظریہ شائع ہوا', 'Nicolaus Copernicus proposes a heliocentric model of the universe.', 'نکولس کوپرنیکس نے کائنات کا شمسی مرکزیت کا ماڈل پیش کیا۔', '1543-01-01', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Spanish Armada Defeated', 'ہسپانوی آرماڈا کی شکست', 'English naval victory over the Spanish fleet.', 'ہسپانوی بحری بیڑے پر انگریزی بحری فتح۔', '1588-08-08', 'general', 50.5000, -1.0000, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Founding of Jamestown', 'جیمز ٹاؤن کا قیام', 'First permanent English settlement in North America.', 'شمالی امریکہ میں پہلی مستقل انگریزی بستی۔', '1607-05-14', 'general', 37.2200, -76.7800, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Thirty Years\' War', 'تیس سالہ جنگ', 'A major conflict primarily fought in Central Europe.', 'ایک بڑا تنازعہ جو بنیادی طور پر وسطی یورپ میں لڑا گیا۔', '1618-05-23', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Galileo Faces Inquisition', 'گیلیلیو کا تحقیقاتی عدالت کا سامنا', 'Galileo Galilei is tried by the Roman Inquisition for heliocentrism.', 'گیلیلیو گیلیلی پر شمسی مرکزیت کے لیے رومی تحقیقاتی عدالت میں مقدمہ چلایا گیا۔', '1633-06-22', 'general', 41.9022, 12.4583, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['English Civil War', 'انگریزی خانہ جنگی', 'A series of civil wars and political machinations in England.', 'انگلینڈ میں خانہ جنگیوں اور سیاسی سازشوں کا ایک سلسلہ۔', '1642-08-22', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Taj Mahal Construction Completed', 'تاج محل کی تعمیر مکمل', 'Mausoleum built by Mughal emperor Shah Jahan.', 'مغل بادشاہ شاہ جہاں کا تعمیر کردہ مقبرہ۔', '1653-01-01', 'general', 27.1751, 78.0421, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Great Plague of London', 'لندن کی عظیم طاعون', 'Last major epidemic of the bubonic plague in England.', 'انگلینڈ میں بوبونک طاعون کی آخری بڑی وبا۔', '1665-01-01', 'general', 51.5074, 0.1278, $user1_id, 'approved', $admin_id, $current_timestamp],
            ['Newton Publishes Principia Mathematica', 'نیوٹن نے پرنسپیا میتھمیٹکا شائع کی', 'Isaac Newton\'s seminal work on physics and mathematics.', 'طبیعیات اور ریاضی پر آئزک نیوٹن کا اہم کام۔', '1687-07-05', 'general', null, null, $user1_id, 'approved', $admin_id, $current_timestamp],
        ];
        
        $all_events_data = array_merge($islamic_events_data, $general_events_data);
        foreach ($all_events_data as $event_data) {
            $stmt_insert_event->execute($event_data);
        }

        // More Hadiths
        $hadiths_data = [
            ['The believer does not slander, curse, or speak অশ্লীল or abusively.', 'مومن طعنہ زنی، لعنت ملامت، فحش گوئی یا بدکلامی نہیں کرتا۔', 'Tirmidhi', 'ترمذی', 'Abdullah ibn Masud', 'عبداللہ بن مسعود', $ulama1_id, 'approved', $admin_id, $current_timestamp],
            ['Modesty is part of faith.', 'حیا ایمان کا حصہ ہے۔', 'Sahih Bukhari, Sahih Muslim', 'صحیح بخاری، صحیح مسلم', 'Abu Huraira', 'ابو ہریرہ', $ulama1_id, 'approved', $admin_id, $current_timestamp],
            ['He who does not thank people, does not thank Allah.', 'جو لوگوں کا شکر ادا نہیں کرتا، وہ اللہ کا شکر ادا نہیں کرتا۔', 'Abu Dawud, Tirmidhi', 'ابو داؤد، ترمذی', 'Abu Huraira', 'ابو ہریرہ', $ulama1_id, 'approved', $admin_id, $current_timestamp],
            ['The strong man is not the one who wrestles, but the strong man is in fact the one who controls himself in a fit of rage.', 'طاقتور وہ نہیں جو کشتی میں غالب آئے، بلکہ طاقتور وہ ہے جو غصے کے وقت خود پر قابو رکھے۔', 'Sahih Bukhari, Sahih Muslim', 'صحیح بخاری، صحیح مسلم', 'Abu Huraira', 'ابو ہریرہ', $ulama1_id, 'approved', $admin_id, $current_timestamp],
            ['None of you [truly] believes until he wishes for his brother what he wishes for himself.', 'تم میں سے کوئی اس وقت تک [حقیقی] مومن نہیں ہو سکتا جب تک وہ اپنے بھائی کے لیے وہی نہ چاہے جو وہ اپنے لیے چاہتا ہے۔', 'Sahih Bukhari, Sahih Muslim', 'صحیح بخاری، صحیح مسلم', 'Anas ibn Malik', 'انس بن مالک', $ulama1_id, 'approved', $admin_id, $current_timestamp],
            ['Make things easy for the people, and do not make it difficult for them, and make them calm (with glad tidings) and do not repulse (them).', 'لوگوں کے لیے آسانی پیدا کرو اور ان کے لیے مشکل نہ بناؤ، اور انہیں (خوشخبریوں سے) پرسکون کرو اور انہیں (نفرت دلا کر) دور نہ کرو۔', 'Sahih Bukhari', 'صحیح بخاری', 'Anas ibn Malik', 'انس بن مالک', $ulama1_id, 'approved', $admin_id, $current_timestamp],
            ['The best of charity is to give water to drink.', 'بہترین صدقہ پانی پلانا ہے۔', 'Ahmad, Ibn Majah', 'احمد، ابن ماجہ', 'Sa`d ibn `Ubadah', 'سعد بن عبادہ', $ulama1_id, 'pending', null, null],
            ['Every act of goodness is charity.', 'ہر نیک عمل صدقہ ہے۔', 'Sahih Muslim', 'صحیح مسلم', 'Jabir ibn Abdullah', 'جابر بن عبداللہ', $ulama1_id, 'approved', $admin_id, $current_timestamp],
        ];

        foreach ($hadiths_data as $hadith_data) {
            $stmt_insert_hadith->execute($hadith_data);
        }

        $db->commit();
        error_log("Successfully added more sample data.");
        // Optionally add a flash message if this is run in a context where it can be displayed
        // addFlashMessage("Added more sample data!", "success");

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error adding more sample data: " . $e->getMessage());
        // addFlashMessage("Error adding more sample data: " . $e->getMessage(), "danger");
    }
}

// Call the function to add data if needed
// Ensure this is called only once, or the check inside the function handles it.
add_more_sample_data_once();

// --- END ONE-TIME DATA SEEDING SCRIPT ---
?>
<?php
function seedInitialData() {
    $db = getDB();

    // Sample Badges
    $stmt = $db->query("SELECT COUNT(*) FROM badges");
    if ($stmt->fetchColumn() == 0) {
        $badges = [
            ['Contributor', 'شراکت دار', 'Contributed 1 approved item', '1 منظور شدہ آئٹم کا حصہ ڈالا', 'bi-pencil-square', 10],
            ['Scholar', 'عالم', 'Contributed 5 approved items', '5 منظور شدہ آئٹمز کا حصہ ڈالا', 'bi-book-half', 50],
            ['Historian', 'مورخ', 'Contributed 10 approved items', '10 منظور شدہ آئٹمز کا حصہ ڈالا', 'bi-hourglass-split', 100],
            ['Explorer', 'مستكشف', 'Bookmarked 5 items', '5 آئٹمز کو بک مارک کیا', 'bi-compass', 0], // Points 0 for action based
        ];
        $stmt_insert = $db->prepare("INSERT INTO badges (name_en, name_ur, description_en, description_ur, icon_class, points_required) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($badges as $badge) {
            $stmt_insert->execute($badge);
        }
    }

    // Sample Users (beyond admin)
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role != 'admin'");
    if ($stmt->fetchColumn() == 0) {
        $users = [
            ['ulama1', 'ulama1@example.com', password_hash('password123', PASSWORD_DEFAULT), 'ulama'],
            ['user1', 'user1@example.com', password_hash('password123', PASSWORD_DEFAULT), 'user'],
            ['user2', 'user2@example.com', password_hash('password123', PASSWORD_DEFAULT), 'user'],
        ];
        $stmt_insert_user = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
        foreach ($users as $userData) {
            try { $stmt_insert_user->execute($userData); } catch (PDOException $e) { /* ignore if duplicate */ }
        }
    }
    
    // Get sample user IDs for foreign keys
    $sample_user_id = $db->query("SELECT id FROM users WHERE username = 'user1'")->fetchColumn();
    $sample_ulama_id = $db->query("SELECT id FROM users WHERE username = 'ulama1'")->fetchColumn();
    $admin_id = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchColumn();

    if (!$sample_user_id) $sample_user_id = $admin_id; // Fallback if user1 not created
    if (!$sample_ulama_id) $sample_ulama_id = $admin_id; // Fallback if ulama1 not created

    // Sample Events
    $stmt = $db->query("SELECT COUNT(*) FROM events");
    if ($stmt->fetchColumn() == 0 && $sample_user_id && $admin_id) {
        $events = [
            ['Battle of Badr', 'غزوہ بدر', 'The first major battle in Islamic history.', 'اسلامی تاریخ کی پہلی بڑی جنگ۔', '0624-03-13', 'islamic', 24.0600, 39.3700, $sample_user_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
            ['Conquest of Mecca', 'فتح مکہ', 'The peaceful taking of Mecca by Muslims.', 'مسلمانوں کے ذریعے مکہ کی پرامن فتح۔', '0630-01-11', 'islamic', 21.4225, 39.8262, $sample_user_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
            ['Invention of Printing Press', 'پرنٹنگ پریس کی ایجاد', 'Johannes Gutenberg invents the printing press.', 'جوہانس گٹن برگ نے پرنٹنگ پریس ایجاد کیا۔', '1440-01-01', 'general', 49.9929, 8.2473, $sample_user_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
            ['Fall of Constantinople', 'قسطنطنیہ کا سقوط', 'The Ottoman Empire conquers Constantinople.', 'سلطنت عثمانیہ نے قسطنطنیہ فتح کیا۔', '1453-05-29', 'general', 41.0082, 28.9784, $sample_user_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
        ];
        $stmt_insert_event = $db->prepare("INSERT INTO events (title_en, title_ur, description_en, description_ur, event_date, category, latitude, longitude, user_id, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($events as $event) {
            $stmt_insert_event->execute($event);
        }
    }

    // Sample Hadiths
    $stmt = $db->query("SELECT COUNT(*) FROM hadiths");
    if ($stmt->fetchColumn() == 0 && $sample_ulama_id && $admin_id) {
        $hadiths = [
            ['Actions are judged by intentions.', 'اعمال کا دارومدار نیتوں پر ہے۔', 'Sahih Bukhari', 'صحیح بخاری', 'Umar ibn Al-Khattab', 'عمر بن الخطاب', $sample_ulama_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
            ['The best among you are those who have the best manners and character.', 'تم میں سے بہترین وہ ہیں جن کے اخلاق و کردار بہترین ہوں۔', 'Sahih Bukhari', 'صحیح بخاری', 'Abdullah ibn Amr', 'عبداللہ بن عمرو', $sample_ulama_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
            ['Seek knowledge from the cradle to the grave.', 'مہد سے لحد تک علم حاصل کرو۔', 'Various sources', 'مختلف ذرائع', 'Prophet Muhammad (PBUH)', 'نبی کریم صلی اللہ علیہ وسلم', $sample_ulama_id, 'pending', null, null],
            ['Cleanliness is half of faith.', 'صفائی نصف ایمان ہے۔', 'Sahih Muslim', 'صحیح مسلم', 'Abu Malik Al-Ash`ari', 'ابو مالک الاشعری', $sample_ulama_id, 'approved', $admin_id, date('Y-m-d H:i:s')],
        ];
        $stmt_insert_hadith = $db->prepare("INSERT INTO hadiths (text_en, text_ur, source_en, source_ur, narrator_en, narrator_ur, user_id, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($hadiths as $hadith) {
            $stmt_insert_hadith->execute($hadith);
        }
    }
}


// ============== QURAN DATA HANDLING ==============
function loadQuranDataFromFile() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM ayahs");
    if ($stmt->fetchColumn() > 0) {
        return; // Data already loaded
    }

    if (!file_exists(QURAN_DATA_FILE)) {
        error_log("Quran data file not found: " . QURAN_DATA_FILE);
        addFlashMessage(translate('error_quran_file_missing'), 'danger');
        return;
    }

    $lines = file(QURAN_DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log("Could not read Quran data file: " . QURAN_DATA_FILE);
        addFlashMessage(translate('error_quran_file_read'), 'danger');
        return;
    }

    $db->beginTransaction();
    try {
        $stmt_insert = $db->prepare("INSERT INTO ayahs (arabic_text, urdu_translation, surah_number, ayah_number) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($lines as $line) {
            // [Arabic Ayah] ترجمہ: [Urdu Translation]<br/>س [3-digit Surah] آ [3-digit Ayah]
            // Regex: (Arabic Text) ترجمہ: (Urdu Translation) optionally <br/> or <br /> س (Surah Number) آ (Ayah Number)
            if (preg_match('/^(.*?) ترجمہ: (.*?)(?:<br\s*\/?>)?\s*س\s*(\d{1,3})\s*آ\s*(\d{1,3})$/u', $line, $matches)) {
                $arabic_text = trim($matches[1]);
                $urdu_translation = trim($matches[2]);
                $surah_number = intval($matches[3]);
                $ayah_number = intval($matches[4]);

                if ($arabic_text && $urdu_translation && $surah_number > 0 && $ayah_number > 0) {
                    $stmt_insert->execute([$arabic_text, $urdu_translation, $surah_number, $ayah_number]);
                    $count++;
                } else {
                    error_log("Skipping malformed line in Quran data: " . $line);
                }
            } else {
                error_log("Failed to parse line in Quran data: " . $line);
            }
        }
        $db->commit();
        if ($count > 0) {
            addFlashMessage(sprintf(translate('quran_data_loaded_success'), $count), 'success');
        } else {
            addFlashMessage(translate('quran_data_not_loaded_format'), 'warning');
        }
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error loading Quran data: " . $e->getMessage());
        addFlashMessage(translate('error_quran_db_insert') . $e->getMessage(), 'danger');
    }
}

// ============== LANGUAGE STRINGS AND TRANSLATION ==============
$translations = [
    'en' => [
        'site_title' => SITE_NAME,
        'toggle_navigation' => 'Toggle navigation',
        'home' => 'Home',
        'events' => 'Events',
        'islamic_events' => 'Islamic Events',
        'general_events' => 'General Events',
        'timeline' => 'Timeline',
        'map_view' => 'Map View',
        'add_event' => 'Add Event',
        'quran' => 'Quran',
        'search_quran' => 'Search Quran',
        'hadith' => 'Hadith',
        'search_hadith' => 'Search Hadith',
        'add_hadith' => 'Add Hadith',
        'bookmarks' => 'Bookmarks',
        'profile' => 'Profile',
        'admin_panel' => 'Admin Panel',
        'login' => 'Login',
        'register' => 'Register',
        'logout' => 'Logout',
        'username' => 'Username',
        'password' => 'Password',
        'zoom_to_my_location' => 'Zoom to My Location',
        'start_live_tracking' => 'Start Live Tracking',
        'stop_live_tracking' => 'Stop Live Tracking',
        'your_current_location' => 'Your Current Location',
        'error_getting_location' => 'Error getting location: ',
        'geolocation_not_supported' => 'Geolocation is not supported by your browser.',
        'geolocation_not_supported_for_tracking' => 'Geolocation is not supported for live tracking.',
        'location_permission_denied_tracking_stopped' => 'Location permission denied. Live tracking stopped.',
        'email' => 'Email',
        'confirm_password' => 'Confirm Password',
        'role' => 'Role',
        'user' => 'User',
        'ulama' => 'Ulama',
        'admin' => 'Admin',
        'submit' => 'Submit',
        'search' => 'Search',
        'title' => 'Title',
        'description' => 'Description',
        'date' => 'Date',
        'category' => 'Category',
        'location' => 'Location (Optional)',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'get_current_location' => 'Get Current Location',
        'status' => 'Status',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'view' => 'View',
        'no_items_found' => 'No items found.',
        'arabic_text' => 'Arabic Text',
        'urdu_translation' => 'Urdu Translation',
        'surah' => 'Surah',
        'ayah' => 'Ayah',
        'hadith_text' => 'Hadith Text',
        'source' => 'Source',
        'narrator' => 'Narrator',
        'points' => 'Points',
        'badges' => 'Badges',
        'manage_users' => 'Manage Users',
        'manage_content' => 'Manage Content',
        'system_settings' => 'System Settings',
        'backup_restore' => 'Backup/Restore',
        'backup_db' => 'Backup Database',
        'restore_db' => 'Restore Database',
        'upload_sqlite_file' => 'Upload SQLite File for Restore',
        'are_you_sure' => 'Are you sure?',
        'operation_successful' => 'Operation successful.',
        'operation_failed' => 'Operation failed.',
        'invalid_request' => 'Invalid request.',
        'access_denied' => 'Access denied.',
        'login_required' => 'Login required.',
        'registration_successful' => 'Registration successful. You can now login.',
        'login_failed' => 'Login failed. Invalid username or password.',
        'password_mismatch' => 'Passwords do not match.',
        'user_exists' => 'Username or email already exists.',
        'event_submitted' => 'Event submitted for approval.',
        'hadith_submitted' => 'Hadith submitted for approval.',
        'item_bookmarked' => 'Item bookmarked.',
        'item_unbookmarked' => 'Item unbookmarked.',
        'bookmark_failed' => 'Bookmark operation failed.',
        'light_mode' => 'Light Mode',
        'dark_mode' => 'Dark Mode',
        'language' => 'Language',
        'english' => 'English',
        'urdu' => 'Urdu',
        'error_quran_file_missing' => 'Quran data file (data.AM) is missing.',
        'error_quran_file_read' => 'Could not read Quran data file.',
        'quran_data_loaded_success' => 'Successfully loaded %d Quran ayahs.',
        'quran_data_not_loaded_format' => 'No Quran data loaded. Check file format or content.',
        'error_quran_db_insert' => 'Error inserting Quran data into database: ',
        'event_approved' => 'Event approved.',
        'event_rejected' => 'Event rejected.',
        'hadith_approved' => 'Hadith approved.',
        'hadith_rejected' => 'Hadith rejected.',
        'user_updated' => 'User updated.',
        'user_deleted' => 'User deleted.',
        'content_linked' => 'Content linked successfully.',
        'content_link_failed' => 'Failed to link content.',
        'content_unlinked' => 'Content unlinked successfully.',
        'content_unlink_failed' => 'Failed to unlink content.',
        'link_to_event' => 'Link to Event',
        'linked_content' => 'Linked Content',
        'no_linked_content' => 'No content linked to this event yet.',
        'add_new_badge' => 'Add New Badge',
        'badge_name' => 'Badge Name',
        'badge_icon_class' => 'Badge Icon Class (e.g., bi-award)',
        'points_required' => 'Points Required',
        'badge_added' => 'Badge added successfully.',
        'badge_updated' => 'Badge updated successfully.',
        'badge_deleted' => 'Badge deleted successfully.',
        'my_achievements' => 'My Achievements',
        'view_event_on_map' => 'View on Map',
        'database_backup_success' => 'Database backup successful. Download started.',
        'database_backup_failed' => 'Database backup failed.',
        'database_restore_success' => 'Database restored successfully.',
        'database_restore_failed' => 'Database restore failed. Invalid file or error during restore.',
        'database_restore_warning' => 'WARNING: Restoring will overwrite the current database. Make sure you have a backup.',
        'select_event_category' => 'Select Event Category',
        'islamic' => 'Islamic',
        'general' => 'General',
        'all_categories' => 'All Categories',
        'filter' => 'Filter',
        'submitted_by' => 'Submitted by',
        'approved_by' => 'Approved by',
        'pending_approval' => 'Pending Approval',
        'event_details' => 'Event Details',
        'hadith_details' => 'Hadith Details',
        'ayah_details' => 'Ayah Details',
        'dashboard' => 'Dashboard',
        'welcome_user' => 'Welcome, %s!',
        'total_users' => 'Total Users',
        'total_events' => 'Total Events',
        'total_hadiths' => 'Total Hadiths',
        'pending_events' => 'Pending Events',
        'pending_hadiths' => 'Pending Hadiths',
        'recent_activity' => 'Recent Activity',
        'no_recent_activity' => 'No recent activity.',
        'confirm_delete_user' => 'Are you sure you want to delete this user? This action cannot be undone.',
        'confirm_delete_event' => 'Are you sure you want to delete this event?',
        'confirm_delete_hadith' => 'Are you sure you want to delete this hadith?',
        'confirm_delete_badge' => 'Are you sure you want to delete this badge?',
        'confirm_unlink_content' => 'Are you sure you want to unlink this content?',
        'search_results_for' => 'Search results for "%s"',
        'page_not_found' => 'Page Not Found',
        'page_not_found_message' => 'The page you are looking for does not exist or has been moved.',
        'back_to_home' => 'Back to Home',
        'by' => 'By', // For authorship, e.g., "By User1"
        'on' => 'On', // For dates, e.g., "On 2023-01-01"
        'unknown_user' => 'Unknown User',
        'unknown' => 'Unknown',
        'view_profile' => 'View Profile',
        'user_profile_for' => 'User Profile for %s',
        'joined_on' => 'Joined on',
        'contributions' => 'Contributions',
        'events_contributed' => 'Events Contributed',
        'hadiths_contributed' => 'Hadiths Contributed',
        'no_contributions' => 'No contributions yet.',
        'no_bookmarks' => 'No bookmarks yet.',
        'no_badges_earned' => 'No badges earned yet.',
        'awarded_on' => 'Awarded on',
        'theme' => 'Theme',
        'created_at' => 'Created At',
        'last_login' => 'Last Login (Feature not implemented)',
        'edit_profile' => 'Edit Profile (Feature not fully implemented - basic info)',
        'change_password' => 'Change Password (Feature not implemented)',
        'event_category_islamic' => 'Islamic',
        'event_category_general' => 'General',
        'select_item_type' => 'Select Item Type',
        'select_item' => 'Select Item',
        'link_item' => 'Link Item',
        'events_map_title' => 'Events Map - Islamic History Portal',
        'all_events' => 'All Events',
        'event_timeline' => 'Event Timeline',
        'year' => 'Year',
        'century' => 'Century',
        'filter_by_century' => 'Filter by Century',
        'all_centuries' => 'All Centuries',
        'manage_badges' => 'Manage Badges',
        'edit_badge' => 'Edit Badge',
        'stats_overview' => 'Statistics Overview',
        'content_type_distribution' => 'Content Type Distribution',
        'user_roles_distribution' => 'User Roles Distribution',
        'events_by_category' => 'Events by Category',
        'content_status_distribution' => 'Content Status Distribution (Events)',
        'content_status_distribution_hadith' => 'Content Status Distribution (Hadiths)',
        'search_placeholder_quran' => 'Enter keyword, Surah name, or Surah:Ayah (e.g., 1:1)',
        'search_placeholder_hadith' => 'Enter keyword, narrator, or source',
        'search_placeholder_events' => 'Enter keyword in title or description',
        'no_description_available' => 'No description available.',
        'not_applicable' => 'N/A',
        'optional' => 'Optional',
        'required_field' => 'This field is required.',
        'invalid_date_format' => 'Invalid date format. Use YYYY-MM-DD.',
        'invalid_coordinates' => 'Invalid coordinates.',
        'item_not_found' => 'Item not found.',
        'action_not_allowed' => 'Action not allowed for your role.',
        'error_processing_request' => 'Error processing your request. Please try again.',
        'anonymous' => 'Anonymous',
        'event_suggestions' => 'Event Suggestions',
        'hadith_suggestions' => 'Hadith Suggestions',
        'view_user_profile' => "View User's Profile",
        'event_latitude_tip' => 'e.g., 21.4225',
        'event_longitude_tip' => 'e.g., 39.8262',
        'event_date_tip' => 'Format: YYYY-MM-DD, e.g., 0624-03-13',
        'data_AM_note' => 'Ensure data.AM file is in the same directory as this script and has the correct format for Quran loading.',
        'backup_note' => 'This will download the entire SQLite database file. Keep it in a safe place.',
        'restore_note' => 'Restoring will replace the current database with the uploaded file. THIS ACTION IS IRREVERSIBLE. Ensure the uploaded file is a valid SQLite database for this application.',
        'file_too_large' => 'Uploaded file is too large.',
        'invalid_file_type_sqlite' => 'Invalid file type. Please upload a .sqlite file.',
        'restore_failed_check_log' => 'Restore failed. Check error log for details.',
        'developer_info' => 'Developed by Yasin Ullah (Pakistani).',
        'version_info' => 'Version: ' . APP_VERSION,
        'go_to_admin_dashboard' => 'Go to Admin Dashboard',
        'toggle_theme' => 'Toggle Theme',
    ],
    'ur' => [
        'site_title' => SITE_NAME,
        'toggle_navigation' => 'نیویگیشن ٹوگل کریں',
        'home' => 'صفحہ اول',
        'events' => 'واقعات',
        'islamic_events' => 'اسلامی واقعات',
        'general_events' => 'عمومی واقعات',
        'timeline' => 'ٹائم لائن',
        'map_view' => 'نقشہ کا منظر',
        'add_event' => 'واقعہ شامل کریں',
        'quran' => 'قرآن',
        'search_quran' => 'قرآن میں تلاش کریں',
        'hadith' => 'حدیث',
        'search_hadith' => 'حدیث میں تلاش کریں',
        'add_hadith' => 'حدیث شامل کریں',
        'bookmarks' => 'بک مارکس',
        'profile' => 'پروفائل',
        'admin_panel' => 'ایڈمن پینل',
        'login' => 'لاگ ان',
        'register' => 'رجسٹر کریں',
        'logout' => 'لاگ آؤٹ',
        'username' => 'صارف نام',
        'password' => 'پاس ورڈ',
        'email' => 'ای میل',
        'confirm_password' => 'پاس ورڈ کی تصدیق کریں',
        'role' => 'کردار',
        'user' => 'صارف',
        'ulama' => 'علماء',
        'admin' => 'ایڈمن',
        'submit' => 'جمع کرائیں',
        'search' => 'تلاش کریں',
        'title' => 'عنوان',
        'description' => 'تفصیل',
        'date' => 'تاریخ',
        'zoom_to_my_location' => 'میری جگہ پر زوم کریں',
        'start_live_tracking' => 'لائیو ٹریکنگ شروع کریں',
        'stop_live_tracking' => 'لائیو ٹریکنگ روکیں',
        'your_current_location' => 'آپ کا موجودہ مقام',
        'error_getting_location' => 'مقام حاصل کرنے میں خرابی: ',
        'geolocation_not_supported' => 'جغرافیائی محل وقوع آپ کے براؤزر کے ذریعے تعاون یافتہ نہیں ہے۔',
        'geolocation_not_supported_for_tracking' => 'لائیو ٹریکنگ کے لیے جغرافیائی محل وقوع معاون نہیں ہے۔',
        'location_permission_denied_tracking_stopped' => 'مقام کی اجازت مسترد کر دی گئی۔ لائیو ٹریکنگ روک دی گئی۔',
        'category' => 'زمرہ',
        'location' => 'مقام (اختیاری)',
        'latitude' => 'عرض بلد',
        'longitude' => 'طول بلد',
        'get_current_location' => 'موجودہ مقام حاصل کریں',
        'status' => 'حیثیت',
        'pending' => 'زیر التواء',
        'approved' => 'منظور شدہ',
        'rejected' => 'مسترد',
        'actions' => 'کاروائیاں',
        'edit' => 'ترمیم',
        'delete' => 'حذف کریں',
        'approve' => 'منظور کریں',
        'reject' => 'مسترد کریں',
        'view' => 'دیکھیں',
        'no_items_found' => 'کوئی آئٹم نہیں ملا۔',
        'arabic_text' => 'عربی متن',
        'urdu_translation' => 'اردو ترجمہ',
        'surah' => 'سورۃ',
        'ayah' => 'آیت',
        'hadith_text' => 'متن حدیث',
        'source' => 'ماخذ',
        'narrator' => 'راوی',
        'points' => 'پوائنٹس',
        'badges' => 'بیجز',
        'manage_users' => 'صارفین کا انتظام',
        'manage_content' => 'مواد کا انتظام',
        'system_settings' => 'سسٹم سیٹنگز',
        'backup_restore' => 'بیک اپ/ریسٹور',
        'backup_db' => 'ڈیٹا بیس کا بیک اپ',
        'restore_db' => 'ڈیٹا بیس کو ریسٹور کریں',
        'upload_sqlite_file' => 'ریسٹور کے لیے SQLite فائل اپ لوڈ کریں',
        'are_you_sure' => 'کیا آپ واقعی کرنا چاہتے ہیں؟',
        'operation_successful' => 'آپریشن کامیاب رہا۔',
        'operation_failed' => 'آپریشن ناکام رہا۔',
        'invalid_request' => 'غلط درخواست۔',
        'access_denied' => 'رسائی ممنوع ہے۔',
        'login_required' => 'لاگ ان ضروری ہے۔',
        'registration_successful' => 'رجسٹریشن کامیاب۔ اب آپ لاگ ان کر سکتے ہیں۔',
        'login_failed' => 'لاگ ان ناکام۔ غلط صارف نام یا پاس ورڈ۔',
        'password_mismatch' => 'پاس ورڈ مماثل نہیں ہیں۔',
        'user_exists' => 'صارف نام یا ای میل پہلے سے موجود ہے۔',
        'event_submitted' => 'واقعہ منظوری کے لیے جمع کر دیا گیا ہے۔',
        'hadith_submitted' => 'حدیث منظوری کے لیے جمع کر دی گئی ہے۔',
        'item_bookmarked' => 'آئٹم بک مارک کر لیا گیا۔',
        'item_unbookmarked' => 'آئٹم ان بک مارک کر دیا گیا۔',
        'bookmark_failed' => 'بک مارک آپریشن ناکام رہا۔',
        'light_mode' => 'لائٹ موڈ',
        'dark_mode' => 'ڈارک موڈ',
        'language' => 'زبان',
        'english' => 'English',
        'urdu' => 'اردو',
        'error_quran_file_missing' => 'قرآن ڈیٹا فائل (data.AM) موجود نہیں ہے۔',
        'error_quran_file_read' => 'قرآن ڈیٹا فائل نہیں پڑھی جا سکی۔',
        'quran_data_loaded_success' => '%d قرآنی آیات کامیابی سے لوڈ ہو گئیں۔',
        'quran_data_not_loaded_format' => 'قرآن کا کوئی ڈیٹا لوڈ نہیں ہوا۔ فائل کی فارمیٹ یا مواد چیک کریں۔',
        'error_quran_db_insert' => 'قرآن کا ڈیٹا ڈیٹا بیس میں ڈالتے وقت خرابی: ',
        'event_approved' => 'واقعہ منظور کر لیا گیا۔',
        'event_rejected' => 'واقعہ مسترد کر دیا گیا۔',
        'hadith_approved' => 'حدیث منظور کر لی گئی۔',
        'hadith_rejected' => 'حدیث مسترد کر دی گئی۔',
        'user_updated' => 'صارف اپ ڈیٹ ہو گیا۔',
        'user_deleted' => 'صارف حذف کر دیا گیا۔',
        'content_linked' => 'مواد کامیابی سے منسلک ہو گیا۔',
        'content_link_failed' => 'مواد منسلک کرنے میں ناکامی۔',
        'content_unlinked' => 'مواد کامیابی سے غیر منسلک ہو گیا۔',
        'content_unlink_failed' => 'مواد غیر منسلک کرنے میں ناکامی۔',
        'link_to_event' => 'واقعہ سے منسلک کریں',
        'linked_content' => 'منسلک مواد',
        'no_linked_content' => 'اس واقعہ سے ابھی تک کوئی مواد منسلک نہیں ہے۔',
        'add_new_badge' => 'نیا بیج شامل کریں',
        'badge_name' => 'بیج کا نام',
        'badge_icon_class' => 'بیج آئیکن کلاس (مثلاً bi-award)',
        'points_required' => 'مطلوبہ پوائنٹس',
        'badge_added' => 'بیج کامیابی سے شامل کر دیا گیا۔',
        'badge_updated' => 'بیج کامیابی سے اپ ڈیٹ ہو گیا۔',
        'badge_deleted' => 'بیج کامیابی سے حذف کر دیا گیا۔',
        'my_achievements' => 'میری کامیابیاں',
        'view_event_on_map' => 'نقشے پر دیکھیں',
        'database_backup_success' => 'ڈیٹا بیس کا بیک اپ کامیاب۔ ڈاؤن لوڈ شروع ہو گیا۔',
        'database_backup_failed' => 'ڈیٹا بیس کا بیک اپ ناکام۔',
        'database_restore_success' => 'ڈیٹا بیس کامیابی سے بحال ہو گیا۔',
        'database_restore_failed' => 'ڈیٹا بیس کی بحالی ناکام۔ غلط فائل یا بحالی کے دوران خرابی۔',
        'database_restore_warning' => 'انتباہ: بحالی موجودہ ڈیٹا بیس کو اوور رائٹ کر دے گی۔ یقینی بنائیں کہ آپ کے پاس بیک اپ موجود ہے۔',
        'select_event_category' => 'واقعہ کا زمرہ منتخب کریں',
        'islamic' => 'اسلامی',
        'general' => 'عمومی',
        'all_categories' => 'تمام زمرے',
        'filter' => 'فلٹر',
        'submitted_by' => 'جمع کرایا گیا از',
        'approved_by' => 'منظور شدہ از',
        'pending_approval' => 'منظوری کا منتظر',
        'event_details' => 'واقعہ کی تفصیلات',
        'hadith_details' => 'حدیث کی تفصیلات',
        'ayah_details' => 'آیت کی تفصیلات',
        'dashboard' => 'ڈیش بورڈ',
        'welcome_user' => 'خوش آمدید، %s!',
        'total_users' => 'کل صارفین',
        'total_events' => 'کل واقعات',
        'total_hadiths' => 'کل احادیث',
        'pending_events' => 'زیر التواء واقعات',
        'pending_hadiths' => 'زیر التواء احادیث',
        'recent_activity' => 'حالیہ سرگرمی',
        'no_recent_activity' => 'کوئی حالیہ سرگرمی نہیں۔',
        'confirm_delete_user' => 'کیا آپ واقعی اس صارف کو حذف کرنا چاہتے ہیں؟ یہ عمل ناقابل واپسی ہے۔',
        'confirm_delete_event' => 'کیا آپ واقعی اس واقعہ کو حذف کرنا چاہتے ہیں؟',
        'confirm_delete_hadith' => 'کیا آپ واقعی اس حدیث کو حذف کرنا چاہتے ہیں؟',
        'confirm_delete_badge' => 'کیا آپ واقعی اس بیج کو حذف کرنا چاہتے ہیں؟',
        'confirm_unlink_content' => 'کیا آپ واقعی اس مواد کو غیر منسلک کرنا چاہتے ہیں؟',
        'search_results_for' => '"%s" کے لیے تلاش کے نتائج',
        'page_not_found' => 'صفحہ نہیں ملا',
        'page_not_found_message' => 'آپ جس صفحے کی تلاش میں ہیں وہ موجود نہیں ہے یا منتقل کر دیا گیا ہے۔',
        'back_to_home' => 'مرکزی صفحہ پر واپس',
        'by' => 'منجانب',
        'on' => 'بتاریخ',
        'unknown_user' => 'نامعلوم صارف',
        'unknown' => 'نامعلوم',
        'view_profile' => 'پروفائل دیکھیں',
        'user_profile_for' => '%s کا صارف پروفائل',
        'joined_on' => 'شامل ہوئے',
        'contributions' => 'شراکتیں',
        'events_contributed' => 'شامل کردہ واقعات',
        'hadiths_contributed' => 'شامل کردہ احادیث',
        'no_contributions' => 'ابھی تک کوئی شراکت نہیں۔',
        'no_bookmarks' => 'ابھی تک کوئی بک مارک نہیں۔',
        'no_badges_earned' => 'ابھی تک کوئی بیج حاصل نہیں ہوا۔',
        'awarded_on' => 'عطا کیا گیا',
        'theme' => 'تھیم',
        'created_at' => 'بنانے کی تاریخ',
        'last_login' => 'آخری لاگ ان (فیچر نافذ نہیں)',
        'edit_profile' => 'پروفائل میں ترمیم کریں (فیچر مکمل طور پر نافذ نہیں - بنیادی معلومات)',
        'change_password' => 'پاس ورڈ تبدیل کریں (فیچر نافذ نہیں)',
        'event_category_islamic' => 'اسلامی',
        'event_category_general' => 'عمومی',
        'select_item_type' => 'آئٹم کی قسم منتخب کریں',
        'select_item' => 'آئٹم منتخب کریں',
        'link_item' => 'آئٹم منسلک کریں',
        'events_map_title' => 'واقعات کا نقشہ - اسلامی تاریخ پورٹل',
        'all_events' => 'تمام واقعات',
        'event_timeline' => 'واقعات کا ٹائم لائن',
        'year' => 'سال',
        'century' => 'صدی',
        'filter_by_century' => 'صدی کے لحاظ سے فلٹر کریں',
        'all_centuries' => 'تمام صدیاں',
        'manage_badges' => 'بیجز کا انتظام',
        'edit_badge' => 'بیج میں ترمیم کریں',
        'stats_overview' => 'اعداد و شمار کا جائزہ',
        'content_type_distribution' => 'مواد کی قسم کی تقسیم',
        'user_roles_distribution' => 'صارف کے کردار کی تقسیم',
        'events_by_category' => 'زمرہ کے لحاظ سے واقعات',
        'content_status_distribution' => 'مواد کی حیثیت کی تقسیم (واقعات)',
        'content_status_distribution_hadith' => 'مواد کی حیثیت کی تقسیم (احادیث)',
        'search_placeholder_quran' => 'کلیدی لفظ، سورہ کا نام، یا سورہ:آیت درج کریں (مثلاً 1:1)',
        'search_placeholder_hadith' => 'کلیدی لفظ، راوی، یا ماخذ درج کریں',
        'search_placeholder_events' => 'عنوان یا تفصیل میں کلیدی لفظ درج کریں',
        'no_description_available' => 'کوئی تفصیل دستیاب نہیں۔',
        'not_applicable' => 'لاگو نہیں',
        'optional' => 'اختیاری',
        'required_field' => 'یہ خانہ ضروری ہے۔',
        'invalid_date_format' => 'تاریخ کی غلط فارمیٹ۔ YYYY-MM-DD استعمال کریں۔',
        'invalid_coordinates' => 'غلط نقاط۔',
        'item_not_found' => 'آئٹم نہیں ملا۔',
        'action_not_allowed' => 'آپ کے کردار کے لیے یہ کارروائی ممنوع ہے۔',
        'error_processing_request' => 'آپ کی درخواست پر کارروائی میں خرابی۔ براہ کرم دوبارہ کوشش کریں۔',
        'anonymous' => 'گمنام',
        'event_suggestions' => 'واقعات کی تجاویز',
        'hadith_suggestions' => 'احادیث کی تجاویز',
        'view_user_profile' => "صارف کا پروفائل دیکھیں",
        'event_latitude_tip' => 'مثلاً 21.4225',
        'event_longitude_tip' => 'مثلاً 39.8262',
        'event_date_tip' => 'فارمیٹ: YYYY-MM-DD، مثلاً 0624-03-13',
        'data_AM_note' => 'یقینی بنائیں کہ data.AM فائل اسی ڈائرکٹری میں ہے جہاں یہ اسکرپٹ ہے اور قرآن لوڈنگ کے لیے صحیح فارمیٹ میں ہے۔',
        'backup_note' => 'یہ پوری SQLite ڈیٹا بیس فائل ڈاؤن لوڈ کرے گا۔ اسے محفوظ جگہ پر رکھیں۔',
        'restore_note' => 'بحالی موجودہ ڈیٹا بیس کو اپ لوڈ کردہ فائل سے بدل دے گی۔ یہ عمل ناقابل واپسی ہے۔ یقینی بنائیں کہ اپ لوڈ کردہ فائل اس ایپلیکیشن کے لیے ایک درست SQLite ڈیٹا بیس ہے۔',
        'file_too_large' => 'اپ لوڈ کردہ فائل بہت بڑی ہے۔',
        'invalid_file_type_sqlite' => 'غلط فائل کی قسم۔ براہ کرم .sqlite فائل اپ لوڈ کریں۔',
        'restore_failed_check_log' => 'بحالی ناکام۔ تفصیلات کے لیے ایرر لاگ چیک کریں۔',
        'developer_info' => 'تیار کردہ یاسین اللہ (پاکستانی)۔',
        'version_info' => 'ورژن: ' . APP_VERSION,
        'go_to_admin_dashboard' => 'ایڈمن ڈیش بورڈ پر جائیں',
        'toggle_theme' => 'تھیم تبدیل کریں',
    ]
];

function translate($key, ...$args) {
    global $translations, $current_lang;
    $lang_to_use = $current_lang;
    if (!isset($translations[$lang_to_use][$key])) {
        // Fallback to English if translation not found in current language
        $lang_to_use = 'en';
        if (!isset($translations[$lang_to_use][$key])) {
            return $key; // Return key if not found in English either
        }
    }
    $text = $translations[$lang_to_use][$key];
    if (!empty($args)) {
        return vsprintf($text, $args);
    }
    return $text;
}
// Alias for translate
function t($key, ...$args) {
    return translate($key, ...$args);
}


// ============== CORE HELPER FUNCTIONS ==============
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function addFlashMessage($message, $type = 'info') {
    $_SESSION['flash_messages'][] = ['message' => $message, 'type' => $type];
}

function displayFlashMessages() {
    if (isset($_SESSION['flash_messages'])) {
        foreach ($_SESSION['flash_messages'] as $msg) {
            echo '<div class="alert alert-' . sanitize_input($msg['type']) . ' alert-dismissible fade show" role="alert">';
            echo sanitize_input($msg['message']);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo '</div>';
        }
        unset($_SESSION['flash_messages']);
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser() {
    global $current_user;
    if ($current_user === null && isLoggedIn()) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user = $stmt->fetch();
    }
    return $current_user;
}

function userHasRole($role) {
    $user = getCurrentUser();
    if ($user && $user['role'] === $role) {
        return true;
    }
    if (is_array($role)) { // Check against multiple roles
        if ($user && in_array($user['role'], $role)) {
            return true;
        }
    }
    return false;
}

function isAdmin() { return userHasRole('admin'); }
function isUlama() { return userHasRole('ulama'); }
function isUser() { return userHasRole('user'); }

function requireLogin() {
    if (!isLoggedIn()) {
        addFlashMessage(t('login_required'), 'warning');
        redirect('index.php?page=login&return_url=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

function requireRole($role) {
    requireLogin();
    if (!userHasRole($role)) {
        addFlashMessage(t('access_denied'), 'danger');
        redirect('index.php');
    }
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getPagination($total_items, $current_page, $base_url, $items_per_page = ITEMS_PER_PAGE) {
    $total_pages = ceil($total_items / $items_per_page);
    $pagination_html = '';

    if ($total_pages > 1) {
        $pagination_html .= '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';

        // Previous button
        if ($current_page > 1) {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&p=' . ($current_page - 1) . '">Previous</a></li>';
        } else {
            $pagination_html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }

        // Page numbers
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $current_page) {
                $pagination_html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
            } else {
                $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&p=' . $i . '">' . $i . '</a></li>';
            }
        }

        // Next button
        if ($current_page < $total_pages) {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&p=' . ($current_page + 1) . '">Next</a></li>';
        } else {
            $pagination_html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }

        $pagination_html .= '</ul></nav>';
    }
    return $pagination_html;
}

function awardPoints($user_id, $points_to_add) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
    $stmt->execute([$points_to_add, $user_id]);
    checkAndAwardBadges($user_id);
}

function checkAndAwardBadges($user_id) {
    $db = getDB();
    $stmt_user = $db->prepare("SELECT points FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user_points = $stmt_user->fetchColumn();

    if ($user_points === false) return;

    $stmt_badges = $db->prepare("SELECT id, name_en FROM badges WHERE points_required <= ? 
                                 AND id NOT IN (SELECT badge_id FROM user_badges WHERE user_id = ?)");
    $stmt_badges->execute([$user_points, $user_id]);
    $new_badges = $stmt_badges->fetchAll();

    if (!empty($new_badges)) {
        $stmt_award = $db->prepare("INSERT OR IGNORE INTO user_badges (user_id, badge_id, awarded_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        foreach ($new_badges as $badge) {
            $stmt_award->execute([$user_id, $badge['id']]);
            // Optionally, add a flash message for new badge
            // addFlashMessage("Congratulations! You've earned the '{$badge['name_en']}' badge!", 'success');
        }
    }
}

function getUserById($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function format_date_for_display($date_string) {
    if (empty($date_string) || $date_string == '0000-00-00' || $date_string == '0000-00-00 00:00:00') {
        return t('not_applicable');
    }
    try {
        $date = new DateTime($date_string);
        return $date->format('F j, Y'); // Example: March 10, 2001
    } catch (Exception $e) {
        return $date_string; // Return original if formatting fails
    }
}

function getCenturyFromDate($date_string) {
    if (empty($date_string)) return null;
    try {
        $year = (new DateTime($date_string))->format('Y');
        return ceil($year / 100);
    } catch (Exception $e) {
        return null;
    }
}

// ============== ACTION PROCESSING (Forms, GET requests) ==============

// --- USER AUTHENTICATION ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    // Verify CSRF token for all POST actions
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        addFlashMessage(t('invalid_request') . ' (CSRF)', 'danger');
        // Avoid redirecting immediately for forms, let them redisplay with error
        // For critical actions, could redirect: redirect('index.php');
    } else {
        if ($action === 'register') {
            $username = sanitize_input($_POST['username']);
            $email = sanitize_input($_POST['email']);
            $password = $_POST['password']; // Not sanitized before hashing
            $confirm_password = $_POST['confirm_password'];

            if ($password !== $confirm_password) {
                addFlashMessage(t('password_mismatch'), 'danger');
            } elseif (empty($username) || empty($email) || empty($password)) {
                addFlashMessage(t('required_field'), 'danger');
            } else {
                $db = getDB();
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                if ($stmt->fetch()) {
                    addFlashMessage(t('user_exists'), 'danger');
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
                    if ($stmt->execute([$username, $email, $hashed_password])) {
                        addFlashMessage(t('registration_successful'), 'success');
                        redirect('index.php?page=login');
                    } else {
                        addFlashMessage(t('operation_failed'), 'danger');
                    }
                }
            }
        } elseif ($action === 'login') {
            $username = sanitize_input($_POST['username']);
            $password = $_POST['password'];
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                unset($_SESSION['csrf_token']); // Regenerate CSRF token after login
                addFlashMessage(t('welcome_user', $user['username']), 'success');
                $return_url = isset($_POST['return_url']) && !empty($_POST['return_url']) ? $_POST['return_url'] : 'index.php';
                redirect(urldecode($return_url));
            } else {
                addFlashMessage(t('login_failed'), 'danger');
            }
        }
        // --- EVENT ACTIONS ---
        elseif ($action === 'add_event' || $action === 'edit_event') {
            requireLogin();
            $title_en = sanitize_input($_POST['title_en']);
            $title_ur = sanitize_input($_POST['title_ur']); // Assuming direct input for Urdu
            $description_en = sanitize_input($_POST['description_en']);
            $description_ur = sanitize_input($_POST['description_ur']);
            $event_date = sanitize_input($_POST['event_date']);
            $category = sanitize_input($_POST['category']);
            $latitude = !empty($_POST['latitude']) ? filter_var($_POST['latitude'], FILTER_VALIDATE_FLOAT) : null;
            $longitude = !empty($_POST['longitude']) ? filter_var($_POST['longitude'], FILTER_VALIDATE_FLOAT) : null;
            $user_id = $_SESSION['user_id'];
            $status = (isAdmin() || isUlama()) ? 'approved' : 'pending';
            $approved_by = (isAdmin() || isUlama()) ? $user_id : null;
            $approved_at = (isAdmin() || isUlama()) ? date('Y-m-d H:i:s') : null;

            if (empty($title_en) || empty($title_ur) || empty($event_date) || empty($category)) {
                addFlashMessage(t('required_field'), 'danger');
            } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $event_date)) {
                 addFlashMessage(t('invalid_date_format'), 'danger');
            } else {
                $db = getDB();
                if ($action === 'add_event') {
                    $stmt = $db->prepare("INSERT INTO events (title_en, title_ur, description_en, description_ur, event_date, category, latitude, longitude, user_id, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$title_en, $title_ur, $description_en, $description_ur, $event_date, $category, $latitude, $longitude, $user_id, $status, $approved_by, $approved_at])) {
                        if ($status == 'approved') awardPoints($user_id, 10); // Example points
                        addFlashMessage( ($status == 'approved') ? t('operation_successful') : t('event_submitted'), 'success');
                        redirect('index.php?page=events');
                    } else {
                        addFlashMessage(t('operation_failed'), 'danger');
                    }
                } elseif ($action === 'edit_event' && isset($_POST['event_id'])) {
                    $event_id = filter_var($_POST['event_id'], FILTER_VALIDATE_INT);
                    // Check ownership or admin/ulama role
                    $stmt_check = $db->prepare("SELECT user_id FROM events WHERE id = ?");
                    $stmt_check->execute([$event_id]);
                    $event_owner_id = $stmt_check->fetchColumn();

                    if ($event_owner_id == $user_id || isAdmin() || isUlama()) {
                        $stmt = $db->prepare("UPDATE events SET title_en=?, title_ur=?, description_en=?, description_ur=?, event_date=?, category=?, latitude=?, longitude=? WHERE id=?");
                        if ($stmt->execute([$title_en, $title_ur, $description_en, $description_ur, $event_date, $category, $latitude, $longitude, $event_id])) {
                            addFlashMessage(t('operation_successful'), 'success');
                            redirect('index.php?page=view_event&id=' . $event_id);
                        } else {
                            addFlashMessage(t('operation_failed'), 'danger');
                        }
                    } else {
                         addFlashMessage(t('access_denied'), 'danger');
                    }
                }
            }
        }
        // --- HADITH ACTIONS ---
        elseif ($action === 'add_hadith' || $action === 'edit_hadith') {
            requireRole(['user', 'ulama', 'admin']); // Users can suggest, Ulama/Admin can add directly
            $text_en = sanitize_input($_POST['text_en']);
            $text_ur = sanitize_input($_POST['text_ur']);
            $source_en = sanitize_input($_POST['source_en']);
            $source_ur = sanitize_input($_POST['source_ur']);
            $narrator_en = sanitize_input($_POST['narrator_en']);
            $narrator_ur = sanitize_input($_POST['narrator_ur']);
            $user_id = $_SESSION['user_id'];
            $status = (isAdmin() || isUlama()) ? 'approved' : 'pending';
            $approved_by = (isAdmin() || isUlama()) ? $user_id : null;
            $approved_at = (isAdmin() || isUlama()) ? date('Y-m-d H:i:s') : null;

            if (empty($text_en) || empty($text_ur)) {
                 addFlashMessage(t('required_field'), 'danger');
            } else {
                $db = getDB();
                if ($action === 'add_hadith') {
                    $stmt = $db->prepare("INSERT INTO hadiths (text_en, text_ur, source_en, source_ur, narrator_en, narrator_ur, user_id, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$text_en, $text_ur, $source_en, $source_ur, $narrator_en, $narrator_ur, $user_id, $status, $approved_by, $approved_at])) {
                        if ($status == 'approved') awardPoints($user_id, 5); // Example points
                        addFlashMessage( ($status == 'approved') ? t('operation_successful') : t('hadith_submitted'), 'success');
                        redirect('index.php?page=hadith');
                    } else {
                        addFlashMessage(t('operation_failed'), 'danger');
                    }
                } elseif ($action === 'edit_hadith' && isset($_POST['hadith_id'])) {
                    $hadith_id = filter_var($_POST['hadith_id'], FILTER_VALIDATE_INT);
                    $stmt_check = $db->prepare("SELECT user_id FROM hadiths WHERE id = ?");
                    $stmt_check->execute([$hadith_id]);
                    $hadith_owner_id = $stmt_check->fetchColumn();
                     if ($hadith_owner_id == $user_id || isAdmin() || isUlama()) {
                        $stmt = $db->prepare("UPDATE hadiths SET text_en=?, text_ur=?, source_en=?, source_ur=?, narrator_en=?, narrator_ur=? WHERE id=?");
                        if ($stmt->execute([$text_en, $text_ur, $source_en, $source_ur, $narrator_en, $narrator_ur, $hadith_id])) {
                            addFlashMessage(t('operation_successful'), 'success');
                            redirect('index.php?page=view_hadith&id=' . $hadith_id);
                        } else {
                            addFlashMessage(t('operation_failed'), 'danger');
                        }
                    } else {
                        addFlashMessage(t('access_denied'), 'danger');
                    }
                }
            }
        }
        // --- BOOKMARK ACTIONS ---
        elseif ($action === 'toggle_bookmark') {
            requireLogin();
            $item_type = sanitize_input($_POST['item_type']);
            $item_id = filter_var($_POST['item_id'], FILTER_VALIDATE_INT);
            $user_id = $_SESSION['user_id'];

            if (in_array($item_type, ['event', 'ayah', 'hadith']) && $item_id > 0) {
                $db = getDB();
                $stmt_check = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND item_type = ? AND item_id = ?");
                $stmt_check->execute([$user_id, $item_type, $item_id]);
                if ($stmt_check->fetch()) { // Exists, so unbookmark
                    $stmt_delete = $db->prepare("DELETE FROM bookmarks WHERE user_id = ? AND item_type = ? AND item_id = ?");
                    if ($stmt_delete->execute([$user_id, $item_type, $item_id])) {
                        addFlashMessage(t('item_unbookmarked'), 'success');
                    } else {
                        addFlashMessage(t('bookmark_failed'), 'danger');
                    }
                } else { // Does not exist, so bookmark
                    $stmt_insert = $db->prepare("INSERT INTO bookmarks (user_id, item_type, item_id) VALUES (?, ?, ?)");
                    if ($stmt_insert->execute([$user_id, $item_type, $item_id])) {
                        addFlashMessage(t('item_bookmarked'), 'success');
                        // Award badge for bookmarking if it's a specific achievement
                        $stmt_count_bookmarks = $db->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
                        $stmt_count_bookmarks->execute([$user_id]);
                        if ($stmt_count_bookmarks->fetchColumn() >= 5) { // Example: 5 bookmarks for 'Explorer' badge
                            $explorer_badge = $db->query("SELECT id FROM badges WHERE name_en = 'Explorer'")->fetch();
                            if ($explorer_badge) {
                                $stmt_award_b = $db->prepare("INSERT OR IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)");
                                $stmt_award_b->execute([$user_id, $explorer_badge['id']]);
                            }
                        }
                    } else {
                        addFlashMessage(t('bookmark_failed'), 'danger');
                    }
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
            // Redirect back to the referring page if available, otherwise to home
            $redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
            redirect($redirect_url);
        }
        // --- ADMIN ACTIONS ---
        elseif ($action === 'approve_item' || $action === 'reject_item') {
            requireRole(['admin', 'ulama']);
            $item_type = sanitize_input($_POST['item_type']); // 'event' or 'hadith'
            $item_id = filter_var($_POST['item_id'], FILTER_VALIDATE_INT);
            $new_status = ($action === 'approve_item') ? 'approved' : 'rejected';
            $user_id = $_SESSION['user_id']; // Approver/Rejecter ID

            if (in_array($item_type, ['event', 'hadith']) && $item_id > 0) {
                $db = getDB();
                $table_name = ($item_type === 'event') ? 'events' : 'hadiths';
                
                // Get submitter ID to award points if approved
                $stmt_submitter = $db->prepare("SELECT user_id FROM $table_name WHERE id = ?");
                $stmt_submitter->execute([$item_id]);
                $submitter_id = $stmt_submitter->fetchColumn();

                $stmt = $db->prepare("UPDATE $table_name SET status = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
                if ($stmt->execute([$new_status, $user_id, $item_id])) {
                    if ($new_status === 'approved' && $submitter_id) {
                        $points = ($item_type === 'event') ? 10 : 5;
                        awardPoints($submitter_id, $points);
                    }
                    addFlashMessage(t($item_type . '_' . $new_status), 'success');
                } else {
                    addFlashMessage(t('operation_failed'), 'danger');
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
            redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php?page=admin_content');
        }
        elseif ($action === 'delete_item') {
            requireRole(['admin', 'ulama']); // Or owner for their own pending items
            $item_type = sanitize_input($_POST['item_type']);
            $item_id = filter_var($_POST['item_id'], FILTER_VALIDATE_INT);
            $current_user_id = $_SESSION['user_id'];

            if (in_array($item_type, ['event', 'hadith']) && $item_id > 0) {
                $db = getDB();
                $table_name = ($item_type === 'event') ? 'events' : 'hadiths';
                
                // Check if user can delete (admin, ulama, or owner of PENDING item)
                $can_delete = false;
                if (isAdmin() || isUlama()) {
                    $can_delete = true;
                } else {
                    $stmt_check = $db->prepare("SELECT user_id, status FROM $table_name WHERE id = ?");
                    $stmt_check->execute([$item_id]);
                    $item_details = $stmt_check->fetch();
                    if ($item_details && $item_details['user_id'] == $current_user_id && $item_details['status'] == 'pending') {
                        $can_delete = true;
                    }
                }

                if ($can_delete) {
                    if ($item_type === 'event') { // Delete linked content first for events
                        $stmt_del_links = $db->prepare("DELETE FROM content_links WHERE event_id = ?");
                        $stmt_del_links->execute([$item_id]);
                    }
                    $stmt = $db->prepare("DELETE FROM $table_name WHERE id = ?");
                    if ($stmt->execute([$item_id])) {
                        addFlashMessage(t('operation_successful'), 'success');
                    } else {
                        addFlashMessage(t('operation_failed'), 'danger');
                    }
                } else {
                    addFlashMessage(t('access_denied'), 'danger');
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
             redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php');
        }
        elseif ($action === 'update_user_role') {
            requireRole('admin');
            $user_id_to_update = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
            $new_role = sanitize_input($_POST['role']);
            if ($user_id_to_update && in_array($new_role, ['user', 'ulama', 'admin'])) {
                // Prevent admin from demoting themselves if they are the only admin
                $db = getDB();
                if ($user_id_to_update == $_SESSION['user_id'] && $new_role != 'admin') {
                    $stmt_admin_count = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                    if ($stmt_admin_count->fetchColumn() <= 1) {
                        addFlashMessage(t('action_not_allowed') . ' Cannot demote the only admin.', 'danger');
                        redirect('index.php?page=admin_users');
                    }
                }

                $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
                if ($stmt->execute([$new_role, $user_id_to_update])) {
                    addFlashMessage(t('user_updated'), 'success');
                } else {
                    addFlashMessage(t('operation_failed'), 'danger');
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
            redirect('index.php?page=admin_users');
        }
        elseif ($action === 'delete_user') {
            requireRole('admin');
            $user_id_to_delete = filter_var($_POST['user_id_delete'], FILTER_VALIDATE_INT);
            if ($user_id_to_delete) {
                if ($user_id_to_delete == $_SESSION['user_id']) {
                    addFlashMessage(t('action_not_allowed') . ' Cannot delete yourself.', 'danger');
                } else {
                    $db = getDB();
                    // Consider handling user's content (reassign or delete) - for now, just delete user
                    // This might fail if there are FK constraints not set to ON DELETE CASCADE/SET NULL
                    // For simplicity, we assume content can be orphaned or manually managed.
                    try {
                        $db->beginTransaction();
                        // Remove user badges
                        $stmt_badges = $db->prepare("DELETE FROM user_badges WHERE user_id = ?");
                        $stmt_badges->execute([$user_id_to_delete]);
                        // Remove bookmarks
                        $stmt_bookmarks = $db->prepare("DELETE FROM bookmarks WHERE user_id = ?");
                        $stmt_bookmarks->execute([$user_id_to_delete]);
                        
                        // What to do with user's content? For now, we'll set user_id to NULL or a generic "deleted user" ID if one exists.
                        // Or, prevent deletion if user has content. For this app, let's reassign to admin or make it anonymous.
                        // A simpler approach for now: just delete the user. FKs might prevent this if content exists.
                        // Let's try to set user_id to NULL on related content.
                        // This requires ALTER TABLE to allow NULL or a default user.
                        // For now, a simple delete. If FKs block, it's a sign more complex handling is needed.
                        $stmt_del_user = $db->prepare("DELETE FROM users WHERE id = ?");
                        $stmt_del_user->execute([$user_id_to_delete]);
                        // If events/hadiths have FK constraint on user_id, this will fail if user has content.
                        // A better approach would be to set user_id to a special "deleted_user" id or NULL if schema allows.
                        // For this example, we'll assume content might be orphaned or admin needs to reassign.
                        // For a production app, this needs careful thought.
                        // A simple fix is to update content to have a NULL user_id if the schema allows.
                        // Let's assume for now that deletion is direct and might fail due to FKs if not handled.
                        // To make it work, we'd need to update related tables first.
                        // $db->exec("UPDATE events SET user_id = NULL WHERE user_id = $user_id_to_delete");
                        // $db->exec("UPDATE hadiths SET user_id = NULL WHERE user_id = $user_id_to_delete");

                        $db->commit();
                        addFlashMessage(t('user_deleted'), 'success');
                    } catch (PDOException $e) {
                        $db->rollBack();
                        addFlashMessage(t('operation_failed') . ' ' . $e->getMessage(), 'danger');
                        error_log("Error deleting user: " . $e->getMessage());
                    }
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
            redirect('index.php?page=admin_users');
        }
        elseif ($action === 'link_content_to_event') {
            requireRole(['admin', 'ulama']);
            $event_id = filter_var($_POST['event_id_for_link'], FILTER_VALIDATE_INT);
            $linked_item_type = sanitize_input($_POST['linked_item_type']);
            $linked_item_id = filter_var($_POST['linked_item_id'], FILTER_VALIDATE_INT);

            if ($event_id && in_array($linked_item_type, ['ayah', 'hadith']) && $linked_item_id) {
                $db = getDB();
                $stmt = $db->prepare("INSERT INTO content_links (event_id, linked_item_type, linked_item_id) VALUES (?, ?, ?)");
                try {
                    if ($stmt->execute([$event_id, $linked_item_type, $linked_item_id])) {
                        addFlashMessage(t('content_linked'), 'success');
                    } else {
                        addFlashMessage(t('content_link_failed'), 'danger');
                    }
                } catch (PDOException $e) { // Catch unique constraint violation
                    addFlashMessage(t('content_link_failed') . ' (Already linked or invalid ID)', 'warning');
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
            redirect('index.php?page=view_event&id=' . $event_id);
        }
        elseif ($action === 'unlink_content_from_event') {
            requireRole(['admin', 'ulama']);
            $link_id = filter_var($_POST['link_id'], FILTER_VALIDATE_INT);
            $event_id_redirect = filter_var($_POST['event_id_redirect'], FILTER_VALIDATE_INT); // For redirecting back

            if ($link_id && $event_id_redirect) {
                $db = getDB();
                $stmt = $db->prepare("DELETE FROM content_links WHERE id = ?");
                if ($stmt->execute([$link_id])) {
                    addFlashMessage(t('content_unlinked'), 'success');
                } else {
                    addFlashMessage(t('content_unlink_failed'), 'danger');
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
             redirect('index.php?page=view_event&id=' . $event_id_redirect);
        }
        elseif ($action === 'add_badge' || $action === 'edit_badge_action') { // Renamed to avoid conflict
            requireRole('admin');
            $name_en = sanitize_input($_POST['name_en']);
            $name_ur = sanitize_input($_POST['name_ur']);
            $description_en = sanitize_input($_POST['description_en']);
            $description_ur = sanitize_input($_POST['description_ur']);
            $icon_class = sanitize_input($_POST['icon_class']);
            $points_required = filter_var($_POST['points_required'], FILTER_VALIDATE_INT);

            if (empty($name_en) || empty($name_ur) || $points_required === false) { // 0 points is valid
                addFlashMessage(t('required_field'), 'danger');
            } else {
                $db = getDB();
                if ($action === 'add_badge') {
                    $stmt = $db->prepare("INSERT INTO badges (name_en, name_ur, description_en, description_ur, icon_class, points_required) VALUES (?, ?, ?, ?, ?, ?)");
                    try {
                        if ($stmt->execute([$name_en, $name_ur, $description_en, $description_ur, $icon_class, $points_required])) {
                            addFlashMessage(t('badge_added'), 'success');
                        } else {
                            addFlashMessage(t('operation_failed'), 'danger');
                        }
                    } catch (PDOException $e) {
                        addFlashMessage(t('operation_failed') . ' (Points required might be duplicate)', 'danger');
                    }
                } elseif ($action === 'edit_badge_action' && isset($_POST['badge_id'])) {
                    $badge_id = filter_var($_POST['badge_id'], FILTER_VALIDATE_INT);
                    $stmt = $db->prepare("UPDATE badges SET name_en=?, name_ur=?, description_en=?, description_ur=?, icon_class=?, points_required=? WHERE id=?");
                     try {
                        if ($stmt->execute([$name_en, $name_ur, $description_en, $description_ur, $icon_class, $points_required, $badge_id])) {
                            addFlashMessage(t('badge_updated'), 'success');
                        } else {
                            addFlashMessage(t('operation_failed'), 'danger');
                        }
                    } catch (PDOException $e) {
                        addFlashMessage(t('operation_failed') . ' (Points required might be duplicate)', 'danger');
                    }
                }
                redirect('index.php?page=admin_badges');
            }
        }
        elseif ($action === 'delete_badge') {
            requireRole('admin');
            $badge_id = filter_var($_POST['badge_id_delete'], FILTER_VALIDATE_INT);
            if ($badge_id) {
                $db = getDB();
                try {
                    $db->beginTransaction();
                    // Remove from user_badges first
                    $stmt_user_badges = $db->prepare("DELETE FROM user_badges WHERE badge_id = ?");
                    $stmt_user_badges->execute([$badge_id]);
                    // Then delete badge
                    $stmt_badge = $db->prepare("DELETE FROM badges WHERE id = ?");
                    $stmt_badge->execute([$badge_id]);
                    $db->commit();
                    addFlashMessage(t('badge_deleted'), 'success');
                } catch (PDOException $e) {
                    $db->rollBack();
                    addFlashMessage(t('operation_failed') . ': ' . $e->getMessage(), 'danger');
                }
            } else {
                addFlashMessage(t('invalid_request'), 'danger');
            }
            redirect('index.php?page=admin_badges');
        }
        elseif ($action === 'backup_db') {
            requireRole('admin');
            $db_file_path = DB_FILE;
            if (file_exists($db_file_path)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="islamic_history_backup_'.date('Y-m-d_H-i-s').'.sqlite"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($db_file_path));
                readfile($db_file_path);
                // Log backup action
                error_log("Database backup performed by user ID: {$_SESSION['user_id']}");
                // No flash message here as headers are already sent.
                exit;
            } else {
                addFlashMessage(t('database_backup_failed') . ' File not found.', 'danger');
                redirect('index.php?page=admin_backup_restore');
            }
        }
        elseif ($action === 'restore_db') {
            requireRole('admin');
            if (isset($_FILES['db_file_restore']) && $_FILES['db_file_restore']['error'] == UPLOAD_ERR_OK) {
                $uploaded_file = $_FILES['db_file_restore'];
                $file_name = $uploaded_file['name'];
                $file_tmp_name = $uploaded_file['tmp_name'];
                $file_size = $uploaded_file['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if ($file_ext !== 'sqlite') {
                    addFlashMessage(t('invalid_file_type_sqlite'), 'danger');
                } elseif ($file_size > 50000000) { // Max 50MB, adjust as needed
                    addFlashMessage(t('file_too_large'), 'danger');
                } else {
                    // Close current DB connection if open
                    global $db; $db = null; 
                    
                    // Securely move the file
                    $destination = DB_FILE; // Overwrite current DB
                    // Optional: Create a backup of the current DB before overwriting
                    // copy(DB_FILE, DB_FILE . '.backup-' . date('Y-m-d_H-i-s'));

                    if (move_uploaded_file($file_tmp_name, $destination)) {
                        // Re-initialize DB connection to check if it's valid
                        try {
                            getDB(); // This will try to open the new DB file
                            initializeDatabase(); // This will ensure tables exist, useful if restoring an empty schema
                            addFlashMessage(t('database_restore_success'), 'success');
                            error_log("Database restored by user ID: {$_SESSION['user_id']} from file: {$file_name}");
                        } catch (PDOException $e) {
                            addFlashMessage(t('database_restore_failed') . ' Invalid SQLite file or structure.', 'danger');
                            error_log("Restore failed, invalid SQLite file: " . $e->getMessage());
                            // Try to restore the backup if one was made
                        }
                    } else {
                        addFlashMessage(t('database_restore_failed') . ' Could not move uploaded file.', 'danger');
                        error_log("Restore failed, could not move uploaded file.");
                    }
                }
            } else {
                addFlashMessage(t('database_restore_failed') . ' No file uploaded or upload error.', 'danger');
                if (isset($_FILES['db_file_restore']['error'])) {
                     error_log("Restore upload error code: " . $_FILES['db_file_restore']['error']);
                }
            }
            redirect('index.php?page=admin_backup_restore');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        redirect('index.php');
    }
    // Other GET actions can be here if needed, but most are handled by page rendering
}

// Initialize DB and load Quran data after potential POST actions that might modify DB structure (like restore)
initializeDatabase();
loadQuranDataFromFile(); // Load Quran data if not already present
$current_user = getCurrentUser(); // Refresh current user info

// ============== HTML TEMPLATING FUNCTIONS ==============
function render_header($page_title) {
    global $current_lang, $current_user;
    $theme_class = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'dark-mode' : 'light-mode';
    $html_attrs = 'lang="' . $current_lang . '"';
    if ($current_lang === 'ur') {
        $html_attrs .= ' dir="rtl"';
    }

    // Pre-evaluate translations for the header
    $t_toggle_navigation = t('toggle_navigation');
    $t_home = t('home');
    $t_events = t('events');
    $t_islamic_events = t('islamic_events');
    $t_general_events = t('general_events');
    $t_all_events = t('all_events');
    $t_timeline = t('timeline');
    $t_map_view = t('map_view');
    $t_add_event_text = t('add_event');
    $t_quran = t('quran');
    $t_hadith = t('hadith');
    $t_search_quran = t('search_quran');
    $t_search_hadith = t('search_hadith');
    $t_add_hadith_text = t('add_hadith');
    $t_bookmarks_text = t('bookmarks');
    $t_language = t('language');
    $t_english = t('english');
    $t_urdu = t('urdu');
    $t_profile_text = t('profile');
    $t_admin_panel_text = t('admin_panel');
    $t_logout_text = t('logout');
    $t_login_text = t('login');
    $t_register_text = t('register');
    $t_toggle_theme = t('toggle_theme');
    $t_unknown_user = t('unknown_user');

    // Pre-evaluate preserve_query_string for language links
    $pq_lang_en = preserve_query_string('lang'); // String for English link
    $pq_lang_ur = preserve_query_string('lang'); // String for Urdu link (usually the same logic)


    // Pre-build conditional HTML parts for navigation
    $add_event_link_html = '';
    if (isLoggedIn()) {
        $add_event_link_html = '<li><a class="dropdown-item" href="index.php?page=add_event">' . $t_add_event_text . '</a></li>';
    }

    $add_hadith_link_html = '';
    if (isLoggedIn()) {
        $add_hadith_link_html = '<li><a class="dropdown-item" href="index.php?page=add_hadith">' . $t_add_hadith_text . '</a></li>';
    }
    
    $bookmarks_link_html = '';
    if (isLoggedIn()) {
        $bookmarks_link_html = '<li class="nav-item"><a class="nav-link" href="index.php?page=bookmarks">' . $t_bookmarks_text . '</a></li>';
    }

    $user_menu_html = '';
    if (isLoggedIn()) {
        $username_display = (isset($current_user) && is_array($current_user) && isset($current_user['username'])) ? sanitize_input($current_user['username']) : $t_unknown_user;
        
        $admin_panel_link_html = '';
        if (isAdmin() || isUlama()) {
            $admin_panel_link_html = '<li><a class="dropdown-item" href="index.php?page=admin_dashboard">' . $t_admin_panel_text . '</a></li>';
        }

        $user_menu_html = '
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i> ' . $username_display . '
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="index.php?page=profile">' . $t_profile_text . '</a></li>
                    ' . $admin_panel_link_html . '
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="index.php?action=logout">' . $t_logout_text . '</a></li>
                </ul>
            </li>';
    } else {
        $user_menu_html = '
            <li class="nav-item"><a class="nav-link" href="index.php?page=login">' . $t_login_text . '</a></li>
            <li class="nav-item"><a class="nav-link" href="index.php?page=register">' . $t_register_text . '</a></li>';
    }

echo <<<HTML
<!DOCTYPE html>
<html {$html_attrs}>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$page_title} - Yasin Islamic History Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Embedded CSS - Placed in <PHP heredoc for single file structure */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; transition: background-color 0.3s, color 0.3s; }
        .islamic-theme-font { font-family: 'Noto Naskh Arabic', 'Times New Roman', serif; } /* Example */
        .arabic-text { font-family: 'Noto Naskh Arabic', Arial, sans-serif; font-size: 1.5em; direction: rtl; line-height: 1.8; }
        .urdu-text { font-family: 'Noto Nastaliq Urdu', Arial, sans-serif; font-size: 1.3em; direction: rtl; line-height: 1.8; }
        
        .light-mode { background-color: #f8f9fa; color: #212529; }
        .light-mode .navbar { background-color: #e9ecef !important; }
        .light-mode .card { background-color: #fff; }
        .light-mode .list-group-item { background-color: #fff; }
        .light-mode .form-control, .light-mode .form-select { background-color: #fff; color: #212529; }
        .light-mode .table { color: #212529; }
        .light-mode .table-striped > tbody > tr:nth-of-type(odd) > * { --bs-table-accent-bg: rgba(0, 0, 0, 0.05); }
        .light-mode .btn-outline-secondary { color: #6c757d; border-color: #6c757d; }
        .light-mode .btn-outline-secondary:hover { color: #fff; background-color: #6c757d; border-color: #6c757d; }
        .light-mode .nav-link { color: #343a40; }
        .light-mode .nav-link:hover, .light-mode .nav-link.active { color: #007bff; }
        .light-mode .dropdown-menu { background-color: #fff; border: 1px solid rgba(0,0,0,.15); }
        .light-mode .dropdown-item { color: #212529; }
        .light-mode .dropdown-item:hover, .light-mode .dropdown-item:focus { color: #16181b; background-color: #f8f9fa; }


        .dark-mode { background-color: #212529; color: #f8f9fa; }
        .dark-mode .navbar { background-color: #343a40 !important; }
        .dark-mode .navbar-brand, .dark-mode .nav-link, .dark-mode .dropdown-toggle { color: #f8f9fa !important; }
        .dark-mode .card { background-color: #343a40; border-color: #495057; color: #f8f9fa; }
        .dark-mode .list-group-item { background-color: #343a40; border-color: #495057; color: #f8f9fa; }
        .dark-mode .form-control, .dark-mode .form-select { background-color: #495057; color: #f8f9fa; border-color: #6c757d; }
        .dark-mode .form-control::placeholder { color: #adb5bd; }
        .dark-mode .table { color: #f8f9fa; --bs-table-striped-bg: rgba(255, 255, 255, 0.08); --bs-table-hover-bg: rgba(255,255,255,0.1); border-color: #495057;}
        .dark-mode .table-striped > tbody > tr:nth-of-type(odd) > * { --bs-table-accent-bg: var(--bs-table-striped-bg); }
        .dark-mode .btn-outline-secondary { color: #adb5bd; border-color: #adb5bd; }
        .dark-mode .btn-outline-secondary:hover { color: #212529; background-color: #adb5bd; border-color: #adb5bd; }
        .dark-mode .modal-content { background-color: #343a40; color: #f8f9fa; }
        .dark-mode .modal-header, .dark-mode .modal-footer { border-color: #495057; }
        .dark-mode .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .dark-mode .nav-link:hover, .dark-mode .nav-link.active { color: #0dcaf0 !important; }
        .dark-mode .dropdown-menu { background-color: #343a40; border: 1px solid #495057; }
        .dark-mode .dropdown-item { color: #f8f9fa; }
        .dark-mode .dropdown-item:hover, .dark-mode .dropdown-item:focus { color: #f8f9fa; background-color: #495057; }
        .dark-mode .page-link { background-color: #495057; border-color: #6c757d; color: #f8f9fa; }
        .dark-mode .page-item.active .page-link { background-color: #0dcaf0; border-color: #0dcaf0; color: #212529; }
        .dark-mode .page-item.disabled .page-link { background-color: #343a40; border-color: #495057; color: #6c757d; }


        .navbar-brand { font-weight: bold; }
        .footer { padding: 1rem 0; text-align: center; font-size: 0.9em; }
        .map-container { height: 400px; width: 100%; }
        .timeline-year { font-size: 1.5em; font-weight: bold; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
        .timeline-event { margin-bottom: 15px; padding-left: 20px; border-left: 3px solid #007bff; }
        .timeline-event .date { font-weight: bold; color: #555; }
        .dark-mode .timeline-year { border-bottom-color: #495057; }
        .dark-mode .timeline-event { border-left-color: #0dcaf0; }
        .dark-mode .timeline-event .date { color: #adb5bd; }
        .badge-icon { font-size: 2em; }
        .btn-urdu { font-family: 'Noto Nastaliq Urdu', Arial, sans-serif; }
        /* RTL specific adjustments */
        body[dir="rtl"] .ms-auto { margin-right: auto !important; margin-left: 0 !important; }
        body[dir="rtl"] .me-auto { margin-left: auto !important; margin-right: 0 !important; }
        body[dir="rtl"] .dropdown-menu-end { right: 0; left: auto; }
        body[dir="rtl"] .text-end { text-align: left !important; }
        body[dir="rtl"] .text-start { text-align: right !important; }
        body[dir="rtl"] .float-end { float: left !important; }
        body[dir="rtl"] .float-start { float: right !important; }
        body[dir="rtl"] .form-check { padding-right: 1.25em; padding-left: 0; }
        body[dir="rtl"] .form-check .form-check-input { margin-right: -1.25em; margin-left: 0; }
        body[dir="rtl"] .modal-header .btn-close { margin-left: auto; margin-right: -0.5rem; }
        body[dir="rtl"] .list-group-item > .float-end { float:left !important; }
        body[dir="rtl"] .card-header > .float-end { float:left !important; }
        /* Add these styles to your existing <style> block in render_header */
/* === NEW TIMELINE INFOGRAPHIC STYLES (RTL AWARE) === */
.timeline-infographic {
    position: relative;
    margin: 0 auto;
    padding: 40px 0;
    width: 90%; /* Max width */
    max-width: 1000px;
}

.timeline-infographic::after { /* This is the central line */
    content: '';
    position: absolute;
    width: 3px;
    background-color: #007bff; /* LTR primary */
    top: 0;
    bottom: 0;
    left: 50%;
    margin-left: -1.5px;
}
body[dir="rtl"] .timeline-infographic::after {
    left: auto;
    right: 50%;
    margin-left: auto;
    margin-right: -1.5px;
}
.dark-mode .timeline-infographic::after {
    background-color: #0dcaf0; /* Dark mode accent */
}

.timeline-item-container {
    padding: 10px 40px;
    position: relative;
    background-color: inherit;
    width: 50%;
    box-sizing: border-box; /* Important for width calculations */
    margin-bottom: 20px;
}

/* The actual content box */
.timeline-content {
    padding: 20px 30px;
    background-color: #f8f9fa; /* LTR light bg */
    position: relative;
    border-radius: 6px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.dark-mode .timeline-content {
    background-color: #343a40; /* Dark mode bg */
    border: 1px solid #495057;
    color: #f8f9fa;
}
.dark-mode .timeline-content .text-muted {
    color: #adb5bd !important;
}


/* Items on the left side (in LTR) */
.timeline-item-container.left {
    left: 0;
}
/* Items on the right side (in LTR) */
.timeline-item-container.right {
    left: 50%;
}

/* RTL Overrides for item container positioning */
body[dir="rtl"] .timeline-item-container.left {
    left: 50%; /* LTR-left becomes RTL-right */
}
body[dir="rtl"] .timeline-item-container.right {
    left: 0;   /* LTR-right becomes RTL-left */
}

/* Circle on the timeline */
.timeline-item-container::before {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background-color: white;
    border: 3px solid #007bff; /* LTR primary */
    top: 25px; /* Adjust as needed */
    z-index: 1;
}
.dark-mode .timeline-item-container::before {
    background-color: #343a40; /* Dark mode bg */
    border-color: #0dcaf0; /* Dark mode accent */
}

/* Positioning the circle for LTR-left / RTL-right items */
.timeline-item-container.left::before {
    right: -10px; /* (width/2) */
}
body[dir="rtl"] .timeline-item-container.left::before { /* This is now the item on the RIGHT in RTL */
    left: -10px;
    right: auto;
}

/* Positioning the circle for LTR-right / RTL-left items */
.timeline-item-container.right::before {
    left: -10px;
}
body[dir="rtl"] .timeline-item-container.right::before { /* This is now the item on the LEFT in RTL */
    right: -10px;
    left: auto;
}


/* Arrows (optional, can be tricky with RTL and responsiveness) */
.timeline-content::before { /* This creates the arrow */
    content: " ";
    height: 0;
    position: absolute;
    top: 28px; /* Align with circle */
    width: 0;
    z-index: 1;
    border: medium solid #f8f9fa; /* Match content background */
}
.dark-mode .timeline-content::before {
    border-color: medium solid #343a40; /* Match dark mode content background */
}

/* Arrow for LTR-left / RTL-right items */
.timeline-item-container.left .timeline-content::before {
    right: -10px; /* Points right */
    border-width: 10px 0 10px 10px;
    border-left-color: inherit; /* Inherits border color from .timeline-content */
}
body[dir="rtl"] .timeline-item-container.left .timeline-content::before { /* Item on RIGHT in RTL, arrow points left */
    left: -10px;
    right: auto;
    border-width: 10px 10px 10px 0;
    border-right-color: inherit;
    border-left-color: transparent; /* Ensure other sides are transparent */
}


/* Arrow for LTR-right / RTL-left items */
.timeline-item-container.right .timeline-content::before {
    left: -10px; /* Points left */
    border-width: 10px 10px 10px 0;
    border-right-color: inherit;
}
body[dir="rtl"] .timeline-item-container.right .timeline-content::before { /* Item on LEFT in RTL, arrow points right */
    right: -10px;
    left: auto;
    border-width: 10px 0 10px 10px;
    border-left-color: inherit;
    border-right-color: transparent; /* Ensure other sides are transparent */
}


.timeline-century-header {
    text-align: center;
    font-size: 1.8em; /* Slightly smaller */
    font-weight: bold;
    padding: 10px 20px;
    margin: 30px auto 30px auto; /* Center it and give space */
    background-color: rgba(0,123,255,0.1);
    border-radius: .25rem;
    display: table; /* To allow margin auto for centering inline-block like element */
    position: relative; /* To ensure it's above the timeline line if overlapping */
    z-index: 2;
}
.dark-mode .timeline-century-header {
    background-color: rgba(13,202,240,0.2);
    color: #f8f9fa;
}
/* No specific RTL needed for century header if it's centered and display:table */


/* Responsive adjustments for smaller screens */
@media screen and (max-width: 768px) {
    .timeline-infographic::after { /* Central line to the left */
        left: 15px;
        margin-left: 0;
    }
    body[dir="rtl"] .timeline-infographic::after {
        right: 15px;
        left: auto;
        margin-right: 0;
    }

    .timeline-item-container,
    body[dir="rtl"] .timeline-item-container.left,
    body[dir="rtl"] .timeline-item-container.right { /* All items to full width and align left (in LTR sense) */
        width: 100%;
        padding-left: 55px; /* Space for circle and line */
        padding-right: 15px;
        left: 0 !important; /* Override inline style if any was set by mistake */
        right: auto !important;
    }
    body[dir="rtl"] .timeline-item-container {
        padding-left: 15px;
        padding-right: 55px; /* Space for circle and line on the right */
    }


    .timeline-item-container::before, /* Circle */
    body[dir="rtl"] .timeline-item-container.left::before,
    body[dir="rtl"] .timeline-item-container.right::before {
        left: 5px; /* Position circle near the line */
        right: auto;
    }
    body[dir="rtl"] .timeline-item-container::before {
        right: 5px;
        left: auto;
    }

    .timeline-content::before, /* Arrow */
    body[dir="rtl"] .timeline-item-container.left .timeline-content::before,
    body[dir="rtl"] .timeline-item-container.right .timeline-content::before {
        left: -10px; /* Arrow points left from content box */
        right: auto;
        border-width: 10px 10px 10px 0;
        border-right-color: inherit;
        border-left-color: transparent;
    }
     body[dir="rtl"] .timeline-content::before {
        right: -10px; /* Arrow points right from content box */
        left: auto;
        border-width: 10px 0 10px 10px;
        border-left-color: inherit;
        border-right-color: transparent;
    }

    .timeline-century-header {
        width: auto; /* Let it size by content */
        margin-left: 15px;
        margin-right: 15px;
        /* Reset positioning if it was absolute/relative for centering */
        left: auto;
        right: auto;
        transform: none;
        text-align: center; /* Or left/right as per language */
    }
    body[dir="rtl"] .timeline-century-header {
        text-align: center; /* Or right */
    }
}
/* === END NEW TIMELINE INFOGRAPHIC STYLES === */

    </style>
    
</head>
<body class="{$theme_class}">
    <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">{$page_title}</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="{$t_toggle_navigation}">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="index.php">{$t_home}</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="eventsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">{$t_events}</a>
                        <ul class="dropdown-menu" aria-labelledby="eventsDropdown">
                            <li><a class="dropdown-item" href="index.php?page=events&category=islamic">{$t_islamic_events}</a></li>
                            <li><a class="dropdown-item" href="index.php?page=events&category=general">{$t_general_events}</a></li>
                            <li><a class="dropdown-item" href="index.php?page=events">{$t_all_events}</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="index.php?page=timeline">{$t_timeline}</a></li>
                            <li><a class="dropdown-item" href="index.php?page=map_view">{$t_map_view}</a></li>
                            {$add_event_link_html}
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="quranHadithDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">{$t_quran} / {$t_hadith}</a>
                        <ul class="dropdown-menu" aria-labelledby="quranHadithDropdown">
                            <li><a class="dropdown-item" href="index.php?page=quran_search">{$t_search_quran}</a></li>
                            <li><a class="dropdown-item" href="index.php?page=hadith">{$t_search_hadith}</a></li>
                            {$add_hadith_link_html}
                        </ul>
                    </li>
                    {$bookmarks_link_html}
                </ul>
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <button id="themeToggle" class="btn btn-outline-secondary me-2" title="{$t_toggle_theme}" aria-label="{$t_toggle_theme}">
                            <i class="bi bi-sun-fill light-mode-icon"></i><i class="bi bi-moon-fill dark-mode-icon"></i>
                        </button>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="languageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-translate"></i> {$t_language}
                        </a>
                         <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
                            <li><a class="dropdown-item" href="?lang=en{$pq_lang_en}">{$t_english}</a></li>
                            <li><a class="dropdown-item" href="?lang=ur{$pq_lang_ur}">{$t_urdu}</a></li>
                        </ul>
                    </li>
                    {$user_menu_html}
                </ul>
            </div>
        </div>
    </nav>
    <div class="container">
HTML;
    displayFlashMessages();
}

function preserve_query_string($exclude_key = null) {
    $query = $_GET;
    if ($exclude_key) {
        unset($query[$exclude_key]);
    }
    if (!empty($query)) {
        return '&' . http_build_query($query);
    }
    return '';
}


function render_footer() {
    global $current_lang;
    $text_align_class = ($current_lang === 'ur') ? 'text-start' : 'text-end';
    $are_you_sure_js_string = htmlspecialchars(t('are_you_sure'), ENT_QUOTES, 'UTF-8');

    // Pre-evaluate values for the footer
    $current_year = date('Y');
    $site_name_const = SITE_NAME; // Constants can be used directly with {} but for consistency let's use a var
    $t_developer_info = t('developer_info');
    $t_version_info = t('version_info');
    $t_go_to_admin_dashboard = t('go_to_admin_dashboard'); // For the conditional link

echo <<<HTML
    </div> <!-- /.container -->
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container">
            <div class="row">
                <div class="col-md-6 text-muted 
HTML;
    // For RTL text-align
    echo ($current_lang === 'ur') ? 'text-end' : 'text-start';
echo <<<HTML
">
                    © {$current_year} {$site_name_const}. {$t_developer_info}
                </div>
                <div class="col-md-6 text-muted {$text_align_class}">
                    {$t_version_info}
                </div>
            </div>
HTML;
            // This part is outside HEREDOC, so standard PHP is fine and already uses the pre-evaluated var
            if (isLoggedIn() && (isAdmin() || isUlama())) {
                echo '<div class="row mt-2"><div class="col text-center"><small><a href="index.php?page=admin_dashboard">' . $t_go_to_admin_dashboard . '</a></small></div></div>';
            }
echo <<<HTML
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Theme switcher logic
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            const lightIcon = themeToggle.querySelector('.light-mode-icon');
            const darkIcon = themeToggle.querySelector('.dark-mode-icon');
            const currentTheme = document.cookie.split('; ').find(row => row.startsWith('theme='))?.split('=')[1] || 'light';

            function applyTheme(theme) {
                document.body.classList.remove('light-mode', 'dark-mode');
                document.body.classList.add(theme + '-mode');
                if (theme === 'dark') {
                    if(lightIcon) lightIcon.style.display = 'inline-block';
                    if(darkIcon) darkIcon.style.display = 'none';
                } else {
                    if(lightIcon) lightIcon.style.display = 'none';
                    if(darkIcon) darkIcon.style.display = 'inline-block';
                }
            }
            applyTheme(currentTheme);

            themeToggle.addEventListener('click', () => {
                let newTheme = document.body.classList.contains('light-mode') ? 'dark' : 'light';
                applyTheme(newTheme);
                document.cookie = "theme=" + newTheme + ";path=/;max-age=" + (60*60*24*365) + ";samesite=lax";
            });
        }
        
        // Geolocation for forms
        const geoBtn = document.getElementById('getCurrentLocationBtn');
        if (geoBtn) {
            geoBtn.addEventListener('click', function() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        if(document.getElementById('latitude')) document.getElementById('latitude').value = position.coords.latitude.toFixed(6);
                        if(document.getElementById('longitude')) document.getElementById('longitude').value = position.coords.longitude.toFixed(6);
                    }, function(error) {
                        alert('Error getting location: ' + error.message);
                    });
                } else {
                    alert('Geolocation is not supported by your browser.');
                }
            });
        }

        // Confirm delete
        document.querySelectorAll('.confirm-delete').forEach(button => {
            button.addEventListener('click', function(event) {
                const message = this.dataset.confirmMessage || '{$are_you_sure_js_string}';
                if (!confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
HTML;
}

// ============== PAGE RENDERING FUNCTIONS ==============
function render_home_page() {
    render_header(t('home'));
    $db = getDB();
    
    // Fetch some recent approved events
    $stmt_events = $db->prepare("SELECT * FROM events WHERE status = 'approved' ORDER BY event_date DESC LIMIT 13");
    $stmt_events->execute();
    $recent_events = $stmt_events->fetchAll();

    // Fetch some recent approved hadiths
    $stmt_hadiths = $db->prepare("SELECT * FROM hadiths WHERE status = 'approved' ORDER BY created_at DESC LIMIT 3");
    $stmt_hadiths->execute();
    $recent_hadiths = $stmt_hadiths->fetchAll();

    // Fetch some random ayahs
    $stmt_ayahs = $db->query("SELECT * FROM ayahs ORDER BY RANDOM() LIMIT 3");
    $random_ayahs = $stmt_ayahs->fetchAll();

    ?>
    <div class="px-4 py-5 my-5 text-center">
        <i class="bi bi-moon-stars-fill display-4 text-primary"></i>
        <h1 class="display-5 fw-bold"><?php echo t('site_title'); ?></h1>
        <div class="col-lg-6 mx-auto">
            <p class="lead mb-4"><?php echo t('welcome_user', isLoggedIn() ? sanitize_input(getCurrentUser()['username']) : t('anonymous')); ?></p>
            <p class="lead mb-4">Explore the rich tapestry of Islamic history, discover significant events, and delve into the wisdom of the Quran and Hadith.</p>
            <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                <a href="index.php?page=events" class="btn btn-primary btn-lg px-4 gap-3"><?php echo t('explore_events'); ?></a>
                <a href="index.php?page=quran_search" class="btn btn-outline-secondary btn-lg px-4"><?php echo t('search_quran'); ?></a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-<?php echo (count($recent_hadiths) > 0 || count($random_ayahs) > 0) ? '8' : '12'; ?>">
            <h2><?php echo t('recent_events'); ?></h2>
            <?php if (!empty($recent_events)): ?>
                <div class="list-group">
                    <?php foreach ($recent_events as $event): ?>
                        <a href="index.php?page=view_event&id=<?php echo $event['id']; ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h5 class="mb-1"><?php echo sanitize_input(t_dynamic($event['title_en'], $event['title_ur'])); ?></h5>
                                <small><?php echo format_date_for_display($event['event_date']); ?></small>
                            </div>
                            <p class="mb-1 small"><?php echo substr(sanitize_input(t_dynamic($event['description_en'], $event['description_ur'])), 0, 150); ?>...</p>
                            <small class="text-muted"><?php echo t('category'); ?>: <?php echo t('event_category_' . $event['category']); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p><?php echo t('no_items_found'); ?></p>
            <?php endif; ?>
        </div>
        <?php if (count($recent_hadiths) > 0 || count($random_ayahs) > 0): ?>
        <div class="col-md-4">
            <?php if (!empty($recent_hadiths)): ?>
            <h4 class="mt-4 mt-md-0"><?php echo t('recent_hadiths'); ?></h4>
            <div class="list-group mb-3">
                <?php foreach ($recent_hadiths as $hadith): ?>
                <a href="index.php?page=view_hadith&id=<?php echo $hadith['id']; ?>" class="list-group-item list-group-item-action">
                    <p class="mb-1 small urdu-text"><?php echo substr(sanitize_input($hadith['text_ur']), 0, 100); ?>...</p>
                    <small class="text-muted"><?php echo sanitize_input(t_dynamic($hadith['source_en'], $hadith['source_ur'])); ?></small>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($random_ayahs)): ?>
            <h4 class="mt-4 mt-md-0"><?php echo t('quran'); ?></h4>
            <div class="list-group">
                <?php foreach ($random_ayahs as $ayah): ?>
                <a href="index.php?page=quran_search&query=<?php echo $ayah['surah_number'].':'.$ayah['ayah_number']; ?>" class="list-group-item list-group-item-action">
                    <p class="mb-1 arabic-text"><?php echo sanitize_input($ayah['arabic_text']); ?></p>
                    <p class="mb-1 small urdu-text"><?php echo substr(sanitize_input($ayah['urdu_translation']), 0, 100); ?>...</p>
                    <small class="text-muted"><?php echo t('surah'); ?> <?php echo $ayah['surah_number']; ?>, <?php echo t('ayah'); ?> <?php echo $ayah['ayah_number']; ?></small>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
}

function render_login_page() {
    if (isLoggedIn()) redirect('index.php');
    render_header(t('login'));
    $return_url = isset($_GET['return_url']) ? sanitize_input($_GET['return_url']) : '';
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h2><?php echo t('login'); ?></h2></div>
                <div class="card-body">
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="return_url" value="<?php echo $return_url; ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label"><?php echo t('username'); ?></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label"><?php echo t('password'); ?></label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary"><?php echo t('login'); ?></button>
                    </form>
                </div>
                <div class="card-footer text-center">
                    <p>Don't have an account? <a href="index.php?page=register"><?php echo t('register'); ?></a></p>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_footer();
}

function render_register_page() {
    if (isLoggedIn()) redirect('index.php');
    render_header(t('register'));
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h2><?php echo t('register'); ?></h2></div>
                <div class="card-body">
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label"><?php echo t('username'); ?></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label"><?php echo t('email'); ?></label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label"><?php echo t('password'); ?></label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label"><?php echo t('confirm_password'); ?></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-primary"><?php echo t('register'); ?></button>
                    </form>
                </div>
                 <div class="card-footer text-center">
                    <p>Already have an account? <a href="index.php?page=login"><?php echo t('login'); ?></a></p>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_footer();
}

function t_dynamic($en_text, $ur_text) {
    global $current_lang;
    return ($current_lang === 'ur' && !empty($ur_text)) ? $ur_text : $en_text;
}

function render_events_page() {
    render_header(t('events'));
    $db = getDB();
    $current_page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $offset = ($current_page_num - 1) * ITEMS_PER_PAGE;

    $where_clauses = ["status = 'approved'"];
    $params = []; // For WHERE clause parameters

    $category_filter = isset($_GET['category']) ? sanitize_input($_GET['category']) : '';
    if ($category_filter && ($category_filter == 'islamic' || $category_filter == 'general')) {
        $where_clauses[] = "category = ?";
        $params[] = $category_filter;
    }
    
    $search_term = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
    if (!empty($search_term)) {
        $where_clauses[] = "(title_en LIKE ? OR title_ur LIKE ? OR description_en LIKE ? OR description_ur LIKE ?)";
        for ($i=0; $i<4; $i++) $params[] = '%' . $search_term . '%';
    }

    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $stmt_count = $db->prepare("SELECT COUNT(*) FROM events $where_sql");
    $stmt_count->execute($params); // Execute with WHERE params
    $total_items = $stmt_count->fetchColumn();

    // Prepare the main query
    $stmt_events = $db->prepare("SELECT * FROM events $where_sql ORDER BY event_date DESC LIMIT :limit OFFSET :offset");

    // Bind WHERE clause parameters first (if any) using bindValue
    foreach ($params as $key => $value) {
        $stmt_events->bindValue($key + 1, $value); // Parameters are 1-indexed
    }

    // Bind LIMIT and OFFSET parameters using bindValue and named placeholders for clarity
    $limit_val = ITEMS_PER_PAGE; // Use a variable for bindValue if preferred, or bind directly
    $offset_val = $offset;

    // Adjust parameter index if WHERE params exist
    $param_idx_start = count($params) + 1;
    $stmt_events->bindValue(':limit', $limit_val, PDO::PARAM_INT);
    $stmt_events->bindValue(':offset', $offset_val, PDO::PARAM_INT);
    
    $stmt_events->execute();
    $events = $stmt_events->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><?php echo t('events'); ?> <?php if($category_filter) echo "(".t($category_filter).")"; ?></h1>
        <?php if (isLoggedIn()): ?>
        <a href="index.php?page=add_event" class="btn btn-primary"><?php echo t('add_event'); ?></a>
        <?php endif; ?>
    </div>

    <form method="GET" action="index.php" class="mb-3">
        <input type="hidden" name="page" value="events">
        <?php if ($category_filter): ?> <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>"> <?php endif; ?>
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_placeholder_events'); ?>" value="<?php echo htmlspecialchars($search_term); ?>">
            <button class="btn btn-outline-secondary" type="submit"><?php echo t('search'); ?></button>
        </div>
    </form>

    <?php if (!empty($events)): ?>
        <div class="list-group">
            <?php foreach ($events as $event): ?>
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">
                            <a href="index.php?page=view_event&id=<?php echo $event['id']; ?>">
                                <?php echo sanitize_input(t_dynamic($event['title_en'], $event['title_ur'])); ?>
                            </a>
                        </h5>
                        <small><?php echo format_date_for_display($event['event_date']); ?></small>
                    </div>
                    <p class="mb-1"><?php echo substr(sanitize_input(t_dynamic($event['description_en'], $event['description_ur'])), 0, 200); ?>...</p>
                    <small class="text-muted"><?php echo t('category'); ?>: <?php echo t('event_category_' . $event['category']); ?></small>
                    <?php if (isLoggedIn()): ?>
                    <form method="POST" action="index.php" class="d-inline float-end">
                        <input type="hidden" name="action" value="toggle_bookmark">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="event">
                        <input type="hidden" name="item_id" value="<?php echo $event['id']; ?>">
                        <?php
                            $is_bookmarked_stmt = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND item_type = 'event' AND item_id = ?");
                            $is_bookmarked_stmt->execute([$_SESSION['user_id'], $event['id']]);
                            $is_bookmarked = $is_bookmarked_stmt->fetch();
                        ?>
                        <button type="submit" class="btn btn-sm <?php echo $is_bookmarked ? 'btn-success' : 'btn-outline-secondary'; ?>" title="<?php echo $is_bookmarked ? t('item_unbookmarked') : t('item_bookmarked'); ?>">
                            <i class="bi <?php echo $is_bookmarked ? 'bi-bookmark-fill' : 'bi-bookmark'; ?>"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                     <?php if (isAdmin() || isUlama()): ?>
                        <a href="index.php?page=edit_event&id=<?php echo $event['id']; ?>" class="btn btn-sm btn-warning float-end me-2"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php echo getPagination($total_items, $current_page_num, 'index.php?page=events' . ($category_filter ? '&category='.urlencode($category_filter) : '') . ($search_term ? '&search='.urlencode($search_term) : '')); ?>
    <?php else: ?>
        <p><?php echo t('no_items_found'); ?></p>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_view_event_page() {
    $event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$event_id) { render_not_found_page(); return; }

    $db = getDB();
    $stmt = $db->prepare("SELECT e.*, u.username as submitter_username, a.username as approver_username 
                          FROM events e 
                          JOIN users u ON e.user_id = u.id 
                          LEFT JOIN users a ON e.approved_by = a.id
                          WHERE e.id = ? AND (e.status = 'approved' OR ? OR ?)");
    $stmt->execute([$event_id, (isLoggedIn() && isAdmin()), (isLoggedIn() && isUlama())]); // Allow admin/ulama to see non-approved
    $event = $stmt->fetch();

    if (!$event) { render_not_found_page(); return; }
    
    // If event is pending and current user is not submitter or admin/ulama, deny access
    if ($event['status'] === 'pending' && !(isLoggedIn() && ($event['user_id'] == $_SESSION['user_id'] || isAdmin() || isUlama()))) {
        addFlashMessage(t('access_denied'), 'danger');
        redirect('index.php?page=events');
    }


    render_header(sanitize_input(t_dynamic($event['title_en'], $event['title_ur'])));

    // Fetch linked content
    $stmt_links = $db->prepare("SELECT cl.id as link_id, cl.linked_item_type, cl.linked_item_id, 
                                a.arabic_text, a.urdu_translation, a.surah_number, a.ayah_number,
                                h.text_en as hadith_text_en, h.text_ur as hadith_text_ur, h.source_en as hadith_source_en, h.source_ur as hadith_source_ur
                                FROM content_links cl
                                LEFT JOIN ayahs a ON cl.linked_item_type = 'ayah' AND cl.linked_item_id = a.id
                                LEFT JOIN hadiths h ON cl.linked_item_type = 'hadith' AND cl.linked_item_id = h.id
                                WHERE cl.event_id = ?");
    $stmt_links->execute([$event_id]);
    $linked_items = $stmt_links->fetchAll();
    ?>
    <div class="card">
        <div class="card-header">
            <h1 class="card-title <?php echo ($current_lang == 'ur' && !empty($event['title_ur'])) ? 'urdu-text' : ''; ?>"><?php echo sanitize_input(t_dynamic($event['title_en'], $event['title_ur'])); ?></h1>
            <?php if ($event['status'] !== 'approved'): ?>
                <span class="badge bg-<?php echo $event['status'] === 'pending' ? 'warning' : 'danger'; ?> text-dark">
                    <?php echo t($event['status']); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p><strong><?php echo t('date'); ?>:</strong> <?php echo format_date_for_display($event['event_date']); ?></p>
            <p><strong><?php echo t('category'); ?>:</strong> <?php echo t('event_category_' . $event['category']); ?></p>
            <div class="<?php echo ($current_lang == 'ur' && !empty($event['description_ur'])) ? 'urdu-text' : ''; ?>">
                <?php echo nl2br(sanitize_input(t_dynamic($event['description_en'], $event['description_ur']))); ?>
            </div>
            <hr>
            <p><small><?php echo t('submitted_by'); ?>: <a href="index.php?page=user_profile&id=<?php echo $event['user_id']; ?>"><?php echo sanitize_input($event['submitter_username']); ?></a> <?php echo t('on'); ?> <?php echo format_date_for_display($event['created_at']); ?></small></p>
            <?php if ($event['status'] === 'approved' && $event['approved_by']): ?>
                <p><small><?php echo t('approved_by'); ?>: <a href="index.php?page=user_profile&id=<?php echo $event['approved_by']; ?>"><?php echo sanitize_input($event['approver_username'] ?: t('unknown_user')); ?></a> <?php echo t('on'); ?> <?php echo format_date_for_display($event['approved_at']); ?></small></p>
            <?php endif; ?>

            <?php if ($event['latitude'] && $event['longitude']): ?>
                <h5 class="mt-4"><?php echo t('location'); ?></h5>
                <div id="eventMap" class="map-container mb-3"></div>
                <p><small><?php echo t('latitude'); ?>: <?php echo $event['latitude']; ?>, <?php echo t('longitude'); ?>: <?php echo $event['longitude']; ?></small></p>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <?php if (isLoggedIn()): ?>
                <form method="POST" action="index.php" class="d-inline">
                    <input type="hidden" name="action" value="toggle_bookmark">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="item_type" value="event">
                    <input type="hidden" name="item_id" value="<?php echo $event['id']; ?>">
                    <?php
                        $is_bookmarked_stmt = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND item_type = 'event' AND item_id = ?");
                        $is_bookmarked_stmt->execute([$_SESSION['user_id'], $event['id']]);
                        $is_bookmarked = $is_bookmarked_stmt->fetch();
                    ?>
                    <button type="submit" class="btn <?php echo $is_bookmarked ? 'btn-success' : 'btn-outline-secondary'; ?>">
                        <i class="bi <?php echo $is_bookmarked ? 'bi-bookmark-fill' : 'bi-bookmark'; ?>"></i> <?php echo $is_bookmarked ? t('item_unbookmarked') : t('item_bookmarked'); ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (isLoggedIn() && (isAdmin() || isUlama() || (isset($_SESSION['user_id']) && $event['user_id'] == $_SESSION['user_id']))): ?>
                <a href="index.php?page=edit_event&id=<?php echo $event['id']; ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> <?php echo t('edit'); ?></a>
            <?php endif; ?>
            <?php if (isLoggedIn() && (isAdmin() || isUlama())): ?>
                <?php if ($event['status'] === 'pending'): ?>
                    <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="action" value="approve_item">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="event">
                        <input type="hidden" name="item_id" value="<?php echo $event['id']; ?>">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> <?php echo t('approve'); ?></button>
                    </form>
                    <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="action" value="reject_item">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="event">
                        <input type="hidden" name="item_id" value="<?php echo $event['id']; ?>">
                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> <?php echo t('reject'); ?></button>
                    </form>
                <?php endif; ?>
                 <form method="POST" action="index.php" class="d-inline confirm-delete" data-confirm-message="<?php echo t('confirm_delete_event'); ?>">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="item_type" value="event">
                    <input type="hidden" name="item_id" value="<?php echo $event['id']; ?>">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> <?php echo t('delete'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5><?php echo t('linked_content'); ?></h5>
        </div>
        <div class="card-body">
            <?php if (isAdmin() || isUlama()): ?>
                <form method="POST" action="index.php" class="mb-3 row g-3 align-items-center">
                    <input type="hidden" name="action" value="link_content_to_event">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="event_id_for_link" value="<?php echo $event['id']; ?>">
                    <div class="col-md-4">
                        <select name="linked_item_type" class="form-select" required>
                            <option value="ayah"><?php echo t('ayah'); ?></option>
                            <option value="hadith"><?php echo t('hadith'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-6">
                         <input type="number" name="linked_item_id" class="form-control" placeholder="<?php echo t('select_item'); ?> ID" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><?php echo t('link_item'); ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!empty($linked_items)): ?>
                <ul class="list-group">
                    <?php foreach ($linked_items as $item): ?>
                        <li class="list-group-item">
                            <?php if ($item['linked_item_type'] === 'ayah'): ?>
                                <strong><?php echo t('ayah'); ?>:</strong>
                                <span class="arabic-text"><?php echo sanitize_input($item['arabic_text']); ?></span><br>
                                <span class="urdu-text"><?php echo sanitize_input($item['urdu_translation']); ?></span><br>
                                <small>(<?php echo t('surah'); ?> <?php echo $item['surah_number']; ?>, <?php echo t('ayah'); ?> <?php echo $item['ayah_number']; ?>)</small>
                                <a href="index.php?page=quran_search&query=<?php echo $item['surah_number'].':'.$item['ayah_number']; ?>" class="btn btn-sm btn-outline-info float-end ms-2"><i class="bi bi-eye"></i></a>
                            <?php elseif ($item['linked_item_type'] === 'hadith'): ?>
                                <strong><?php echo t('hadith'); ?>:</strong>
                                <span class="<?php echo ($current_lang == 'ur' && !empty($item['hadith_text_ur'])) ? 'urdu-text' : ''; ?>">
                                    <?php echo substr(sanitize_input(t_dynamic($item['hadith_text_en'], $item['hadith_text_ur'])), 0, 150); ?>...
                                </span><br>
                                <small>(<?php echo sanitize_input(t_dynamic($item['hadith_source_en'], $item['hadith_source_ur'])); ?>)</small>
                                <a href="index.php?page=view_hadith&id=<?php echo $item['linked_item_id']; ?>" class="btn btn-sm btn-outline-info float-end ms-2"><i class="bi bi-eye"></i></a>
                            <?php endif; ?>
                            <?php if (isAdmin() || isUlama()): ?>
                                <form method="POST" action="index.php" class="d-inline float-end confirm-delete" data-confirm-message="<?php echo t('confirm_unlink_content'); ?>">
                                    <input type="hidden" name="action" value="unlink_content_from_event">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="link_id" value="<?php echo $item['link_id']; ?>">
                                    <input type="hidden" name="event_id_redirect" value="<?php echo $event['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p><?php echo t('no_linked_content'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($event['latitude'] && $event['longitude']): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var map = L.map('eventMap').setView([<?php echo $event['latitude']; ?>, <?php echo $event['longitude']; ?>], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            L.marker([<?php echo $event['latitude']; ?>, <?php echo $event['longitude']; ?>]).addTo(map)
                .bindPopup('<?php echo addslashes(sanitize_input(t_dynamic($event['title_en'], $event['title_ur']))); ?>')
                .openPopup();
        });
    </script>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_add_edit_event_page($event_data = null) {
    requireLogin();
    $is_edit = ($event_data !== null);
    render_header($is_edit ? t('edit_event') : t('add_event'));
    ?>
    <h1><?php echo $is_edit ? t('edit_event') : t('add_event'); ?></h1>
    <form method="POST" action="index.php">
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'edit_event' : 'add_event'; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <?php if ($is_edit): ?>
            <input type="hidden" name="event_id" value="<?php echo $event_data['id']; ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="title_en" class="form-label"><?php echo t('title'); ?> (English) <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title_en" name="title_en" value="<?php echo $is_edit ? sanitize_input($event_data['title_en']) : ''; ?>" required>
        </div>
        <div class="mb-3">
            <label for="title_ur" class="form-label urdu-text"><?php echo t('title'); ?> (اردو) <span class="text-danger">*</span></label>
            <input type="text" class="form-control urdu-text" id="title_ur" name="title_ur" value="<?php echo $is_edit ? sanitize_input($event_data['title_ur']) : ''; ?>" required dir="rtl">
        </div>
        <div class="mb-3">
            <label for="description_en" class="form-label"><?php echo t('description'); ?> (English)</label>
            <textarea class="form-control" id="description_en" name="description_en" rows="5"><?php echo $is_edit ? sanitize_input($event_data['description_en']) : ''; ?></textarea>
        </div>
        <div class="mb-3">
            <label for="description_ur" class="form-label urdu-text"><?php echo t('description'); ?> (اردو)</label>
            <textarea class="form-control urdu-text" id="description_ur" name="description_ur" rows="5" dir="rtl"><?php echo $is_edit ? sanitize_input($event_data['description_ur']) : ''; ?></textarea>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="event_date" class="form-label"><?php echo t('date'); ?> <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="event_date" name="event_date" value="<?php echo $is_edit ? sanitize_input($event_data['event_date']) : ''; ?>" required>
                <div class="form-text"><?php echo t('event_date_tip'); ?></div>
            </div>
            <div class="col-md-6 mb-3">
                <label for="category" class="form-label"><?php echo t('category'); ?> <span class="text-danger">*</span></label>
                <select class="form-select" id="category" name="category" required>
                    <option value="islamic" <?php echo ($is_edit && $event_data['category'] === 'islamic') ? 'selected' : ''; ?>><?php echo t('islamic'); ?></option>
                    <option value="general" <?php echo ($is_edit && $event_data['category'] === 'general') ? 'selected' : ''; ?>><?php echo t('general'); ?></option>
                </select>
            </div>
        </div>
        <fieldset class="mb-3 p-3 border rounded">
            <legend class="w-auto px-2 h6"><?php echo t('location'); ?></legend>
            <div class="row">
                <div class="col-md-5 mb-3">
                    <label for="latitude" class="form-label"><?php echo t('latitude'); ?></label>
                    <input type="text" class="form-control" id="latitude" name="latitude" value="<?php echo $is_edit ? sanitize_input($event_data['latitude']) : ''; ?>" placeholder="<?php echo t('event_latitude_tip'); ?>">
                </div>
                <div class="col-md-5 mb-3">
                    <label for="longitude" class="form-label"><?php echo t('longitude'); ?></label>
                    <input type="text" class="form-control" id="longitude" name="longitude" value="<?php echo $is_edit ? sanitize_input($event_data['longitude']) : ''; ?>" placeholder="<?php echo t('event_longitude_tip'); ?>">
                </div>
                <div class="col-md-2 mb-3 align-self-end">
                    <button type="button" id="getCurrentLocationBtnOnForm" class="btn btn-secondary w-100"><?php echo t('get_current_location'); ?></button>
                </div>
            </div>
             <div id="miniMap" style="height: 200px; width: 100%;" class="mb-2"></div>
             <small class="form-text text-muted">Click on map to set coordinates, or enter manually. Use "Get Current Location" to center map on your position.</small>
        </fieldset>
        
        <button type="submit" class="btn btn-primary"><?php echo t('submit'); ?></button>
        <a href="index.php?page=events" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></a>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var miniMap = L.map('miniMap').setView([21.4225, 39.8262], 2); // Default view (Mecca)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(miniMap);

        var marker = null;
        var latInput = document.getElementById('latitude');
        var lonInput = document.getElementById('longitude');

        function updateMarkerAndZoom(lat, lng, zoomLevel = 13) {
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng]).addTo(miniMap);
            }
            miniMap.setView([lat, lng], zoomLevel);
        }

        // If editing and coords exist, place marker and zoom
        <?php if ($is_edit && !empty($event_data['latitude']) && !empty($event_data['longitude'])): ?>
        var initialLat = <?php echo floatval($event_data['latitude']); ?>;
        var initialLon = <?php echo floatval($event_data['longitude']); ?>;
        if (!isNaN(initialLat) && !isNaN(initialLon)) {
            updateMarkerAndZoom(initialLat, initialLon);
        }
        <?php endif; ?>

        miniMap.on('click', function(e) {
            latInput.value = e.latlng.lat.toFixed(6);
            lonInput.value = e.latlng.lng.toFixed(6);
            updateMarkerAndZoom(e.latlng.lat, e.latlng.lng); // Update marker and zoom on map click
        });

        function handleInputChange() {
            var lat = parseFloat(latInput.value);
            var lon = parseFloat(lonInput.value);
            if (!isNaN(lat) && !isNaN(lon)) {
                updateMarkerAndZoom(lat, lon);
            }
        }

        latInput.addEventListener('change', handleInputChange);
        lonInput.addEventListener('change', handleInputChange);

        // Geolocation for the "Get Current Location" button on this specific form
        const geoBtnOnForm = document.getElementById('getCurrentLocationBtnOnForm'); // Changed ID
        if (geoBtnOnForm) {
            geoBtnOnForm.addEventListener('click', function() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        var currentLat = position.coords.latitude;
                        var currentLon = position.coords.longitude;
                        latInput.value = currentLat.toFixed(6);
                        lonInput.value = currentLon.toFixed(6);
                        updateMarkerAndZoom(currentLat, currentLon, 15); // Update map and zoom to a closer level
                    }, function(error) {
                        alert('Error getting location: ' + error.message);
                    });
                } else {
                    alert('Geolocation is not supported by your browser.');
                }
            });
        }
    });
    </script>
    <?php
    render_footer();
}

function render_quran_search_page() {
    render_header(t('search_quran'));
    $db = getDB();
    $search_query = isset($_GET['query']) ? sanitize_input($_GET['query']) : '';
    $results = [];
    $total_results = 0;
    $current_page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $offset = ($current_page_num - 1) * ITEMS_PER_PAGE;

    if (!empty($search_query)) {
        $params = [];
        $sql_conditions = [];

        // Check for Surah:Ayah format e.g., 1:1 or 114:2
        if (preg_match('/^(\d{1,3}):(\d{1,3})$/', $search_query, $matches)) {
            $sql_conditions[] = "(surah_number = ? AND ayah_number = ?)";
            $params[] = (int)$matches[1];
            $params[] = (int)$matches[2];
        } 
        // Check for Surah number only e.g., s1 or s114
        elseif (preg_match('/^s(\d{1,3})$/i', $search_query, $matches)) {
            $sql_conditions[] = "surah_number = ?";
            $params[] = (int)$matches[1];
        }
        // General keyword search
        else {
            $sql_conditions[] = "(arabic_text LIKE ? OR urdu_translation LIKE ?)";
            $params[] = '%' . $search_query . '%';
            $params[] = '%' . $search_query . '%';
            // Could also search Surah names if we had a surah names table
        }
        
        $where_sql = implode(" OR ", $sql_conditions);

        $stmt_count = $db->prepare("SELECT COUNT(*) FROM ayahs WHERE $where_sql");
        $stmt_count->execute($params);
        $total_results = $stmt_count->fetchColumn();

        $stmt_results = $db->prepare("SELECT * FROM ayahs WHERE $where_sql ORDER BY surah_number, ayah_number LIMIT ? OFFSET ?");
        $all_params = array_merge($params, [ITEMS_PER_PAGE, $offset]);
        
        foreach ($all_params as $key => $value) {
            $stmt_results->bindValue($key + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt_results->execute();
        $results = $stmt_results->fetchAll();
    }
    ?>
    <h1><?php echo t('search_quran'); ?></h1>
    <form method="GET" action="index.php" class="mb-4">
        <input type="hidden" name="page" value="quran_search">
        <div class="input-group">
            <input type="text" name="query" class="form-control" placeholder="<?php echo t('search_placeholder_quran'); ?>" value="<?php echo $search_query; ?>">
            <button class="btn btn-primary" type="submit"><?php echo t('search'); ?></button>
        </div>
    </form>

    <?php if (!empty($search_query) && $total_results > 0): ?>
        <p><?php echo t('search_results_for', $search_query); ?> (<?php echo $total_results; ?> <?php echo t('results_found', 'results found'); ?>)</p>
        <div class="list-group">
            <?php foreach ($results as $ayah): ?>
                <div class="list-group-item">
                    <p class="arabic-text"><?php echo sanitize_input($ayah['arabic_text']); ?></p>
                    <p class="urdu-text"><?php echo sanitize_input($ayah['urdu_translation']); ?></p>
                    <small class="text-muted"><?php echo t('surah'); ?> <?php echo $ayah['surah_number']; ?>, <?php echo t('ayah'); ?> <?php echo $ayah['ayah_number']; ?></small>
                     <?php if (isLoggedIn()): ?>
                    <form method="POST" action="index.php" class="d-inline float-end">
                        <input type="hidden" name="action" value="toggle_bookmark">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="ayah">
                        <input type="hidden" name="item_id" value="<?php echo $ayah['id']; ?>">
                        <?php
                            $is_bookmarked_stmt = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND item_type = 'ayah' AND item_id = ?");
                            $is_bookmarked_stmt->execute([$_SESSION['user_id'], $ayah['id']]);
                            $is_bookmarked = $is_bookmarked_stmt->fetch();
                        ?>
                        <button type="submit" class="btn btn-sm <?php echo $is_bookmarked ? 'btn-success' : 'btn-outline-secondary'; ?>" title="<?php echo $is_bookmarked ? t('item_unbookmarked') : t('item_bookmarked'); ?>">
                            <i class="bi <?php echo $is_bookmarked ? 'bi-bookmark-fill' : 'bi-bookmark'; ?>"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php echo getPagination($total_results, $current_page_num, 'index.php?page=quran_search&query=' . urlencode($search_query)); ?>
    <?php elseif (!empty($search_query)): ?>
        <p><?php echo t('no_items_found'); ?></p>
    <?php else: ?>
        <p><?php echo t('data_AM_note'); ?></p>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_hadith_page() {
    render_header(t('hadith'));
    $db = getDB();
    $current_page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $offset = ($current_page_num - 1) * ITEMS_PER_PAGE;

    $where_clauses = ["status = 'approved'"];
    $params = []; // For WHERE clause parameters
    
    $search_term = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
    if (!empty($search_term)) {
        $where_clauses[] = "(text_en LIKE ? OR text_ur LIKE ? OR source_en LIKE ? OR source_ur LIKE ? OR narrator_en LIKE ? OR narrator_ur LIKE ?)";
        for ($i=0; $i<6; $i++) $params[] = '%' . $search_term . '%';
    }
    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $stmt_count = $db->prepare("SELECT COUNT(*) FROM hadiths $where_sql");
    $stmt_count->execute($params); // Execute with WHERE params
    $total_items = $stmt_count->fetchColumn();

    // Prepare the main query
    $stmt_hadiths = $db->prepare("SELECT * FROM hadiths $where_sql ORDER BY id DESC LIMIT :limit OFFSET :offset");

    // Bind WHERE clause parameters first (if any) using bindValue
    foreach ($params as $key => $value) {
        $stmt_hadiths->bindValue($key + 1, $value); // Parameters are 1-indexed
    }
    
    // Bind LIMIT and OFFSET parameters using bindValue and named placeholders
    $limit_val = ITEMS_PER_PAGE;
    $offset_val = $offset;

    $stmt_hadiths->bindValue(':limit', $limit_val, PDO::PARAM_INT);
    $stmt_hadiths->bindValue(':offset', $offset_val, PDO::PARAM_INT);
    
    $stmt_hadiths->execute();
    $hadiths = $stmt_hadiths->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><?php echo t('hadith'); ?></h1>
        <?php if (isLoggedIn()): ?>
        <a href="index.php?page=add_hadith" class="btn btn-primary"><?php echo t('add_hadith'); ?></a>
        <?php endif; ?>
    </div>

    <form method="GET" action="index.php" class="mb-3">
        <input type="hidden" name="page" value="hadith">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_placeholder_hadith'); ?>" value="<?php echo htmlspecialchars($search_term); ?>">
            <button class="btn btn-outline-secondary" type="submit"><?php echo t('search'); ?></button>
        </div>
    </form>

    <?php if (!empty($hadiths)): ?>
        <div class="list-group">
            <?php foreach ($hadiths as $hadith): ?>
                <div class="list-group-item">
                    <a href="index.php?page=view_hadith&id=<?php echo $hadith['id']; ?>" class="text-decoration-none text-body">
                        <p class="<?php echo ($current_lang == 'ur' && !empty($hadith['text_ur'])) ? 'urdu-text' : ''; ?>">
                            <?php echo sanitize_input(t_dynamic($hadith['text_en'], $hadith['text_ur'])); ?>
                        </p>
                    </a>
                    <small class="text-muted">
                        <?php echo t('source'); ?>: <?php echo sanitize_input(t_dynamic($hadith['source_en'], $hadith['source_ur'])); ?> | 
                        <?php echo t('narrator'); ?>: <?php echo sanitize_input(t_dynamic($hadith['narrator_en'], $hadith['narrator_ur'])); ?>
                    </small>
                     <?php if (isLoggedIn()): ?>
                    <form method="POST" action="index.php" class="d-inline float-end">
                        <input type="hidden" name="action" value="toggle_bookmark">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="hadith">
                        <input type="hidden" name="item_id" value="<?php echo $hadith['id']; ?>">
                        <?php
                            $is_bookmarked_stmt = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND item_type = 'hadith' AND item_id = ?");
                            $is_bookmarked_stmt->execute([$_SESSION['user_id'], $hadith['id']]);
                            $is_bookmarked = $is_bookmarked_stmt->fetch();
                        ?>
                        <button type="submit" class="btn btn-sm <?php echo $is_bookmarked ? 'btn-success' : 'btn-outline-secondary'; ?>" title="<?php echo $is_bookmarked ? t('item_unbookmarked') : t('item_bookmarked'); ?>">
                            <i class="bi <?php echo $is_bookmarked ? 'bi-bookmark-fill' : 'bi-bookmark'; ?>"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if (isAdmin() || isUlama()): ?>
                        <a href="index.php?page=edit_hadith&id=<?php echo $hadith['id']; ?>" class="btn btn-sm btn-warning float-end me-2"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php echo getPagination($total_items, $current_page_num, 'index.php?page=hadith' . ($search_term ? '&search='.urlencode($search_term) : '')); ?>
    <?php else: ?>
        <p><?php echo t('no_items_found'); ?></p>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_view_hadith_page() {
    $hadith_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$hadith_id) { render_not_found_page(); return; }

    $db = getDB();
    $stmt = $db->prepare("SELECT h.*, u.username as submitter_username, a.username as approver_username 
                          FROM hadiths h
                          JOIN users u ON h.user_id = u.id
                          LEFT JOIN users a ON h.approved_by = a.id
                          WHERE h.id = ? AND (h.status = 'approved' OR ? OR ?)");
    $stmt->execute([$hadith_id, (isLoggedIn() && isAdmin()), (isLoggedIn() && isUlama())]);
    $hadith = $stmt->fetch();

    if (!$hadith) { render_not_found_page(); return; }

    if ($hadith['status'] === 'pending' && !(isLoggedIn() && ($hadith['user_id'] == $_SESSION['user_id'] || isAdmin() || isUlama()))) {
        addFlashMessage(t('access_denied'), 'danger');
        redirect('index.php?page=hadith');
    }

    render_header(t('hadith_details'));
    ?>
    <div class="card">
        <div class="card-header">
            <h1 class="card-title"><?php echo t('hadith_details'); ?></h1>
             <?php if ($hadith['status'] !== 'approved'): ?>
                <span class="badge bg-<?php echo $hadith['status'] === 'pending' ? 'warning' : 'danger'; ?> text-dark">
                    <?php echo t($hadith['status']); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <h4><?php echo t('hadith_text'); ?> (English)</h4>
            <p><?php echo nl2br(sanitize_input($hadith['text_en'])); ?></p>
            <h4 class="urdu-text"><?php echo t('hadith_text'); ?> (اردو)</h4>
            <p class="urdu-text"><?php echo nl2br(sanitize_input($hadith['text_ur'])); ?></p>
            <hr>
            <p><strong><?php echo t('source'); ?>:</strong> <?php echo sanitize_input(t_dynamic($hadith['source_en'], $hadith['source_ur'])); ?></p>
            <p><strong><?php echo t('narrator'); ?>:</strong> <?php echo sanitize_input(t_dynamic($hadith['narrator_en'], $hadith['narrator_ur'])); ?></p>
            <hr>
            <p><small><?php echo t('submitted_by'); ?>: <a href="index.php?page=user_profile&id=<?php echo $hadith['user_id']; ?>"><?php echo sanitize_input($hadith['submitter_username']); ?></a> <?php echo t('on'); ?> <?php echo format_date_for_display($hadith['created_at']); ?></small></p>
            <?php if ($hadith['status'] === 'approved' && $hadith['approved_by']): ?>
                <p><small><?php echo t('approved_by'); ?>: <a href="index.php?page=user_profile&id=<?php echo $hadith['approved_by']; ?>"><?php echo sanitize_input($hadith['approver_username'] ?: t('unknown_user')); ?></a> <?php echo t('on'); ?> <?php echo format_date_for_display($hadith['approved_at']); ?></small></p>
            <?php endif; ?>
        </div>
        <div class="card-footer">
             <?php if (isLoggedIn()): ?>
                <form method="POST" action="index.php" class="d-inline">
                    <input type="hidden" name="action" value="toggle_bookmark">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="item_type" value="hadith">
                    <input type="hidden" name="item_id" value="<?php echo $hadith['id']; ?>">
                    <?php
                        $is_bookmarked_stmt = $db->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND item_type = 'hadith' AND item_id = ?");
                        $is_bookmarked_stmt->execute([$_SESSION['user_id'], $hadith['id']]);
                        $is_bookmarked = $is_bookmarked_stmt->fetch();
                    ?>
                    <button type="submit" class="btn <?php echo $is_bookmarked ? 'btn-success' : 'btn-outline-secondary'; ?>">
                        <i class="bi <?php echo $is_bookmarked ? 'bi-bookmark-fill' : 'bi-bookmark'; ?>"></i> <?php echo $is_bookmarked ? t('item_unbookmarked') : t('item_bookmarked'); ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (isLoggedIn() && (isAdmin() || isUlama() || (isset($_SESSION['user_id']) && $hadith['user_id'] == $_SESSION['user_id']))): ?>
                <a href="index.php?page=edit_hadith&id=<?php echo $hadith['id']; ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> <?php echo t('edit'); ?></a>
            <?php endif; ?>
            <?php if (isLoggedIn() && (isAdmin() || isUlama())): ?>
                <?php if ($hadith['status'] === 'pending'): ?>
                    <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="action" value="approve_item">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="hadith">
                        <input type="hidden" name="item_id" value="<?php echo $hadith['id']; ?>">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> <?php echo t('approve'); ?></button>
                    </form>
                    <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="action" value="reject_item">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="hadith">
                        <input type="hidden" name="item_id" value="<?php echo $hadith['id']; ?>">
                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> <?php echo t('reject'); ?></button>
                    </form>
                <?php endif; ?>
                 <form method="POST" action="index.php" class="d-inline confirm-delete" data-confirm-message="<?php echo t('confirm_delete_hadith'); ?>">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="item_type" value="hadith">
                    <input type="hidden" name="item_id" value="<?php echo $hadith['id']; ?>">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> <?php echo t('delete'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    render_footer();
}

function render_add_edit_hadith_page($hadith_data = null) {
    requireLogin(); // At least user role to suggest
    $is_edit = ($hadith_data !== null);
    render_header($is_edit ? t('edit_hadith') : t('add_hadith'));
    ?>
    <h1><?php echo $is_edit ? t('edit_hadith') : t('add_hadith'); ?></h1>
    <form method="POST" action="index.php">
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'edit_hadith' : 'add_hadith'; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <?php if ($is_edit): ?>
            <input type="hidden" name="hadith_id" value="<?php echo $hadith_data['id']; ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="text_en" class="form-label"><?php echo t('hadith_text'); ?> (English) <span class="text-danger">*</span></label>
            <textarea class="form-control" id="text_en" name="text_en" rows="5" required><?php echo $is_edit ? sanitize_input($hadith_data['text_en']) : ''; ?></textarea>
        </div>
        <div class="mb-3">
            <label for="text_ur" class="form-label urdu-text"><?php echo t('hadith_text'); ?> (اردو) <span class="text-danger">*</span></label>
            <textarea class="form-control urdu-text" id="text_ur" name="text_ur" rows="5" required dir="rtl"><?php echo $is_edit ? sanitize_input($hadith_data['text_ur']) : ''; ?></textarea>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="source_en" class="form-label"><?php echo t('source'); ?> (English)</label>
                <input type="text" class="form-control" id="source_en" name="source_en" value="<?php echo $is_edit ? sanitize_input($hadith_data['source_en']) : ''; ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="source_ur" class="form-label urdu-text"><?php echo t('source'); ?> (اردو)</label>
                <input type="text" class="form-control urdu-text" id="source_ur" name="source_ur" value="<?php echo $is_edit ? sanitize_input($hadith_data['source_ur']) : ''; ?>" dir="rtl">
            </div>
        </div>
         <div class="row">
            <div class="col-md-6 mb-3">
                <label for="narrator_en" class="form-label"><?php echo t('narrator'); ?> (English)</label>
                <input type="text" class="form-control" id="narrator_en" name="narrator_en" value="<?php echo $is_edit ? sanitize_input($hadith_data['narrator_en']) : ''; ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="narrator_ur" class="form-label urdu-text"><?php echo t('narrator'); ?> (اردو)</label>
                <input type="text" class="form-control urdu-text" id="narrator_ur" name="narrator_ur" value="<?php echo $is_edit ? sanitize_input($hadith_data['narrator_ur']) : ''; ?>" dir="rtl">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary"><?php echo t('submit'); ?></button>
        <a href="index.php?page=hadith" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></a>
    </form>
    <?php
    render_footer();
}

function render_bookmarks_page() {
    requireLogin();
    render_header(t('bookmarks'));
    $db = getDB();
    $user_id = $_SESSION['user_id'];

    $stmt = $db->prepare("SELECT b.id as bookmark_id, b.item_type, b.item_id, b.created_at,
                                e.title_en as event_title_en, e.title_ur as event_title_ur,
                                a.arabic_text as ayah_arabic, a.surah_number, a.ayah_number,
                                h.text_en as hadith_text_en, h.text_ur as hadith_text_ur
                          FROM bookmarks b
                          LEFT JOIN events e ON b.item_type = 'event' AND b.item_id = e.id
                          LEFT JOIN ayahs a ON b.item_type = 'ayah' AND b.item_id = a.id
                          LEFT JOIN hadiths h ON b.item_type = 'hadith' AND b.item_id = h.id
                          WHERE b.user_id = ? ORDER BY b.created_at DESC");
    $stmt->execute([$user_id]);
    $bookmarks = $stmt->fetchAll();
    ?>
    <h1><?php echo t('bookmarks'); ?></h1>
    <?php if (!empty($bookmarks)): ?>
        <div class="list-group">
            <?php foreach ($bookmarks as $bookmark): ?>
                <div class="list-group-item">
                    <?php
                    $title = ''; $link = '#'; $type_display = '';
                    if ($bookmark['item_type'] === 'event' && $bookmark['event_title_en']) {
                        $title = sanitize_input(t_dynamic($bookmark['event_title_en'], $bookmark['event_title_ur']));
                        $link = 'index.php?page=view_event&id=' . $bookmark['item_id'];
                        $type_display = t('event');
                    } elseif ($bookmark['item_type'] === 'ayah' && $bookmark['ayah_arabic']) {
                        $title = t('surah') . ' ' . $bookmark['surah_number'] . ', ' . t('ayah') . ' ' . $bookmark['ayah_number'] . ': ' . substr(sanitize_input($bookmark['ayah_arabic']), 0, 100) . '...';
                        $link = 'index.php?page=quran_search&query=' . $bookmark['surah_number'] . ':' . $bookmark['ayah_number'];
                        $type_display = t('ayah');
                    } elseif ($bookmark['item_type'] === 'hadith' && $bookmark['hadith_text_en']) {
                        $title = substr(sanitize_input(t_dynamic($bookmark['hadith_text_en'], $bookmark['hadith_text_ur'])), 0, 100) . '...';
                        $link = 'index.php?page=view_hadith&id=' . $bookmark['item_id'];
                        $type_display = t('hadith');
                    } else {
                        $title = t('item_not_found'); // Item might have been deleted
                        $type_display = t($bookmark['item_type']);
                    }
                    ?>
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1"><a href="<?php echo $link; ?>"><?php echo $title; ?></a></h5>
                        <small><?php echo format_date_for_display($bookmark['created_at']); ?></small>
                    </div>
                    <p class="mb-1"><span class="badge bg-info"><?php echo $type_display; ?></span></p>
                    <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="action" value="toggle_bookmark">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="<?php echo $bookmark['item_type']; ?>">
                        <input type="hidden" name="item_id" value="<?php echo $bookmark['item_id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-bookmark-x-fill"></i> <?php echo t('item_unbookmarked'); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><?php echo t('no_bookmarks'); ?></p>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_profile_page() {
    requireLogin();
    global $current_user; // Use the globally available current user
    $user_id_to_view = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
    
    $db = getDB();
    if ($user_id_to_view == $_SESSION['user_id']) {
        $profile_user = $current_user;
    } else {
        $stmt_profile = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt_profile->execute([$user_id_to_view]);
        $profile_user = $stmt_profile->fetch();
    }

    if (!$profile_user) {
        addFlashMessage(t('user_not_found', 'User not found.'), 'danger');
        redirect('index.php');
    }

    render_header(t('user_profile_for', sanitize_input($profile_user['username'])));

    // Fetch user's badges
    $stmt_badges = $db->prepare("SELECT b.name_en, b.name_ur, b.description_en, b.description_ur, b.icon_class, ub.awarded_at 
                                 FROM user_badges ub 
                                 JOIN badges b ON ub.badge_id = b.id 
                                 WHERE ub.user_id = ? ORDER BY ub.awarded_at DESC");
    $stmt_badges->execute([$profile_user['id']]);
    $user_badges = $stmt_badges->fetchAll();

    // Fetch user's contributions (approved events and hadiths)
    $stmt_events = $db->prepare("SELECT id, title_en, title_ur, event_date FROM events WHERE user_id = ? AND status = 'approved' ORDER BY event_date DESC LIMIT 5");
    $stmt_events->execute([$profile_user['id']]);
    $user_events = $stmt_events->fetchAll();

    $stmt_hadiths = $db->prepare("SELECT id, text_en, text_ur, source_en FROM hadiths WHERE user_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 5");
    $stmt_hadiths->execute([$profile_user['id']]);
    $user_hadiths = $stmt_hadiths->fetchAll();

    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-person-circle display-1"></i>
                    <h3 class="card-title mt-2"><?php echo sanitize_input($profile_user['username']); ?></h3>
                    <p class="text-muted"><?php echo t(ucfirst($profile_user['role'])); ?></p>
                    <p><strong><?php echo t('email'); ?>:</strong> <?php echo sanitize_input($profile_user['email']); ?></p>
                    <p><strong><?php echo t('points'); ?>:</strong> <?php echo $profile_user['points']; ?></p>
                    <p><strong><?php echo t('joined_on'); ?>:</strong> <?php echo format_date_for_display($profile_user['created_at']); ?></p>
                    <?php if ($profile_user['id'] == $_SESSION['user_id']): ?>
                        <!-- <a href="index.php?page=edit_profile" class="btn btn-primary"><?php echo t('edit_profile'); ?></a> -->
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header"><h4><?php echo t('my_achievements'); ?></h4></div>
                <div class="card-body">
                    <?php if (!empty($user_badges)): ?>
                        <ul class="list-group list-group-flush">
                        <?php foreach ($user_badges as $badge): ?>
                            <li class="list-group-item">
                                <i class="bi <?php echo sanitize_input($badge['icon_class'] ?: 'bi-award'); ?> fs-4 me-2"></i>
                                <strong><?php echo sanitize_input(t_dynamic($badge['name_en'], $badge['name_ur'])); ?></strong><br>
                                <small class="text-muted"><?php echo sanitize_input(t_dynamic($badge['description_en'], $badge['description_ur'])); ?></small><br>
                                <small><?php echo t('awarded_on'); ?>: <?php echo format_date_for_display($badge['awarded_at']); ?></small>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p><?php echo t('no_badges_earned'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h4><?php echo t('contributions'); ?></h4></div>
                <div class="card-body">
                    <h5><?php echo t('events_contributed'); ?></h5>
                    <?php if (!empty($user_events)): ?>
                        <ul class="list-group">
                        <?php foreach ($user_events as $event): ?>
                            <li class="list-group-item">
                                <a href="index.php?page=view_event&id=<?php echo $event['id']; ?>">
                                    <?php echo sanitize_input(t_dynamic($event['title_en'], $event['title_ur'])); ?>
                                </a> (<?php echo format_date_for_display($event['event_date']); ?>)
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p><?php echo t('no_contributions'); ?></p>
                    <?php endif; ?>

                    <h5 class="mt-4"><?php echo t('hadiths_contributed'); ?></h5>
                    <?php if (!empty($user_hadiths)): ?>
                        <ul class="list-group">
                        <?php foreach ($user_hadiths as $hadith): ?>
                            <li class="list-group-item">
                                <a href="index.php?page=view_hadith&id=<?php echo $hadith['id']; ?>">
                                    <?php echo substr(sanitize_input(t_dynamic($hadith['text_en'], $hadith['text_ur'])), 0, 100); ?>...
                                </a>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p><?php echo t('no_contributions'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_footer();
}

function render_map_view_page() {
    render_header(t('map_view'));
    $db = getDB();
    // Fetch ALL approved events with coordinates for the map
    $stmt = $db->prepare("SELECT id, title_en, title_ur, latitude, longitude, category FROM events WHERE status = 'approved' AND latitude IS NOT NULL AND longitude IS NOT NULL");
    $stmt->execute();
    $events_for_map = $stmt->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><?php echo t('map_view'); ?></h1>
        <div>
            <button id="zoomToMyLocationBtn" class="btn btn-primary me-2"><i class="bi bi-geo-alt-fill"></i> <?php echo t('zoom_to_my_location', 'Zoom to My Location'); ?></button>
            <button id="toggleLiveTrackingBtn" class="btn btn-info"><i class="bi bi-broadcast"></i> <?php echo t('start_live_tracking', 'Start Live Tracking'); ?></button>
        </div>
    </div>
    <p><?php echo t('events_map_title'); ?></p>
    <div id="fullEventsMap" class="map-container mb-3"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var map = L.map('fullEventsMap').setView([25, 45], 3); // Centered broadly on Middle East
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            var eventMarkers = L.layerGroup().addTo(map);
            var eventsData = <?php echo json_encode($events_for_map); ?>;
            
            eventsData.forEach(function(event) {
                if (event.latitude && event.longitude) {
                    var titleKey = '<?php echo $current_lang == "ur" ? "title_ur" : "title_en"; ?>';
                    var displayTitle = event[titleKey] || event.title_en; // Fallback to English title
                    var marker = L.marker([event.latitude, event.longitude])
                        .bindPopup('<b><a href="index.php?page=view_event&id=' + event.id + '">' + displayTitle + '</a></b><br><?php echo t('category'); ?>: ' + event.category);
                    eventMarkers.addLayer(marker);
                }
            });

            var myLocationMarker = null;
            var watchId = null; // To store the ID of the geolocation watch
            var liveTrackingInterval = 1200; // milliseconds
            var isLiveTracking = false;
            const toggleLiveTrackingBtn = document.getElementById('toggleLiveTrackingBtn');
            const zoomToMyLocationBtn = document.getElementById('zoomToMyLocationBtn');

            function updateMyLocationMarker(lat, lng) {
                if (!myLocationMarker) {
                    myLocationMarker = L.circleMarker([lat, lng], {
                        radius: 8,
                        fillColor: "#007bff", // Blue
                        color: "#fff",
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.8
                    }).addTo(map).bindPopup("<?php echo t('your_current_location', 'Your Current Location'); ?>");
                } else {
                    myLocationMarker.setLatLng([lat, lng]);
                }
            }

            function zoomToLocation(lat, lng, zoomLevel = 15) {
                map.setView([lat, lng], zoomLevel);
            }
            
            if (zoomToMyLocationBtn) {
                zoomToMyLocationBtn.addEventListener('click', function() {
                    if (navigator.geolocation) {
                        navigator.geolocation.getCurrentPosition(function(position) {
                            var lat = position.coords.latitude;
                            var lng = position.coords.longitude;
                            updateMyLocationMarker(lat, lng);
                            zoomToLocation(lat, lng);
                            if (myLocationMarker) myLocationMarker.openPopup();
                        }, function(error) {
                            alert("<?php echo t('error_getting_location', 'Error getting location: '); ?>" + error.message);
                        }, { enableHighAccuracy: true });
                    } else {
                        alert("<?php echo t('geolocation_not_supported', 'Geolocation is not supported by your browser.'); ?>");
                    }
                });
            }

            function handlePositionUpdate(position) {
                var lat = position.coords.latitude;
                var lng = position.coords.longitude;
                updateMyLocationMarker(lat, lng);
                
                // Optional: If you want the map to continuously re-center on the user
                // Be careful with this, as it can be annoying if the user is trying to pan the map.
                // map.panTo([lat, lng]); 

                // --- Advanced: Re-filter events based on proximity ---
                // This is a conceptual outline. Full implementation requires more logic.
                // 1. Calculate distance from current location (lat, lng) to each event in eventsData.
                // 2. Clear existing eventMarkers.
                // 3. Add only events within a certain radius to eventMarkers.
                // console.log("Current location: ", lat, lng, " - Consider re-filtering events.");
            }

            function handlePositionError(error) {
                console.warn("Error getting live location: " + error.message);
                // Optionally, stop tracking if there's a persistent error
                if (error.code === error.PERMISSION_DENIED && isLiveTracking) {
                    stopLiveTracking();
                    alert("<?php echo t('location_permission_denied_tracking_stopped', 'Location permission denied. Live tracking stopped.'); ?>");
                }
            }

            function startLiveTracking() {
                if (navigator.geolocation) {
                    if (watchId) { // Clear previous watch if any
                        navigator.geolocation.clearWatch(watchId);
                    }
                    watchId = navigator.geolocation.watchPosition(
                        handlePositionUpdate, 
                        handlePositionError, 
                        { 
                            enableHighAccuracy: true, 
                            maximumAge: liveTrackingInterval, // Don't use a cached position older than this
                            timeout: liveTrackingInterval * 2 // Time to wait for a position
                        }
                    );
                    isLiveTracking = true;
                    toggleLiveTrackingBtn.innerHTML = '<i class="bi bi-stop-circle-fill"></i> <?php echo t('stop_live_tracking', 'Stop Live Tracking'); ?>';
                    toggleLiveTrackingBtn.classList.remove('btn-info');
                    toggleLiveTrackingBtn.classList.add('btn-danger');
                    map.locate({setView: true, maxZoom: 16, watch: true, enableHighAccuracy: true}); // Leaflet's own locate
                } else {
                    alert("<?php echo t('geolocation_not_supported_for_tracking', 'Geolocation is not supported for live tracking.'); ?>");
                }
            }

            function stopLiveTracking() {
                if (navigator.geolocation && watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                map.stopLocate(); // Stop Leaflet's locate
                isLiveTracking = false;
                toggleLiveTrackingBtn.innerHTML = '<i class="bi bi-broadcast"></i> <?php echo t('start_live_tracking', 'Start Live Tracking'); ?>';
                toggleLiveTrackingBtn.classList.remove('btn-danger');
                toggleLiveTrackingBtn.classList.add('btn-info');
                if (myLocationMarker) {
                    // Optionally remove the marker or just leave it at the last known position
                    // myLocationMarker.remove(); 
                    // myLocationMarker = null;
                }
            }
            
            if (toggleLiveTrackingBtn) {
                toggleLiveTrackingBtn.addEventListener('click', function() {
                    if (isLiveTracking) {
                        stopLiveTracking();
                    } else {
                        startLiveTracking();
                    }
                });
            }

            // Leaflet's built-in location events (can be used alongside or instead of watchPosition)
            map.on('locationfound', function(e) {
                updateMyLocationMarker(e.latitude, e.longitude);
                if (!isLiveTracking) { // Only set view if not actively tracking via button, to avoid conflict
                     map.setView(e.latlng, 16);
                }
            });
            map.on('locationerror', function(e) {
                alert(e.message);
            });

        });
    </script>
    <?php
    render_footer();
}

function render_timeline_page() {
    global $current_lang;
    render_header(t('event_timeline'));
    $db = getDB();

    $selected_century = isset($_GET['century']) ? (int)$_GET['century'] : null;
    
    $sql = "SELECT id, title_en, title_ur, description_en, description_ur, event_date, category FROM events WHERE status = 'approved'";
    $params = [];
    if ($selected_century) {
        $start_year = ($selected_century - 1) * 100;
        // For century definition, e.g., 7th century is 600-699.
        // If year is 600, it's 7th century. If 699, it's 7th. If 700, it's 8th.
        // So, for 7th century (selected_century = 7), start_year = 600, end_year = 699.
        $end_year = $selected_century * 100 -1; 
        $sql .= " AND strftime('%Y', event_date) >= ? AND strftime('%Y', event_date) <= ?";
        $params[] = str_pad($start_year, 4, '0', STR_PAD_LEFT); // e.g. '0600'
        $params[] = str_pad($end_year, 4, '0', STR_PAD_LEFT);   // e.g. '0699'
    }
    $sql .= " ORDER BY event_date ASC"; // Order events chronologically
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    $grouped_events_by_century = [];
    foreach ($events as $event) {
        $year = (int)date('Y', strtotime($event['event_date']));
        // Calculate century: Year 1-100 is 1st century, 101-200 is 2nd, etc.
        // Year 622 is 7th century.
        $century = floor(($year - 1) / 100) + 1;
        $grouped_events_by_century[$century][] = $event; // Group by century first
    }
    ksort($grouped_events_by_century); // Sort centuries

    // Get distinct centuries for filter
    $stmt_centuries = $db->query("SELECT DISTINCT (CAST(strftime('%Y', event_date) AS INTEGER) - 1) / 100 + 1 as century FROM events WHERE status = 'approved' AND strftime('%Y', event_date) > '0000' ORDER BY century ASC");
    $centuries_for_filter = $stmt_centuries->fetchAll(PDO::FETCH_COLUMN);

    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?php echo t('event_timeline'); ?></h1>
        <form method="GET" class="col-md-4">
            <input type="hidden" name="page" value="timeline">
            <div class="input-group">
                <select name="century" id="century_filter" class="form-select" onchange="this.form.submit()">
                    <option value=""><?php echo t('all_centuries'); ?></option>
                    <?php foreach ($centuries_for_filter as $c): if($c <= 0) continue; ?>
                        <option value="<?php echo $c; ?>" <?php echo ($selected_century == $c) ? 'selected' : ''; ?>>
                            <?php echo $c; ?><?php echo ($current_lang == 'en' ? (in_array($c % 100, [11,12,13]) ? 'th' : (['st','nd','rd'][($c % 10) - 1] ?? 'th')) : 'ویں'); ?> <?php echo t('century'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if (!empty($events)): ?>
        <div class="timeline-infographic">
            <?php 
            $item_counter = 0;
            foreach ($grouped_events_by_century as $century => $century_events): 
            ?>
                <div class="timeline-century-header">
                    <?php echo $century; ?><?php echo ($current_lang == 'en' ? (in_array($century % 100, [11,12,13]) ? 'th' : (['st','nd','rd'][($century % 10) - 1] ?? 'th')) : 'ویں'); ?> <?php echo t('century'); ?>
                </div>

                <?php foreach ($century_events as $event): 
                    $alignment_class = ($item_counter % 2 == 0) ? 'left' : 'right'; // This determines LTR alignment
                ?>
                    <div class="timeline-item-container <?php echo $alignment_class; ?>">
                        <div class="timeline-content">
                            <h5 class="card-title">
                                <a href="index.php?page=view_event&id=<?php echo $event['id']; ?>">
                                    <?php echo sanitize_input(t_dynamic($event['title_en'], $event['title_ur'])); ?>
                                </a>
                            </h5>
                            <h6 class="card-subtitle mb-2 text-muted">
                                <?php echo format_date_for_display($event['event_date']); ?>
                                <span class="badge bg-secondary ms-2"><?php echo t('event_category_'.$event['category']); ?></span>
                            </h6>
                            <p class="card-text small">
                                <?php 
                                $description = sanitize_input(t_dynamic($event['description_en'], $event['description_ur']));
                                echo mb_substr($description, 0, 150);
                                if (mb_strlen($description) > 150) echo "...";
                                ?>
                            </p>
                        </div>
                    </div>
                <?php 
                    $item_counter++;
                endforeach; 
            endforeach; 
            ?>
        </div>
    <?php else: ?>
        <p class="text-center mt-4"><?php echo t('no_items_found'); ?> <?php if($selected_century) echo t('for_the_selected_century', "for the selected century."); ?></p>
    <?php endif; ?>

    <?php
    render_footer();
}

// ============== ADMIN PANEL PAGES ==============
function render_admin_dashboard() {
    requireRole(['admin', 'ulama']);
    render_header(t('admin_panel'));
    $db = getDB();

    $stats = [
        'total_users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'total_events' => $db->query("SELECT COUNT(*) FROM events WHERE status='approved'")->fetchColumn(),
        'total_hadiths' => $db->query("SELECT COUNT(*) FROM hadiths WHERE status='approved'")->fetchColumn(),
        'pending_events' => $db->query("SELECT COUNT(*) FROM events WHERE status='pending'")->fetchColumn(),
        'pending_hadiths' => $db->query("SELECT COUNT(*) FROM hadiths WHERE status='pending'")->fetchColumn(),
    ];

    // Recent pending items
    $pending_events_list = $db->query("SELECT id, title_en, title_ur, user_id FROM events WHERE status='pending' ORDER BY created_at DESC LIMIT 5")->fetchAll();
    $pending_hadiths_list = $db->query("SELECT id, text_en, text_ur, user_id FROM hadiths WHERE status='pending' ORDER BY created_at DESC LIMIT 5")->fetchAll();
    ?>
    <h1><?php echo t('admin_panel'); ?> - <?php echo t('dashboard'); ?></h1>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary mb-3">
                <div class="card-header"><?php echo t('total_users'); ?></div>
                <div class="card-body"><h5 class="card-title"><?php echo $stats['total_users']; ?></h5></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success mb-3">
                <div class="card-header"><?php echo t('total_events'); ?></div>
                <div class="card-body"><h5 class="card-title"><?php echo $stats['total_events']; ?></h5></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info mb-3">
                <div class="card-header"><?php echo t('total_hadiths'); ?></div>
                <div class="card-body"><h5 class="card-title"><?php echo $stats['total_hadiths']; ?></h5></div>
            </div>
        </div>
         <div class="col-md-3">
            <div class="card text-dark bg-warning mb-3">
                <div class="card-header"><?php echo t('pending_approval'); ?></div>
                <div class="card-body"><h5 class="card-title"><?php echo $stats['pending_events'] + $stats['pending_hadiths']; ?></h5></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <h4><?php echo t('pending_events'); ?> (<?php echo $stats['pending_events']; ?>)</h4>
            <?php if (!empty($pending_events_list)): ?>
                <ul class="list-group">
                    <?php foreach ($pending_events_list as $item): 
                        $submitter = getUserById($item['user_id']);
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="index.php?page=view_event&id=<?php echo $item['id']; ?>"><?php echo sanitize_input(t_dynamic($item['title_en'], $item['title_ur'])); ?></a>
                        <small><?php echo t('by'); ?> <?php echo $submitter ? sanitize_input($submitter['username']) : t('unknown'); ?></small>
                        <span>
                            <a href="index.php?page=view_event&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                 <?php if($stats['pending_events'] > 5): ?> <a href="index.php?page=admin_content&type=events&status=pending" class="btn btn-link"><?php echo t('view_all'); ?></a> <?php endif; ?>
            <?php else: ?>
                <p><?php echo t('no_items_found'); ?></p>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <h4><?php echo t('pending_hadiths'); ?> (<?php echo $stats['pending_hadiths']; ?>)</h4>
            <?php if (!empty($pending_hadiths_list)): ?>
                 <ul class="list-group">
                    <?php foreach ($pending_hadiths_list as $item): 
                        $submitter = getUserById($item['user_id']);
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="index.php?page=view_hadith&id=<?php echo $item['id']; ?>"><?php echo substr(sanitize_input(t_dynamic($item['text_en'], $item['text_ur'])), 0, 50); ?>...</a>
                        <small><?php echo t('by'); ?> <?php echo $submitter ? sanitize_input($submitter['username']) : t('unknown'); ?></small>
                        <span>
                            <a href="index.php?page=view_hadith&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if($stats['pending_hadiths'] > 5): ?> <a href="index.php?page=admin_content&type=hadiths&status=pending" class="btn btn-link"><?php echo t('view_all'); ?></a> <?php endif; ?>
            <?php else: ?>
                <p><?php echo t('no_items_found'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <h4 class="mt-4"><?php echo t('stats_overview'); ?></h4>
    <div class="row">
        <div class="col-md-6"><canvas id="eventsCategoryChart"></canvas></div>
        <div class="col-md-6"><canvas id="userRolesChart"></canvas></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php
        // Data for charts
        $event_categories_data = $db->query("SELECT category, COUNT(*) as count FROM events WHERE status='approved' GROUP BY category")->fetchAll();
        $user_roles_data = $db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role")->fetchAll();
        ?>
        var eventsCtx = document.getElementById('eventsCategoryChart').getContext('2d');
        new Chart(eventsCtx, {
            type: 'pie',
            data: {
                labels: [<?php foreach($event_categories_data as $cat) { echo "'" . t('event_category_'.$cat['category']) . "',"; } ?>],
                datasets: [{
                    label: '<?php echo t('events_by_category'); ?>',
                    data: [<?php foreach($event_categories_data as $cat) { echo $cat['count'] . ","; } ?>],
                    backgroundColor: ['rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)', 'rgba(75, 192, 192, 0.7)'],
                }]
            },
            options: { responsive: true, plugins: { title: { display: true, text: '<?php echo t('events_by_category'); ?>' } } }
        });

        var usersCtx = document.getElementById('userRolesChart').getContext('2d');
        new Chart(usersCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php foreach($user_roles_data as $role) { echo "'" . t($role['role']) . "',"; } ?>],
                datasets: [{
                    label: '<?php echo t('user_roles_distribution'); ?>',
                    data: [<?php foreach($user_roles_data as $role) { echo $role['count'] . ","; } ?>],
                    backgroundColor: ['rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)'],
                }]
            },
            options: { responsive: true, plugins: { title: { display: true, text: '<?php echo t('user_roles_distribution'); ?>' } } }
        });
    });
    </script>

    <?php
    render_admin_nav_tabs('dashboard');
    render_footer();
}

function render_admin_users_page() {
    requireRole('admin');
    render_header(t('manage_users'));
    $db = getDB();

    $current_page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $offset = ($current_page_num - 1) * ITEMS_PER_PAGE;

    $stmt_count = $db->query("SELECT COUNT(*) FROM users");
    $total_users = $stmt_count->fetchColumn();
    
    $stmt_users = $db->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    
    // Bind LIMIT and OFFSET parameters using bindValue and named placeholders
    $limit_val = ITEMS_PER_PAGE;
    $offset_val = $offset;
    $stmt_users->bindValue(':limit', $limit_val, PDO::PARAM_INT);
    $stmt_users->bindValue(':offset', $offset_val, PDO::PARAM_INT);
    
    $stmt_users->execute();
    $users = $stmt_users->fetchAll();
    ?>
    <h1><?php echo t('manage_users'); ?></h1>
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>ID</th>
                <th><?php echo t('username'); ?></th>
                <th><?php echo t('email'); ?></th>
                <th><?php echo t('role'); ?></th>
                <th><?php echo t('points'); ?></th>
                <th><?php echo t('created_at'); ?></th>
                <th><?php echo t('actions'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?php echo $user['id']; ?></td>
                <td><a href="index.php?page=profile&id=<?php echo $user['id']; ?>"><?php echo sanitize_input($user['username']); ?></a></td>
                <td><?php echo sanitize_input($user['email']); ?></td>
                <td>
                    <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="action" value="update_user_role">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <select name="role" class="form-select form-select-sm" onchange="this.form.submit()" <?php echo ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin' && $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <=1) ? 'disabled' : ''; ?>>
                            <option value="user" <?php if ($user['role'] === 'user') echo 'selected'; ?>><?php echo t('user'); ?></option>
                            <option value="ulama" <?php if ($user['role'] === 'ulama') echo 'selected'; ?>><?php echo t('ulama'); ?></option>
                            <option value="admin" <?php if ($user['role'] === 'admin') echo 'selected'; ?>><?php echo t('admin'); ?></option>
                        </select>
                    </form>
                </td>
                <td><?php echo $user['points']; ?></td>
                <td><?php echo format_date_for_display($user['created_at']); ?></td>
                <td>
                    <?php if ($user['id'] != $_SESSION['user_id']): // Admin cannot delete themselves ?>
                    <form method="POST" action="index.php" class="d-inline confirm-delete" data-confirm-message="<?php echo t('confirm_delete_user'); ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="user_id_delete" value="<?php echo $user['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                    <a href="index.php?page=profile&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo getPagination($total_users, $current_page_num, 'index.php?page=admin_users'); ?>
    <?php
    render_admin_nav_tabs('users');
    render_footer();
}
function render_admin_content_page() {
    requireRole(['admin', 'ulama']);
    render_header(t('manage_content'));
    $db = getDB();

    $type = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'events'; // 'events' or 'hadiths'
    $status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : 'all'; // 'all', 'pending', 'approved', 'rejected'
    
    $current_page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $offset = ($current_page_num - 1) * ITEMS_PER_PAGE;

    $table_name = ($type === 'events') ? 'events' : 'hadiths';
    $title_field_en = ($type === 'events') ? 'title_en' : 'text_en';
    $title_field_ur = ($type === 'events') ? 'title_ur' : 'text_ur';
    $view_page = ($type === 'events') ? 'view_event' : 'view_hadith';

    $where_clauses = [];
    $params = []; // For WHERE clause parameters

    if ($status_filter !== 'all') {
        $where_clauses[] = "status = ?";
        $params[] = $status_filter;
    }
    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $stmt_count = $db->prepare("SELECT COUNT(*) FROM $table_name $where_sql");
    $stmt_count->execute($params); // Execute with WHERE params
    $total_items = $stmt_count->fetchColumn();

    // Prepare the main query
    $stmt_items = $db->prepare("SELECT i.*, u.username as submitter_username 
                                FROM $table_name i 
                                JOIN users u ON i.user_id = u.id 
                                $where_sql 
                                ORDER BY i.created_at DESC LIMIT :limit OFFSET :offset");
    
    // Bind WHERE clause parameters first (if any) using bindValue
    foreach ($params as $key => $value) {
        $stmt_items->bindValue($key + 1, $value); // Parameters are 1-indexed
    }
    
    // Bind LIMIT and OFFSET parameters using bindValue and named placeholders
    $limit_val = ITEMS_PER_PAGE;
    $offset_val = $offset;

    $stmt_items->bindValue(':limit', $limit_val, PDO::PARAM_INT);
    $stmt_items->bindValue(':offset', $offset_val, PDO::PARAM_INT);
    
    $stmt_items->execute();
    $items = $stmt_items->fetchAll();
    ?>
    <h1><?php echo t('manage_content'); ?>: <?php echo t(ucfirst($type)); ?></h1>

    <div class="mb-3">
        <a href="?page=admin_content&type=events" class="btn <?php echo ($type == 'events') ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo t('events'); ?></a>
        <a href="?page=admin_content&type=hadiths" class="btn <?php echo ($type == 'hadiths') ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo t('hadith'); ?></a>
    </div>
    <form method="GET" class="mb-3">
        <input type="hidden" name="page" value="admin_content">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
        <div class="row">
            <div class="col-md-4">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?php if ($status_filter === 'all') echo 'selected'; ?>><?php echo t('all_statuses', 'All Statuses'); ?></option>
                    <option value="pending" <?php if ($status_filter === 'pending') echo 'selected'; ?>><?php echo t('pending'); ?></option>
                    <option value="approved" <?php if ($status_filter === 'approved') echo 'selected'; ?>><?php echo t('approved'); ?></option>
                    <option value="rejected" <?php if ($status_filter === 'rejected') echo 'selected'; ?>><?php echo t('rejected'); ?></option>
                </select>
            </div>
        </div>
    </form>

    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>ID</th>
                <th><?php echo t('title'); ?>/<?php echo t('text'); ?></th>
                <th><?php echo t('submitted_by'); ?></th>
                <th><?php echo t('status'); ?></th>
                <th><?php echo t('created_at'); ?></th>
                <th><?php echo t('actions'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo $item['id']; ?></td>
                <td>
                    <a href="index.php?page=<?php echo $view_page; ?>&id=<?php echo $item['id']; ?>">
                        <?php echo substr(sanitize_input(t_dynamic($item[$title_field_en], $item[$title_field_ur])), 0, 70); ?>...
                    </a>
                </td>
                <td><a href="index.php?page=profile&id=<?php echo $item['user_id']; ?>"><?php echo sanitize_input($item['submitter_username']); ?></a></td>
                <td><span class="badge bg-<?php echo $item['status'] === 'approved' ? 'success' : ($item['status'] === 'pending' ? 'warning text-dark' : 'danger'); ?>"><?php echo t($item['status']); ?></span></td>
                <td><?php echo format_date_for_display($item['created_at']); ?></td>
                <td>
                    <a href="index.php?page=<?php echo $view_page; ?>&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                    <?php if ($item['status'] === 'pending'): ?>
                        <form method="POST" action="index.php" class="d-inline">
                            <input type="hidden" name="action" value="approve_item">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="item_type" value="<?php echo $type === 'events' ? 'event' : 'hadith'; ?>">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="<?php echo t('approve'); ?>"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <form method="POST" action="index.php" class="d-inline">
                            <input type="hidden" name="action" value="reject_item">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="item_type" value="<?php echo $type === 'events' ? 'event' : 'hadith'; ?>">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning" title="<?php echo t('reject'); ?>"><i class="bi bi-x-lg"></i></button>
                        </form>
                    <?php endif; ?>
                     <form method="POST" action="index.php" class="d-inline confirm-delete" data-confirm-message="<?php echo ($type === 'events') ? t('confirm_delete_event') : t('confirm_delete_hadith'); ?>">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="item_type" value="<?php echo $type === 'events' ? 'event' : 'hadith'; ?>">
                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('delete'); ?>"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo getPagination($total_items, $current_page_num, 'index.php?page=admin_content&type='.urlencode($type).'&status='.urlencode($status_filter)); ?>
    <?php
    render_admin_nav_tabs('content');
    render_footer();
}

function render_admin_badges_page($badge_to_edit = null) {
    requireRole('admin');
    $is_edit_mode = ($badge_to_edit !== null);
    render_header($is_edit_mode ? t('edit_badge') : t('manage_badges'));
    $db = getDB();

    $badges = $db->query("SELECT * FROM badges ORDER BY points_required ASC")->fetchAll();
    ?>
    <h1><?php echo $is_edit_mode ? t('edit_badge') : t('manage_badges'); ?></h1>

    <div class="row">
        <div class="col-md-4">
            <h4><?php echo $is_edit_mode ? t('edit_badge') : t('add_new_badge'); ?></h4>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="<?php echo $is_edit_mode ? 'edit_badge_action' : 'add_badge'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <?php if ($is_edit_mode): ?>
                    <input type="hidden" name="badge_id" value="<?php echo $badge_to_edit['id']; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label for="name_en" class="form-label"><?php echo t('badge_name'); ?> (EN)</label>
                    <input type="text" class="form-control" id="name_en" name="name_en" value="<?php echo $is_edit_mode ? sanitize_input($badge_to_edit['name_en']) : ''; ?>" required>
                </div>
                <div class="mb-3">
                    <label for="name_ur" class="form-label urdu-text"><?php echo t('badge_name'); ?> (UR)</label>
                    <input type="text" class="form-control urdu-text" id="name_ur" name="name_ur" value="<?php echo $is_edit_mode ? sanitize_input($badge_to_edit['name_ur']) : ''; ?>" dir="rtl" required>
                </div>
                <div class="mb-3">
                    <label for="description_en" class="form-label"><?php echo t('description'); ?> (EN)</label>
                    <textarea class="form-control" id="description_en" name="description_en"><?php echo $is_edit_mode ? sanitize_input($badge_to_edit['description_en']) : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="description_ur" class="form-label urdu-text"><?php echo t('description'); ?> (UR)</label>
                    <textarea class="form-control urdu-text" id="description_ur" name="description_ur" dir="rtl"><?php echo $is_edit_mode ? sanitize_input($badge_to_edit['description_ur']) : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="icon_class" class="form-label"><?php echo t('badge_icon_class'); ?></label>
                    <input type="text" class="form-control" id="icon_class" name="icon_class" value="<?php echo $is_edit_mode ? sanitize_input($badge_to_edit['icon_class']) : 'bi-award'; ?>" placeholder="e.g., bi-award-fill">
                </div>
                <div class="mb-3">
                    <label for="points_required" class="form-label"><?php echo t('points_required'); ?></label>
                    <input type="number" class="form-control" id="points_required" name="points_required" value="<?php echo $is_edit_mode ? $badge_to_edit['points_required'] : '0'; ?>" required>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $is_edit_mode ? t('update') : t('add_new_badge'); ?></button>
                <?php if ($is_edit_mode): ?>
                    <a href="index.php?page=admin_badges" class="btn btn-secondary"><?php echo t('cancel', 'Cancel'); ?></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="col-md-8">
            <h4><?php echo t('current_badges', 'Current Badges'); ?></h4>
            <?php if (!empty($badges)): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th><?php echo t('icon', 'Icon'); ?></th>
                        <th><?php echo t('badge_name'); ?></th>
                        <th><?php echo t('points_required'); ?></th>
                        <th><?php echo t('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($badges as $badge): ?>
                    <tr>
                        <td><i class="bi <?php echo sanitize_input($badge['icon_class'] ?: 'bi-award'); ?> fs-3"></i></td>
                        <td><?php echo sanitize_input(t_dynamic($badge['name_en'], $badge['name_ur'])); ?><br><small class="text-muted"><?php echo sanitize_input(t_dynamic($badge['description_en'], $badge['description_ur'])); ?></small></td>
                        <td><?php echo $badge['points_required']; ?></td>
                        <td>
                            <a href="index.php?page=admin_badges&edit_badge_id=<?php echo $badge['id']; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="index.php" class="d-inline confirm-delete" data-confirm-message="<?php echo t('confirm_delete_badge'); ?>">
                                <input type="hidden" name="action" value="delete_badge">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="badge_id_delete" value="<?php echo $badge['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p><?php echo t('no_items_found'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    render_admin_nav_tabs('badges');
    render_footer();
}

function render_admin_backup_restore_page() {
    requireRole('admin');
    render_header(t('backup_restore'));
    ?>
    <h1><?php echo t('backup_restore'); ?></h1>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h4><?php echo t('backup_db'); ?></h4></div>
                <div class="card-body">
                    <p><?php echo t('backup_note'); ?></p>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="backup_db">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-download"></i> <?php echo t('backup_db'); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h4><?php echo t('restore_db'); ?></h4></div>
                <div class="card-body">
                    <div class="alert alert-warning" role="alert">
                        <strong><?php echo t('warning', 'Warning'); ?>:</strong> <?php echo t('restore_note'); ?>
                    </div>
                    <form method="POST" action="index.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="restore_db">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="mb-3">
                            <label for="db_file_restore" class="form-label"><?php echo t('upload_sqlite_file'); ?></label>
                            <input type="file" class="form-control" id="db_file_restore" name="db_file_restore" accept=".sqlite" required>
                        </div>
                        <button type="submit" class="btn btn-danger confirm-delete" data-confirm-message="<?php echo t('database_restore_warning'); ?>"><i class="bi bi-upload"></i> <?php echo t('restore_db'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_admin_nav_tabs('backup_restore');
    render_footer();
}

function render_admin_nav_tabs($active_tab) {
    ?>
    <ul class="nav nav-tabs mt-4">
        <li class="nav-item">
            <a class="nav-link <?php if ($active_tab === 'dashboard') echo 'active'; ?>" href="index.php?page=admin_dashboard"><?php echo t('dashboard'); ?></a>
        </li>
        <?php if (isAdmin()): ?>
        <li class="nav-item">
            <a class="nav-link <?php if ($active_tab === 'users') echo 'active'; ?>" href="index.php?page=admin_users"><?php echo t('manage_users'); ?></a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?php if ($active_tab === 'content') echo 'active'; ?>" href="index.php?page=admin_content"><?php echo t('manage_content'); ?></a>
        </li>
         <?php if (isAdmin()): ?>
        <li class="nav-item">
            <a class="nav-link <?php if ($active_tab === 'badges') echo 'active'; ?>" href="index.php?page=admin_badges"><?php echo t('manage_badges'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php if ($active_tab === 'backup_restore') echo 'active'; ?>" href="index.php?page=admin_backup_restore"><?php echo t('backup_restore'); ?></a>
        </li>
        <?php endif; ?>
    </ul>
    <?php
}

function render_not_found_page() {
    http_response_code(404);
    render_header(t('page_not_found'));
    ?>
    <div class="text-center py-5">
        <h1 class="display-1">404</h1>
        <h2><?php echo t('page_not_found'); ?></h2>
        <p><?php echo t('page_not_found_message'); ?></p>
        <a href="index.php" class="btn btn-primary"><?php echo t('back_to_home'); ?></a>
    </div>
    <?php
    render_footer();
}

// ============== MAIN ROUTER / PAGE DISPATCHER ==============
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

switch ($page) {
    case 'home':
        render_home_page();
        break;
    case 'login':
        render_login_page();
        break;
    case 'register':
        render_register_page();
        break;
    case 'events':
        render_events_page();
        break;
    case 'view_event':
        render_view_event_page();
        break;
    case 'add_event':
        render_add_edit_event_page();
        break;
    case 'edit_event':
        requireLogin();
        $event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch();
        if ($event && (isAdmin() || isUlama() || (isset($_SESSION['user_id']) && $event['user_id'] == $_SESSION['user_id']))) {
            render_add_edit_event_page($event);
        } else {
            addFlashMessage(t('item_not_found_or_access_denied', 'Event not found or access denied.'), 'danger');
            redirect('index.php?page=events');
        }
        break;
    case 'quran_search':
        render_quran_search_page();
        break;
    case 'hadith':
        render_hadith_page();
        break;
    case 'view_hadith':
        render_view_hadith_page();
        break;
    case 'add_hadith':
        render_add_edit_hadith_page();
        break;
    case 'edit_hadith':
        requireLogin();
        $hadith_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM hadiths WHERE id = ?");
        $stmt->execute([$hadith_id]);
        $hadith = $stmt->fetch();
         if ($hadith && (isAdmin() || isUlama() || (isset($_SESSION['user_id']) && $hadith['user_id'] == $_SESSION['user_id']))) {
            render_add_edit_hadith_page($hadith);
        } else {
            addFlashMessage(t('item_not_found_or_access_denied', 'Hadith not found or access denied.'), 'danger');
            redirect('index.php?page=hadith');
        }
        break;
    case 'bookmarks':
        render_bookmarks_page();
        break;
    case 'profile':
        render_profile_page();
        break;
    case 'map_view':
        render_map_view_page();
        break;
    case 'timeline':
        render_timeline_page();
        break;
    // Admin Panel
    case 'admin_dashboard':
        render_admin_dashboard();
        break;
    case 'admin_users':
        render_admin_users_page();
        break;
    case 'admin_content':
        render_admin_content_page();
        break;
    case 'admin_badges':
        $badge_to_edit = null;
        if (isset($_GET['edit_badge_id'])) {
            requireRole('admin');
            $badge_id = (int)$_GET['edit_badge_id'];
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM badges WHERE id = ?");
            $stmt->execute([$badge_id]);
            $badge_to_edit = $stmt->fetch();
            if (!$badge_to_edit) {
                addFlashMessage(t('item_not_found'), 'danger');
                redirect('index.php?page=admin_badges');
            }
        }
        render_admin_badges_page($badge_to_edit);
        break;
    case 'admin_backup_restore':
        render_admin_backup_restore_page();
        break;
    default:
        render_not_found_page();
        break;
}

ob_end_flush(); // Send output buffer
?>
<script>
const centuryFilter = document.querySelector("#century_filter");
const styleId = "timeline-style";
//alert(centuryFilter.textContent.includes("تمام"))
if (centuryFilter && centuryFilter.textContent.includes("تمام")) {
  if (!document.getElementById(styleId)) {
    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      .timeline-item-container.left { left: -50% !important; }
      .timeline-item-container.right { left: 0% !important; }
    `;
    document.head.appendChild(style);
  }
} else {
  const existingStyle = document.getElementById(styleId);
  if (existingStyle) {
    existingStyle.remove();
  }
}

</script>