<?php
/**
 * Islamic History App
 * Author: Yasin Ullah
 * Country: Pakistan
 *
 * This is a single-file PHP application for exploring Islamic history.
 * It includes features like geo-location based search, interactive infographics,
 * role-based access, multi-language support, Quranic Ayahs and Hadith integration,
 * and backup/restore functionality for the SQLite database.
 *
 * Please ensure the 'data.AM' file containing Quranic Ayahs is in the same directory.
 * Ensure the PHP process has write permissions for the directory to create the SQLite database file.
 * Backup and restore functionality is a must in this app.
 * Use AJAX with caution, ensuring proper validation and error handling.
 * Includes sample 4 data points.
 * Designed with a focus on error and bug-free code.
 */

// Report all errors for debugging during development
// In production, you might want to log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =============================================================================
// 1. Configuration
// =============================================================================

// Define database file path
define('DB_PATH', __DIR__ . '/islamic_history.sqlite');
// Define path for the Quran data file
define('QURAN_DATA_PATH', __DIR__ . '/data.AM');
// Define path for backups
define('BACKUP_PATH', __DIR__ . '/backups/');
// Define default language
define('DEFAULT_LANG', 'en');
// Define available languages
define('AVAILABLE_LANGS', ['en', 'ur']);
// Define roles
define('ROLE_PUBLIC', 'Public');
define('ROLE_USER', 'User');
define('ROLE_ULAMA', 'Ulama');
define('ROLE_ADMIN', 'Admin');
// Define default admin credentials (CHANGE THESE IN PRODUCTION)
define('DEFAULT_ADMIN_USER', 'admin');
define('DEFAULT_ADMIN_PASS', 'adminpass'); // **CHANGE THIS!**

// Ensure backup directory exists
if (!is_dir(BACKUP_PATH)) {
    // Attempt to create the directory with appropriate permissions
    if (!mkdir(BACKUP_PATH, 0775, true)) {
        // Log or display error if directory creation fails
        error_log("Failed to create backup directory: " . BACKUP_PATH);
        // In a real application, you might want to halt or show a user-friendly error
    }
}

// =============================================================================
// 2. Database Connection and Initialization
// =============================================================================

function get_db() {
    try {
        // Check if DB file exists before attempting connection
        $db_exists = file_exists(DB_PATH);

        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // If the DB file didn't exist, it was just created, so initialize it
        if (!$db_exists) {
            initialize_database($pdo);
        }

        return $pdo;
    } catch (PDOException $e) {
        // Log the error and provide a user-friendly message
        error_log("Database connection failed: " . $e->getMessage());
        die("An error occurred while connecting to the database. Please try again later.");
    }
}

function initialize_database($db) {
    // Check if a basic table exists (e.g., users) to avoid re-initializing
    try {
        $db->query("SELECT 1 FROM users LIMIT 1");
        // If the query succeeds, tables already exist, so do nothing
        return;
    } catch (PDOException $e) {
        // If the query fails (table doesn't exist), proceed with creation
        // Log the fact that tables are being created
        error_log("Database tables not found. Initializing database.");
    }

    $db->exec("BEGIN TRANSACTION;");
    try {
        // Roles table
        $db->exec("CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL
        );");

        // Users table
        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL, -- Hashed password
            role_id INTEGER,
            points INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(id)
        );");

        // Locations table
        $db->exec("CREATE TABLE locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name_en TEXT NOT NULL,
            name_ur TEXT NOT NULL,
            description_en TEXT,
            description_ur TEXT,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );");

        // Events table
        $db->exec("CREATE TABLE events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title_en TEXT NOT NULL,
            title_ur TEXT NOT NULL,
            description_en TEXT,
            description_ur TEXT,
            date TEXT, -- Store as YYYY-MM-DD or similar
            category TEXT CHECK(category IN ('Islamic', 'General')) NOT NULL DEFAULT 'Islamic',
            location_id INTEGER, -- Link to a location
            suggested_by_user_id INTEGER, -- If suggested by a user
            status TEXT CHECK(status IN ('pending', 'approved', 'rejected')) NOT NULL DEFAULT 'approved', -- For moderation
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (location_id) REFERENCES locations(id),
            FOREIGN KEY (suggested_by_user_id) REFERENCES users(id)
        );");

        // Ayahs table (for data.AM)
        $db->exec("CREATE TABLE ayahs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            surah INTEGER NOT NULL,
            ayah INTEGER NOT NULL,
            arabic TEXT NOT NULL,
            urdu TEXT NOT NULL,
            UNIQUE(surah, ayah) -- Ensure no duplicate ayah entries
        );");

        // Hadiths table
        $db->exec("CREATE TABLE hadiths (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_en TEXT,
            book_ur TEXT,
            chapter_en TEXT,
            chapter_ur TEXT,
            text_en TEXT,
            text_ur TEXT,
            reference TEXT, -- e.g., Sahih Bukhari 1:1
            authenticated_by_ulama_id INTEGER, -- If authenticated by Ulama
            status TEXT CHECK(status IN ('pending', 'approved', 'rejected')) NOT NULL DEFAULT 'pending', -- Default to pending for Ulama suggestions
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (authenticated_by_ulama_id) REFERENCES users(id)
        );");

        // Linking tables for events/locations to Ayahs/Hadiths
        $db->exec("CREATE TABLE event_ayahs (
            event_id INTEGER,
            ayah_id INTEGER,
            PRIMARY KEY (event_id, ayah_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE
        );");
         $db->exec("CREATE TABLE event_hadiths (
            event_id INTEGER,
            hadith_id INTEGER,
            PRIMARY KEY (event_id, hadith_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (hadith_id) REFERENCES hadiths(id) ON DELETE CASCADE
        );");
        $db->exec("CREATE TABLE location_ayahs (
            location_id INTEGER,
            ayah_id INTEGER,
            PRIMARY KEY (location_id, ayah_id),
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
            FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE
        );");
         $db->exec("CREATE TABLE location_hadiths (
            location_id INTEGER,
            hadith_id INTEGER,
            PRIMARY KEY (location_id, hadith_id),
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
            FOREIGN KEY (hadith_id) REFERENCES hadiths(id) ON DELETE CASCADE
        );");

        // User bookmarks table
        $db->exec("CREATE TABLE user_bookmarks (
            user_id INTEGER,
            location_id INTEGER,
            PRIMARY KEY (user_id, location_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
        );");

        // Insert default roles
        $stmt = $db->prepare("INSERT INTO roles (name) VALUES (:name)");
        $stmt->execute([':name' => ROLE_PUBLIC]);
        $stmt->execute([':name' => ROLE_USER]);
        $stmt->execute([':name' => ROLE_ULAMA]);
        $stmt->execute([':name' => ROLE_ADMIN]);

        // Insert default admin user
        $admin_role_id = $db->query("SELECT id FROM roles WHERE name = '" . ROLE_ADMIN . "'")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO users (username, password, role_id) VALUES (:username, :password, :role_id)");
        $stmt->execute([
            ':username' => DEFAULT_ADMIN_USER,
            ':password' => password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT),
            ':role_id' => $admin_role_id
        ]);

        // Add sample data
        add_sample_data($db);

        $db->exec("COMMIT;");
        error_log("Database initialized successfully.");

    } catch (PDOException $e) {
        $db->exec("ROLLBACK;");
        // Log the error and provide a user-friendly message
        error_log("Database initialization failed: " . $e->getMessage());
        die("An error occurred during database setup. Please try again later.");
    }
}

function add_sample_data($db) {
     // Check if sample data already exists (e.g., check location count)
     // Use a transaction for sample data insertion
     $db->exec("BEGIN TRANSACTION;");
     try {
         $location_count = $db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
         if ($location_count > 0) {
             $db->exec("ROLLBACK;"); // No need to commit if data exists
             return; // Sample data already added
         }

         error_log("Adding sample data.");

         // Sample Locations
         $stmt_loc = $db->prepare("INSERT INTO locations (name_en, name_ur, description_en, description_ur, latitude, longitude) VALUES (:name_en, :name_ur, :description_en, :description_ur, :latitude, :longitude)");
         $stmt_loc->execute([
             ':name_en' => 'Mecca',
             ':name_ur' => 'مکہ',
             ':description_en' => 'The holiest city in Islam, birthplace of Prophet Muhammad (PBUH).',
             ':description_ur' => 'اسلام کا مقدس ترین شہر، نبی کریم صلی اللہ علیہ وسلم کی جائے پیدائش۔',
             ':latitude' => 21.4225,
             ':longitude' => 39.8262
         ]);
         $mecca_id = $db->lastInsertId();

         $stmt_loc->execute([
             ':name_en' => 'Medina',
             ':name_ur' => 'مدینہ',
             ':description_en' => 'City where Prophet Muhammad (PBUH) migrated and is buried.',
             ':description_ur' => 'وہ شہر جہاں نبی کریم صلی اللہ علیہ وسلم نے ہجرت کی اور مدفون ہیں۔',
             ':latitude' => 24.4686,
             ':longitude' => 39.6148
         ]);
         $medina_id = $db->lastInsertId();

         $stmt_loc->execute([
            ':name_en' => 'Jerusalem',
            ':name_ur' => 'یروشلم',
            ':description_en' => 'Site of Al-Aqsa Mosque, the third holiest site in Islam.',
            ':description_ur' => 'مسجد اقصیٰ کا مقام، اسلام کا تیسرا مقدس ترین مقام۔',
            ':latitude' => 31.7767,
            ':longitude' => 35.2345
        ]);
        $jerusalem_id = $db->lastInsertId();

        $stmt_loc->execute([
            ':name_en' => 'Cordoba',
            ':name_ur' => 'قرطبہ',
            ':description_en' => 'Historical capital of the Islamic Caliphate in Al-Andalus.',
            ':description_ur' => 'اندلس میں اسلامی خلافت کا تاریخی دارالحکومت۔',
            ':latitude' => 37.8882,
            ':longitude' => -4.7794
        ]);
        $cordoba_id = $db->lastInsertId();


         // Sample Events
         // Events added directly by system are considered 'approved'
         $stmt_event = $db->prepare("INSERT INTO events (title_en, title_ur, description_en, description_ur, date, category, location_id, status) VALUES (:title_en, :title_ur, :description_en, :description_ur, :date, :category, :location_id, 'approved')");
         $stmt_event->execute([
             ':title_en' => 'Hijra (Migration to Medina)',
             ':title_ur' => 'ہجرت مدینہ',
             ':description_en' => 'The migration of Prophet Muhammad (PBUH) and his followers from Mecca to Medina.',
             ':description_ur' => 'نبی کریم صلی اللہ علیہ وسلم اور ان کے پیروکاروں کی مکہ سے مدینہ ہجرت۔',
             ':date' => '0622-09-01', // Approximate date
             ':category' => 'Islamic',
             ':location_id' => $medina_id
         ]);
         $hijra_event_id = $db->lastInsertId();

         $stmt_event->execute([
             ':title_en' => 'Conquest of Mecca',
             ':title_ur' => 'فتح مکہ',
             ':description_en' => 'The Muslim conquest of Mecca.',
             ':description_ur' => 'مسلمانوں کا مکہ فتح کرنا۔',
             ':date' => '0630-01-11', // Approximate date
             ':category' => 'Islamic',
             ':location_id' => $mecca_id
         ]);
          $conquest_mecca_event_id = $db->lastInsertId();

        $stmt_event->execute([
            ':title_en' => 'Battle of Badr',
            ':title_ur' => 'غزوہ بدر',
            ':description_en' => 'Key battle early in Islam between Muslims and Quraysh.',
            ':description_ur' => 'اسلام کے ابتدائی دور میں مسلمانوں اور قریش کے درمیان اہم جنگ۔',
            ':date' => '0624-03-13', // Approximate date
            ':category' => 'Islamic',
            ':location_id' => $medina_id // Near Medina
        ]);
        $badr_event_id = $db->lastInsertId();


        $stmt_event->execute([
            ':title_en' => 'Establishment of Umayyad Caliphate in Cordoba',
            ':title_ur' => 'قرطبہ میں اموی خلافت کا قیام',
            ':description_en' => 'Abd al-Rahman I established the independent Umayyad emirate, later caliphate, in Al-Andalus.',
            ':description_ur' => 'عبدالرحمن اول نے اندلس میں آزاد اموی امارت، بعد میں خلافت، قائم کی۔',
            ':date' => '0756-01-15', // Approximate date
            ':category' => 'Islamic',
            ':location_id' => $cordoba_id
        ]);
        $umayyad_cordoba_event_id = $db->lastInsertId();


         // Sample Hadiths (added directly by system, considered 'approved' initially)
         $stmt_hadith = $db->prepare("INSERT INTO hadiths (book_en, book_ur, chapter_en, chapter_ur, text_en, text_ur, reference, status) VALUES (:book_en, :book_ur, :chapter_en, :chapter_ur, :text_en, :text_ur, :reference, 'approved')");
         $stmt_hadith->execute([
             ':book_en' => 'Sahih Bukhari',
             ':book_ur' => 'صحیح بخاری',
             ':chapter_en' => 'Beginning of Creation',
             ':chapter_ur' => 'کتاب بدء الخلق',
             ':text_en' => 'Narrated \'Umar bin Al-Khattab: I heard Allah\'s Apostle saying, "The reward of deeds depends upon the intentions and every person will get the reward according to what he has intended. So whoever emigrated for worldly benefits or for a woman to marry, his emigration was for what he emigrated for."',
             ':text_ur' => 'عمر بن خطاب رضی اللہ عنہ سے روایت ہے کہ میں نے رسول اللہ صلی اللہ علیہ وسلم کو فرماتے سنا کہ اعمال کا دارومدار نیتوں پر ہے اور ہر شخص کو وہی ملے گا جس کی اس نے نیت کی۔ پس جس نے دنیا کے فائدے کے لیے یا کسی عورت سے شادی کے لیے ہجرت کی، اس کی ہجرت اسی کے لیے تھی جس کے لیے اس نے ہجرت کی۔',
             ':reference' => 'Sahih Bukhari 1:1',
         ]);
         $hadith1_id = $db->lastInsertId();

         $stmt_hadith->execute([
             ':book_en' => 'Sahih Muslim',
             ':book_ur' => 'صحیح مسلم',
             ':chapter_en' => 'Faith',
             ':chapter_ur' => 'کتاب الایمان',
             ':text_en' => 'Narrated Abu Huraira: The Messenger of Allah (ﷺ) said, "Faith has over seventy branches or over sixty branches, the most excellent of which is the declaration that there is no god but Allah, and the humblest of which is the removal of a thorny path; and modesty is a branch of faith."',
             ':text_ur' => 'ابوہریرہ رضی اللہ عنہ سے روایت ہے کہ رسول اللہ صلی اللہ علیہ وسلم نے فرمایا: ایمان کی ستر سے زیادہ یا ساٹھ سے زیادہ شاخیں ہیں، جن میں سب سے افضل لا الہ الا اللہ کا اقرار ہے، اور سب سے کم تر راستے سے کانٹے دار چیز کو ہٹانا ہے؛ اور حیا ایمان کی ایک شاخ ہے۔',
             ':reference' => 'Sahih Muslim 1:51',
         ]);
         $hadith2_id = $db->lastInsertId();


         // Link sample Ayahs/Hadiths to sample data (Requires Ayahs table to be populated first)
         // This linking might be better done *after* load_quran_data runs,
         // or we can link based on Surah/Ayah numbers if we know them.
         // Let's link the first ayah (Al-Fatiha 1:1) to Mecca and Hijra event for demonstration.
         // We need the ID of Surah 1, Ayah 1 from the `ayahs` table.
         $ayah_fatiha1_id = $db->prepare("SELECT id FROM ayahs WHERE surah = 1 AND ayah = 1")->execute()->fetchColumn();
         if ($ayah_fatiha1_id) {
             $db->prepare("INSERT OR IGNORE INTO location_ayahs (location_id, ayah_id) VALUES (:location_id, :ayah_id)")->execute([':location_id' => $mecca_id, ':ayah_id' => $ayah_fatiha1_id]);
             $db->prepare("INSERT OR IGNORE INTO event_ayahs (event_id, ayah_id) VALUES (:event_id, :ayah_id)")->execute([':event_id' => $hijra_event_id, ':ayah_id' => $ayah_fatiha1_id]);
         }

         // Link sample Hadiths
         $db->prepare("INSERT OR IGNORE INTO event_hadiths (event_id, hadith_id) VALUES (:event_id, :hadith_id)")->execute([':event_id' => $hijra_event_id, ':hadith_id' => $hadith1_id]);
         $db->prepare("INSERT OR IGNORE INTO location_hadiths (location_id, hadith_id) VALUES (:location_id, :hadith_id)")->execute([':location_id' => $medina_id, ':hadith_id' => $hadith2_id]);


         $db->exec("COMMIT;");
         error_log("Sample data added successfully.");
     } catch (PDOException $e) {
         $db->exec("ROLLBACK;");
         // In a real application, log this error properly
         error_log("Failed to add sample data: " . $e->getMessage());
     }
}


function load_quran_data($db) {
    // Check if Ayahs table is empty
    $count = $db->query("SELECT COUNT(*) FROM ayahs")->fetchColumn();
    if ($count > 0) {
        // error_log("Quran data already loaded."); // Avoid excessive logging on every page load
        return; // Data already loaded
    }

    error_log("Loading Quran data from data.AM...");

    if (!file_exists(QURAN_DATA_PATH)) {
        error_log("Quran data file not found: " . QURAN_DATA_PATH);
        return; // File not found
    }

    $file = fopen(QURAN_DATA_PATH, 'r');
    if (!$file) {
        error_log("Could not open Quran data file: " . QURAN_DATA_PATH);
        return; // File not readable
    }

    $db->exec("BEGIN TRANSACTION;");
    $stmt = $db->prepare("INSERT INTO ayahs (surah, ayah, arabic, urdu) VALUES (:surah, :ayah, :arabic, :urdu)");
    $inserted_count = 0;
    $line_num = 0;

    while (($line = fgets($file)) !== false) {
        $line_num++;
        // Example: بِسْمِ اللَّهِ الرَّحْمَنِ الرَّحِيمِ ترجمہ: شروع اللہ کے نام سے جو بڑا مہربان نہایت رحم والا ہے<br/>س 001 آ 001
        $line = trim($line);
        if (empty($line)) continue;

        // Find the translation marker
        $translation_pos = strpos($line, 'ترجمہ:');
        if ($translation_pos === false) {
             error_log("Skipping line $line_num (no 'ترجمہ:') in data.AM: " . $line);
            continue; // Skip lines without the expected format
        }

        $arabic_part = trim(substr($line, 0, $translation_pos));
        $rest_of_line = substr($line, $translation_pos + strlen('ترجمہ:'));

        // Find the metadata marker
        $metadata_pos = strpos($rest_of_line, '<br/>س ');
         if ($metadata_pos === false) {
             error_log("Skipping line $line_num (no '<br/>س ') in data.AM: " . $line);
            continue; // Skip lines without metadata
        }

        $urdu_part = trim(substr($rest_of_line, 0, $metadata_pos));
        $metadata_part = trim(substr($rest_of_line, $metadata_pos + strlen('<br/>')));

        // Extract Surah and Ayah numbers
        $surah = null;
        $ayah = null;
        if (preg_match('/س\s*(\d{3})\s*آ\s*(\d{3})/', $metadata_part, $matches)) {
            $surah = (int)$matches[1];
            $ayah = (int)$matches[2];
        } else {
             error_log("Skipping line $line_num (metadata parse error) in data.AM: " . $line);
            continue; // Skip if metadata parsing fails
        }

        if ($surah > 0 && $ayah > 0 && !empty($arabic_part) && !empty($urdu_part)) {
            try {
                $stmt->execute([
                    ':surah' => $surah,
                    ':ayah' => $ayah,
                    ':arabic' => $arabic_part,
                    ':urdu' => $urdu_part
                ]);
                $inserted_count++;
            } catch (PDOException $e) {
                // Log duplicate entry errors but continue
                if ($e->getCode() === '23000') { // SQLite constraint violation (UNIQUE)
                     // error_log("Duplicate Ayah found (Surah: $surah, Ayah: $ayah) in data.AM. Skipping."); // Avoid excessive logging if file has duplicates
                } else {
                    // Log other DB errors and potentially stop
                    error_log("Database error inserting Ayah (Line $line_num): " . $e->getMessage() . " Line: " . $line);
                    // Decide whether to continue or break on other errors
                }
            }
        } else {
             error_log("Skipping line $line_num (missing data) in data.AM: " . $line);
        }
    }

    fclose($file);

    // Check if any data was inserted before committing
    if ($inserted_count > 0) {
        $db->exec("COMMIT;");
        error_log("Finished loading Quran data. Inserted " . $inserted_count . " ayahs.");
    } else {
         $db->exec("ROLLBACK;");
         error_log("No new Quran data inserted from data.AM.");
         // If count was 0 initially and still 0, maybe the file was empty or malformed
         if ($count == 0) {
              error_log("Warning: Ayahs table is empty after attempting to load data.AM. Check file format and content.");
         }
    }
}


// =============================================================================
// 3. Session Management and Language Handling
// =============================================================================

// Ensure session is started only once
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


function set_language($lang) {
    if (in_array($lang, AVAILABLE_LANGS)) {
        $_SESSION['lang'] = $lang;
    } else {
        $_SESSION['lang'] = DEFAULT_LANG;
    }
}

function get_language() {
    return $_SESSION['lang'] ?? DEFAULT_LANG;
}

// Set language if requested via GET or if not set
if (isset($_GET['lang'])) {
    set_language($_GET['lang']);
    // Redirect to clean URL after setting language
    $params = $_GET;
    unset($params['lang']);
    $redirect_url = strtok($_SERVER['REQUEST_URI'], '?') . ($params ? '?' . http_build_query($params) : '');
    header("Location: " . $redirect_url);
    exit();
} elseif (!isset($_SESSION['lang'])) {
    set_language(DEFAULT_LANG);
}

// =============================================================================
// 4. Role-based Access Control
// =============================================================================

function get_user_role_name($db) {
    if (!isset($_SESSION['user_id'])) {
        return ROLE_PUBLIC;
    }
    try {
        $stmt = $db->prepare("SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $role = $stmt->fetchColumn();
        return $role ? $role : ROLE_PUBLIC; // Default to public if user or role not found (shouldn't happen with proper FK)
    } catch (PDOException $e) {
        error_log("Error fetching user role: " . $e->getMessage());
        return ROLE_PUBLIC; // Default to public on error
    }
}

function has_role($db, $required_role) {
    $current_role = get_user_role_name($db);
    $role_hierarchy = [ROLE_PUBLIC => 0, ROLE_USER => 1, ROLE_ULAMA => 2, ROLE_ADMIN => 3];

    return isset($role_hierarchy[$current_role]) && isset($role_hierarchy[$required_role]) && $role_hierarchy[$current_role] >= $role_hierarchy[$required_role];
}

function require_role($db, $required_role) {
    if (!has_role($db, $required_role)) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
            exit();
        } else {
            // Standard request
            $_SESSION['error_message'] = "You do not have permission to access this page.";
            header("Location: ?action=login"); // Redirect to login or home
            exit();
        }
    }
}

// =============================================================================
// 5. Data Fetching Functions
// =============================================================================

function get_locations($db, $status = 'approved') {
    $lang = get_language();
    $name_col = 'name_' . $lang;
    $desc_col = 'description_' . $lang;
    $sql = "SELECT id, name_en, name_ur, description_en, description_ur, $name_col AS name, $desc_col AS description, latitude, longitude FROM locations";
    // In a real app, filter locations based on related events status if needed
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

function get_events($db, $filters = [], $status = 'approved') {
    $lang = get_language();
    $title_col = 'title_' . $lang;
    $desc_col = 'description_' . $lang;

    $sql = "SELECT e.id, e.title_en, e.title_ur, e.description_en, e.description_ur, e.$title_col AS title, e.$desc_col AS description, e.date, e.category, e.location_id, e.status, e.suggested_by_user_id,
                   l.name_en AS location_name_en, l.name_ur AS location_name_ur
            FROM events e
            LEFT JOIN locations l ON e.location_id = l.id
            WHERE 1=1";
    $params = [];

    if ($status !== null) {
         $sql .= " AND e.status = :status";
         $params[':status'] = $status;
    }

    if (!empty($filters['category'])) {
        $sql .= " AND e.category = :category";
        $params[':category'] = $filters['category'];
    }
     if (!empty($filters['location_id'])) {
        $sql .= " AND e.location_id = :location_id";
        $params[':location_id'] = $filters['location_id'];
    }
     if (isset($filters['user_id'])) { // Use isset to allow user_id = null
        $sql .= " AND e.suggested_by_user_id = :user_id";
        $params[':user_id'] = $filters['user_id'];
    }
     if (!empty($filters['search'])) {
        $search_term = '%' . $filters['search'] . '%';
        $sql .= " AND (e.title_en LIKE :search OR e.title_ur LIKE :search OR e.description_en LIKE :search OR e.description_ur LIKE :search)";
        $params[':search'] = $search_term;
    }


    $sql .= " ORDER BY e.date ASC"; // For timeline

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_event($db, $id) {
     $lang = get_language();
     $title_col = 'title_' . $lang;
     $desc_col = 'description_' . $lang;

     $stmt = $db->prepare("SELECT e.id, e.title_en, e.title_ur, e.description_en, e.description_ur, e.$title_col AS title, e.$desc_col AS description, e.date, e.category, e.location_id, e.status, e.suggested_by_user_id,
                                 l.name_en AS location_name_en, l.name_ur AS location_name_ur
                           FROM events e
                           LEFT JOIN locations l ON e.location_id = l.id
                           WHERE e.id = :id");
     $stmt->execute([':id' => $id]);
     return $stmt->fetch();
}

function get_location($db, $id) {
     $lang = get_language();
     $name_col = 'name_' . $lang;
     $desc_col = 'description_' . $lang;

     $stmt = $db->prepare("SELECT id, name_en, name_ur, description_en, description_ur, $name_col AS name, $desc_col AS description, latitude, longitude FROM locations WHERE id = :id");
     $stmt->execute([':id' => $id]);
     return $stmt->fetch();
}


function get_ayahs($db, $filters = []) {
     $lang = get_language();
     // $text_col = $lang === 'ur' ? 'urdu' : 'arabic'; // Default to Arabic if lang is not urdu - not needed for fetching all data

     $sql = "SELECT id, surah, ayah, arabic, urdu FROM ayahs WHERE 1=1";
     $params = [];

     if (!empty($filters['search'])) {
         $search_term = '%' . $filters['search'] . '%';
         $sql .= " AND (arabic LIKE :search OR urdu LIKE :search)";
         $params[':search'] = $search_term;
     }
     if (!empty($filters['surah'])) {
         $sql .= " AND surah = :surah";
         $params[':surah'] = $filters['surah'];
     }
      if (!empty($filters['ayah'])) {
         $sql .= " AND ayah = :ayah";
         $params[':ayah'] = $filters['ayah']; // Corrected variable name
     }
      if (!empty($filters['ids']) && is_array($filters['ids'])) {
         $placeholders = implode(',', array_fill(0, count($filters['ids']), '?'));
         $sql .= " AND id IN ($placeholders)";
         $params = array_merge($params, $filters['ids']);
     }


     $sql .= " ORDER BY surah ASC, ayah ASC";

     $stmt = $db->prepare($sql);
     $stmt->execute($params);
     return $stmt->fetchAll();
}

function get_hadiths($db, $filters = [], $status = 'approved') {
     $lang = get_language();
     $book_col = 'book_' . $lang;
     $chapter_col = 'chapter_' . $lang;
     $text_col = 'text_' . $lang;

     $sql = "SELECT id, book_en, book_ur, chapter_en, chapter_ur, text_en, text_ur, $book_col AS book, $chapter_col AS chapter, $text_col AS text, reference, status, authenticated_by_ulama_id FROM hadiths WHERE 1=1";
     $params = [];

     if ($status !== null) {
         $sql .= " AND status = :status";
         $params[':status'] = $status;
     }
     if (!empty($filters['search'])) {
         $search_term = '%' . $filters['search'] . '%';
         $sql .= " AND (book_en LIKE :search OR book_ur LIKE :search OR chapter_en LIKE :search OR chapter_ur LIKE :search OR text_en LIKE :search OR text_ur LIKE :search OR reference LIKE :search)";
         $params[':search'] = $search_term;
     }
      if (!empty($filters['ids']) && is_array($filters['ids'])) {
         $placeholders = implode(',', array_fill(0, count($filters['ids']), '?'));
         $sql .= " AND id IN ($placeholders)";
         $params = array_merge($params, $filters['ids']);
     }
      if (isset($filters['authenticated_by_ulama_id'])) { // Use isset to allow null
         $sql .= " AND authenticated_by_ulama_id = :ulama_id";
         $params[':ulama_id'] = $filters['authenticated_by_ulama_id'];
     }


     $stmt = $db->prepare($sql);
     $stmt->execute($params);
     return $stmt->fetchAll();
}

function get_linked_ayahs($db, $event_id = null, $location_id = null) {
    if (!$event_id && !$location_id) return [];

    $sql = "SELECT a.id, a.surah, a.ayah, a.arabic, a.urdu
            FROM ayahs a";
    $params = [];

    if ($event_id !== null) {
        $sql .= " JOIN event_ayahs ea ON a.id = ea.ayah_id WHERE ea.event_id = :id";
        $params[':id'] = $event_id;
    } elseif ($location_id !== null) {
        $sql .= " JOIN location_ayahs la ON a.id = la.ayah_id WHERE la.location_id = :id";
        $params[':id'] = $location_id;
    } else {
         return []; // Should not happen with the initial check, but good practice
    }

    $sql .= " ORDER BY a.surah ASC, a.ayah ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_linked_hadiths($db, $event_id = null, $location_id = null) {
     if (!$event_id && !$location_id) return [];

    $lang = get_language();
    $book_col = 'book_' . $lang;
    $chapter_col = 'chapter_' . $lang;
    $text_col = 'text_' . $lang;

    $sql = "SELECT h.id, h.book_en, h.book_ur, h.chapter_en, h.chapter_ur, h.text_en, h.text_ur, $book_col AS book, $chapter_col AS chapter, $text_col AS text, h.reference
            FROM hadiths h";
    $params = [];

    if ($event_id !== null) {
        $sql .= " JOIN event_hadiths eh ON h.id = eh.hadith_id WHERE eh.event_id = :id";
        $params[':id'] = $event_id;
    } elseif ($location_id !== null) {
        $sql .= " JOIN location_hadiths la ON h.id = la.hadith_id WHERE la.location_id = :id";
        $params[':id'] = $location_id;
    } else {
        return []; // Should not happen
    }

    $sql .= " AND h.status = 'approved'"; // Only show approved hadiths
    $sql .= " ORDER BY h.reference ASC"; // Simple ordering

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}


function get_users($db, $filters = []) {
     // Role check is done before calling this function
     $sql = "SELECT u.id, u.username, u.points, r.name AS role FROM users u JOIN roles r ON u.role_id = r.id WHERE 1=1";
     $params = [];
     if (!empty($filters['role'])) {
         $sql .= " AND r.name = :role";
         $params[':role'] = $filters['role'];
     }
     if (!empty($filters['search'])) {
        $search_term = '%' . $filters['search'] . '%';
        $sql .= " AND u.username LIKE :search";
        $params[':search'] = $search_term;
     }

     $stmt = $db->prepare($sql);
     $stmt->execute($params);
     return $stmt->fetchAll();
}

function get_roles($db) {
    $stmt = $db->query("SELECT id, name FROM roles");
    return $stmt->fetchAll();
}

function get_user_bookmarks($db, $user_id) {
    if (!isset($user_id)) return []; // Must have a user ID
    $lang = get_language();
    $name_col = 'name_' . $lang;
    $stmt = $db->prepare("SELECT l.id, l.$name_col AS name, l.latitude, l.longitude
                          FROM locations l
                          JOIN user_bookmarks ub ON l.id = ub.location_id
                          WHERE ub.user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function is_bookmarked($db, $user_id, $location_id) {
     if (!isset($user_id) || !isset($location_id)) return false;
     $stmt = $db->prepare("SELECT COUNT(*) FROM user_bookmarks WHERE user_id = :user_id AND location_id = :location_id");
     $stmt->execute([':user_id' => $user_id, ':location_id' => $location_id]);
     return $stmt->fetchColumn() > 0;
}

// =============================================================================
// 6. Action Handling (AJAX and Form Submissions)
// =============================================================================

function handle_ajax_request($db) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Unknown action'];

    // Get request body for POST/PUT/DELETE via AJAX
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if ($data === null && $input !== '') {
         // Handle case where input is not valid JSON but not empty
         $response = ['success' => false, 'message' => 'Invalid JSON data received.'];
         echo json_encode($response);
         exit();
    }


    try {
        switch ($action) {
            case 'get_map_data':
                $locations = get_locations($db);
                $events = get_events($db, [], 'approved'); // Only show approved events on map
                // Fetch bookmarks for the current user if logged in
                $user_bookmarks = [];
                if (isset($_SESSION['user_id'])) {
                    $user_bookmarks = get_user_bookmarks($db, $_SESSION['user_id']);
                    $bookmarked_location_ids = array_column($user_bookmarks, 'id');
                } else {
                     $bookmarked_location_ids = [];
                }


                // Link events, Ayahs, Hadiths, and bookmark status to locations for map popups
                foreach($locations as &$loc) {
                    $loc['events'] = array_filter($events, function($event) use ($loc) {
                        return $event['location_id'] == $loc['id'];
                    });
                     // Fetch linked Ayahs/Hadiths for locations
                    $loc['ayahs'] = get_linked_ayahs($db, null, $loc['id']);
                    $loc['hadiths'] = get_linked_hadiths($db, null, $loc['id']);
                    $loc['is_bookmarked'] = in_array($loc['id'], $bookmarked_location_ids);
                }
                 // Also include events without locations for timeline/list view if needed, but map data is location-centric
                $response = ['success' => true, 'locations' => $locations, 'events' => $events]; // Sending events separately might be useful for timeline
                break;

             case 'get_event_details':
                 $event_id = $_GET['id'] ?? null;
                 if ($event_id) {
                     $event = get_event($db, $event_id);
                      // Allow viewing if approved, or if user has sufficient role, or if user suggested it and it's pending/rejected
                     $can_view = ($event && $event['status'] === 'approved') || has_role($db, ROLE_ULAMA) || has_role($db, ROLE_ADMIN) || ($event && isset($_SESSION['user_id']) && $event['suggested_by_user_id'] == $_SESSION['user_id']);

                     if ($can_view) {
                         $event['ayahs'] = get_linked_ayahs($db, $event_id, null);
                         $event['hadiths'] = get_linked_hadiths($db, $event_id, null);
                         $response = ['success' => true, 'event' => $event];
                     } else {
                         $response = ['success' => false, 'message' => 'Event not found or unauthorized access.'];
                     }
                 } else {
                     $response = ['success' => false, 'message' => 'Event ID missing.'];
                 }
                 break;

             case 'get_location_details':
                 $location_id = $_GET['id'] ?? null;
                 if ($location_id) {
                     $location = get_location($db, $location_id);
                     if ($location) {
                         $location['events'] = get_events($db, ['location_id' => $location_id], 'approved'); // Only show approved events linked to location
                         $location['ayahs'] = get_linked_ayahs($db, null, $location['id']);
                         $location['hadiths'] = get_linked_hadiths($db, null, $location['id']);
                         $location['is_bookmarked'] = is_bookmarked($db, $_SESSION['user_id'] ?? null, $location['id']);
                         $response = ['success' => true, 'location' => $location];
                     } else {
                         $response = ['success' => false, 'message' => 'Location not found.'];
                     }
                 } else {
                      $response = ['success' => false, 'message' => 'Location ID missing.'];
                 }
                 break;

            case 'get_timeline_data':
                // Only show approved events on the timeline
                $events = get_events($db, [], 'approved');
                $response = ['success' => true, 'events' => $events];
                break;

            case 'get_chart_data':
                 // Example: Events per century (only approved)
                 $stmt = $db->query("SELECT strftime('%Y', date) / 100 * 100 AS century, COUNT(*) AS count FROM events WHERE date IS NOT NULL AND status = 'approved' GROUP BY century ORDER BY century");
                 $events_per_century = $stmt->fetchAll();

                 // Example: Event category distribution (only approved)
                 $stmt = $db->query("SELECT category, COUNT(*) AS count FROM events WHERE status = 'approved' GROUP BY category");
                 $category_distribution = $stmt->fetchAll();

                 $response = ['success' => true, 'events_per_century' => $events_per_century, 'category_distribution' => $category_distribution];
                 break;

            case 'search_ayahs':
                 $search_term = $_GET['query'] ?? '';
                 $ayahs = get_ayahs($db, ['search' => $search_term]);
                 $response = ['success' => true, 'ayahs' => $ayahs];
                 break;

            case 'search_hadiths':
                 $search_term = $_GET['query'] ?? '';
                 // Public/User only see approved hadiths, Ulama/Admin see all statuses for management
                 $status_filter = has_role($db, ROLE_ULAMA) ? null : 'approved';
                 $hadiths = get_hadiths($db, ['search' => $search_term], $status_filter);
                 $response = ['success' => true, 'hadiths' => $hadiths];
                 break;

            case 'add_bookmark':
                 require_role($db, ROLE_USER);
                 $location_id = $data['location_id'] ?? null;
                 if ($location_id) {
                     $stmt = $db->prepare("INSERT OR IGNORE INTO user_bookmarks (user_id, location_id) VALUES (:user_id, :location_id)");
                     $success = $stmt->execute([':user_id' => $_SESSION['user_id'], ':location_id' => $location_id]);
                     if ($success) {
                         // Add points for bookmarking
                         $db->prepare("UPDATE users SET points = points + 1 WHERE id = :user_id")->execute([':user_id' => $_SESSION['user_id']]);
                         $response = ['success' => true, 'message' => __('data_saved')]; // Use generic saved message
                     } else {
                         $response = ['success' => false, 'message' => 'Failed to add bookmark.'];
                     }
                 } else {
                     $response = ['success' => false, 'message' => 'Location ID missing.'];
                 }
                 break;

            case 'remove_bookmark':
                 require_role($db, ROLE_USER);
                 $location_id = $data['location_id'] ?? null;
                 if ($location_id) {
                     $stmt = $db->prepare("DELETE FROM user_bookmarks WHERE user_id = :user_id AND location_id = :location_id");
                     $success = $stmt->execute([':user_id' => $_SESSION['user_id'], ':location_id' => $location_id]);
                     if ($success) {
                          // Remove points for unbookmarking? Or just don't give points for adding. Let's not penalize.
                         $response = ['success' => true, 'message' => __('data_deleted')]; // Use generic deleted message
                     } else {
                         $response = ['success' => false, 'message' => 'Failed to remove bookmark.'];
                     }
                 } else {
                     $response = ['success' => false, 'message' => 'Location ID missing.'];
                 }
                 break;

             // --- Admin/Ulama/User Actions ---
             case 'add_event':
             case 'edit_event':
             case 'delete_event':
             case 'approve_event':
             case 'reject_event':
                 handle_event_action($db, $action, $data);
                 return; // handle_event_action outputs JSON and exits

             case 'add_location':
             case 'edit_location':
             case 'delete_location':
                 handle_location_action($db, $action, $data);
                 return; // handle_location_action outputs JSON and exits

             case 'link_ayah_to_event':
             case 'unlink_ayah_from_event':
             case 'link_ayah_to_location':
             case 'unlink_ayah_from_location':
                 handle_ayah_linking_action($db, $action, $data);
                 return; // outputs JSON and exits

             case 'add_hadith': // Ulama/Admin can add new Hadiths
             case 'edit_hadith':
             case 'delete_hadith':
             case 'approve_hadith': // Admin authenticates Ulama's hadiths
             case 'reject_hadith': // Admin rejects Ulama's hadiths
             case 'link_hadith_to_event':
             case 'unlink_hadith_from_event':
             case 'link_hadith_to_location':
             case 'unlink_hadith_from_location':
                 handle_hadith_action($db, $action, $data);
                 return; // outputs JSON and exits

             case 'admin_get_users':
                 require_role($db, ROLE_ADMIN);
                 $users = get_users($db);
                 $response = ['success' => true, 'users' => $users];
                 break;

             case 'admin_add_user':
             case 'admin_edit_user':
             case 'admin_delete_user':
                 handle_user_action($db, $action, $data);
                 return; // outputs JSON and exits

             case 'admin_backup_db':
                  require_role($db, ROLE_ADMIN);
                  $backup_file = BACKUP_PATH . 'islamic_history_backup_' . date('YmdHis') . '.sqlite';
                  // Use SQLite backup API for safer backup of live DB
                  try {
                       $db->sqliteCreateFunction('sqlite_backup', function($source_db, $dest_file) use ($db) {
                            $dest_db = new SQLite3($dest_file);
                            $source_db->backup($dest_db, 'main');
                            $dest_db->close();
                            return true; // Indicate success
                       });
                       // Call the backup function
                       $backup_success = $db->querySingle("SELECT sqlite_backup('main', '" . $db->escapeString($backup_file) . "')");

                       if ($backup_success) {
                           $response = ['success' => true, 'message' => 'Database backed up successfully.', 'file' => basename($backup_file)];
                       } else {
                           $response = ['success' => false, 'message' => 'Failed to create backup using SQLite API. Check directory permissions or SQLite version.'];
                       }
                  } catch (Exception $e) {
                       error_log("SQLite backup API error: " . $e->getMessage());
                       // Fallback to copy if API fails or is unavailable
                       if (copy(DB_PATH, $backup_file)) {
                           $response = ['success' => true, 'message' => 'Database backed up successfully (via file copy).', 'file' => basename($backup_file)];
                       } else {
                           error_log("Failed to create backup file via copy: " . $e->getMessage());
                           $response = ['success' => false, 'message' => 'Failed to create backup file. Check directory permissions.'];
                       }
                  }
                  break;

            case 'admin_get_backups':
                 require_role($db, ROLE_ADMIN);
                 $files = glob(BACKUP_PATH . '*.sqlite');
                 $backups = [];
                 if ($files) {
                     foreach ($files as $file) {
                          if (is_file($file)) { // Ensure it's a file
                             $backups[] = [
                                 'name' => basename($file),
                                 'size' => filesize($file),
                                 'date' => filemtime($file)
                             ];
                          }
                     }
                     // Sort by date descending
                     usort($backups, function($a, $b) { return $b['date'] - $a['date']; });
                 }
                 $response = ['success' => true, 'backups' => $backups];
                 break;

            case 'get_all_ayahs': // For linking interface
                 // Ulama and Admin can link Ayahs
                 if (!has_role($db, ROLE_ULAMA)) {
                     $response = ['success' => false, 'message' => 'Unauthorized access.'];
                     break;
                 }
                 $ayahs = get_ayahs($db);
                 $response = ['success' => true, 'ayahs' => $ayahs];
                 break;

            case 'get_all_hadiths': // For linking interface
                 // Ulama and Admin can link Hadiths
                 if (!has_role($db, ROLE_ULAMA)) {
                     $response = ['success' => false, 'message' => 'Unauthorized access.'];
                     break;
                 }
                 $hadiths = get_hadiths($db, [], null); // Get all statuses for management/linking
                 $response = ['success' => true, 'hadiths' => $hadiths];
                 break;

            case 'get_all_locations': // For event/ayah/hadith linking
                 // No specific role required to *list* locations for linking,
                 // but linking itself requires Ulama/Admin role.
                 $locations = get_locations($db); // Get all locations (approved status filter doesn't apply to locations)
                 $response = ['success' => true, 'locations' => $locations];
                 break;

             case 'get_linked_ayahs': // For linking modal - needs target type/id
                 $target_type = $_GET['target_type'] ?? null;
                 $target_id = $_GET['target_id'] ?? null;
                 if (!has_role($db, ROLE_ULAMA)) {
                     $response = ['success' => false, 'message' => 'Unauthorized access.'];
                     break;
                 }
                 if (!$target_type || !$target_id || !in_array($target_type, ['event', 'location'])) {
                      $response = ['success' => false, 'message' => 'Invalid target for linking.'];
                      break;
                 }
                 $linked_ayahs = get_linked_ayahs($db, $target_type === 'event' ? $target_id : null, $target_type === 'location' ? $target_id : null);
                 $response = ['success' => true, 'ayahs' => $linked_ayahs];
                 break;

              case 'get_linked_hadiths': // For linking modal - needs target type/id
                 $target_type = $_GET['target_type'] ?? null;
                 $target_id = $_GET['target_id'] ?? null;
                 if (!has_role($db, ROLE_ULAMA)) {
                     $response = ['success' => false, 'message' => 'Unauthorized access.'];
                     break;
                 }
                 if (!$target_type || !$target_id || !in_array($target_type, ['event', 'location'])) {
                      $response = ['success' => false, 'message' => 'Invalid target for linking.'];
                      break;
                 }
                 $linked_hadiths = get_linked_hadiths($db, $target_type === 'event' ? $target_id : null, $target_type === 'location' ? $target_id : null);
                 $response = ['success' => true, 'hadiths' => $linked_hadiths];
                 break;


            default:
                $response = ['success' => false, 'message' => 'Invalid AJAX action.'];
        }
    } catch (PDOException $e) {
        error_log("Database error in AJAX action '" . $action . "': " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
         error_log("Application error in AJAX action '" . $action . "': " . $e->getMessage());
         $response = ['success' => false, 'message' => 'Application error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

function handle_event_action($db, $action, $data) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        if (!$data) {
             throw new Exception("Invalid data received.");
        }

        switch ($action) {
            case 'add_event':
                // User can suggest (status=pending), Ulama/Admin can add (status=approved)
                if (!has_role($db, ROLE_USER)) {
                    throw new Exception("Unauthorized to add events.");
                }
                $status = has_role($db, ROLE_ULAMA) ? 'approved' : 'pending'; // Ulama/Admin adds directly, User suggests
                $user_id = $_SESSION['user_id'] ?? null;

                // Basic validation
                if (empty($data['title_en']) || empty($data['title_ur'])) {
                     throw new Exception("Event title (English and Urdu) is required.");
                }
                 if (!in_array($data['category'] ?? '', ['Islamic', 'General'])) {
                     $data['category'] = 'Islamic'; // Default or validate strictly
                 }
                 // Validate date format if provided
                 if (!empty($data['date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) {
                      $data['date'] = null; // Ignore invalid date
                 }
                 // Validate location_id if provided
                 $location_id = $data['location_id'] ?? null;
                 if ($location_id !== null) {
                     $stmt_loc = $db->prepare("SELECT COUNT(*) FROM locations WHERE id = :id");
                     $stmt_loc->execute([':id' => $location_id]);
                     if ($stmt_loc->fetchColumn() == 0) {
                         $location_id = null; // Ignore invalid location ID
                     }
                 }


                $stmt = $db->prepare("INSERT INTO events (title_en, title_ur, description_en, description_ur, date, category, location_id, suggested_by_user_id, status) VALUES (:title_en, :title_ur, :description_en, :description_ur, :date, :category, :location_id, :suggested_by_user_id, :status)");
                $stmt->execute([
                    ':title_en' => trim($data['title_en']),
                    ':title_ur' => trim($data['title_ur']),
                    ':description_en' => trim($data['description_en'] ?? '') ?: null,
                    ':description_ur' => trim($data['description_ur'] ?? '') ?: null,
                    ':date' => $data['date'],
                    ':category' => $data['category'],
                    ':location_id' => $location_id,
                    ':suggested_by_user_id' => $user_id,
                    ':status' => $status
                ]);
                 $event_id = $db->lastInsertId();

                 // Add points for suggesting/adding
                 if ($user_id) {
                     $points_earned = ($status === 'approved') ? 10 : 5; // More points for authenticated add/direct add
                     $db->prepare("UPDATE users SET points = points + :points WHERE id = :user_id")->execute([':points' => $points_earned, ':user_id' => $user_id]);
                 }

                $response = ['success' => true, 'message' => ($status === 'approved' ? __('data_saved') : __('data_saved')) . ' (' . __($status) . ')', 'event_id' => $event_id, 'status' => $status];
                break;

            case 'edit_event':
                // Ulama/Admin can edit any approved event. User can edit their own pending suggestions.
                 $event_id = $data['id'] ?? null;
                 if (!$event_id) throw new Exception("Event ID missing.");

                 $event = get_event($db, $event_id);

                 if (!$event) {
                     throw new Exception("Event not found.");
                 }

                 $can_edit = false;
                 if (has_role($db, ROLE_ADMIN) || has_role($db, ROLE_ULAMA)) {
                     $can_edit = true; // Admin/Ulama can edit any
                 } elseif (isset($_SESSION['user_id']) && $event['suggested_by_user_id'] == $_SESSION['user_id'] && $event['status'] === 'pending') {
                     $can_edit = true; // User can edit their own pending suggestion
                 }

                 if (!$can_edit) {
                     throw new Exception("Unauthorized to edit this event.");
                 }

                 // Basic validation for submitted fields
                 $update_fields = [];
                 $params = [':id' => $event_id];

                 if (isset($data['title_en'])) { $update_fields[] = "title_en = :title_en"; $params[':title_en'] = trim($data['title_en']); }
                 if (isset($data['title_ur'])) { $update_fields[] = "title_ur = :title_ur"; $params[':title_ur'] = trim($data['title_ur']); }
                 if (isset($data['description_en'])) { $update_fields[] = "description_en = :description_en"; $params[':description_en'] = trim($data['description_en']) ?: null; }
                 if (isset($data['description_ur'])) { $update_fields[] = "description_ur = :description_ur"; $params[':description_ur'] = trim($data['description_ur']) ?: null; }
                 if (isset($data['date'])) {
                      // Validate date format if provided
                      if (!empty($data['date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) {
                           $data['date'] = null; // Ignore invalid date
                      }
                      $update_fields[] = "date = :date"; $params[':date'] = $data['date'];
                 }
                 if (isset($data['category']) && in_array($data['category'], ['Islamic', 'General'])) { $update_fields[] = "category = :category"; $params[':category'] = $data['category']; }
                 if (isset($data['location_id'])) {
                      // Validate location_id if provided
                      $location_id = $data['location_id'];
                      if ($location_id !== null) {
                          $stmt_loc = $db->prepare("SELECT COUNT(*) FROM locations WHERE id = :id");
                          $stmt_loc->execute([':id' => $location_id]);
                          if ($stmt_loc->fetchColumn() == 0) {
                              $location_id = null; // Ignore invalid location ID
                          }
                      }
                     $update_fields[] = "location_id = :location_id"; $params[':location_id'] = $location_id;
                 }

                 if (empty($update_fields)) {
                     throw new Exception("No fields to update.");
                 }

                 $sql = "UPDATE events SET " . implode(', ', $update_fields) . " WHERE id = :id";
                 $stmt = $db->prepare($sql);
                 $stmt->execute($params);

                 $response = ['success' => true, 'message' => __('data_saved')];
                 break;

            case 'delete_event':
                 // Admin can delete any. Ulama can delete approved events they added? User can delete their own pending suggestions.
                 $event_id = $data['id'] ?? null;
                 if (!$event_id) throw new Exception("Event ID missing.");

                 $event = get_event($db, $event_id);

                 if (!$event) {
                     throw new Exception("Event not found.");
                 }

                 $can_delete = false;
                 if (has_role($db, ROLE_ADMIN)) {
                     $can_delete = true; // Admin can delete any
                 } elseif (has_role($db, ROLE_ULAMA) && $event['status'] === 'approved' && ($event['suggested_by_user_id'] == ($_SESSION['user_id'] ?? null) || $event['suggested_by_user_id'] === null)) {
                     // Ulama can delete approved events they added or that weren't suggested by a user
                     $can_delete = true;
                 } elseif (isset($_SESSION['user_id']) && $event['suggested_by_user_id'] == $_SESSION['user_id'] && $event['status'] === 'pending') {
                     $can_delete = true; // User can delete their own pending suggestion
                 }

                 if (!$can_delete) {
                     throw new Exception("Unauthorized to delete this event.");
                 }

                 // Also delete links in linking tables (ON DELETE CASCADE handles this if set up)
                 // $db->prepare("DELETE FROM event_ayahs WHERE event_id = :event_id")->execute([':event_id' => $event_id]);
                 // $db->prepare("DELETE FROM event_hadiths WHERE event_id = :event_id")->execute([':event_id' => $event_id]);

                 $stmt = $db->prepare("DELETE FROM events WHERE id = :id");
                 $stmt->execute([':id' => $event_id]);

                 $response = ['success' => true, 'message' => __('data_deleted')];
                 break;

            case 'approve_event':
                 require_role($db, ROLE_ULAMA); // Ulama or Admin can approve
                 $event_id = $data['id'] ?? null;
                 if (!$event_id) throw new Exception("Event ID missing.");

                 $event = get_event($db, $event_id);

                 if (!$event || $event['status'] !== 'pending') {
                     throw new Exception("Event not found or not pending approval.");
                 }

                 $stmt = $db->prepare("UPDATE events SET status = 'approved' WHERE id = :id");
                 $stmt->execute([':id' => $event_id]);

                 // Add points to the suggester if they exist
                 if ($event['suggested_by_user_id']) {
                      $db->prepare("UPDATE users SET points = points + 5 WHERE id = :user_id")->execute([':user_id' => $event['suggested_by_user_id']]); // Points for approval
                 }

                 $response = ['success' => true, 'message' => 'Event approved.'];
                 break;

            case 'reject_event':
                 require_role($db, ROLE_ULAMA); // Ulama or Admin can reject
                 $event_id = $data['id'] ?? null;
                 if (!$event_id) throw new Exception("Event ID missing.");

                 $event = get_event($db, $event_id);

                 if (!$event || $event['status'] !== 'pending') {
                     throw new Exception("Event not found or not pending approval.");
                 }

                 $stmt = $db->prepare("UPDATE events SET status = 'rejected' WHERE id = :id");
                 $stmt->execute([':id' => $event_id]);

                 // Optionally penalize suggester or just don't give points

                 $response = ['success' => true, 'message' => 'Event rejected.'];
                 break;

            default:
                throw new Exception("Invalid event action.");
        }
    } catch (PDOException $e) {
        error_log("Database error in event action '" . $action . "': " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
         error_log("Application error in event action '" . $action . "': " . $e->getMessage());
         $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

function handle_location_action($db, $action, $data) {
     header('Content-Type: application/json');
     $response = ['success' => false, 'message' => ''];
     // Actions vary by role

    try {
        if (!$data) {
             throw new Exception("Invalid data received.");
        }

        switch ($action) {
            case 'add_location':
                 // Only Ulama/Admin can add locations
                 require_role($db, ROLE_ULAMA);

                 // Basic validation
                 if (empty($data['name_en']) || empty($data['name_ur'])) {
                     throw new Exception("Location name (English and Urdu) is required.");
                 }
                 if (!isset($data['latitude']) || !is_numeric($data['latitude']) || !isset($data['longitude']) || !is_numeric($data['longitude'])) {
                      throw new Exception("Valid latitude and longitude are required.");
                 }

                 $stmt = $db->prepare("INSERT INTO locations (name_en, name_ur, description_en, description_ur, latitude, longitude) VALUES (:name_en, :name_ur, :description_en, :description_ur, :latitude, :longitude)");
                 $stmt->execute([
                     ':name_en' => trim($data['name_en']),
                     ':name_ur' => trim($data['name_ur']),
                     ':description_en' => trim($data['description_en'] ?? '') ?: null,
                     ':description_ur' => trim($data['description_ur'] ?? '') ?: null,
                     ':latitude' => (float)$data['latitude'],
                     ':longitude' => (float)$data['longitude']
                 ]);
                 $location_id = $db->lastInsertId();

                 // Add points for adding location
                 if (isset($_SESSION['user_id'])) {
                     $db->prepare("UPDATE users SET points = points + 10 WHERE id = :user_id")->execute([':user_id' => $_SESSION['user_id']]);
                 }

                 $response = ['success' => true, 'message' => __('data_saved'), 'location_id' => $location_id];
                 break;

            case 'edit_location':
                 // Only Ulama/Admin can edit locations
                 require_role($db, ROLE_ULAMA);
                 $location_id = $data['id'] ?? null;
                 if (!$location_id) throw new Exception("Location ID missing.");

                 $location = get_location($db, $location_id);

                 if (!$location) {
                     throw new Exception("Location not found.");
                 }

                 // Basic validation for submitted fields
                 $update_fields = [];
                 $params = [':id' => $location_id];

                 if (isset($data['name_en'])) { $update_fields[] = "name_en = :name_en"; $params[':name_en'] = trim($data['name_en']); }
                 if (isset($data['name_ur'])) { $update_fields[] = "name_ur = :name_ur"; $params[':name_ur'] = trim($data['name_ur']); }
                 if (isset($data['description_en'])) { $update_fields[] = "description_en = :description_en"; $params[':description_en'] = trim($data['description_en'] ?? '') ?: null; }
                 if (isset($data['description_ur'])) { $update_fields[] = "description_ur = :description_ur"; $params[':description_ur'] = trim($data['description_ur'] ?? '') ?: null; }
                 if (isset($data['latitude']) && is_numeric($data['latitude'])) { $update_fields[] = "latitude = :latitude"; $params[':latitude'] = (float)$data['latitude']; }
                 if (isset($data['longitude']) && is_numeric($data['longitude'])) { $update_fields[] = "longitude = :longitude"; $params[':longitude'] = (float)$data['longitude']; }

                 if (empty($update_fields)) {
                     throw new Exception("No fields to update.");
                 }

                 $sql = "UPDATE locations SET " . implode(', ', $update_fields) . " WHERE id = :id";
                 $stmt = $db->prepare($sql);
                 $stmt->execute($params);

                 $response = ['success' => true, 'message' => __('data_saved')];
                 break;

            case 'delete_location':
                 require_role($db, ROLE_ADMIN); // Only Admin can delete locations (to prevent breaking event links easily)
                 $location_id = $data['id'] ?? null;
                 if (!$location_id) throw new Exception("Location ID missing.");

                 $location = get_location($db, $location_id);

                 if (!$location) {
                     throw new Exception("Location not found.");
                 }

                 // Check for linked events before deleting
                 $stmt_event_count = $db->prepare("SELECT COUNT(*) FROM events WHERE location_id = :location_id");
                 $stmt_event_count->execute([':location_id' => $location_id]);
                 $event_count = $stmt_event_count->fetchColumn();

                 if ($event_count > 0) {
                     throw new Exception("Cannot delete location with linked events. Unlink events first.");
                 }

                 // Delete links in linking tables (ON DELETE CASCADE handles this if set up)
                 // $db->prepare("DELETE FROM location_ayahs WHERE location_id = :location_id")->execute([':location_id' => $location_id]);
                 // $db->prepare("DELETE FROM location_hadiths WHERE location_id = :location_id")->execute([':location_id' => $location_id]);
                 // $db->prepare("DELETE FROM user_bookmarks WHERE location_id = :location_id")->execute([':location_id' => $location_id]);


                 $stmt = $db->prepare("DELETE FROM locations WHERE id = :id");
                 $stmt->execute([':id' => $location_id]);

                 $response = ['success' => true, 'message' => __('data_deleted')];
                 break;

            default:
                throw new Exception("Invalid location action.");
        }
    } catch (PDOException $e) {
        error_log("Database error in location action '" . $action . "': " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
         error_log("Application error in location action '" . $action . "': " . $e->getMessage());
         $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

function handle_ayah_linking_action($db, $action, $data) {
     header('Content-Type: application/json');
     $response = ['success' => false, 'message' => ''];
     require_role($db, ROLE_ULAMA); // Only Ulama/Admin can link Ayahs

    try {
        if (!$data) {
             throw new Exception("Invalid data received.");
        }

        $ayah_id = $data['ayah_id'] ?? null;
        $event_id = $data['event_id'] ?? null;
        $location_id = $data['location_id'] ?? null;

        if (!$ayah_id || (!$event_id && !$location_id)) {
             throw new Exception("Missing ayah_id or target (event_id/location_id).");
        }

        // Validate Ayah ID exists
        $stmt_ayah = $db->prepare("SELECT COUNT(*) FROM ayahs WHERE id = :id");
        $stmt_ayah->execute([':id' => $ayah_id]);
        if ($stmt_ayah->fetchColumn() == 0) {
             throw new Exception("Invalid Ayah ID.");
        }

        if ($event_id !== null) {
             // Validate Event ID exists
             $stmt_event = $db->prepare("SELECT COUNT(*) FROM events WHERE id = :id");
             $stmt_event->execute([':id' => $event_id]);
             if ($stmt_event->fetchColumn() == 0) {
                  throw new Exception("Invalid Event ID.");
             }
        }

         if ($location_id !== null) {
             // Validate Location ID exists
             $stmt_loc = $db->prepare("SELECT COUNT(*) FROM locations WHERE id = :id");
             $stmt_loc->execute([':id' => $location_id]);
             if ($stmt_loc->fetchColumn() == 0) {
                  throw new Exception("Invalid Location ID.");
             }
         }


        switch ($action) {
            case 'link_ayah_to_event':
                 if ($event_id === null) throw new Exception("Event ID missing for linking.");
                 $stmt = $db->prepare("INSERT OR IGNORE INTO event_ayahs (event_id, ayah_id) VALUES (:event_id, :ayah_id)");
                 $stmt->execute([':event_id' => $event_id, ':ayah_id' => $ayah_id]);
                 $response = ['success' => true, 'message' => 'Ayah linked to event.'];
                 break;
            case 'unlink_ayah_from_event':
                 if ($event_id === null) throw new Exception("Event ID missing for unlinking.");
                 $stmt = $db->prepare("DELETE FROM event_ayahs WHERE event_id = :event_id AND ayah_id = :ayah_id");
                 $stmt->execute([':event_id' => $event_id, ':ayah_id' => $ayah_id]);
                 $response = ['success' => true, 'message' => 'Ayah unlinked from event.'];
                 break;
            case 'link_ayah_to_location':
                 if ($location_id === null) throw new Exception("Location ID missing for linking.");
                 $stmt = $db->prepare("INSERT OR IGNORE INTO location_ayahs (location_id, ayah_id) VALUES (:location_id, :ayah_id)");
                 $stmt->execute([':location_id' => $location_id, ':ayah_id' => $ayah_id]);
                 $response = ['success' => true, 'message' => 'Ayah linked to location.'];
                 break;
            case 'unlink_ayah_from_location':
                 if ($location_id === null) throw new Exception("Location ID missing for unlinking.");
                 $stmt = $db->prepare("DELETE FROM location_ayahs WHERE location_id = :location_id AND ayah_id = :ayah_id");
                 $stmt->execute([':location_id' => $location_id, ':ayah_id' => $ayah_id]);
                 $response = ['success' => true, 'message' => 'Ayah unlinked from location.'];
                 break;

            default:
                throw new Exception("Invalid ayah linking action.");
        }
    } catch (PDOException $e) {
        error_log("Database error in ayah linking action '" . $action . "': " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
         error_log("Application error in ayah linking action '" . $action . "': " . $e->getMessage());
         $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

function handle_hadith_action($db, $action, $data) {
     header('Content-Type: application/json');
     $response = ['success' => false, 'message' => ''];
     // Actions vary by role

    try {
        if (!$data) {
             throw new Exception("Invalid data received.");
        }

        switch ($action) {
            case 'add_hadith':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can add
                 $status = has_role($db, ROLE_ADMIN) ? 'approved' : 'pending'; // Admin adds directly, Ulama suggests for authentication
                 $user_id = $_SESSION['user_id'] ?? null;

                 // Basic validation
                 if (empty($data['text_en']) || empty($data['text_ur'])) {
                     throw new Exception("Hadith text (English and Urdu) is required.");
                 }

                 $stmt = $db->prepare("INSERT INTO hadiths (book_en, book_ur, chapter_en, chapter_ur, text_en, text_ur, reference, authenticated_by_ulama_id, status) VALUES (:book_en, :book_ur, :chapter_en, :chapter_ur, :text_en, :text_ur, :reference, :authenticated_by_ulama_id, :status)");
                 $stmt->execute([
                     ':book_en' => trim($data['book_en'] ?? '') ?: null,
                     ':book_ur' => trim($data['book_ur'] ?? '') ?: null,
                     ':chapter_en' => trim($data['chapter_en'] ?? '') ?: null,
                     ':chapter_ur' => trim($data['chapter_ur'] ?? '') ?: null,
                     ':text_en' => trim($data['text_en']),
                     ':text_ur' => trim($data['text_ur']),
                     ':reference' => trim($data['reference'] ?? '') ?: null,
                     ':authenticated_by_ulama_id' => ($status === 'approved' && has_role($db, ROLE_ULAMA)) ? $user_id : null, // Mark Ulama as authenticator if they add and it's approved immediately (Admin)
                     ':status' => $status
                 ]);
                 $hadith_id = $db->lastInsertId();

                 // Add points
                 if ($user_id) {
                      $points_earned = ($status === 'approved') ? 10 : 5; // More points for authenticated add/direct add
                     $db->prepare("UPDATE users SET points = points + :points WHERE id = :user_id")->execute([':points' => $points_earned, ':user_id' => $user_id]);
                 }

                 $response = ['success' => true, 'message' => ($status === 'approved' ? __('data_saved') : __('data_saved')) . ' (' . __($status) . ')', 'hadith_id' => $hadith_id, 'status' => $status];
                 break;

            case 'edit_hadith':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can edit
                 $hadith_id = $data['id'] ?? null;
                 if (!$hadith_id) throw new Exception("Hadith ID missing.");

                 $stmt_hadith = $db->prepare("SELECT * FROM hadiths WHERE id = :id");
                 $stmt_hadith->execute([':id' => $hadith_id]);
                 $hadith = $stmt_hadith->fetch();

                 if (!$hadith) {
                     throw new Exception("Hadith not found.");
                 }

                 // Ulama can edit their own pending or approved hadiths. Admin can edit any.
                 $can_edit = false;
                 if (has_role($db, ROLE_ADMIN)) {
                     $can_edit = true;
                 } elseif (isset($_SESSION['user_id']) && $hadith['authenticated_by_ulama_id'] == $_SESSION['user_id']) {
                     $can_edit = true;
                 }

                 if (!$can_edit) {
                     throw new Exception("Unauthorized to edit this hadith.");
                 }

                 // Basic validation for submitted fields
                 $update_fields = [];
                 $params = [':id' => $hadith_id];

                 if (isset($data['book_en'])) { $update_fields[] = "book_en = :book_en"; $params[':book_en'] = trim($data['book_en'] ?? '') ?: null; }
                 if (isset($data['book_ur'])) { $update_fields[] = "book_ur = :book_ur"; $params[':book_ur'] = trim($data['book_ur'] ?? '') ?: null; }
                 if (isset($data['chapter_en'])) { $update_fields[] = "chapter_en = :chapter_en"; $params[':chapter_en'] = trim($data['chapter_en'] ?? '') ?: null; }
                 if (isset($data['chapter_ur'])) { $update_fields[] = "chapter_ur = :chapter_ur"; $params[':chapter_ur'] = trim($data['chapter_ur'] ?? '') ?: null; }
                 if (isset($data['reference'])) { $update_fields[] = "reference = :reference"; $params[':reference'] = trim($data['reference'] ?? '') ?: null; }
                 if (isset($data['text_en'])) { $update_fields[] = "text_en = :text_en"; $params[':text_en'] = trim($data['text_en']); }
                 if (isset($data['text_ur'])) { $update_fields[] = "text_ur = :text_ur"; $params[':text_ur'] = trim($data['text_ur']); }

                 if (empty($update_fields)) {
                     throw new Exception("No fields to update.");
                 }

                 $sql = "UPDATE hadiths SET " . implode(', ', $update_fields) . " WHERE id = :id";
                 $stmt = $db->prepare($sql);
                 $stmt->execute($params);

                 $response = ['success' => true, 'message' => __('data_saved')];
                 break;

            case 'delete_hadith':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can delete
                 $hadith_id = $data['id'] ?? null;
                 if (!$hadith_id) throw new Exception("Hadith ID missing.");

                 $stmt_hadith = $db->prepare("SELECT * FROM hadiths WHERE id = :id");
                 $stmt_hadith->execute([':id' => $hadith_id]);
                 $hadith = $stmt_hadith->fetch();

                 if (!$hadith) {
                     throw new Exception("Hadith not found.");
                 }

                 // Ulama can delete their own. Admin can delete any.
                 $can_delete = false;
                 if (has_role($db, ROLE_ADMIN)) {
                     $can_delete = true;
                 } elseif (isset($_SESSION['user_id']) && $hadith['authenticated_by_ulama_id'] == $_SESSION['user_id']) {
                     $can_delete = true;
                 }

                 if (!$can_delete) {
                     throw new Exception("Unauthorized to delete this hadith.");
                 }

                 // Delete links (ON DELETE CASCADE handles this if set up)
                 // $db->prepare("DELETE FROM event_hadiths WHERE hadith_id = :hadith_id")->execute([':hadith_id' => $hadith_id]);
                 // $db->prepare("DELETE FROM location_hadiths WHERE hadith_id = :hadith_id")->execute([':hadith_id' => $hadith_id]);

                 $stmt = $db->prepare("DELETE FROM hadiths WHERE id = :id");
                 $stmt->execute([':id' => $hadith_id]);

                 $response = ['success' => true, 'message' => __('data_deleted')];
                 break;

            case 'approve_hadith': // Admin authenticates Ulama's suggestions
                 require_role($db, ROLE_ADMIN);
                 $hadith_id = $data['id'] ?? null;
                 if (!$hadith_id) throw new Exception("Hadith ID missing.");

                 $stmt_hadith = $db->prepare("SELECT * FROM hadiths WHERE id = :id");
                 $stmt_hadith->execute([':id' => $hadith_id]);
                 $hadith = $stmt_hadith->fetch();

                 if (!$hadith || $hadith['status'] !== 'pending') {
                     throw new Exception("Hadith not found or not pending authentication.");
                 }

                 // Determine the authenticator ID
                 // If an Ulama suggested it, they become the authenticator upon Admin approval.
                 // If Admin added it directly (status would be approved), this case wouldn't apply.
                 // If a User suggested it (not possible with current add_hadith logic), this would need refinement.
                 // Assuming pending hadiths come from Ulama suggestions:
                 $authenticator_id = $hadith['authenticated_by_ulama_id'] ?? $_SESSION['user_id']; // Use Ulama suggester ID or Admin ID if none set

                 $stmt = $db->prepare("UPDATE hadiths SET status = 'approved', authenticated_by_ulama_id = :auth_id WHERE id = :id");
                 $stmt->execute([':id' => $hadith_id, ':auth_id' => $authenticator_id]);

                  // Add points to the authenticator
                 if ($authenticator_id) {
                      $db->prepare("UPDATE users SET points = points + 5 WHERE id = :user_id")->execute([':user_id' => $authenticator_id]); // Points for authentication
                 }

                 $response = ['success' => true, 'message' => 'Hadith authenticated.'];
                 break;

            case 'reject_hadith': // Admin rejects Ulama's suggestions
                 require_role($db, ROLE_ADMIN);
                 $hadith_id = $data['id'] ?? null;
                 if (!$hadith_id) throw new Exception("Hadith ID missing.");

                 $stmt_hadith = $db->prepare("SELECT * FROM hadiths WHERE id = :id");
                 $stmt_hadith->execute([':id' => $hadith_id]);
                 $hadith = $stmt_hadith->fetch();

                 if (!$hadith || $hadith['status'] !== 'pending') {
                     throw new Exception("Hadith not found or not pending authentication.");
                 }

                 $stmt = $db->prepare("UPDATE hadiths SET status = 'rejected' WHERE id = :id");
                 $stmt->execute([':id' => $hadith_id]);

                 $response = ['success' => true, 'message' => 'Hadith rejected.'];
                 break;

            case 'link_hadith_to_event':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can link
                 $hadith_id = $data['hadith_id'] ?? null;
                 $event_id = $data['event_id'] ?? null;
                 if (!$hadith_id || !$event_id) throw new Exception("Missing IDs.");

                 // Validate IDs exist and hadith is approved
                 $stmt_hadith = $db->prepare("SELECT COUNT(*) FROM hadiths WHERE id = :id AND status = 'approved'");
                 $stmt_hadith->execute([':id' => $hadith_id]);
                 if ($stmt_hadith->fetchColumn() == 0) {
                      throw new Exception("Invalid or unapproved Hadith ID.");
                 }
                 $stmt_event = $db->prepare("SELECT COUNT(*) FROM events WHERE id = :id");
                 $stmt_event->execute([':id' => $event_id]);
                 if ($stmt_event->fetchColumn() == 0) {
                      throw new Exception("Invalid Event ID.");
                 }

                 $stmt = $db->prepare("INSERT OR IGNORE INTO event_hadiths (event_id, hadith_id) VALUES (:event_id, :hadith_id)");
                 $stmt->execute([':event_id' => $event_id, ':hadith_id' => $hadith_id]);
                 $response = ['success' => true, 'message' => 'Hadith linked to event.'];
                 break;
            case 'unlink_hadith_from_event':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can unlink
                 $hadith_id = $data['hadith_id'] ?? null;
                 $event_id = $data['event_id'] ?? null;
                 if (!$hadith_id || !$event_id) throw new Exception("Missing IDs.");
                 $stmt = $db->prepare("DELETE FROM event_hadiths WHERE event_id = :event_id AND hadith_id = :hadith_id");
                 $stmt->execute([':event_id' => $event_id, ':hadith_id' => $hadith_id]);
                 $response = ['success' => true, 'message' => 'Hadith unlinked from event.'];
                 break;
            case 'link_hadith_to_location':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can link
                 $hadith_id = $data['hadith_id'] ?? null;
                 $location_id = $data['location_id'] ?? null;
                 if (!$hadith_id || !$location_id) throw new Exception("Missing IDs.");

                 // Validate IDs exist and hadith is approved
                 $stmt_hadith = $db->prepare("SELECT COUNT(*) FROM hadiths WHERE id = :id AND status = 'approved'");
                 $stmt_hadith->execute([':id' => $hadith_id]);
                 if ($stmt_hadith->fetchColumn() == 0) {
                      throw new Exception("Invalid or unapproved Hadith ID.");
                 }
                 $stmt_loc = $db->prepare("SELECT COUNT(*) FROM locations WHERE id = :id");
                 $stmt_loc->execute([':id' => $location_id]);
                 if ($stmt_loc->fetchColumn() == 0) {
                      throw new Exception("Invalid Location ID.");
                 }

                 $stmt = $db->prepare("INSERT OR IGNORE INTO location_hadiths (location_id, hadith_id) VALUES (:location_id, :hadith_id)");
                 $stmt->execute([':location_id' => $location_id, ':hadith_id' => $hadith_id]);
                 $response = ['success' => true, 'message' => 'Hadith linked to location.'];
                 break;
            case 'unlink_hadith_from_location':
                 require_role($db, ROLE_ULAMA); // Ulama/Admin can unlink
                 $hadith_id = $data['hadith_id'] ?? null;
                 $location_id = $data['location_id'] ?? null;
                 if (!$hadith_id || !$location_id) throw new Exception("Missing IDs.");
                 $stmt = $db->prepare("DELETE FROM location_hadiths WHERE location_id = :location_id AND hadith_id = :hadith_id");
                 $stmt->execute([':location_id' => $location_id, ':hadith_id' => $hadith_id]);
                 $response = ['success' => true, 'message' => 'Hadith unlinked from location.'];
                 break;

            default:
                throw new Exception("Invalid hadith action.");
        }
    } catch (PDOException $e) {
        error_log("Database error in hadith action '" . $action . "': " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
         error_log("Application error in hadith action '" . $action . "': " . $e->getMessage());
         $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}


function handle_user_action($db, $action, $data) {
     header('Content-Type: application/json');
     $response = ['success' => false, 'message' => ''];
     require_role($db, ROLE_ADMIN); // Only Admin can manage users

     try {
        if (!$data) {
             throw new Exception("Invalid data received.");
        }

        switch ($action) {
            case 'admin_add_user':
                 $username = trim($data['username'] ?? '');
                 $password = $data['password'] ?? null;
                 $role_id = $data['role_id'] ?? null;

                 if (empty($username) || empty($password) || empty($role_id)) {
                     throw new Exception("Missing username, password, or role.");
                 }

                 // Check if username exists
                 $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                 $stmt->execute([':username' => $username]);
                 if ($stmt->fetchColumn() > 0) {
                     throw new Exception("Username already exists.");
                 }

                 // Check if role_id is valid (exists in roles table and is not Public)
                 $stmt_role = $db->prepare("SELECT COUNT(*) FROM roles WHERE id = :id AND name != :public_role");
                 $stmt_role->execute([':id' => $role_id, ':public_role' => ROLE_PUBLIC]);
                 if ($stmt_role->fetchColumn() == 0) {
                     throw new Exception("Invalid role selected.");
                 }


                 $stmt = $db->prepare("INSERT INTO users (username, password, role_id) VALUES (:username, :password, :role_id)");
                 $stmt->execute([
                     ':username' => $username,
                     ':password' => password_hash($password, PASSWORD_DEFAULT),
                     ':role_id' => $role_id
                 ]);

                 $response = ['success' => true, 'message' => __('data_saved')];
                 break;

            case 'admin_edit_user':
                 $user_id = $data['id'] ?? null;
                 $username = trim($data['username'] ?? ''); // Allow empty string to check if provided
                 $password = $data['password'] ?? null; // Optional
                 $role_id = $data['role_id'] ?? null;

                 if (!$user_id) {
                     throw new Exception("Missing user ID.");
                 }

                 // Prevent changing the default admin's role or deleting them via this interface
                 $default_admin_id = $db->query("SELECT id FROM users WHERE username = '" . DEFAULT_ADMIN_USER . "' LIMIT 1")->fetchColumn();
                 $admin_role_id = $db->query("SELECT id FROM roles WHERE name = '" . ROLE_ADMIN . "' LIMIT 1")->fetchColumn();

                 if ($user_id == $default_admin_id) {
                      if ($role_id !== null && $role_id != $admin_role_id) {
                           throw new Exception("Cannot change the role of the default admin user.");
                      }
                      // Prevent changing username of default admin? Or just be careful. Let's allow changing username but not role or deletion.
                 }


                 $update_fields = [];
                 $params = [':id' => $user_id];

                 if ($username !== '') { // Check if username was provided (not just empty after trim)
                      // Check if new username exists (excluding current user)
                     $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND id != :id");
                     $stmt->execute([':username' => $username, ':id' => $user_id]);
                     if ($stmt->fetchColumn() > 0) {
                         throw new Exception("Username already exists.");
                     }
                     $update_fields[] = "username = :username";
                     $params[':username'] = $username;
                 }
                 if ($password !== null && $password !== '') {
                     $update_fields[] = "password = :password";
                     $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                 }
                 if ($role_id !== null) {
                      // Check if role_id is valid (exists in roles table and is not Public)
                     $stmt_role = $db->prepare("SELECT COUNT(*) FROM roles WHERE id = :id AND name != :public_role");
                     $stmt_role->execute([':id' => $role_id, ':public_role' => ROLE_PUBLIC]);
                     if ($stmt_role->fetchColumn() == 0) {
                         throw new Exception("Invalid role selected.");
                     }
                     $update_fields[] = "role_id = :role_id";
                     $params[':role_id'] = $role_id;
                 }

                 if (empty($update_fields)) {
                     throw new Exception("No fields to update.");
                 }

                 $sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = :id";
                 $stmt = $db->prepare($sql);
                 $stmt->execute($params);

                 $response = ['success' => true, 'message' => __('data_saved')];
                 break;

            case 'admin_delete_user':
                 $user_id = $data['id'] ?? null;

                 if (!$user_id) {
                     throw new Exception("Missing user ID.");
                 }

                 // Prevent deleting the default admin user
                 $default_admin_id = $db->query("SELECT id FROM users WHERE username = '" . DEFAULT_ADMIN_USER . "' LIMIT 1")->fetchColumn();
                 if ($user_id == $default_admin_id) {
                      throw new Exception("Cannot delete the default admin user.");
                 }

                 // Delete user-related data (bookmarks, suggested events, etc.) - ON DELETE CASCADE handles this if set up
                 // $db->prepare("DELETE FROM user_bookmarks WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
                 // Events suggested by this user might be kept with user_id set to NULL, or deleted. Let's set user_id to NULL.
                 $db->prepare("UPDATE events SET suggested_by_user_id = NULL WHERE suggested_by_user_id = :user_id")->execute([':user_id' => $user_id]);
                 // Hadiths authenticated by this Ulama might be kept with user_id set to NULL.
                 $db->prepare("UPDATE hadiths SET authenticated_by_ulama_id = NULL WHERE authenticated_by_ulama_id = :user_id")->execute([':user_id' => $user_id]);


                 $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
                 $stmt->execute([':id' => $user_id]);

                 $response = ['success' => true, 'message' => __('data_deleted')];
                 break;

            default:
                throw new Exception("Invalid user action.");
        }
    } catch (PDOException $e) {
        error_log("Database error in user action '" . $action . "': " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
         error_log("Application error in user action '" . $action . "': " . $e->getMessage());
         $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}


// =============================================================================
// 7. Main Request Handling
// =============================================================================

$db = get_db();
// initialize_database($db); // Called inside get_db if file doesn't exist
load_quran_data($db); // Load Quran data on first run if DB is new/empty

$current_action = $_GET['action'] ?? 'home';
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle AJAX requests
if ($is_ajax_request) {
    handle_ajax_request($db);
    // handle_event_action, handle_location_action, etc. also exit
    exit(); // Should not be reached if AJAX handlers exit
}

// Handle standard form POSTs that don't use AJAX (e.g., file upload for restore)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'login':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $stmt = $db->prepare("SELECT u.id, u.password, r.name as role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $user['role'];
                $_SESSION['success_message'] = "Logged in as " . htmlspecialchars($username) . " (" . __($user['role'] . '_role') . ").";
                header("Location: ?"); // Redirect to home
                exit();
            } else {
                $_SESSION['error_message'] = "Invalid username or password.";
                header("Location: ?action=login");
                exit();
            }
            break;

        case 'logout':
            session_unset();
            session_destroy();
            // Clear session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            $_SESSION['success_message'] = "Logged out successfully.";
            header("Location: ?"); // Redirect to home
            exit();
            break;

        case 'admin_restore_db_upload':
            require_role($db, ROLE_ADMIN);
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['backup_file'];
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);

                if ($file_extension !== 'sqlite') {
                     $_SESSION['error_message'] = "Invalid file type. Only .sqlite files are allowed.";
                } elseif (!is_writable(dirname(DB_PATH))) {
                     $_SESSION['error_message'] = "Database directory is not writable.";
                } else {
                    // Use SQLite backup API for safer restore if possible
                    // This requires the SQLite3 class and the backup method
                    if (class_exists('SQLite3')) {
                         try {
                             // Close current PDO connection
                             $db = null;

                             // Open the uploaded file using SQLite3
                             $uploaded_db = new SQLite3($file['tmp_name'], SQLITE3_OPEN_READONLY);

                             // Create a temporary path for the current DB file
                             $old_db_temp = dirname(DB_PATH) . '/islamic_history_old_' . date('YmdHis') . '.sqlite';

                             // Rename current DB file as a temporary backup
                             $rename_success = rename(DB_PATH, $old_db_temp);

                             if ($rename_success) {
                                 // Perform the restore using the backup API (from uploaded to new DB_PATH)
                                 // Note: The backup API backs up FROM a source DB TO a destination DB.
                                 // We need to back up FROM the uploaded DB TO the main DB path.
                                 // This requires opening the destination path as a writable DB.
                                  $main_db_writable = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                                  $backup = $uploaded_db->backup($main_db_writable, 'main'); // Back up from uploaded to main
                                  $backup->step(-1); // Perform the backup
                                  $backup->finish(); // Finalize

                                  $main_db_writable->close(); // Close the new main DB
                                  $uploaded_db->close(); // Close the uploaded DB

                                  $_SESSION['success_message'] = "Database restored successfully.";
                                  // Optionally delete the old temp file
                                  // unlink($old_db_temp); // Be cautious before uncommenting this

                             } else {
                                  // Rename of old DB failed, cannot safely restore
                                  $_SESSION['error_message'] = "Failed to rename current database file for backup. Restore aborted.";
                                  $uploaded_db->close(); // Close the uploaded DB
                             }

                         } catch (Exception $e) {
                              error_log("SQLite restore API error: " . $e->getMessage());
                              $_SESSION['error_message'] = "Failed to restore database using SQLite API: " . $e->getMessage();
                              // Attempt to restore the old DB file if it was renamed
                              if (isset($old_db_temp) && file_exists($old_db_temp)) {
                                   rename($old_db_temp, DB_PATH);
                                   $_SESSION['error_message'] .= " Attempted rollback to previous version.";
                              }
                         } finally {
                              // Re-establish PDO DB connection for the next request
                              $db = get_db();
                         }

                    } else {
                        // Fallback to simple file copy if SQLite3 class is not available
                        error_log("SQLite3 class not available for API backup/restore. Falling back to file copy.");
                        // Close current PDO connection
                        $db = null;

                        // Create a temporary path for the current DB file
                        $old_db_temp = dirname(DB_PATH) . '/islamic_history_old_' . date('YmdHis') . '.sqlite';

                        // Rename current DB file as a temporary backup
                        $rename_success = rename(DB_PATH, $old_db_temp);

                        if ($rename_success) {
                            // Replace with the uploaded file
                            if (move_uploaded_file($file['tmp_name'], DB_PATH)) {
                                $_SESSION['success_message'] = "Database restored successfully (via file copy).";
                                // Optionally delete the old temp file
                                // unlink($old_db_temp); // Be cautious before uncommenting this
                            } else {
                                // Restore failed, try to put the old one back
                                rename($old_db_temp, DB_PATH); // Attempt rollback
                                $_SESSION['error_message'] = "Failed to replace database file. Attempted rollback.";
                                // Clean up the temp uploaded file (already handled by move_uploaded_file)
                            }
                        } else {
                             // Rename of old DB failed, cannot safely restore
                             $_SESSION['error_message'] = "Failed to rename current database file for backup. Restore aborted.";
                             // Clean up the temp uploaded file
                             unlink($file['tmp_name']);
                        }

                         // Re-establish PDO DB connection for the next request
                         $db = get_db();
                    }


                }
            } else {
                 $_SESSION['error_message'] = "File upload error: " . $_FILES['backup_file']['error'] . ". Max file size or permissions issue?"; // More detailed error handling needed
            }
            header("Location: ?action=admin_panel"); // Redirect back to admin panel
            exit();
            break;

        // Other POST actions (if any)
        default:
            // If it's a POST but not a known action, redirect to home or show error
            $_SESSION['error_message'] = "Invalid POST action.";
            header("Location: ?");
            exit();
            break;
    }
}


// =============================================================================
// 8. HTML Output
// =============================================================================

$current_role = get_user_role_name($db);
$lang = get_language();
$is_rtl = ($lang === 'ur');

// Language strings (basic example)
$lang_strings = [
    'en' => [
        'app_title' => 'Islamic History Explorer',
        'nav_home' => 'Home',
        'nav_map' => 'Map',
        'nav_timeline' => 'Timeline',
        'nav_infographics' => 'Infographics',
        'nav_ayahs' => 'Quranic Ayahs',
        'nav_hadiths' => 'Hadiths',
        'nav_admin' => 'Admin Panel',
        'nav_login' => 'Login',
        'nav_logout' => 'Logout',
        'nav_profile' => 'Profile',
        'nav_suggest_event' => 'Suggest Event',
        'nav_add_location' => 'Add Location',
        'nav_add_hadith' => 'Add Hadith',
        'welcome' => 'Welcome to the Islamic History Explorer',
        'login_title' => 'Login',
        'username' => 'Username',
        'password' => 'Password',
        'login_button' => 'Login',
        'logout_button' => 'Logout',
        'map_title' => 'Historical Map',
        'timeline_title' => 'Historical Timeline',
        'infographics_title' => 'Historical Infographics',
        'ayahs_title' => 'Quranic Ayahs',
        'hadiths_title' => 'Hadiths',
        'admin_title' => 'Admin Panel',
        'profile_title' => 'User Profile',
        'suggest_event_title' => 'Suggest New Event',
        'add_location_title' => 'Add New Location',
        'add_hadith_title' => 'Add New Hadith',
        'event_details_title' => 'Event Details',
        'location_details_title' => 'Location Details',
        'location' => 'Location',
        'date' => 'Date',
        'category' => 'Category',
        'description' => 'Description',
        'related_ayahs' => 'Related Quranic Ayahs',
        'related_hadiths' => 'Related Hadiths',
        'search_ayahs' => 'Search Ayahs',
        'search_hadiths' => 'Search Hadiths',
        'search' => 'Search',
        'surah' => 'Surah',
        'ayah' => 'Ayah',
        'arabic_text' => 'Arabic Text',
        'urdu_translation' => 'Urdu Translation',
        'book' => 'Book',
        'chapter' => 'Chapter',
        'text' => 'Text',
        'reference' => 'Reference',
        'users' => 'Users',
        'roles' => 'Roles',
        'events' => 'Events',
        'locations' => 'Locations',
        'pending_suggestions' => 'Pending Suggestions',
        'manage_users' => 'Manage Users',
        'manage_events' => 'Manage Events',
        'manage_locations' => 'Manage Locations',
        'manage_ayahs' => 'Manage Ayah Links',
        'manage_hadiths' => 'Manage Hadiths',
        'backup_restore' => 'Backup / Restore Database',
        'add_user' => 'Add User',
        'edit_user' => 'Edit User',
        'delete_user' => 'Delete User',
        'add_event' => 'Add Event',
        'edit_event' => 'Edit Event',
        'delete_event' => 'Delete Event',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'add_location' => 'Add Location',
        'edit_location' => 'Edit Location',
        'delete_location' => 'Delete Location',
        'add_hadith' => 'Add Hadith',
        'edit_hadith' => 'Edit Hadith',
        'delete_hadith' => 'Delete Hadith',
        'authenticate' => 'Authenticate',
        'points' => 'Points',
        'bookmarks' => 'Bookmarks',
        'bookmarked_locations' => 'Bookmarked Locations',
        'bookmark' => 'Bookmark',
        'unbookmark' => 'Unbookmark',
        'event_suggestions_by_you' => 'Your Event Suggestions',
        'hadith_suggestions_by_you' => 'Your Hadith Suggestions',
        'status' => 'Status',
        'action' => 'Action',
        'confirm_delete' => 'Are you sure you want to delete this?',
        'upload_backup' => 'Upload Backup File (.sqlite)',
        'restore' => 'Restore',
        'backup_now' => 'Backup Now',
        'available_backups' => 'Available Backups',
        'filename' => 'Filename',
        'size' => 'Size',
        'date' => 'Date',
        'download' => 'Download',
        'uploading' => 'Uploading...',
        'restoring' => 'Restoring...',
        'events_per_century' => 'Events Per Century',
        'event_category_distribution' => 'Event Category Distribution',
        'link_ayahs' => 'Link Ayahs',
        'link_hadiths' => 'Link Hadiths',
        'select_ayahs_to_link' => 'Select Ayahs to Link',
        'select_hadiths_to_link' => 'Select Hadiths to Link',
        'link' => 'Link',
        'unlink' => 'Unlink',
        'linked_ayahs' => 'Linked Ayahs',
        'linked_hadiths' => 'Linked Hadiths',
        'select_location' => 'Select Location',
        'select_category' => 'Select Category',
        'islamic' => 'Islamic',
        'general' => 'General',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'authenticated_by' => 'Authenticated By',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'ulama_authenticated' => 'Ulama Authenticated',
        'not_authenticated' => 'Not Authenticated',
        'profile_points_desc' => 'Your contribution points:',
        'profile_badges_desc' => 'Your badges:',
        'badge_beginner' => 'Beginner Historian', // 10+ points
        'badge_contributor' => 'Valued Contributor', // 50+ points
        'badge_scholar' => 'Islamic Scholar', // 100+ points
        'badge_authenticator' => 'Hadith Authenticator', // 5+ authenticated hadiths
        'badge_moderator' => 'Community Moderator', // 5+ approved suggestions
        'badge_explorer' => 'Global Explorer', // 10+ bookmarked locations
        'surah_ayah_short' => 'S: %d, A: %d',
        'error_loading_data' => 'Error loading data.',
        'data_saved' => 'Data saved successfully.',
        'data_deleted' => 'Data deleted successfully.',
        'confirm_action' => 'Are you sure?',
        'loading' => 'Loading...',
        'no_data_available' => 'No data available.',
        'no_results_found' => 'No results found.',
        'no_pending_items' => 'No pending items.',
        'no_bookmarks' => 'No bookmarks yet.',
        'no_suggestions' => 'No suggestions yet.',
         'select_ayah' => 'Select Ayah',
         'select_hadith' => 'Select Hadith',
         'select_role' => 'Select Role',
         'public_role' => 'Public',
         'user_role' => 'User',
         'ulama_role' => 'Ulama',
         'admin_role' => 'Admin',
         'all_roles' => 'All Roles',
         'all_categories' => 'All Categories',
         'all_locations' => 'All Locations',
         'filter' => 'Filter',
         'clear_filters' => 'Clear Filters',
         'century' => 'Century',
         'count' => 'Count',
         'total_events' => 'Total Events',
         'total_locations' => 'Total Locations',
         'total_ayahs' => 'Total Ayahs',
         'total_hadiths' => 'Total Hadiths',
         'total_users' => 'Total Users',
         'total_bookmarks' => 'Total Bookmarks',
         'pending_events' => 'Pending Events',
         'pending_hadiths' => 'Pending Hadiths',
         'no_description' => 'No description available.',
         'theme_toggle' => 'Toggle Theme',

    ],
    'ur' => [
        'app_title' => 'اسلامی تاریخ کا متلاشی',
        'nav_home' => 'مرکزی صفحہ',
        'nav_map' => 'نقشہ',
        'nav_timeline' => 'ٹائم لائن',
        'nav_infographics' => 'انفوگرافکس',
        'nav_ayahs' => 'قرآنی آیات',
        'nav_hadiths' => 'احادیث',
        'nav_admin' => 'ایڈمن پینل',
        'nav_login' => 'لاگ ان',
        'nav_logout' => 'لاگ آؤٹ',
        'nav_profile' => 'پروفائل',
        'nav_suggest_event' => 'واقعہ تجویز کریں',
        'nav_add_location' => 'مقام شامل کریں',
        'nav_add_hadith' => 'حدیث شامل کریں',
        'welcome' => 'اسلامی تاریخ کے متلاشی میں خوش آمدید',
        'login_title' => 'لاگ ان',
        'username' => 'صارف نام',
        'password' => 'پاس ورڈ',
        'login_button' => 'لاگ ان کریں',
        'logout_button' => 'لاگ آؤٹ',
        'map_title' => 'تاریخی نقشہ',
        'timeline_title' => 'تاریخی ٹائم لائن',
        'infographics_title' => 'تاریخی انفوگرافکس',
        'ayahs_title' => 'قرآنی آیات',
        'hadiths_title' => 'احادیث',
        'admin_title' => 'ایڈمن پینل',
        'profile_title' => 'صارف پروفائل',
        'suggest_event_title' => 'نیا واقعہ تجویز کریں',
        'add_location_title' => 'نیا مقام شامل کریں',
        'add_hadith_title' => 'نئی حدیث شامل کریں',
        'event_details_title' => 'واقعہ کی تفصیلات',
        'location_details_title' => 'مقام کی تفصیلات',
        'location' => 'مقام',
        'date' => 'تاریخ',
        'category' => 'زمرہ',
        'description' => 'تفصیل',
        'related_ayahs' => 'متعلقہ قرآنی آیات',
        'related_hadiths' => 'متعلقہ احادیث',
        'search_ayahs' => 'آیات تلاش کریں',
        'search_hadiths' => 'احادیث تلاش کریں',
        'search' => 'تلاش کریں',
        'surah' => 'سورہ',
        'ayah' => 'آیت',
        'arabic_text' => 'عربی متن',
        'urdu_translation' => 'اردو ترجمہ',
        'book' => 'کتاب',
        'chapter' => 'باب',
        'text' => 'متن',
        'reference' => 'حوالہ',
        'users' => 'صارفین',
        'roles' => 'کردار',
        'events' => 'واقعات',
        'locations' => 'مقامات',
        'pending_suggestions' => 'زیر التواء تجاویز',
        'manage_users' => 'صارفین کا انتظام کریں',
        'manage_events' => 'واقعات کا انتظام کریں',
        'manage_locations' => 'مقامات کا انتظام کریں',
        'manage_ayahs' => 'آیات کے روابط کا انتظام کریں',
        'manage_hadiths' => 'احادیث کا انتظام کریں',
        'backup_restore' => 'ڈیٹا بیس کا بیک اپ / بحالی',
        'add_user' => 'صارف شامل کریں',
        'edit_user' => 'صارف میں ترمیم کریں',
        'delete_user' => 'صارف حذف کریں',
        'add_event' => 'واقعہ شامل کریں',
        'edit_event' => 'واقعہ میں ترمیم کریں',
        'delete_event' => 'واقعہ حذف کریں',
        'approve' => 'منظور کریں',
        'reject' => 'مسترد کریں',
        'add_location' => 'مقام شامل کریں',
        'edit_location' => 'مقام میں ترمیم کریں',
        'delete_location' => 'مقام حذف کریں',
        'add_hadith' => 'حدیث شامل کریں',
        'edit_hadith' => 'حدیث میں ترمیم کریں',
        'delete_hadith' => 'حدیث حذف کریں',
        'authenticate' => 'تصدیق کریں',
        'points' => 'پوائنٹس',
        'bookmarks' => 'بک مارکس',
        'bookmarked_locations' => 'بک مارک شدہ مقامات',
        'bookmark' => 'بک مارک کریں',
        'unbookmark' => 'بک مارک ہٹائیں',
        'event_suggestions_by_you' => 'آپ کی واقعات کی تجاویز',
        'hadith_suggestions_by_you' => 'آپ کی احادیث کی تجاویز',
        'status' => 'حالت',
        'action' => 'عمل',
        'confirm_delete' => 'کیا آپ واقعی اسے حذف کرنا چاہتے ہیں؟',
        'upload_backup' => 'بیک اپ فائل (.sqlite) اپ لوڈ کریں',
        'restore' => 'بحال کریں',
        'backup_now' => 'ابھی بیک اپ لیں',
        'available_backups' => 'دستیاب بیک اپ',
        'filename' => 'فائل کا نام',
        'size' => 'سائز',
        'date' => 'تاریخ',
        'download' => 'ڈاؤن لوڈ کریں',
        'uploading' => 'اپ لوڈ ہو رہا ہے...',
        'restoring' => 'بحال ہو رہا ہے...',
        'events_per_century' => 'فی صدی واقعات',
        'event_category_distribution' => 'واقعہ زمرہ کی تقسیم',
        'link_ayahs' => 'آیات کو لنک کریں',
        'link_hadiths' => 'احادیث کو لنک کریں',
        'select_ayahs_to_link' => 'لنک کرنے کے لیے آیات منتخب کریں',
        'select_hadiths_to_link' => 'لنک کرنے کے لیے احادیث منتخب کریں',
        'link' => 'لنک کریں',
        'unlink' => 'لنک ہٹائیں',
        'linked_ayahs' => 'لنک شدہ آیات',
        'linked_hadiths' => 'لنک شدہ احادیث',
        'select_location' => 'مقام منتخب کریں',
        'select_category' => 'زمرہ منتخب کریں',
        'islamic' => 'اسلامی',
        'general' => 'عام',
        'latitude' => 'عرض بلد',
        'longitude' => 'طول بلد',
        'authenticated_by' => 'تصدیق کنندہ',
        'pending' => 'زیر التواء',
        'approved' => 'منظور شدہ',
        'rejected' => 'مسترد شدہ',
        'ulama_authenticated' => 'علماء نے تصدیق کی',
        'not_authenticated' => 'تصدیق شدہ نہیں',
        'profile_points_desc' => 'آپ کے شراکت کے پوائنٹس:',
        'profile_badges_desc' => 'آپ کے بیجز:',
        'badge_beginner' => 'ابتدائی مورخ', // 10+ points
        'badge_contributor' => 'قابل قدر شراکت دار', // 50+ points
        'badge_scholar' => 'اسلامی اسکالر', // 100+ points
        'badge_authenticator' => 'حدیث تصدیق کنندہ', // 5+ authenticated hadiths
        'badge_moderator' => 'کمیونٹی ماڈریٹر', // 5+ approved suggestions
        'badge_explorer' => 'عالمی متلاشی', // 10+ bookmarked locations
        'surah_ayah_short' => 'س: %d، آ: %d',
        'error_loading_data' => 'ڈیٹا لوڈ کرنے میں خرابی۔',
        'data_saved' => 'ڈیٹا کامیابی سے محفوظ ہو گیا۔',
        'data_deleted' => 'ڈیٹا کامیابی سے حذف ہو گیا۔',
        'confirm_action' => 'کیا آپ کو یقین ہے؟',
        'loading' => 'لوڈ ہو رہا ہے...',
        'no_data_available' => 'کوئی ڈیٹا دستیاب نہیں ہے۔',
        'no_results_found' => 'کوئی نتائج نہیں ملے۔',
        'no_pending_items' => 'کوئی زیر التواء اشیاء نہیں ہیں۔',
        'no_bookmarks' => 'ابھی تک کوئی بک مارک نہیں ہے۔',
        'no_suggestions' => 'ابھی تک کوئی تجاویز نہیں ہیں۔',
        'select_ayah' => 'آیت منتخب کریں',
        'select_hadith' => 'حدیث منتخب کریں',
        'select_role' => 'کردار منتخب کریں',
        'public_role' => 'عوام',
        'user_role' => 'صارف',
        'ulama_role' => 'علماء',
        'admin_role' => 'ایڈمن',
        'all_roles' => 'تمام کردار',
        'all_categories' => 'تمام زمرے',
        'all_locations' => 'تمام مقامات',
        'filter' => 'فلٹر کریں',
        'clear_filters' => 'فلٹر صاف کریں',
        'century' => 'صدی',
        'count' => 'تعداد',
        'total_events' => 'کل واقعات',
        'total_locations' => 'کل مقامات',
        'total_ayahs' => 'کل آیات',
        'total_hadiths' => 'کل احادیث',
        'total_users' => 'کل صارفین',
        'total_bookmarks' => 'کل بک مارکس',
        'pending_events' => 'زیر التواء واقعات',
        'pending_hadiths' => 'زیر التواء احادیث',
        'no_description' => 'کوئی تفصیل دستیاب نہیں۔',
        'theme_toggle' => 'تھیم ٹوگل کریں',
    ],
];

function __($key) {
    global $lang_strings, $lang;
    return $lang_strings[$lang][$key] ?? $lang_strings[DEFAULT_LANG][$key] ?? $key;
}

// Get flash messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $is_rtl ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('app_title'); ?></title>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <!-- Basic Embedded CSS -->
    <style>
        :root {
            --primary-color: #007bff; /* Blue */
            --secondary-color: #6c757d; /* Gray */
            --success-color: #28a745; /* Green */
            --danger-color: #dc3545; /* Red */
            --warning-color: #ffc107; /* Yellow */
            --info-color: #17a2b8; /* Cyan */
            --light-bg: #f8f9fa; /* Light gray */
            --dark-bg: #343a40; /* Dark gray */
            --light-text: #212529; /* Dark text */
            --dark-text: #f8f9fa; /* Light text */
            --border-color: #dee2e6; /* Light border */
            --card-bg: #ffffff; /* White */
            --card-dark-bg: #454d55; /* Dark gray */
        }

        /* Light Theme */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: var(--light-bg);
            color: var(--light-text);
            transition: background-color 0.3s, color 0.3s;
        }
        .container {
            width: 95%;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background-color: var(--card-bg);
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        header {
            background-color: var(--primary-color);
            color: white;
            padding: 10px 0;
            text-align: center;
        }
        nav {
            background-color: var(--secondary-color);
            padding: 10px 0;
        }
        nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            text-align: center;
        }
        nav ul li {
            display: inline-block;
            margin: 0 10px;
        }
        nav ul li a {
            color: white;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        nav ul li a:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        .flash-message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .flash-success {
            background-color: var(--success-color);
            color: white;
        }
        .flash-error {
            background-color: var(--danger-color);
            color: white;
        }
         .flash-info {
            background-color: var(--info-color);
            color: white;
        }
        .content {
            padding: 20px 0;
        }
        .map-container {
            height: 500px;
            width: 100%;
            margin-bottom: 20px;
            border-radius: 8px;
            overflow: hidden;
        }
        .timeline-container {
            margin-bottom: 20px;
        }
         .timeline-item {
             border-left: 2px solid var(--primary-color);
             padding-left: 15px;
             margin-bottom: 15px;
         }
         .timeline-item h4 {
             margin: 0 0 5px 0;
             color: var(--primary-color);
         }
         .timeline-item p {
             margin: 0;
             font-size: 0.9em;
         }
        .infographics-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }
        .chart-container {
            width: 100%;
            max-width: 500px;
            background-color: var(--light-bg);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .data-list {
            margin-top: 20px;
        }
        .data-item {
            border: 1px solid var(--border-color);
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            background-color: #fefefe;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .data-item strong {
            color: var(--primary-color);
        }
        .data-item .actions button, .data-item .actions a.button {
             margin-left: 5px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="date"],
        .form-group input[type="number"],
        .form-group textarea,
        .form-group select,
        .form-group input[type="file"] {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-sizing: border-box; /* Include padding in width */
        }
         .form-actions {
             margin-top: 20px;
             text-align: right;
         }
        button, .button {
            background-color: var(--primary-color);
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s;
            text-decoration: none; /* For button class on links */
            display: inline-block; /* For button class on links */
        }
        button:hover, .button:hover {
            background-color: #0056b3;
        }
        button.secondary, .button.secondary {
            background-color: var(--secondary-color);
        }
        button.secondary:hover, .button.secondary:hover {
            background-color: #545b62;
        }
         button.danger, .button.danger {
            background-color: var(--danger-color);
        }
        button.danger:hover, .button.danger:hover {
            background-color: #c82333;
        }
         button.success, .button.success {
            background-color: var(--success-color);
        }
        button.success:hover, .button.success:hover {
            background-color: #218838;
        }
         button.warning, .button.warning {
            background-color: var(--warning-color);
            color: #212529; /* Dark text for yellow */
        }
        button.warning:hover, .button.warning:hover {
            background-color: #e0a800;
        }
        button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: var(--card-bg);
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 600px;
            border-radius: 8px;
            position: relative;
        }
        .modal-content h3 {
            margin-top: 0;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            <?php echo $is_rtl ? 'left' : 'right'; ?>: 10px;
        }
        .close-button:hover,
        .close-button:focus {
            color: #000;
            text-decoration: none;
            cursor: pointer;
        }
        .language-switcher {
             text-align: center;
             margin-top: 10px;
        }
        .language-switcher a {
            margin: 0 5px;
            text-decoration: none;
            color: var(--primary-color);
        }
         .language-switcher a.active {
             font-weight: bold;
             text-decoration: underline;
         }
         .arabic-text {
             font-family: 'Amiri', serif; /* Example Arabic font */
             direction: rtl;
             text-align: right;
             font-size: 1.2em;
         }
         .urdu-text {
             font-family: 'Noto Nastaliq Urdu', serif; /* Example Urdu font */
             direction: rtl;
             text-align: right;
             font-size: 1.1em;
         }
         /* Ensure RTL layout for Urdu */
         <?php if ($is_rtl): ?>
         body {
             direction: rtl;
             text-align: right;
         }
         nav ul li {
             margin: 0 10px; /* Adjust margins if needed */
         }
         .timeline-item {
             border-left: none;
             border-right: 2px solid var(--primary-color);
             padding-left: 0;
             padding-right: 15px;
         }
         .data-item .actions button, .data-item .actions a.button {
             margin-left: 0;
             margin-right: 5px;
         }
         .close-button {
            <?php echo $is_rtl ? 'left' : 'right'; ?>: 10px;
         }
         <?php endif; ?>

         /* Dark Theme (Optional - basic example) */
         body.dark-theme {
             background-color: var(--dark-bg);
             color: var(--dark-text);
         }
          body.dark-theme .container {
             background-color: var(--card-dark-bg);
             box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
         }
         body.dark-theme header {
             background-color: #0056b3;
         }
         body.dark-theme nav {
             background-color: #545b62;
         }
         body.dark-theme nav ul li a:hover {
             background-color: rgba(255, 255, 255, 0.1);
         }
         body.dark-theme .data-item {
             border-color: #6c757d;
             background-color: #545b62;
             color: var(--dark-text);
         }
         body.dark-theme .data-item strong {
             color: #74b9f3; /* Lighter primary color */
         }
         body.dark-theme .form-group input[type="text"],
         body.dark-theme .form-group input[type="password"],
         body.dark-theme .form-group input[type="date"],
         body.dark-theme .form-group input[type="number"],
         body.dark-theme .form-group textarea,
         body.dark-theme .form-group select,
         body.dark-theme .form-group input[type="file"] {
             background-color: #6c757d;
             border-color: #545b62;
             color: var(--dark-text);
         }
         body.dark-theme .modal-content {
             background-color: var(--card-dark-bg);
             border-color: #6c757d;
         }
         body.dark-theme .modal-content h3 {
             border-bottom-color: #6c757d;
         }
         body.dark-theme .close-button {
             color: #ccc;
         }
         body.dark-theme .close-button:hover,
         body.dark-theme .close-button:focus {
             color: #fff;
         }
         body.dark-theme .chart-container {
             background-color: var(--card-dark-bg);
             box-shadow: 0 2px 5px rgba(255,255,255,0.1);
         }
         body.dark-theme .language-switcher a {
             color: #74b9f3;
         }
          /* Islamic Art Inspired - Minimal */
         .container, .modal-content {
             border: 2px solid var(--primary-color); /* Simple border */
             box-shadow: 5px 5px 15px rgba(0, 0, 0, 0.2); /* Softer shadow */
         }
         header, nav {
             border-bottom: 2px solid var(--light-bg); /* Contrast border */
         }
         /* Add more specific classes for patterns/details if needed */

        /* Responsive adjustments */
        @media (max-width: 768px) {
            nav ul li {
                display: block;
                margin: 5px 0;
            }
            .infographics-container {
                flex-direction: column;
            }
            .chart-container {
                max-width: 100%;
            }
             .data-item {
                 flex-direction: column;
                 align-items: flex-start;
             }
             .data-item .actions {
                 margin-top: 10px;
                 width: 100%;
                 text-align: center;
             }
             .data-item .actions button, .data-item .actions a.button {
                 margin: 5px;
             }
        }

    </style>
     <!-- Optional: Google Fonts for Arabic/Urdu -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Noto+Nastaliq+Urdu:wght@400..700&display=swap" rel="stylesheet">

</head>
<body class="<?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'dark-theme' : ''; ?>">
    <header>
        <h1><?php echo __('app_title'); ?></h1>
    </header>
    <nav>
        <ul>
            <li><a href="?action=home"><?php echo __('nav_home'); ?></a></li>
            <li><a href="?action=map"><?php echo __('nav_map'); ?></a></li>
            <li><a href="?action=timeline"><?php echo __('nav_timeline'); ?></a></li>
            <li><a href="?action=infographics"><?php echo __('nav_infographics'); ?></a></li>
            <li><a href="?action=ayahs"><?php echo __('nav_ayahs'); ?></a></li>
            <li><a href="?action=hadiths"><?php echo __('nav_hadiths'); ?></a></li>
            <?php if (has_role($db, ROLE_ADMIN)): ?>
                <li><a href="?action=admin_panel"><?php echo __('nav_admin'); ?></a></li>
            <?php endif; ?>
             <?php if (has_role($db, ROLE_USER)): ?>
                <li><a href="?action=profile"><?php echo __('nav_profile'); ?></a></li>
             <?php endif; ?>
            <?php if (isset($_SESSION['user_id'])): ?>
                <li><a href="?" onclick="event.preventDefault(); document.getElementById('logout-form').submit();"><?php echo __('nav_logout'); ?></a></li>
            <?php else: ?>
                <li><a href="?action=login"><?php echo __('nav_login'); ?></a></li>
            <?php endif; ?>
             <li>
                 <a href="#" onclick="toggleTheme(); return false;"><?php echo __('theme_toggle'); ?></a>
             </li>
        </ul>
         <div class="language-switcher">
             <?php foreach(AVAILABLE_LANGS as $available_lang): ?>
                 <a href="?lang=<?php echo $available_lang; ?>" class="<?php echo $lang === $available_lang ? 'active' : ''; ?>"><?php echo strtoupper($available_lang); ?></a>
             <?php endforeach; ?>
         </div>
    </nav>

     <?php if (isset($_SESSION['user_id'])): ?>
     <form id="logout-form" action="" method="POST" style="display: none;">
         <input type="hidden" name="action" value="logout">
     </form>
     <?php endif; ?>

    <div class="container">
        <?php if ($success_message): ?>
            <div class="flash-message flash-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="flash-message flash-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="content">
            <?php
            // =============================================================================
            // 9. Page Content Rendering
            // =============================================================================

            switch ($current_action) {
                case 'home':
                    ?>
                    <h2><?php echo __('welcome'); ?></h2>
                    <p><?php echo __('app_title'); ?> allows you to explore key events, locations, and related religious texts from Islamic history.</p>
                    <p>Current Role: <strong><?php echo __($current_role . '_role'); ?></strong></p>
                    <?php if (isset($_SESSION['username'])): ?>
                        <p>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></p>
                    <?php endif; ?>
                    <?php
                    // Basic stats for home page
                    $total_events = $db->query("SELECT COUNT(*) FROM events WHERE status = 'approved'")->fetchColumn();
                    $total_locations = $db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
                    $total_ayahs = $db->query("SELECT COUNT(*) FROM ayahs")->fetchColumn();
                    $total_hadiths = $db->query("SELECT COUNT(*) FROM hadiths WHERE status = 'approved'")->fetchColumn();
                    $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
                    $total_bookmarks = isset($_SESSION['user_id']) ? $db->prepare("SELECT COUNT(*) FROM user_bookmarks WHERE user_id = :user_id")->execute([':user_id' => $_SESSION['user_id']])->fetchColumn() : 0;
                    $pending_events = has_role($db, ROLE_ULAMA) ? $db->query("SELECT COUNT(*) FROM events WHERE status = 'pending'")->fetchColumn() : 0;
                    $pending_hadiths = has_role($db, ROLE_ADMIN) ? $db->query("SELECT COUNT(*) FROM hadiths WHERE status = 'pending'")->fetchColumn() : 0;

                    ?>
                    <h3>Stats</h3>
                    <ul>
                        <li><?php echo __('total_events'); ?>: <?php echo $total_events; ?></li>
                        <li><?php echo __('total_locations'); ?>: <?php echo $total_locations; ?></li>
                        <li><?php echo __('total_ayahs'); ?>: <?php echo $total_ayahs; ?></li>
                        <li><?php echo __('total_hadiths'); ?>: <?php echo $total_hadiths; ?></li>
                        <?php if (has_role($db, ROLE_ADMIN)): ?>
                        <li><?php echo __('total_users'); ?>: <?php echo $total_users; ?></li>
                        <?php endif; ?>
                         <?php if (has_role($db, ROLE_USER)): ?>
                        <li><?php echo __('total_bookmarks'); ?>: <?php echo $total_bookmarks; ?></li>
                        <?php endif; ?>
                         <?php if (has_role($db, ROLE_ULAMA)): ?>
                        <li><?php echo __('pending_events'); ?>: <?php echo $pending_events; ?></li>
                        <?php endif; ?>
                         <?php if (has_role($db, ROLE_ADMIN)): ?>
                        <li><?php echo __('pending_hadiths'); ?>: <?php echo $pending_hadiths; ?></li>
                        <?php endif; ?>
                    </ul>

                    <?php
                    break;

                case 'login':
                    if (isset($_SESSION['user_id'])) {
                         echo "<p>You are already logged in as <strong>" . htmlspecialchars($_SESSION['username']) . "</strong>.</p>";
                         echo '<a href="?" class="button secondary">Go to Home</a>';
                    } else {
                        ?>
                        <h2><?php echo __('login_title'); ?></h2>
                        <form action="" method="POST">
                            <input type="hidden" name="action" value="login">
                            <div class="form-group">
                                <label for="username"><?php echo __('username'); ?>:</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="password"><?php echo __('password'); ?>:</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            <button type="submit"><?php echo __('login_button'); ?></button>
                        </form>
                        <?php
                    }
                    break;

                case 'map':
                    ?>
                    <h2><?php echo __('map_title'); ?></h2>
                    <div id="map" class="map-container"></div>

                    <!-- Location/Event Details Modal -->
                    <div id="detailsModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3 id="modalTitle"></h3>
                        <div id="modalContent">
                            <!-- Details will be loaded here -->
                        </div>
                      </div>
                    </div>

                    <?php if (has_role($db, ROLE_ULAMA)): ?>
                    <!-- Add Location Modal -->
                    <div id="addLocationModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('add_location_title'); ?></h3>
                        <form id="addLocationForm">
                            <div class="form-group">
                                <label for="add_loc_name_en"><?php echo __('location'); ?> (EN):</label>
                                <input type="text" id="add_loc_name_en" name="name_en" required>
                            </div>
                             <div class="form-group">
                                <label for="add_loc_name_ur"><?php echo __('location'); ?> (UR):</label>
                                <input type="text" id="add_loc_name_ur" name="name_ur" required class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="add_loc_desc_en"><?php echo __('description'); ?> (EN):</label>
                                <textarea id="add_loc_desc_en" name="description_en"></textarea>
                            </div>
                             <div class="form-group">
                                <label for="add_loc_desc_ur"><?php echo __('description'); ?> (UR):</label>
                                <textarea id="add_loc_desc_ur" name="description_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="add_loc_lat"><?php echo __('latitude'); ?>:</label>
                                <input type="number" id="add_loc_lat" name="latitude" step="any" required>
                            </div>
                            <div class="form-group">
                                <label for="add_loc_lng"><?php echo __('longitude'); ?>:</label>
                                <input type="number" id="add_loc_lng" name="longitude" step="any" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('add_location'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>
                    <?php endif; // End Ulama/Admin add location ?>

                    <?php if (has_role($db, ROLE_USER)): ?>
                    <!-- Suggest Event Modal -->
                    <div id="suggestEventModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('suggest_event_title'); ?></h3>
                        <form id="suggestEventForm">
                            <div class="form-group">
                                <label for="suggest_event_title_en"><?php echo __('event_details_title'); ?> (EN):</label>
                                <input type="text" id="suggest_event_title_en" name="title_en" required>
                            </div>
                             <div class="form-group">
                                <label for="suggest_event_title_ur"><?php echo __('event_details_title'); ?> (UR):</label>
                                <input type="text" id="suggest_event_title_ur" name="title_ur" required class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="suggest_event_desc_en"><?php echo __('description'); ?> (EN):</label>
                                <textarea id="suggest_event_desc_en" name="description_en"></textarea>
                            </div>
                             <div class="form-group">
                                <label for="suggest_event_desc_ur"><?php echo __('description'); ?> (UR):</label>
                                <textarea id="suggest_event_desc_ur" name="description_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>"></textarea>
                            </div>
                             <div class="form-group">
                                <label for="suggest_event_date"><?php echo __('date'); ?>:</label>
                                <input type="date" id="suggest_event_date" name="date">
                            </div>
                             <div class="form-group">
                                <label for="suggest_event_category"><?php echo __('category'); ?>:</label>
                                <select id="suggest_event_category" name="category" required>
                                    <option value="Islamic"><?php echo __('islamic'); ?></option>
                                    <option value="General"><?php echo __('general'); ?></option>
                                </select>
                            </div>
                             <div class="form-group">
                                <label for="suggest_event_location"><?php echo __('location'); ?>:</label>
                                <select id="suggest_event_location" name="location_id">
                                    <option value="">-- <?php echo __('select_location'); ?> --</option>
                                    <!-- Locations will be loaded here -->
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('suggest_event'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>
                    <?php endif; // End User suggest event ?>


                    <?php
                    break;

                case 'timeline':
                    ?>
                    <h2><?php echo __('timeline_title'); ?></h2>
                    <div id="timeline" class="timeline-container">
                        <p><?php echo __('loading'); ?></p>
                    </div>
                    <?php
                    break;

                case 'infographics':
                    ?>
                    <h2><?php echo __('infographics_title'); ?></h2>
                    <div class="infographics-container">
                        <div class="chart-container">
                            <h3><?php echo __('events_per_century'); ?></h3>
                            <canvas id="eventsPerCenturyChart"></canvas>
                        </div>
                         <div class="chart-container">
                            <h3><?php echo __('event_category_distribution'); ?></h3>
                            <canvas id="eventCategoryChart"></canvas>
                        </div>
                        <!-- Add more charts here -->
                    </div>
                    <?php
                    break;

                case 'ayahs':
                    ?>
                    <h2><?php echo __('ayahs_title'); ?></h2>
                    <div class="search-form">
                        <div class="form-group">
                            <label for="ayah-search"><?php echo __('search_ayahs'); ?>:</label>
                            <input type="text" id="ayah-search" placeholder="<?php echo __('search'); ?>...">
                        </div>
                    </div>
                    <div id="ayah-results" class="data-list">
                         <p><?php echo __('loading'); ?></p>
                    </div>
                    <?php
                    break;

                case 'hadiths':
                    ?>
                    <h2><?php echo __('hadiths_title'); ?></h2>
                    <div class="search-form">
                        <div class="form-group">
                            <label for="hadith-search"><?php echo __('search_hadiths'); ?>:</label>
                            <input type="text" id="hadith-search" placeholder="<?php echo __('search'); ?>...">
                        </div>
                    </div>
                    <div id="hadith-results" class="data-list">
                         <p><?php echo __('loading'); ?></p>
                    </div>

                     <?php if (has_role($db, ROLE_ULAMA)): ?>
                    <!-- Add Hadith Modal -->
                    <div id="addHadithModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('add_hadith_title'); ?></h3>
                        <form id="addHadithForm">
                             <div class="form-group">
                                <label for="add_hadith_book_en"><?php echo __('book'); ?> (EN):</label>
                                <input type="text" id="add_hadith_book_en" name="book_en">
                            </div>
                             <div class="form-group">
                                <label for="add_hadith_book_ur"><?php echo __('book'); ?> (UR):</label>
                                <input type="text" id="add_hadith_book_ur" name="book_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="add_hadith_chapter_en"><?php echo __('chapter'); ?> (EN):</label>
                                <input type="text" id="add_hadith_chapter_en" name="chapter_en">
                            </div>
                             <div class="form-group">
                                <label for="add_hadith_chapter_ur"><?php echo __('chapter'); ?> (UR):</label>
                                <input type="text" id="add_hadith_chapter_ur" name="chapter_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="add_hadith_reference"><?php echo __('reference'); ?>:</label>
                                <input type="text" id="add_hadith_reference" name="reference">
                            </div>
                             <div class="form-group">
                                <label for="add_hadith_text_en"><?php echo __('text'); ?> (EN):</label>
                                <textarea id="add_hadith_text_en" name="text_en" required></textarea>
                            </div>
                             <div class="form-group">
                                <label for="add_hadith_text_ur"><?php echo __('text'); ?> (UR):</label>
                                <textarea id="add_hadith_text_ur" name="text_ur" required class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>"></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('add_hadith'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>
                    <?php endif; // End Ulama/Admin add hadith ?>

                    <?php
                    break;

                case 'admin_panel':
                    require_role($db, ROLE_ADMIN);
                    ?>
                    <h2><?php echo __('admin_title'); ?></h2>

                    <div class="admin-sections">
                        <h3><?php echo __('manage_users'); ?></h3>
                        <button onclick="openModal('addUserModal')"><?php echo __('add_user'); ?></button>
                         <div class="data-list" id="user-list">
                             <p><?php echo __('loading'); ?></p>
                         </div>

                        <h3><?php echo __('manage_events'); ?></h3>
                        <p>Showing all events (approved, pending, rejected).</p>
                         <div class="data-list" id="admin-event-list">
                             <p><?php echo __('loading'); ?></p>
                         </div>

                         <h3><?php echo __('manage_locations'); ?></h3>
                         <div class="data-list" id="admin-location-list">
                             <p><?php echo __('loading'); ?></p>
                         </div>

                         <h3><?php echo __('manage_hadiths'); ?></h3>
                         <p>Showing all hadiths (approved, pending, rejected).</p>
                         <div class="data-list" id="admin-hadith-list">
                             <p><?php echo __('loading'); ?></p>
                         </div>

                         <h3><?php echo __('backup_restore'); ?></h3>
                         <div class="form-group">
                             <button id="backup-db-button"><?php echo __('backup_now'); ?></button>
                         </div>
                         <div class="form-group">
                             <label><?php echo __('upload_backup'); ?>:</label>
                             <form id="restoreForm" action="" method="POST" enctype="multipart/form-data">
                                 <input type="hidden" name="action" value="admin_restore_db_upload">
                                 <input type="file" name="backup_file" accept=".sqlite" required>
                                 <button type="submit"><?php echo __('restore'); ?></button>
                             </form>
                         </div>
                         <h4><?php echo __('available_backups'); ?></h4>
                         <div class="data-list" id="backup-list">
                             <p><?php echo __('loading'); ?></p>
                         </div>

                    </div>

                    <!-- Add User Modal -->
                    <div id="addUserModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('add_user'); ?></h3>
                        <form id="addUserForm">
                            <div class="form-group">
                                <label for="add_user_username"><?php echo __('username'); ?>:</label>
                                <input type="text" id="add_user_username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="add_user_password"><?php echo __('password'); ?>:</label>
                                <input type="password" id="add_user_password" name="password" required>
                            </div>
                            <div class="form-group">
                                <label for="add_user_role"><?php echo __('role'); ?>:</label>
                                <select id="add_user_role" name="role_id" required>
                                     <option value="">-- <?php echo __('select_role'); ?> --</option>
                                     <?php
                                     $roles = get_roles($db);
                                     foreach($roles as $role) {
                                         if ($role['name'] !== ROLE_PUBLIC) { // Public isn't a login role
                                             echo '<option value="' . $role['id'] . '">' . __($role['name'] . '_role') . '</option>';
                                         }
                                     }
                                     ?>
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('add_user'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>

                    <!-- Edit User Modal -->
                    <div id="editUserModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('edit_user'); ?></h3>
                        <form id="editUserForm">
                             <input type="hidden" name="id" id="edit_user_id">
                            <div class="form-group">
                                <label for="edit_user_username"><?php echo __('username'); ?>:</label>
                                <input type="text" id="edit_user_username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_user_password"><?php echo __('password'); ?> (Leave blank to keep current):</label>
                                <input type="password" id="edit_user_password" name="password">
                            </div>
                            <div class="form-group">
                                <label for="edit_user_role"><?php echo __('role'); ?>:</label>
                                <select id="edit_user_role" name="role_id" required>
                                     <option value="">-- <?php echo __('select_role'); ?> --</option>
                                     <?php
                                     $roles = get_roles($db);
                                     foreach($roles as $role) {
                                          if ($role['name'] !== ROLE_PUBLIC) { // Public isn't a login role
                                             echo '<option value="' . $role['id'] . '">' . __($role['name'] . '_role') . '</option>';
                                         }
                                     }
                                     ?>
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('edit_user'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>

                    <!-- Edit Event Modal (Admin/Ulama) -->
                    <div id="editEventModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('edit_event'); ?></h3>
                        <form id="editEventForm">
                            <input type="hidden" name="id" id="edit_event_id">
                             <div class="form-group">
                                <label for="edit_event_title_en"><?php echo __('event_details_title'); ?> (EN):</label>
                                <input type="text" id="edit_event_title_en" name="title_en" required>
                            </div>
                             <div class="form-group">
                                <label for="edit_event_title_ur"><?php echo __('event_details_title'); ?> (UR):</label>
                                <input type="text" id="edit_event_title_ur" name="title_ur" required class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="edit_event_desc_en"><?php echo __('description'); ?> (EN):</label>
                                <textarea id="edit_event_desc_en" name="description_en"></textarea>
                            </div>
                             <div class="form-group">
                                <label for="edit_event_desc_ur"><?php echo __('description'); ?> (UR):</label>
                                <textarea id="edit_event_desc_ur" name="description_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>"></textarea>
                            </div>
                             <div class="form-group">
                                <label for="edit_event_date"><?php echo __('date'); ?>:</label>
                                <input type="date" id="edit_event_date" name="date">
                            </div>
                             <div class="form-group">
                                <label for="edit_event_category"><?php echo __('category'); ?>:</label>
                                <select id="edit_event_category" name="category" required>
                                    <option value="Islamic"><?php echo __('islamic'); ?></option>
                                    <option value="General"><?php echo __('general'); ?></option>
                                </select>
                            </div>
                             <div class="form-group">
                                <label for="edit_event_location"><?php echo __('location'); ?>:</label>
                                <select id="edit_event_location" name="location_id">
                                    <option value="">-- <?php echo __('select_location'); ?> --</option>
                                    <!-- Locations will be loaded here -->
                                </select>
                            </div>
                             <div class="form-actions">
                                <button type="submit"><?php echo __('edit_event'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>

                    <!-- Edit Location Modal (Admin/Ulama) -->
                    <div id="editLocationModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('edit_location'); ?></h3>
                        <form id="editLocationForm">
                             <input type="hidden" name="id" id="edit_loc_id">
                            <div class="form-group">
                                <label for="edit_loc_name_en"><?php echo __('location'); ?> (EN):</label>
                                <input type="text" id="edit_loc_name_en" name="name_en" required>
                            </div>
                             <div class="form-group">
                                <label for="edit_loc_name_ur"><?php echo __('location'); ?> (UR):</label>
                                <input type="text" id="edit_loc_name_ur" name="name_ur" required class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="edit_loc_desc_en"><?php echo __('description'); ?> (EN):</label>
                                <textarea id="edit_loc_desc_en" name="description_en"></textarea>
                            </div>
                             <div class="form-group">
                                <label for="edit_loc_desc_ur"><?php echo __('description'); ?> (UR):</label>
                                <textarea id="edit_loc_desc_ur" name="description_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="edit_loc_lat"><?php echo __('latitude'); ?>:</label>
                                <input type="number" id="edit_loc_lat" name="latitude" step="any" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_loc_lng"><?php echo __('longitude'); ?>:</label>
                                <input type="number" id="edit_loc_lng" name="longitude" step="any" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('edit_location'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>

                     <!-- Edit Hadith Modal (Admin/Ulama) -->
                    <div id="editHadithModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('edit_hadith'); ?></h3>
                        <form id="editHadithForm">
                             <input type="hidden" name="id" id="edit_hadith_id">
                             <div class="form-group">
                                <label for="edit_hadith_book_en"><?php echo __('book'); ?> (EN):</label>
                                <input type="text" id="edit_hadith_book_en" name="book_en">
                            </div>
                             <div class="form-group">
                                <label for="edit_hadith_book_ur"><?php echo __('book'); ?> (UR):</label>
                                <input type="text" id="edit_hadith_book_ur" name="book_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="edit_hadith_chapter_en"><?php echo __('chapter'); ?> (EN):</label>
                                <input type="text" id="edit_hadith_chapter_en" name="chapter_en">
                            </div>
                             <div class="form-group">
                                <label for="edit_hadith_chapter_ur"><?php echo __('chapter'); ?> (UR):</label>
                                <input type="text" id="edit_hadith_chapter_ur" name="chapter_ur" class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>">
                            </div>
                             <div class="form-group">
                                <label for="edit_hadith_reference"><?php echo __('reference'); ?>:</label>
                                <input type="text" id="edit_hadith_reference" name="reference">
                            </div>
                             <div class="form-group">
                                <label for="edit_hadith_text_en"><?php echo __('text'); ?> (EN):</label>
                                <textarea id="edit_hadith_text_en" name="text_en" required></textarea>
                            </div>
                             <div class="form-group">
                                <label for="edit_hadith_text_ur"><?php echo __('text'); ?> (UR):</label>
                                <textarea id="edit_hadith_text_ur" name="text_ur" required class="<?php echo $is_rtl ? 'urdu-text' : ''; ?>"></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit"><?php echo __('edit_hadith'); ?></button>
                            </div>
                        </form>
                      </div>
                    </div>

                     <!-- Link Ayahs Modal -->
                    <div id="linkAyahsModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('link_ayahs'); ?></h3>
                        <input type="hidden" id="link_ayahs_target_type"> <!-- 'event' or 'location' -->
                        <input type="hidden" id="link_ayahs_target_id">

                         <div class="form-group">
                            <label for="link-ayah-search"><?php echo __('search_ayahs'); ?>:</label>
                            <input type="text" id="link-ayah-search" placeholder="<?php echo __('search'); ?>...">
                        </div>

                        <h4><?php echo __('linked_ayahs'); ?></h4>
                        <div id="linked-ayahs-list" class="data-list"></div>

                        <h4><?php echo __('select_ayahs_to_link'); ?></h4>
                        <div id="available-ayahs-list" class="data-list">
                             <p><?php echo __('loading'); ?></p>
                        </div>
                      </div>
                    </div>

                     <!-- Link Hadiths Modal -->
                    <div id="linkHadithsModal" class="modal">
                      <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h3><?php echo __('link_hadiths'); ?></h3>
                        <input type="hidden" id="link_hadiths_target_type"> <!-- 'event' or 'location' -->
                        <input type="hidden" id="link_hadiths_target_id">

                         <div class="form-group">
                            <label for="link-hadith-search"><?php echo __('search_hadiths'); ?>:</label>
                            <input type="text" id="link-hadith-search" placeholder="<?php echo __('search'); ?>...">
                        </div>

                        <h4><?php echo __('linked_hadiths'); ?></h4>
                        <div id="linked-hadiths-list" class="data-list"></div>

                        <h4><?php echo __('select_hadiths_to_link'); ?></h4>
                        <div id="available-hadiths-list" class="data-list">
                             <p><?php echo __('loading'); ?></p>
                        </div>
                      </div>
                    </div>


                    <?php
                    break;

                case 'profile':
                     require_role($db, ROLE_USER);
                     $user_id = $_SESSION['user_id'] ?? null;
                     $user = $db->prepare("SELECT u.username, u.points, r.name AS role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :id");
                     $user->execute([':id' => $user_id]);
                     $user = $user->fetch();

                    if (!$user) {
                        echo "<p>User profile not found.</p>";
                        break;
                    }

                    // Gamification Badges (basic logic)
                    $badges = [];
                    if ($user['points'] >= 10) $badges[] = __('badge_beginner');
                    if ($user['points'] >= 50) $badges[] = __('badge_contributor');
                    if ($user['points'] >= 100) $badges[] = __('badge_scholar');

                    // Count authenticated hadiths by this user
                    $stmt_auth_hadiths = $db->prepare("SELECT COUNT(*) FROM hadiths WHERE authenticated_by_ulama_id = :user_id");
                    $stmt_auth_hadiths->execute([':user_id' => $user_id]);
                    $authenticated_hadith_count = $stmt_auth_hadiths->fetchColumn();
                    if ($authenticated_hadith_count >= 5) $badges[] = __('badge_authenticator');

                    // Count approved suggestions by this user
                    $stmt_approved_suggestions = $db->prepare("SELECT COUNT(*) FROM events WHERE suggested_by_user_id = :user_id AND status = 'approved'");
                    $stmt_approved_suggestions->execute([':user_id' => $user_id]);
                    $approved_suggestions_count = $stmt_approved_suggestions->fetchColumn();
                     if ($approved_suggestions_count >= 5) $badges[] = __('badge_moderator');

                     // Count bookmarks by this user
                     $stmt_bookmark_count = $db->prepare("SELECT COUNT(*) FROM user_bookmarks WHERE user_id = :user_id");
                     $stmt_bookmark_count->execute([':user_id' => $user_id]);
                     $bookmark_count = $stmt_bookmark_count->fetchColumn();
                     if ($bookmark_count >= 10) $badges[] = __('badge_explorer');


                    ?>
                    <h2><?php echo __('profile_title'); ?></h2>
                    <p><strong><?php echo __('username'); ?>:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                    <p><strong><?php echo __('role'); ?>:</strong> <?php echo __($user['role'] . '_role'); ?></p>
                    <p><strong><?php echo __('profile_points_desc'); ?></strong> <?php echo $user['points']; ?></p>

                    <h3><?php echo __('profile_badges_desc'); ?></h3>
                    <?php if (!empty($badges)): ?>
                        <ul>
                            <?php foreach($badges as $badge): ?>
                                <li><?php echo htmlspecialchars($badge); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No badges yet. Keep contributing!</p>
                    <?php endif; ?>


                    <h3><?php echo __('bookmarked_locations'); ?></h3>
                    <div class="data-list">
                        <?php
                        $bookmarks = get_user_bookmarks($db, $user_id);
                        if (!empty($bookmarks)): ?>
                            <?php foreach($bookmarks as $bookmark): ?>
                                <div class="data-item">
                                    <span>
                                        <strong><?php echo htmlspecialchars($bookmark['name']); ?></strong>
                                        (Lat: <?php echo $bookmark['latitude']; ?>, Lng: <?php echo $bookmark['longitude']; ?>)
                                    </span>
                                     <div class="actions">
                                         <button class="secondary" onclick="viewLocationDetails(<?php echo $bookmark['id']; ?>)"><?php echo __('location_details_title'); ?></button>
                                         <button class="danger" onclick="removeBookmark(<?php echo $bookmark['id']; ?>)"><?php echo __('unbookmark'); ?></button>
                                     </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php echo __('no_bookmarks'); ?></p>
                        <?php endif; ?>
                    </div>

                    <h3><?php echo __('event_suggestions_by_you'); ?></h3>
                     <div class="data-list">
                        <?php
                        $my_suggestions = get_events($db, ['user_id' => $user_id], null); // Get all statuses for user's suggestions
                        if (!empty($my_suggestions)): ?>
                            <?php foreach($my_suggestions as $event): ?>
                                <div class="data-item">
                                    <span>
                                        <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                        (<?php echo htmlspecialchars($event['date'] ?? 'N/A'); ?>) - <?php echo __('status'); ?>: <?php echo __($event['status']); ?>
                                    </span>
                                     <div class="actions">
                                         <button class="secondary" onclick="viewEventDetails(<?php echo $event['id']; ?>)"><?php echo __('event_details_title'); ?></button>
                                         <?php if ($event['status'] === 'pending'): ?>
                                            <button class="secondary" onclick="editEvent(<?php echo $event['id']; ?>)"><?php echo __('edit_event'); ?></button>
                                            <button class="danger" onclick="deleteEvent(<?php echo $event['id']; ?>)"><?php echo __('delete_event'); ?></button>
                                         <?php endif; ?>
                                     </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php echo __('no_suggestions'); ?></p>
                        <?php endif; ?>
                    </div>

                     <?php if (has_role($db, ROLE_ULAMA)): ?>
                     <h3><?php echo __('hadith_suggestions_by_you'); ?></h3>
                     <div class="data-list">
                        <?php
                        // Fetch hadith suggestions if Ulama
                        $my_hadith_suggestions = get_hadiths($db, ['authenticated_by_ulama_id' => $user_id], null); // Get all statuses for Ulama's hadiths
                        if (!empty($my_hadith_suggestions)): ?>
                            <?php foreach($my_hadith_suggestions as $hadith): ?>
                                <div class="data-item">
                                    <span>
                                        <strong><?php echo htmlspecialchars($hadith['reference'] ?? $hadith['book'] ?? 'Hadith'); ?></strong>
                                        - <?php echo __('status'); ?>: <?php echo __($hadith['status']); ?>
                                    </span>
                                     <div class="actions">
                                         <button class="secondary" onclick="viewHadithDetails(<?php echo $hadith['id']; ?>)"><?php echo __('hadiths_title'); ?></button>
                                         <?php if ($hadith['status'] !== 'approved'): ?>
                                            <button class="secondary" onclick="editHadith(<?php echo $hadith['id']; ?>)"><?php echo __('edit_hadith'); ?></button>
                                            <button class="danger" onclick="deleteHadith(${hadith.id})"><?php echo __('delete_hadith'); ?></button>
                                         <?php endif; ?>
                                     </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php echo __('no_suggestions'); ?></p>
                        <?php endif; ?>
                    </div>
                     <?php endif; // End Ulama hadith suggestions ?>


                     <!-- Re-use modals from Admin Panel/Map pages -->
                     <!-- These modals are defined globally in the HTML body -->
                     <!-- <div id="editEventModal" class="modal"></div> -->
                     <!-- <div id="editHadithModal" class="modal"></div> -->
                     <!-- <div id="detailsModal" class="modal"></div> -->


                    <?php
                    break;

                default:
                    // Redirect to home if action is unknown
                    header("Location: ?action=home");
                    exit();
            }
            ?>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20n6fxhV1LBF8B7z7ztLEEnGoq+6nbT8E+b+U6WpVg=" crossorigin=""></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Basic Embedded JavaScript -->
    <script>
        const LANG = '<?php echo $lang; ?>';
        const IS_RTL = <?php echo $is_rtl ? 'true' : 'false'; ?>;
        const CURRENT_ROLE = '<?php echo $current_role; ?>';
        const USER_ID = <?php echo $_SESSION['user_id'] ?? 'null'; ?>; // Use null if not logged in

        // Language strings in JS
        const langStrings = <?php echo json_encode($lang_strings[$lang]); ?>;
        function __js(key) {
            return langStrings[key] || key;
        }

        // Theme Toggler
        function toggleTheme() {
            const body = document.body;
            body.classList.toggle('dark-theme');
            const theme = body.classList.contains('dark-theme') ? 'dark' : 'light';
            document.cookie = `theme=${theme}; path=/; max-age=31536000`; // 1 year
        }

        // Modal Handling
        const modals = document.querySelectorAll('.modal');
        const closeButtons = document.querySelectorAll('.modal .close-button');

        closeButtons.forEach(button => {
            button.onclick = function() {
                this.closest('.modal').style.display = 'none';
            }
        });

        window.onclick = function(event) {
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
             document.getElementById(modalId).style.display = 'none';
        }


        // AJAX Helper
        async function fetchData(action, params = {}, method = 'GET') {
            const url = `?ajax=true&action=${action}&${method === 'GET' ? new URLSearchParams(params).toString() : ''}`;
            const fetchOptions = {
                method: method,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json' // Assuming most sends are JSON
                },
                 body: method !== 'GET' && method !== 'HEAD' ? JSON.stringify(params) : null // Send params as JSON body for non-GET
            };

            try {
                const response = await fetch(url, fetchOptions);

                 if (!response.ok) {
                     const errorText = await response.text();
                     console.error(`HTTP error! status: ${response.status}`, errorText);
                     // Attempt to parse JSON error response if available
                     try {
                         const errorJson = JSON.parse(errorText);
                         showFlashMessage(errorJson.message || `HTTP error: ${response.status}`, 'error');
                     } catch (e) {
                         showFlashMessage(`HTTP error: ${response.status}`, 'error');
                     }
                     throw new Error(`HTTP error! status: ${response.status}`); // Re-throw to stop execution
                 }

                const data = await response.json();
                if (!data.success) {
                    console.error("API Error:", data.message);
                    showFlashMessage(data.message, 'error');
                    // Do NOT re-throw here if you want the calling code to handle `data.success === false` without crashing
                    // return data; // Return the {success: false, message: ...} object
                } else {
                     // Optionally show success message for actions that don't redirect
                     // if (['add_bookmark', 'remove_bookmark', 'link_ayah_to_event', ...].includes(action)) {
                     //      showFlashMessage(data.message, 'success');
                     // }
                }
                return data; // Always return the data object
            } catch (error) {
                console.error('Fetch error:', error);
                 // Flash message is already shown by the response.ok check or data.success check
                return { success: false, message: error.message }; // Return a consistent structure on fetch failure
            }
        }

         async function postData(action, data) {
             return fetchData(action, data, 'POST');
         }

         async function deleteData(action, data) {
             return fetchData(action, data, 'POST'); // Using POST with data payload for simplicity in single file
         }


        // Flash Message Display
        function showFlashMessage(message, type) {
            const container = document.querySelector('.container');
            if (!container) return; // Cannot display message if container is not found

            // Remove existing flash messages before adding a new one
            container.querySelectorAll('.flash-message').forEach(msg => msg.remove());

            const flashDiv = document.createElement('div');
            flashDiv.classList.add('flash-message', `flash-${type}`);
            flashDiv.textContent = message;
            container.insertBefore(flashDiv, container.firstChild); // Insert at the top

            // Auto-remove after a few seconds
            setTimeout(() => {
                flashDiv.remove();
            }, 5000); // 5 seconds
        }


        // =============================================================================
        // 10. Page Specific JavaScript Logic
        // =============================================================================

        <?php if ($current_action === 'map'): ?>
        let map;
        let markers = L.featureGroup();
        let locationsData = [];
        let eventsData = [];

        function initMap() {
            if (map) map.remove(); // Clean up previous map if any
            map = L.map('map').setView([24.4686, 39.6148], 6); // Centered near Medina

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            markers.addTo(map);
            loadMapData();

             <?php if (has_role($db, ROLE_ULAMA)): ?>
             // Add context menu or button for adding location
             map.on('contextmenu', function(e) {
                 // Simple example: open add location modal with lat/lng pre-filled
                 document.getElementById('addLocationModal').querySelector('#add_loc_lat').value = e.latlng.lat.toFixed(4);
                 document.getElementById('addLocationModal').querySelector('#add_loc_lng').value = e.latlng.lng.toFixed(4);
                 openModal('addLocationModal');
             });
             <?php endif; ?>

             <?php if (has_role($db, ROLE_USER)): ?>
             // Load locations for suggest event modal
             loadLocationsForSelect('suggest_event_location');
             <?php endif; ?>
        }

        async function loadMapData() {
            const data = await fetchData('get_map_data');
            if (data.success) {
                locationsData = data.locations;
                eventsData = data.events; // Keep events data for linking/details
                updateMapMarkers(locationsData);
            } else {
                 showFlashMessage(data.message || __js('error_loading_data'), 'error');
            }
        }

        function updateMapMarkers(locations) {
            markers.clearLayers();
            locations.forEach(location => {
                const marker = L.marker([location.latitude, location.longitude]).addTo(markers);

                // Build popup content
                let popupContent = `<strong>${htmlspecialchars(location.name)}</strong><br>${htmlspecialchars(location.description || __js('no_description'))}`;

                // Add linked Ayahs to popup
                if (location.ayahs && location.ayahs.length > 0) {
                    popupContent += `<br><br><strong>${__js('related_ayahs')}</strong><ul>`;
                    location.ayahs.forEach(ayah => {
                         const ayahText = LANG === 'ur' ? ayah.urdu : ayah.arabic;
                         popupContent += `<li>${__js('surah_ayah_short').replace('%d', ayah.surah).replace('%d', ayah.ayah)}: <span class="${LANG === 'ur' ? 'urdu-text' : 'arabic-text'}">${htmlspecialchars(ayahText)}</span></li>`;
                    });
                     popupContent += `</ul>`;
                }

                 // Add linked Hadiths to popup
                if (location.hadiths && location.hadiths.length > 0) {
                    popupContent += `<br><br><strong>${__js('related_hadiths')}</strong><ul>`;
                    location.hadiths.forEach(hadith => {
                         const hadithText = LANG === 'ur' ? hadith.text : hadith.text_en; // Assuming text_en exists or fallback
                         popupContent += `<li>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}: <span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadithText)}</span></li>`;
                    });
                     popupContent += `</ul>`;
                }


                // Add linked events to popup
                const linkedEvents = eventsData.filter(event => event.location_id === location.id);
                if (linkedEvents.length > 0) {
                    popupContent += `<br><br><strong>${__js('events')}</strong><ul>`;
                    linkedEvents.forEach(event => {
                        popupContent += `<li>${htmlspecialchars(event.date || 'N/A')} - <a href="#" onclick="viewEventDetails(${event.id}); return false;">${htmlspecialchars(event.title)}</a></li>`;
                    });
                    popupContent += `</ul>`;
                }

                popupContent += `<br><button class="secondary" onclick="viewLocationDetails(${location.id})">${__js('location_details_title')}</button>`;

                 <?php if (has_role($db, ROLE_USER)): ?>
                 // Add bookmark button (initial state based on data)
                 const isBookmarked = location.is_bookmarked; // This comes from the get_map_data AJAX response
                 popupContent += `<button class="bookmark-button secondary" data-location-id="${location.id}">${isBookmarked ? __js('unbookmark') : __js('bookmark')}</button>`;
                 <?php endif; ?>


                marker.bindPopup(popupContent);

                 // Add event listener to popup open for bookmark button
                 marker.on('popupopen', function() {
                     const bookmarkButton = document.querySelector(`#map .leaflet-popup-content .bookmark-button[data-location-id="${location.id}"]`);
                     if (bookmarkButton) {
                         bookmarkButton.onclick = async function() {
                             const locId = this.dataset.locationId;
                             const isBookmarked = this.textContent === __js('unbookmark');
                             const action = isBookmarked ? 'remove_bookmark' : 'add_bookmark';
                             const result = await postData(action, { location_id: locId });
                             if (result.success) {
                                 // showFlashMessage(result.message, 'success'); // Handled by fetchData
                                 // Update button text
                                 this.textContent = isBookmarked ? __js('bookmark') : __js('unbookmark');
                                 // Update internal state if necessary (e.g., in locationsData)
                                 const locIndex = locationsData.findIndex(loc => loc.id == locId);
                                 if (locIndex !== -1) {
                                     locationsData[locIndex].is_bookmarked = !isBookmarked;
                                 }
                                 // If on profile page, reload bookmarks
                                 if (window.location.search.includes('action=profile')) {
                                     loadMyBookmarks();
                                 }
                             } else {
                                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
                             }
                         };
                     }
                 });
            });
        }

        async function viewEventDetails(eventId) {
            const data = await fetchData('get_event_details', { id: eventId });
            if (data.success) {
                const event = data.event;
                let content = `
                    <p><strong>${__js('date')}:</strong> ${htmlspecialchars(event.date || __js('no_data_available'))}</p>
                    <p><strong>${__js('category')}:</strong> ${__js(event.category.toLowerCase())}</p>
                    ${event.location_id ? `<p><strong>${__js('location')}:</strong> <a href="#" onclick="viewLocationDetails(${event.location_id}); return false;">${htmlspecialchars(LANG === 'ur' ? event.location_name_ur : event.location_name_en)}</a></p>` : ''}
                    <p><strong>${__js('description')}:</strong> ${htmlspecialchars(event.description || __js('no_description'))}</p>
                `;

                 // Linked Ayahs
                if (event.ayahs && event.ayahs.length > 0) {
                    content += `<br><h4>${__js('related_ayahs')}</h4><div class="data-list">`;
                    event.ayahs.forEach(ayah => {
                         const ayahText = LANG === 'ur' ? ayah.urdu : ayah.arabic;
                         content += `<div class="data-item"><span><strong>${__js('surah_ayah_short').replace('%d', ayah.surah).replace('%d', ayah.ayah)}:</strong> <span class="${LANG === 'ur' ? 'urdu-text' : 'arabic-text'}">${htmlspecialchars(ayahText)}</span></span></div>`;
                    });
                    content += `</div>`;
                }

                // Linked Hadiths
                if (event.hadiths && event.hadiths.length > 0) {
                    content += `<br><h4>${__js('related_hadiths')}</h4><div class="data-list">`;
                     event.hadiths.forEach(hadith => {
                         const hadithText = LANG === 'ur' ? hadith.text : hadith.text_en; // Assuming text_en exists or fallback
                         content += `<div class="data-item"><span><strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}:</strong> <span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadithText)}</span></span></div>`;
                    });
                    content += `</div>`;
                }

                document.getElementById('modalTitle').textContent = htmlspecialchars(event.title);
                document.getElementById('modalContent').innerHTML = content;
                openModal('detailsModal');
            } else {
                 showFlashMessage(data.message || __js('error_loading_data'), 'error');
            }
        }

        async function viewLocationDetails(locationId) {
            const data = await fetchData('get_location_details', { id: locationId });
            if (data.success) {
                const location = data.location;
                 let content = `
                    <p><strong><?php echo __('latitude'); ?>:</strong> ${location.latitude}</p>
                    <p><strong><?php echo __('longitude'); ?>:</strong> ${location.longitude}</p>
                    <p><strong>${__js('description')}:</strong> ${htmlspecialchars(location.description || __js('no_description'))}</p>
                 `;

                 // Linked Ayahs
                if (location.ayahs && location.ayahs.length > 0) {
                    content += `<br><h4>${__js('related_ayahs')}</h4><div class="data-list">`;
                    location.ayahs.forEach(ayah => {
                         const ayahText = LANG === 'ur' ? ayah.urdu : ayah.arabic;
                         content += `<div class="data-item"><span><strong>${__js('surah_ayah_short').replace('%d', ayah.surah).replace('%d', ayah.ayah)}:</strong> <span class="${LANG === 'ur' ? 'urdu-text' : 'arabic-text'}">${htmlspecialchars(ayahText)}</span></span></div>`;
                    });
                    content += `</div>`;
                }

                // Linked Hadiths
                if (location.hadiths && location.hadiths.length > 0) {
                    content += `<br><h4>${__js('related_hadiths')}</h4><div class="data-list">`;
                     location.hadiths.forEach(hadith => {
                         const hadithText = LANG === 'ur' ? hadith.text : hadith.text_en; // Assuming text_en exists or fallback
                         content += `<div class="data-item"><span><strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}:</strong> <span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadithText)}</span></span></div>`;
                    });
                    content += `</div>`;
                }


                 // Linked Events
                 if (location.events && location.events.length > 0) {
                    content += `<br><h4>${__js('events')}</h4><div class="data-list">`;
                    location.events.forEach(event => {
                        content += `<div class="data-item"><span>${htmlspecialchars(event.date || 'N/A')} - <a href="#" onclick="viewEventDetails(${event.id}); return false;">${htmlspecialchars(event.title)}</a></span></div>`;
                    });
                    content += `</div>`;
                }

                document.getElementById('modalTitle').textContent = htmlspecialchars(location.name);
                document.getElementById('modalContent').innerHTML = content;
                openModal('detailsModal');
            } else {
                 showFlashMessage(data.message || __js('error_loading_data'), 'error');
            }
        }

         <?php if (has_role($db, ROLE_ULAMA)): ?>
         // Add Location Form Submission
         document.getElementById('addLocationForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             const result = await postData('add_location', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('addLocationModal');
                 this.reset(); // Clear form
                 loadMapData(); // Reload map data to show new location
                 if (CURRENT_ROLE === '<?php echo ROLE_ADMIN; ?>') loadAdminLocations(); // Also update admin list
                 loadLocationsForSelect('suggest_event_location'); // Update location selects
                 loadLocationsForSelect('edit_event_location');
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });
         <?php endif; // End Ulama/Admin add location ?>

         <?php if (has_role($db, ROLE_USER)): ?>
         // Suggest Event Form Submission
         document.getElementById('suggestEventForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             // Convert location_id to number or null
             data.location_id = data.location_id ? parseInt(data.location_id, 10) : null;

             const result = await postData('add_event', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('suggestEventModal');
                 this.reset(); // Clear form
                 // Map might not update immediately if status is pending
                 // loadMapData(); // Optional: reload map if you show pending markers
                 if (CURRENT_ROLE !== '<?php echo ROLE_PUBLIC; ?>') loadMySuggestions(); // Update profile list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });

         async function loadLocationsForSelect(selectId) {
             const selectElement = document.getElementById(selectId);
             if (!selectElement) return;

             const data = await fetchData('get_all_locations'); // Get all locations regardless of linked events status
             if (data.success && data.locations) {
                 selectElement.innerHTML = '<option value="">-- <?php echo __('select_location'); ?> --</option>';
                 data.locations.forEach(location => {
                     const option = document.createElement('option');
                     option.value = location.id;
                     option.textContent = htmlspecialchars(location.name);
                     selectElement.appendChild(option);
                 });
             } else {
                 selectElement.innerHTML = `<option value="">${__js('error_loading_data')}</option>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive flash messages
             }
         }

         async function removeBookmark(locationId) {
             if (confirm(__js('confirm_delete'))) {
                 const result = await postData('remove_bookmark', { location_id: locationId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     // If on profile page, reload bookmarks
                     if (window.location.search.includes('action=profile')) {
                         loadMyBookmarks();
                     }
                     // Need to update map marker popup text if visible
                     // A full map reload is simplest but maybe inefficient
                     loadMapData();
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         async function loadMyBookmarks() {
             if (USER_ID === null) return; // Only for logged-in users

             const bookmarkListDiv = document.querySelector('#profile .data-list'); // Assuming the first data-list is bookmarks
             if (!bookmarkListDiv) return;

             bookmarkListDiv.innerHTML = `<p>${__js('loading')}</p>`;
             const data = await fetchData('get_user_bookmarks', { user_id: USER_ID });
             if (data.success && data.bookmarks) {
                 bookmarkListDiv.innerHTML = '';
                 if (data.bookmarks.length === 0) {
                     bookmarkListDiv.innerHTML = `<p>${__js('no_bookmarks')}</p>`;
                 } else {
                     data.bookmarks.forEach(bookmark => {
                         const item = document.createElement('div');
                         item.classList.add('data-item');
                         item.innerHTML = `
                             <span>
                                 <strong>${htmlspecialchars(bookmark.name)}</strong>
                                 (Lat: ${bookmark.latitude}, Lng: ${bookmark.longitude})
                             </span>
                             <div class="actions">
                                 <button class="secondary" onclick="viewLocationDetails(${bookmark.id})">${__js('location_details_title')}</button>
                                 <button class="danger" onclick="removeBookmark(${bookmark.id})">${__js('unbookmark')}</button>
                             </div>
                         `;
                         bookmarkListDiv.appendChild(item);
                     });
                 }
             } else {
                  bookmarkListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                  // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
             }
         }

         async function loadMySuggestions() {
              if (USER_ID === null) return; // Only for logged-in users

              const suggestionsListDiv = document.querySelector('#profile .data-list:nth-child(6)'); // Assuming this is the event suggestions list
               if (!suggestionsListDiv) return;

              suggestionsListDiv.innerHTML = `<p>${__js('loading')}</p>`;
              const data = await fetchData('get_events', { user_id: USER_ID, status: null }); // Get all statuses
              if (data.success && data.events) {
                  suggestionsListDiv.innerHTML = '';
                  if (data.events.length === 0) {
                      suggestionsListDiv.innerHTML = `<p>${__js('no_suggestions')}</p>`;
                  } else {
                      data.events.forEach(event => {
                          const item = document.createElement('div');
                          item.classList.add('data-item');
                          item.innerHTML = `
                              <span>
                                  <strong>${htmlspecialchars(event.title)}</strong>
                                  (${htmlspecialchars(event.date || 'N/A')}) - ${__js('status')}: ${__js(event.status)}
                              </span>
                              <div class="actions">
                                  <button class="secondary" onclick="viewEventDetails(${event.id})">${__js('event_details_title')}</button>
                                  ${event.status === 'pending' ? `<button class="secondary" onclick="editEvent(${event.id})">${__js('edit_event')}</button>
                                  <button class="danger" onclick="deleteEvent(${event.id})">${__js('delete_event')}</button>` : ''}
                              </div>
                          `;
                          suggestionsListDiv.appendChild(item);
                      });
                  }
              } else {
                   suggestionsListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                   // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
              }
         }

         async function loadMyHadithSuggestions() {
              if (USER_ID === null || CURRENT_ROLE !== '<?php echo ROLE_ULAMA; ?>') return; // Only for logged-in Ulama

              const hadithSuggestionsListDiv = document.querySelector('#profile .data-list:nth-child(8)'); // Assuming this is the hadith suggestions list
               if (!hadithSuggestionsListDiv) return;

              hadithSuggestionsListDiv.innerHTML = `<p>${__js('loading')}</p>`;
              const data = await fetchData('get_hadiths', { authenticated_by_ulama_id: USER_ID, status: null }); // Get all statuses
              if (data.success && data.hadiths) {
                  hadithSuggestionsListDiv.innerHTML = '';
                  if (data.hadiths.length === 0) {
                      hadithSuggestionsListDiv.innerHTML = `<p>${__js('no_suggestions')}</p>`;
                  } else {
                      data.hadiths.forEach(hadith => {
                          const item = document.createElement('div');
                          item.classList.add('data-item');
                          item.innerHTML = `
                              <span>
                                  <strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}</strong>
                                  - ${__js('status')}: ${__js(hadith.status)}
                              </span>
                              <div class="actions">
                                  <button class="secondary" onclick="viewHadithDetails(${hadith.id})"><?php echo __('hadiths_title'); ?></button>
                                  ${hadith.status !== 'approved' ? `<button class="secondary" onclick="editHadith(${hadith.id})">${__js('edit_hadith')}</button>
                                  <button class="danger" onclick="deleteHadith(${hadith.id})">${__js('delete_hadith')}</button>` : ''}
                              </div>
                          `;
                          hadithSuggestionsListDiv.appendChild(item);
                      });
                  }
              } else {
                   hadithSuggestionsListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                   // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
              }
         }


         <?php endif; // End User JS ?>

        <?php if ($current_action === 'timeline'): ?>
        async function loadTimelineData() {
            const timelineDiv = document.getElementById('timeline');
            timelineDiv.innerHTML = `<p>${__js('loading')}</p>`;
            const data = await fetchData('get_timeline_data');
            if (data.success && data.events) {
                timelineDiv.innerHTML = ''; // Clear loading
                if (data.events.length === 0) {
                     timelineDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                } else {
                    data.events.forEach(event => {
                        const item = document.createElement('div');
                        item.classList.add('timeline-item');
                        item.innerHTML = `
                            <h4>${htmlspecialchars(event.date || 'N/A')} - ${htmlspecialchars(event.title)}</h4>
                            <p>${htmlspecialchars(event.description || __js('no_description'))}</p>
                             ${event.location_id ? `<p><strong>${__js('location')}:</strong> <a href="#" onclick="viewLocationDetails(${event.location_id}); return false;">${htmlspecialchars(LANG === 'ur' ? event.location_name_ur : event.location_name_en)}</a></p>` : ''}
                            <p><em>${__js('category')}: ${__js(event.category.toLowerCase())}</em></p>
                        `;
                        timelineDiv.appendChild(item);
                    });
                }
            } else {
                 timelineDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
        }
        <?php endif; ?>

        <?php if ($current_action === 'infographics'): ?>
        let eventsPerCenturyChart, eventCategoryChart;

        async function loadInfographicsData() {
            const data = await fetchData('get_chart_data');
            if (data.success) {
                renderEventsPerCenturyChart(data.events_per_century);
                renderEventCategoryChart(data.category_distribution);
            } else {
                 showFlashMessage(data.message || __js('error_loading_data'), 'error');
            }
        }

        function renderEventsPerCenturyChart(data) {
            const ctx = document.getElementById('eventsPerCenturyChart').getContext('2d');
            const labels = data.map(item => `${item.century}-${item.century + 99}`);
            const counts = data.map(item => item.count);

            if (eventsPerCenturyChart) eventsPerCenturyChart.destroy(); // Destroy previous chart instance

            eventsPerCenturyChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: __js('count'),
                        data: counts,
                        backgroundColor: 'rgba(0, 123, 255, 0.5)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: __js('count')
                            }
                        },
                         x: {
                            title: {
                                display: true,
                                text: __js('century')
                            }
                        }
                    },
                     plugins: {
                         title: {
                             display: true,
                             text: __js('events_per_century')
                         },
                         legend: {
                            display: false // Hide legend if only one dataset
                         }
                     }
                }
            });
        }

         function renderEventCategoryChart(data) {
            const ctx = document.getElementById('eventCategoryChart').getContext('2d');
            const labels = data.map(item => __js(item.category.toLowerCase()));
            const counts = data.map(item => item.count);

            if (eventCategoryChart) eventCategoryChart.destroy(); // Destroy previous chart instance

            eventCategoryChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        label: __js('total_events'),
                        data: counts,
                        backgroundColor: [
                            'rgba(0, 123, 255, 0.7)', // Islamic
                            'rgba(108, 117, 125, 0.7)' // General
                        ],
                        borderColor: '#fff',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                     plugins: {
                         title: {
                             display: true,
                             text: __js('event_category_distribution')
                         }
                     }
                }
            });
        }

        <?php endif; ?>

        <?php if ($current_action === 'ayahs'): ?>
        async function searchAyahs(query) {
            const resultsDiv = document.getElementById('ayah-results');
            resultsDiv.innerHTML = `<p>${__js('loading')}</p>`;
            const data = await fetchData('search_ayahs', { query: query });
            if (data.success && data.ayahs) {
                resultsDiv.innerHTML = ''; // Clear loading
                 if (data.ayahs.length === 0) {
                     resultsDiv.innerHTML = `<p>${__js('no_results_found')}</p>`;
                 } else {
                    data.ayahs.forEach(ayah => {
                        const item = document.createElement('div');
                        item.classList.add('data-item');
                        item.innerHTML = `
                            <span>
                                <strong>${__js('surah')} ${ayah.surah}, ${__js('ayah')} ${ayah.ayah}</strong><br>
                                <span class="arabic-text">${htmlspecialchars(ayah.arabic)}</span><br>
                                <span class="urdu-text">${htmlspecialchars(ayah.urdu)}</span>
                            </span>
                        `;
                        resultsDiv.appendChild(item);
                    });
                 }
            } else {
                 resultsDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
        }

        document.getElementById('ayah-search')?.addEventListener('input', debounce(function() {
            searchAyahs(this.value);
        }, 300)); // Debounce search input

        // Initial load
        searchAyahs('');

        // Simple debounce function
        function debounce(func, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    func.apply(this, args);
                }, delay);
            };
        }
        <?php endif; ?>

        <?php if ($current_action === 'hadiths'): ?>
         async function searchHadiths(query) {
            const resultsDiv = document.getElementById('hadith-results');
            resultsDiv.innerHTML = `<p>${__js('loading')}</p>`;
            // Public/User only see approved hadiths, Ulama/Admin see all statuses for management
            const statusFilter = CURRENT_ROLE === '<?php echo ROLE_PUBLIC; ?>' || CURRENT_ROLE === '<?php echo ROLE_USER; ?>' ? 'approved' : null;
            const data = await fetchData('search_hadiths', { query: query, status: statusFilter });

            if (data.success && data.hadiths) {
                resultsDiv.innerHTML = ''; // Clear loading
                 if (data.hadiths.length === 0) {
                     resultsDiv.innerHTML = `<p>${__js('no_results_found')}</p>`;
                 } else {
                    data.hadiths.forEach(hadith => {
                        const item = document.createElement('div');
                        item.classList.add('data-item');
                        item.innerHTML = `
                            <span>
                                <strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}</strong><br>
                                ${hadith.book ? `<em>${__js('book')}: ${htmlspecialchars(hadith.book)}</em><br>` : ''}
                                ${hadith.chapter ? `<em>${__js('chapter')}: ${htmlspecialchars(hadith.chapter)}</em><br>` : ''}
                                <span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadith.text)}</span>
                            </span>
                        `;
                        resultsDiv.appendChild(item);
                    });
                 }
            } else {
                 resultsDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
        }

        document.getElementById('hadith-search')?.addEventListener('input', debounce(function() {
            searchHadiths(this.value);
        }, 300)); // Debounce search input

        // Initial load
        searchHadiths('');

         <?php if (has_role($db, ROLE_ULAMA)): ?>
         // Add Hadith Form Submission
         document.getElementById('addHadithForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             const result = await postData('add_hadith', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('addHadithModal');
                 this.reset(); // Clear form
                 searchHadiths(''); // Reload list
                 if (CURRENT_ROLE !== '<?php echo ROLE_PUBLIC; ?>') loadMyHadithSuggestions(); // Update profile list
                 if (CURRENT_ROLE === '<?php echo ROLE_ADMIN; ?>') loadAdminHadiths(); // Update admin list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });
         <?php endif; // End Ulama/Admin add hadith ?>

         // Re-usable Hadith Details Viewer (used on map/profile/admin)
         async function viewHadithDetails(hadithId) {
             const data = await fetchData('search_hadiths', { ids: [hadithId], status: null }); // Fetch regardless of status for details view
              if (data.success && data.hadiths && data.hadiths.length > 0) {
                 const hadith = data.hadiths[0];
                 let content = `${hadith.book ? `<strong>${__js('book')}:</strong> ${htmlspecialchars(hadith.book)}<br>` : ''}`;
                 content += `${hadith.chapter ? `<strong>${__js('chapter')}:</strong> ${htmlspecialchars(hadith.chapter)}<br>` : ''}`;
                 content += `${hadith.reference ? `<strong>${__js('reference')}:</strong> ${htmlspecialchars(hadith.reference)}<br>` : ''}`;
                 content += `<br><span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadith.text)}</span>`;

                 document.getElementById('modalTitle').textContent = htmlspecialchars(hadith.reference || hadith.book || 'Hadith Details');
                 document.getElementById('modalContent').innerHTML = content;
                 openModal('detailsModal');

             } else {
                 showFlashMessage(__js('error_loading_data'), 'error');
             }
         }


        <?php endif; ?>

        <?php if ($current_action === 'admin_panel'): ?>
        let allRoles = [];
        let allLocations = [];
        // let allAyahs = []; // Not needed to store all Ayahs in JS, search is enough
        let allHadiths = []; // Store all Hadiths for easier editing

        async function loadAdminData() {
            // Load data for modals/linking interfaces first
            const rolesData = await fetchData('get_roles');
             if (rolesData.success) allRoles = rolesData.roles;

            const locationsData = await fetchData('get_all_locations');
             if (locationsData.success) {
                 allLocations = locationsData.locations;
                 loadLocationsForSelect('edit_event_location'); // Populate select in edit event modal
             } else {
                  showFlashMessage(locationsData.message || __js('error_loading_data'), 'error');
             }

            // const ayahsData = await fetchData('get_all_ayahs'); // Get all ayahs for linking - might be too large, use search instead
            // if (ayahsData.success) allAyahs = ayahsData.ayahs;

            const hadithsData = await fetchData('get_all_hadiths'); // Get all hadiths for linking/editing
            if (hadithsData.success) allHadiths = hadithsData.hadiths;
             else showFlashMessage(hadithsData.message || __js('error_loading_data'), 'error');


            // Load main lists
            loadAdminUsers();
            loadAdminEvents();
            loadAdminLocations();
            loadAdminHadiths();
            loadBackupList();
        }

        async function loadAdminUsers() {
            const userListDiv = document.getElementById('user-list');
            userListDiv.innerHTML = `<p>${__js('loading')}</p>`;
            const data = await fetchData('admin_get_users');
            if (data.success && data.users) {
                userListDiv.innerHTML = '';
                 if (data.users.length === 0) {
                     userListDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                 } else {
                    data.users.forEach(user => {
                        const item = document.createElement('div');
                        item.classList.add('data-item');
                        item.innerHTML = `
                            <span>
                                <strong>${htmlspecialchars(user.username)}</strong> (${__js(user.role.toLowerCase() + '_role')}) - ${__js('points')}: ${user.points}
                            </span>
                            <div class="actions">
                                <button class="secondary" onclick="editUser(${user.id})"><?php echo __('edit_user'); ?></button>
                                ${user.id != 1 ? `<button class="danger" onclick="deleteUser(${user.id})"><?php echo __('delete_user'); ?></button>` : ''}
                            </div>
                        `;
                        userListDiv.appendChild(item);
                    });
                 }
            } else {
                 userListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
        }

        async function loadAdminEvents() {
            const eventListDiv = document.getElementById('admin-event-list');
            eventListDiv.innerHTML = `<p>${__js('loading')}</p>`;
            const data = await fetchData('get_events', { status: null }); // Get all statuses
            if (data.success && data.events) {
                 eventListDiv.innerHTML = '';
                 if (data.events.length === 0) {
                     eventListDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                 } else {
                    data.events.forEach(event => {
                        const item = document.createElement('div');
                        item.classList.add('data-item');
                        item.innerHTML = `
                            <span>
                                <strong>${htmlspecialchars(event.title)}</strong> (${htmlspecialchars(event.date || 'N/A')}) - ${__js('status')}: ${__js(event.status)}
                            </span>
                            <div class="actions">
                                <button class="secondary" onclick="viewEventDetails(${event.id})">${__js('event_details_title')}</button>
                                <button class="secondary" onclick="editEvent(${event.id})"><?php echo __('edit_event'); ?></button>
                                <button class="secondary" onclick="openLinkAyahsModal('event', ${event.id})"><?php echo __('link_ayahs'); ?></button>
                                <button class="secondary" onclick="openLinkHadithsModal('event', ${event.id})"><?php echo __('link_hadiths'); ?></button>
                                ${event.status === 'pending' ? `<button class="success" onclick="approveEvent(${event.id})"><?php echo __('approve'); ?></button>
                                <button class="warning" onclick="rejectEvent(${event.id})"><?php echo __('reject'); ?></button>` : ''}
                                <button class="danger" onclick="deleteEvent(${event.id})"><?php echo __('delete_event'); ?></button>
                            </div>
                        `;
                        eventListDiv.appendChild(item);
                    });
                 }
            } else {
                 eventListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
        }

         async function loadAdminLocations() {
            const locationListDiv = document.getElementById('admin-location-list');
            locationListDiv.innerHTML = `<p>${__js('loading')}</p>`;
            const data = await fetchData('get_locations', null); // Get all locations
            if (data.success && data.locations) {
                 locationListDiv.innerHTML = '';
                 if (data.locations.length === 0) {
                     locationListDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                 } else {
                    data.locations.forEach(location => {
                        const item = document.createElement('div');
                        item.classList.add('data-item');
                        item.innerHTML = `
                            <span>
                                <strong>${htmlspecialchars(location.name)}</strong> (Lat: ${location.latitude}, Lng: ${location.longitude})
                            </span>
                            <div class="actions">
                                <button class="secondary" onclick="viewLocationDetails(${location.id})">${__js('location_details_title')}</button>
                                <button class="secondary" onclick="editLocation(${location.id})"><?php echo __('edit_location'); ?></button>
                                <button class="secondary" onclick="openLinkAyahsModal('location', ${location.id})"><?php echo __('link_ayahs'); ?></button>
                                <button class="secondary" onclick="openLinkHadithsModal('location', ${location.id})"><?php echo __('link_hadiths'); ?></button>
                                <button class="danger" onclick="deleteLocation(${location.id})"><?php echo __('delete_location'); ?></button>
                            </div>
                        `;
                        locationListDiv.appendChild(item);
                    });
                 }
            } else {
                 locationListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
         }

         async function loadAdminHadiths() {
            const hadithListDiv = document.getElementById('admin-hadith-list');
            hadithListDiv.innerHTML = `<p>${__js('loading')}</p>`;
            const data = await fetchData('get_hadiths', { status: null }); // Get all statuses
             const usersData = await fetchData('admin_get_users'); // To show authenticator username
             const usersMap = {};
             if(usersData.success && usersData.users) {
                 usersData.users.forEach(user => usersMap[user.id] = user.username);
             }

            if (data.success && data.hadiths) {
                 allHadiths = data.hadiths; // Update the global list
                 hadithListDiv.innerHTML = '';
                 if (data.hadiths.length === 0) {
                     hadithListDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                 } else {
                    data.hadiths.forEach(hadith => {
                        const authenticatorName = hadith.authenticated_by_ulama_id ? htmlspecialchars(usersMap[hadith.authenticated_by_ulama_id] || 'Unknown') : __js('not_authenticated');
                        const item = document.createElement('div');
                        item.classList.add('data-item');
                        item.innerHTML = `
                            <span>
                                <strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}</strong>
                                - ${__js('status')}: ${__js(hadith.status)}
                                ${hadith.status === 'approved' ? ` (${__js('authenticated_by')}: ${authenticatorName})` : ''}
                            </span>
                            <div class="actions">
                                <button class="secondary" onclick="viewHadithDetails(${hadith.id})"><?php echo __('hadiths_title'); ?></button>
                                <button class="secondary" onclick="editHadith(${hadith.id})"><?php echo __('edit_hadith'); ?></button>
                                <button class="secondary" onclick="openLinkHadithsModal('hadith', ${hadith.id})"><?php echo __('link_hadiths'); ?></button>
                                ${hadith.status === 'pending' ? `<button class="success" onclick="approveHadith(${hadith.id})"><?php echo __('authenticate'); ?></button>
                                <button class="warning" onclick="rejectHadith(${hadith.id})"><?php echo __('reject'); ?></button>` : ''}
                                <button class="danger" onclick="deleteHadith(${hadith.id})"><?php echo __('delete_hadith'); ?></button>
                            </div>
                        `;
                        hadithListDiv.appendChild(item);
                    });
                 }
            } else {
                 hadithListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                 // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
            }
         }


        async function loadBackupList() {
             const backupListDiv = document.getElementById('backup-list');
             backupListDiv.innerHTML = `<p>${__js('loading')}</p>`;
             const data = await fetchData('admin_get_backups');
             if (data.success && data.backups) {
                  backupListDiv.innerHTML = '';
                  if (data.backups.length === 0) {
                      backupListDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                  } else {
                      data.backups.forEach(backup => {
                          const item = document.createElement('div');
                          item.classList.add('data-item');
                          const date = new Date(backup.date * 1000).toLocaleString(); // Convert timestamp to readable date
                          const sizeMB = (backup.size / (1024 * 1024)).toFixed(2); // Size in MB
                          item.innerHTML = `
                              <span>
                                  <strong>${htmlspecialchars(backup.name)}</strong> (${sizeMB} MB) - ${date}
                              </span>
                              <div class="actions">
                                  <a href="<?php echo str_replace('\\', '/', BACKUP_PATH); ?>${encodeURIComponent(backup.name)}" class="button secondary" download><?php echo __('download'); ?></a>
                                  <!-- Restore via upload form -->
                              </div>
                          `;
                          backupListDiv.appendChild(item);
                      });
                  }
             } else {
                  backupListDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                  // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
             }
        }


        // User Management
        async function editUser(userId) {
             const data = await fetchData('admin_get_users');
             if (!data.success || !data.users) {
                  showFlashMessage(data.message || __js('error_loading_data'), 'error');
                  return;
             }
             const user = data.users.find(u => u.id === userId);

             if (user) {
                 document.getElementById('editUserModal').querySelector('#edit_user_id').value = user.id;
                 document.getElementById('editUserModal').querySelector('#edit_user_username').value = user.username;
                 document.getElementById('editUserModal').querySelector('#edit_user_role').value = allRoles.find(r => r.name === user.role)?.id || '';
                 document.getElementById('editUserModal').querySelector('#edit_user_password').value = ''; // Clear password field
                 openModal('editUserModal');
             } else {
                 showFlashMessage('User not found.', 'error');
             }
        }

        async function deleteUser(userId) {
            if (confirm(__js('confirm_delete'))) {
                const result = await deleteData('admin_delete_user', { id: userId });
                if (result.success) {
                    showFlashMessage(result.message, 'success');
                    loadAdminUsers(); // Reload list
                } else {
                    // showFlashMessage(result.message, 'error'); // Handled by fetchData
                }
            }
        }

        document.getElementById('addUserForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             const result = await postData('admin_add_user', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('addUserModal');
                 this.reset(); // Clear form
                 loadAdminUsers(); // Reload list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
        });

         document.getElementById('editUserForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             data.id = parseInt(data.id, 10);

             const result = await postData('admin_edit_user', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('editUserModal');
                 // this.reset(); // Don't reset if user might edit again immediately
                 loadAdminUsers(); // Reload list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
        });

        // Event Management
         async function editEvent(eventId) {
             const data = await fetchData('get_events', { status: null }); // Fetch all statuses
             if (!data.success || !data.events) {
                 showFlashMessage(data.message || __js('error_loading_data'), 'error');
                 return;
             }
             const event = data.events.find(e => e.id === eventId);

             if (event) {
                 document.getElementById('editEventModal').querySelector('#edit_event_id').value = event.id;
                 document.getElementById('editEventModal').querySelector('#edit_event_title_en').value = event.title_en;
                 document.getElementById('editEventModal').querySelector('#edit_event_title_ur').value = event.title_ur;
                 document.getElementById('editEventModal').querySelector('#edit_event_desc_en').value = event.description_en;
                 document.getElementById('editEventModal').querySelector('#edit_event_desc_ur').value = event.description_ur;
                 document.getElementById('editEventModal').querySelector('#edit_event_date').value = event.date;
                 document.getElementById('editEventModal').querySelector('#edit_event_category').value = event.category;
                 document.getElementById('editEventModal').querySelector('#edit_event_location').value = event.location_id || ''; // Set location select
                 openModal('editEventModal');
             } else {
                 showFlashMessage('Event not found.', 'error');
             }
         }

         async function deleteEvent(eventId) {
             if (confirm(__js('confirm_delete'))) {
                 const result = await deleteData('delete_event', { id: eventId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminEvents(); // Reload admin list
                     if (window.location.search.includes('action=profile')) loadMySuggestions(); // Update profile list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         async function approveEvent(eventId) {
             if (confirm(__js('confirm_action'))) {
                 const result = await postData('approve_event', { id: eventId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminEvents(); // Reload admin list
                     if (window.location.search.includes('action=profile')) loadMySuggestions(); // Update profile list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         async function rejectEvent(eventId) {
             if (confirm(__js('confirm_action'))) {
                 const result = await postData('reject_event', { id: eventId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminEvents(); // Reload admin list
                     if (window.location.search.includes('action=profile')) loadMySuggestions(); // Update profile list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         document.getElementById('editEventForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             data.id = parseInt(data.id, 10);
             data.location_id = data.location_id ? parseInt(data.location_id, 10) : null;

             const result = await postData('edit_event', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('editEventModal');
                 // this.reset(); // Don't reset if user might edit again immediately
                 if (window.location.search.includes('action=admin_panel')) loadAdminEvents(); // Reload admin list
                 if (window.location.search.includes('action=profile')) loadMySuggestions(); // Update profile list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });

        // Location Management
        async function editLocation(locationId) {
             const data = await fetchData('get_locations', null); // Fetch all locations
             if (!data.success || !data.locations) {
                  showFlashMessage(data.message || __js('error_loading_data'), 'error');
                  return;
             }
             const location = data.locations.find(l => l.id === locationId);

             if (location) {
                 document.getElementById('editLocationModal').querySelector('#edit_loc_id').value = location.id;
                 document.getElementById('editLocationModal').querySelector('#edit_loc_name_en').value = location.name_en;
                 document.getElementById('editLocationModal').querySelector('#edit_loc_name_ur').value = location.name_ur;
                 document.getElementById('editLocationModal').querySelector('#edit_loc_desc_en').value = location.description_en;
                 document.getElementById('editLocationModal').querySelector('#edit_loc_desc_ur').value = location.description_ur;
                 document.getElementById('editLocationModal').querySelector('#edit_loc_lat').value = location.latitude;
                 document.getElementById('editLocationModal').querySelector('#edit_loc_lng').value = location.longitude;
                 openModal('editLocationModal');
             } else {
                 showFlashMessage('Location not found.', 'error');
             }
        }

         async function deleteLocation(locationId) {
             if (confirm(__js('confirm_delete'))) {
                 const result = await deleteData('delete_location', { id: locationId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminLocations(); // Reload admin list
                     loadMapData(); // Reload map
                     loadLocationsForSelect('suggest_event_location'); // Update location selects
                     loadLocationsForSelect('edit_event_location');
                     if (window.location.search.includes('action=profile')) loadMyBookmarks(); // Update profile bookmarks if needed
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         document.getElementById('editLocationForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             data.id = parseInt(data.id, 10);
             data.latitude = parseFloat(data.latitude);
             data.longitude = parseFloat(data.longitude);

             const result = await postData('edit_location', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('editLocationModal');
                 // this.reset(); // Don't reset
                 if (window.location.search.includes('action=admin_panel')) loadAdminLocations(); // Reload admin list
                 loadMapData(); // Reload map
                 loadLocationsForSelect('suggest_event_location'); // Update location selects
                 loadLocationsForSelect('edit_event_location');
                 if (window.location.search.includes('action=profile')) loadMyBookmarks(); // Update profile bookmarks if needed
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });

         // Hadith Management
         async function editHadith(hadithId) {
             // Fetch Hadith details again to ensure latest data, especially status
             const data = await fetchData('get_hadiths', { ids: [hadithId], status: null });
              if (!data.success || !data.hadiths || data.hadiths.length === 0) {
                  showFlashMessage(data.message || 'Hadith not found.', 'error');
                  return;
              }
             const hadith = data.hadiths[0];

             if (hadith) {
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_id').value = hadith.id;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_book_en').value = hadith.book_en;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_book_ur').value = hadith.book_ur;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_chapter_en').value = hadith.chapter_en;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_chapter_ur').value = hadith.chapter_ur;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_reference').value = hadith.reference;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_text_en').value = hadith.text_en;
                 document.getElementById('editHadithModal').querySelector('#edit_hadith_text_ur').value = hadith.text_ur;
                 openModal('editHadithModal');
             } else {
                 showFlashMessage('Hadith not found.', 'error');
             }
         }

         async function deleteHadith(hadithId) {
             if (confirm(__js('confirm_delete'))) {
                 const result = await deleteData('delete_hadith', { id: hadithId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminHadiths(); // Reload admin list
                      if (window.location.search.includes('action=profile')) loadMyHadithSuggestions(); // Update profile list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         async function approveHadith(hadithId) {
             if (confirm(__js('confirm_action'))) {
                 const result = await postData('approve_hadith', { id: hadithId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminHadiths(); // Reload admin list
                      if (window.location.search.includes('action=profile')) loadMyHadithSuggestions(); // Update profile list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         async function rejectHadith(hadithId) {
             if (confirm(__js('confirm_action'))) {
                 const result = await postData('reject_hadith', { id: hadithId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     if (window.location.search.includes('action=admin_panel')) loadAdminHadiths(); // Reload admin list
                      if (window.location.search.includes('action=profile')) loadMyHadithSuggestions(); // Update profile list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }

         document.getElementById('editHadithForm')?.addEventListener('submit', async function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const data = Object.fromEntries(formData.entries());
             data.id = parseInt(data.id, 10);

             const result = await postData('edit_hadith', data);
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 closeModal('editHadithModal');
                 // this.reset(); // Don't reset
                 if (window.location.search.includes('action=admin_panel')) loadAdminHadiths(); // Reload admin list
                  if (window.location.search.includes('action=profile')) loadMyHadithSuggestions(); // Update profile list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });


         // Ayah/Hadith Linking
         let currentLinkTarget = { type: null, id: null };

         async function openLinkAyahsModal(targetType, targetId) {
             currentLinkTarget = { type: targetType, id: targetId };
             document.getElementById('linkAyahsModal').querySelector('#link_ayahs_target_type').value = targetType;
             document.getElementById('linkAyahsModal').querySelector('#link_ayahs_target_id').value = targetId;

             document.getElementById('linkAyahsModal').querySelector('#link-ayah-search').value = ''; // Clear search
             loadLinkedAyahs(targetType, targetId);
             loadAvailableAyahs(''); // Load all initially

             openModal('linkAyahsModal');
         }

         async function loadLinkedAyahs(targetType, targetId) {
             const listDiv = document.getElementById('linked-ayahs-list');
             listDiv.innerHTML = `<p>${__js('loading')}</p>`;

             const data = await fetchData('get_linked_ayahs', { target_type: targetType, target_id: targetId });

             if (data.success && data.ayahs) {
                 listDiv.innerHTML = '';
                 if (data.ayahs.length === 0) {
                      listDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                 } else {
                     data.ayahs.forEach(ayah => {
                         const item = document.createElement('div');
                         item.classList.add('data-item');
                         const ayahText = LANG === 'ur' ? ayah.urdu : ayah.arabic;
                         item.innerHTML = `
                             <span>
                                 <strong>${__js('surah')} ${ayah.surah}, ${__js('ayah')} ${ayah.ayah}</strong><br>
                                 <span class="${LANG === 'ur' ? 'urdu-text' : 'arabic-text'}">${htmlspecialchars(ayahText.substring(0, 100))}...</span>
                             </span>
                             <div class="actions">
                                 <button class="danger" onclick="unlinkAyah(${ayah.id}, '${targetType}', ${targetId})">${__js('unlink')}</button>
                             </div>
                         `;
                         listDiv.appendChild(item);
                     });
                 }
             } else {
                  listDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                  // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
             }
         }

         async function loadAvailableAyahs(query) {
             const listDiv = document.getElementById('available-ayahs-list');
             listDiv.innerHTML = `<p>${__js('loading')}</p>`;
             const data = await fetchData('search_ayahs', { query: query }); // Re-use search endpoint
             if (data.success && data.ayahs) {
                 listDiv.innerHTML = '';
                 if (data.ayahs.length === 0) {
                     listDiv.innerHTML = `<p>${__js('no_results_found')}</p>`;
                 } else {
                     data.ayahs.forEach(ayah => {
                         const item = document.createElement('div');
                         item.classList.add('data-item');
                         const ayahText = LANG === 'ur' ? ayah.urdu : ayah.arabic;
                         item.innerHTML = `
                             <span>
                                 <strong>${__js('surah')} ${ayah.surah}, ${__js('ayah')} ${ayah.ayah}</strong><br>
                                 <span class="${LANG === 'ur' ? 'urdu-text' : 'arabic-text'}">${htmlspecialchars(ayahText.substring(0, 100))}...</span>
                             </span>
                             <div class="actions">
                                 <button class="success" onclick="linkAyah(${ayah.id})">${__js('link')}</button>
                             </div>
                         `;
                         listDiv.appendChild(item);
                     });
                 }
             } else {
                  listDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                  // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
             }
         }

         document.getElementById('linkAyahsModal')?.querySelector('#link-ayah-search')?.addEventListener('input', debounce(function() {
             loadAvailableAyahs(this.value);
         }, 300));

         async function linkAyah(ayahId) {
             const targetType = document.getElementById('link_ayahs_target_type').value;
             const targetId = document.getElementById('link_ayahs_target_id').value;
             const action = targetType === 'event' ? 'link_ayah_to_event' : 'link_ayah_to_location';

             const result = await postData(action, { ayah_id: ayahId, [targetType + '_id']: targetId });
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 loadLinkedAyahs(targetType, targetId); // Refresh linked list
                 loadAvailableAyahs(document.getElementById('linkAyahsModal').querySelector('#link-ayah-search').value); // Refresh available list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         }

         async function unlinkAyah(ayahId, targetType, targetId) {
              if (confirm(__js('confirm_delete'))) {
                 const action = targetType === 'event' ? 'unlink_ayah_from_event' : 'unlink_ayah_from_location';

                 const result = await deleteData(action, { ayah_id: ayahId, [targetType + '_id']: targetId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     loadLinkedAyahs(targetType, targetId); // Refresh linked list
                     loadAvailableAyahs(document.getElementById('linkAyahsModal').querySelector('#link-ayah-search').value); // Refresh available list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }


         async function openLinkHadithsModal(targetType, targetId) {
             currentLinkTarget = { type: targetType, id: targetId };
             document.getElementById('linkHadithsModal').querySelector('#link_hadiths_target_type').value = targetType;
             document.getElementById('linkHadithsModal').querySelector('#link_hadiths_target_id').value = targetId;

             document.getElementById('linkHadithsModal').querySelector('#link-hadith-search').value = ''; // Clear search
             loadLinkedHadiths(targetType, targetId);
             loadAvailableHadiths(''); // Load all initially

             openModal('linkHadithsModal');
         }

         async function loadLinkedHadiths(targetType, targetId) {
             const listDiv = document.getElementById('linked-hadiths-list');
             listDiv.innerHTML = `<p>${__js('loading')}</p>`;

             const data = await fetchData('get_linked_hadiths', { target_type: targetType, target_id: targetId });

             if (data.success && data.hadiths) {
                 listDiv.innerHTML = '';
                 if (data.hadiths.length === 0) {
                      listDiv.innerHTML = `<p>${__js('no_data_available')}</p>`;
                 } else {
                     data.hadiths.forEach(hadith => {
                         const item = document.createElement('div');
                         item.classList.add('data-item');
                         const hadithText = LANG === 'ur' ? hadith.text : hadith.text_en;
                         item.innerHTML = `
                             <span>
                                 <strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}</strong><br>
                                 <span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadithText.substring(0, 100))}...</span>
                             </span>
                             <div class="actions">
                                 <button class="danger" onclick="unlinkHadith(${hadith.id}, '${targetType}', ${targetId})">${__js('unlink')}</button>
                             </div>
                         `;
                         listDiv.appendChild(item);
                     });
                 }
             } else {
                  listDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                  // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
             }
         }

         async function loadAvailableHadiths(query) {
             const listDiv = document.getElementById('available-hadiths-list');
             listDiv.innerHTML = `<p>${__js('loading')}</p>`;
             const data = await fetchData('search_hadiths', { query: query, status: 'approved' }); // Only link approved hadiths
             if (data.success && data.hadiths) {
                 listDiv.innerHTML = '';
                 if (data.hadiths.length === 0) {
                     listDiv.innerHTML = `<p>${__js('no_results_found')}</p>`;
                 } else {
                     data.hadiths.forEach(hadith => {
                         const item = document.createElement('div');
                         item.classList.add('data-item');
                         const hadithText = LANG === 'ur' ? hadith.text : hadith.text_en;
                         item.innerHTML = `
                             <span>
                                 <strong>${htmlspecialchars(hadith.reference || hadith.book || 'Hadith')}</strong><br>
                                 <span class="${LANG === 'ur' ? 'urdu-text' : ''}">${htmlspecialchars(hadithText.substring(0, 100))}...</span>
                             </span>
                             <div class="actions">
                                 <button class="success" onclick="linkHadith(${hadith.id})">${__js('link')}</button>
                             </div>
                         `;
                         listDiv.appendChild(item);
                     });
                 }
             } else {
                  listDiv.innerHTML = `<p>${__js('error_loading_data')}</p>`;
                  // showFlashMessage(data.message || __js('error_loading_data'), 'error'); // Avoid excessive messages
             }
         }

         document.getElementById('linkHadithsModal')?.querySelector('#link-hadith-search')?.addEventListener('input', debounce(function() {
             loadAvailableHadiths(this.value);
         }, 300));

         async function linkHadith(hadithId) {
             const targetType = document.getElementById('link_hadiths_target_type').value;
             const targetId = document.getElementById('link_hadiths_target_id').value;
             const action = targetType === 'event' ? 'link_hadith_to_event' : 'link_hadith_to_location';

             const result = await postData(action, { hadith_id: hadithId, [targetType + '_id']: targetId });
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 loadLinkedHadiths(targetType, targetId); // Refresh linked list
                 loadAvailableHadiths(document.getElementById('linkHadithsModal').querySelector('#link-hadith-search').value); // Refresh available list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         }

         async function unlinkHadith(hadithId, targetType, targetId) {
              if (confirm(__js('confirm_delete'))) {
                 const action = targetType === 'event' ? 'unlink_hadith_from_event' : 'unlink_hadith_from_location';

                 const result = await deleteData(action, { hadith_id: hadithId, [targetType + '_id']: targetId });
                 if (result.success) {
                     showFlashMessage(result.message, 'success');
                     loadLinkedHadiths(targetType, targetId); // Refresh linked list
                     loadAvailableHadiths(document.getElementById('linkHadithsModal').querySelector('#link-hadith-search').value); // Refresh available list
                 } else {
                     // showFlashMessage(result.message, 'error'); // Handled by fetchData
                 }
             }
         }


         // Backup/Restore
         document.getElementById('backup-db-button')?.addEventListener('click', async function() {
             this.disabled = true;
             this.textContent = __js('loading');
             const result = await fetchData('admin_backup_db');
             this.disabled = false;
             this.textContent = __js('backup_now');
             if (result.success) {
                 showFlashMessage(result.message, 'success');
                 loadBackupList(); // Refresh list
             } else {
                 // showFlashMessage(result.message, 'error'); // Handled by fetchData
             }
         });

         document.getElementById('restoreForm')?.addEventListener('submit', function() {
             // Show loading message during upload/restore
             showFlashMessage(__js('restoring'), 'info');
             // The PHP handles the rest and redirects
         });


        <?php endif; // End Admin Panel JS ?>

         <?php if ($current_action === 'profile'): ?>
         // Re-use edit/delete functions from Admin panel if user has role
         // No need to redefine, just ensure modals are included and functions are global or accessible
         // Modals are included via PHP includes or template structure
         // Functions like editEvent, deleteEvent, editHadith, deleteHadith, viewEventDetails, viewLocationDetails, viewHadithDetails need to be globally accessible or attached to window
         // They are already defined within the <script> block, so they should be accessible.
         // The modals are included as placeholders and will be populated by the JS functions.
         <?php endif; // End profile case JS ?>


        // =============================================================================
        // 11. Initial Load Logic
        // =============================================================================

        document.addEventListener('DOMContentLoaded', () => {
            const action = '<?php echo $current_action; ?>';
            switch (action) {
                case 'map':
                    initMap();
                    break;
                case 'timeline':
                    loadTimelineData();
                    break;
                case 'infographics':
                    loadInfographicsData();
                    break;
                 case 'ayahs':
                     // Initial searchAyahs('') is called after function definition
                     break;
                 case 'hadiths':
                     // Initial searchHadiths('') is called after function definition
                     break;
                case 'admin_panel':
                    loadAdminData();
                    break;
                 case 'profile':
                    <?php if (has_role($db, ROLE_USER)): ?>
                     // loadMyBookmarks() and loadMySuggestions() are called in PHP, but can be re-called here if needed
                     // loadMyBookmarks(); // Called in PHP
                     // loadMySuggestions(); // Called in PHP
                     <?php if (has_role($db, ROLE_ULAMA)): ?>
                     // loadMyHadithSuggestions(); // Called in PHP
                     <?php endif; // End Ulama check inside DOMContentLoaded ?>
                     <?php endif; // End User check inside DOMContentLoaded ?>
                     break;
                default:
                    // Home page or others, no specific JS init needed yet
                    break;
            }
        });

        // Helper function for HTML escaping
        function htmlspecialchars(str) {
             if (typeof str !== 'string') return str;
             return str.replace(/&/g, '&amp;')
                       .replace(/</g, '&lt;')
                       .replace(/>/g, '&gt;')
                       .replace(/"/g, '&quot;')
                       .replace(/'/g, '&#039;');
        }


    </script>
</body>
</html>