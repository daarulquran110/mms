<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


date_default_timezone_set('Asia/Karachi'); // FIX: Force Karachi Time
session_start();

ob_start();


// --- 1. CONFIGURATION ---


$host = 'localhost';
$dbname = 'your_db_name';
$user = 'your_user';
$pass = 'your_password';


try {

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Connection Failed: " . $e->getMessage());
}


// --- LOAD SETTINGS GLOBALLY ---
$sys = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

// Helper Function: Get Setting
function getSet($key) {
    global $sys;
    return isset($sys[$key]) ? $sys[$key] : '';
}

// Helper Function: Get Options for Select
function getOptions($key) {
    $raw = getSet($key);
    $arr = explode(',', $raw);
    $html = '';
    foreach($arr as $val) {
        $val = trim($val);
        if($val) $html .= "<option value='$val'>$val</option>";
    }
    return $html;
}

function classCapacityExists($pdo) {
    static $exists;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $res = $pdo->query("SHOW COLUMNS FROM classmanifest LIKE 'capacity'")->fetch();
        $exists = !empty($res);
    } catch (Exception $e) {
        $exists = false;
    }
    return $exists;
}

function getClassCapacity($pdo, $classId) {
    if (!classCapacityExists($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("SELECT capacity FROM classmanifest WHERE Class = ?");
        $stmt->execute([$classId]);
        $cap = $stmt->fetchColumn();
        return ($cap > 0) ? $cap : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Set Timezone Dynamically
$tz = getSet('timezone');
if($tz) {
    date_default_timezone_set($tz);
} else {
    date_default_timezone_set('Asia/Karachi'); // Fallback
}

// --- 2. DATABASE SELF-HEALING & ID FIXER ---

function dbUpgrade($pdo)
{
    // 1. ALL TABLES CREATION (Fresh Install + Upgrade Safe)
    $sqls = [
        // --- A. CORE SYSTEM TABLES ---
        "CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` varchar(50) NOT NULL,
            `setting_value` text,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `users` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `Username` varchar(50) NOT NULL,
            `Password` varchar(255) NOT NULL,
            `Role` varchar(20) DEFAULT 'user',
            `FullName` varchar(100),
            `IsActive` tinyint(1) DEFAULT 1,
            PRIMARY KEY (`Id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `system_roles` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `RoleName` varchar(50) NOT NULL UNIQUE,
            `Permissions` text,
            PRIMARY KEY (`Id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // --- B. ACADEMIC STRUCTURE ---
        "CREATE TABLE IF NOT EXISTS `enrollmentsession` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `Name` varchar(100) NOT NULL,
            `IsActive` tinyint(1) DEFAULT 0,
            `Timings` varchar(100) DEFAULT NULL,
            `SortOrder` int(11) DEFAULT 0,
            PRIMARY KEY (`Id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `classmanifest` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `ClassName` varchar(100) NOT NULL,
            `Section` varchar(50),
            `WhatsappLink` text,
            `MinAge` int(11) DEFAULT 0,
            `MaxAge` int(11) DEFAULT 99,
            `assigned_user_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`Id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // --- C. STUDENTS & ENROLLMENT ---
        "CREATE TABLE IF NOT EXISTS `students` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `Name` varchar(100) NOT NULL,
            `FatherName` varchar(100),
            `Gender` enum('Male','Female') DEFAULT 'Male',
            `Dob` date,
            `MobileNumber` varchar(20),
            `Address` text,
            `School` text,
            `Cnic` varchar(20),
            `Notes` text,
            PRIMARY KEY (`Id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `enrollment` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `StudentId` int(11) NOT NULL,
            `SessionId` int(11) NOT NULL,
            `ClassId` int(11) NOT NULL,
            `EnrollmentDate` date,
            `IsActive` tinyint(1) DEFAULT 1,
            `enrollment_fee` decimal(10,2) DEFAULT 0,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`Id`),
            UNIQUE KEY `unique_enr` (`StudentId`, `SessionId`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `attendance` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `enrollment_id` int(11) NOT NULL,
            `date` date NOT NULL,
            `status` varchar(20) NOT NULL,
            `marked_by` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_att` (`enrollment_id`, `date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // --- D. EXAMS & RESULTS ---
        "CREATE TABLE IF NOT EXISTS `exams` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `session_id` int(11) NOT NULL,
            `class_id` int(11) NOT NULL,
            `total_marks` int(11) NOT NULL,
            `date` date,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `exam_results` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `exam_id` int(11) NOT NULL,
            `enrollment_id` int(11) NOT NULL,
            `obtained_marks` float NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_result` (`exam_id`, `enrollment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // --- E. FINANCE & CARDS ---
        "CREATE TABLE IF NOT EXISTS `hasanaat_cards` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `CardNumber` varchar(50) NOT NULL UNIQUE,
            `HolderName` varchar(100) NOT NULL,
            `FatherName` varchar(100),
            `Reference` varchar(100),
            `Mobile` varchar(20),
            `CardType` varchar(50) DEFAULT 'Standard',
            `TotalAmount` decimal(15,2) DEFAULT 0,
            `IssueDate` date,
            `Status` enum('Active','Completed','Cancelled') DEFAULT 'Active',
            `Notes` text,
            PRIMARY KEY (`Id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `hasanaat_payments` (
            `Id` int(11) NOT NULL AUTO_INCREMENT,
            `CardId` int(11) NOT NULL,
            `Amount` decimal(15,2) NOT NULL,
            `Date` date NOT NULL,
            `ReceivedBy` int(11),
            `Remarks` text,
            PRIMARY KEY (`Id`),
            FOREIGN KEY (`CardId`) REFERENCES `hasanaat_cards`(`Id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `general_income` (`Id` int(11) NOT NULL AUTO_INCREMENT, `Title` varchar(255), `Category` varchar(100), `Amount` decimal(15,2), `Date` date, `Description` text, `ReceivedBy` int(11), PRIMARY KEY (`Id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS `general_expenses` (`Id` int(11) NOT NULL AUTO_INCREMENT, `Title` varchar(255), `Category` varchar(100), `Amount` decimal(15,2), `Date` date, `Description` text, `AddedBy` int(11), PRIMARY KEY (`Id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // --- F. EXTRAS ---
        "CREATE TABLE IF NOT EXISTS `certificates` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `type` varchar(50), `title` varchar(255), `description` text, `issued_date` date, `issued_by` varchar(100), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS `prizes` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `prize_name` varchar(255), `reason` varchar(255), `cost` decimal(10,2), `date` date, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS `teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100), `gender` enum('Male','Female'), `phone` varchar(20), `user_id` int(11), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS `activity_logs` (`id` int(11) NOT NULL AUTO_INCREMENT, `user_id` int(11), `action` varchar(50), `details` text, `ip_address` varchar(45), `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
"CREATE TABLE IF NOT EXISTS `monthly_fees` (
    `Id` int(11) NOT NULL AUTO_INCREMENT,
    `EnrollmentId` int(11) NOT NULL,
    `Amount` decimal(10,2) NOT NULL,
    `Month` int(2) NOT NULL,
    `Year` int(4) NOT NULL,
    `Status` enum('Paid', 'Unpaid') DEFAULT 'Unpaid',
    `PaidDate` date DEFAULT NULL,
    `CollectedBy` int(11) DEFAULT NULL,
    PRIMARY KEY (`Id`),
    UNIQUE KEY `unique_monthly` (`EnrollmentId`, `Month`, `Year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS `events` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255), `start_date` date, `end_date` date, `description` text, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    // Execute Creations
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }

// 2. COLUMNS ADD KARNA
    try { $pdo->exec("ALTER TABLE `classmanifest` ADD COLUMN `session_id` INT(11) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `enrollmentsession` ADD COLUMN `Timings` VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `enrollmentsession` ADD COLUMN `SortOrder` INT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `enrollment` ADD COLUMN `enrollment_fee` DECIMAL(10,2) DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `classmanifest` ADD COLUMN `WhatsappLink` TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `classmanifest` ADD COLUMN `assigned_user_id` INT(11) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `students` ADD COLUMN `Address` TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `teachers` ADD COLUMN `user_id` INT(11) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `bg_image` VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `text_color` VARCHAR(50) DEFAULT '#1a3c34'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `font_family` VARCHAR(100) DEFAULT 'Cinzel'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `exam_results` ADD COLUMN `calculated_payout` INT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `exams` MODIFY `class_id` VARCHAR(50)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `exams` MODIFY `session_id` VARCHAR(50)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `exams` ADD COLUMN `date` DATE"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `exams` ADD COLUMN `att_start_date` DATE"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `exams` ADD COLUMN `att_end_date` DATE"); } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `round_table_records` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` VARCHAR(50),
            `class_id` VARCHAR(50),
            `date` DATE,
            `enrollment_id` INT,
            `position` INT,
            `reward_amount` INT
        )");
    } catch (Exception $e) {}
    // NEW: Detailed Color Controls
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `color_title` VARCHAR(50) DEFAULT '#6b4c3a'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `color_name` VARCHAR(50) DEFAULT '#000000'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `color_badge` VARCHAR(50) DEFAULT '#FFFFFF'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `bg_badge` VARCHAR(50) DEFAULT '#5A3A22'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `cert_logo` VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `custom_season` VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `custom_session` VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `certificates` ADD COLUMN `custom_class` VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

try { 
    $pdo->exec("ALTER TABLE `enrollment` ADD COLUMN `individual_monthly_fee` DECIMAL(10,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE `enrollment` ADD COLUMN `concession_remarks` TEXT"); 
    $pdo->exec("ALTER TABLE `enrollment` ADD COLUMN `collected_by` INT(11) DEFAULT NULL"); 
} catch (Exception $e) {}

    // 3. FACTORY DEFAULTS
    $defaults = [
        'app_name' => 'Management System',
        'inst_name' => 'Institute Name',
        'inst_address' => 'Address Line 1, City',
'inst_phone' => '0000-0000000',
        'inst_logo'  => 'logo.png',         // Naya (Dynamic Logo)
        'cert_title' => 'CERTIFICATE OF APPRECIATION',
        'cert_sign'  => 'Authorized Signature',         
        'class_capacity' => '25',
        'start_date' => date('d M Y'), 
        'currency_symbol' => 'PKR',
        'timezone' => 'Asia/Karachi',
'cutoff_day' => '31',             
        'cutoff_month' => '05',           
        'kids_max_age' => '9',            
        'kids_min_age' => '5',
        // EXAM & PRIZE BUDGET FORMULA SETTINGS
        'prize_rate_present' => '37',
        'prize_rate_late'    => '25',
        'prize_rate_pct'     => '50',
        'prize_round_to'     => '10',
        'currency_denominations' => '1000,500,100,75,50,20,10',
        'game_reward_1st' => '70',
        'game_reward_2nd' => '50',
        'game_reward_3rd' => '30',

        // Dynamic Dropdowns
        'opt_expense_cats' => 'Utility Bills,Rent,Salary,Maintenance,Stationery,Other',
        'opt_income_cats'  => 'Fees,Donation,Zakat,Sadqa,Other',
        'opt_prize_reasons'=> 'Position Holder,Full Attendance,Good Akhlaq,Hifz Progress',
        'opt_card_types'   => 'Standard,Silver,Gold,Platinum'
    ];

    foreach ($defaults as $key => $val) {
        $stmt = $pdo->prepare("SELECT setting_key FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->rowCount() == 0) {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $val]);
        }
    }

    // 4. AUTO-FIX MISSING IDs (Critical)
    // Ye code ab function ke andar hai (Jo pehle bahar reh gaya tha)
    $ghosts = $pdo->query("SELECT COUNT(*) FROM students WHERE Id = 0")->fetchColumn();
    if ($ghosts > 0) {
        $maxId = $pdo->query("SELECT MAX(Id) FROM students")->fetchColumn();
        $pdo->exec("UPDATE students SET Id = $maxId + 1 WHERE Id = 0 LIMIT 1");
    }
}

dbUpgrade($pdo); // Function Call

// --- 3. HELPER FUNCTIONS ---
function redirect($url)
{
    header("Location: $url");
    exit;
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}



function can($permission)
{

    if (!isLoggedIn())
        return false;

    if ($_SESSION['role'] === 'admin')
        return true;

    global $pdo;

    if (!isset($_SESSION['my_permissions'])) {

        $stmt = $pdo->prepare("SELECT permissions FROM system_roles WHERE role_name = ?");

        $stmt->execute([$_SESSION['role']]);

        $perms = $stmt->fetchColumn();

        $_SESSION['my_permissions'] = $perms ? explode(',', $perms) : [];

    }

    return in_array($permission, $_SESSION['my_permissions']);

}



function logAction($action, $details = '')
{

    global $pdo;

    try {

        $uid = $_SESSION['user_id'] ?? null;

        $ip = $_SERVER['REMOTE_ADDR'];

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");

        $stmt->execute([$uid, $action, $details, $ip]);

    } catch (Exception $e) {
    }

}



function getActiveSessions($pdo)
{

    return $pdo->query("SELECT Id, Name FROM enrollmentsession WHERE IsActive = 1")->fetchAll();

}


function calculateAge($dob) {
    if (empty($dob)) return 0;
    
    // Policy settings se uthao, warna default 31-05
    $d = getSet('cutoff_day') ?: '31';
    $m = getSet('cutoff_month') ?: '05';
    $y = date('Y'); // CURRENT YEAR AUTO!
    
    $targetDate = new DateTime("$y-$m-$d");
    $birthDate = new DateTime($dob);
    
    $age = $targetDate->diff($birthDate);
    return $age->y;
}

function pickSessionForStudent($sessions, $dob, $gender)
{
    if (empty($sessions)) return 0;

    $age = calculateAge($dob);
    $g = strtolower(trim($gender ?? ''));
    $isMale = (isset($g[0]) && $g[0] === 'm') || strpos($g, 'boy') !== false;
    $isFemale = (isset($g[0]) && $g[0] === 'f') || strpos($g, 'girl') !== false;

    // --- NEW 4-WAY LOGIC (Controlled via Settings) ---

    // 1. KIDS (5-9)
$kidsMinAge = getSet('kids_min_age') ?: 5;
$kidsMaxAge = getSet('kids_max_age') ?: 9;
    if ($age >= $kidsMinAge && $age <= $kidsMaxAge) {
        if ($isFemale) {
             return getSet('logic_sess_kid_girls') ?: $sessions[0]['Id'];
        } else {
             return getSet('logic_sess_kid_boys') ?: $sessions[0]['Id'];
        }
    }

    // 2. ADULTS (10+)
    if ($age > $kidsMaxAge) {
        if ($isFemale) {
            return getSet('logic_sess_adult_girls') ?: $sessions[0]['Id'];
        } else {
            return getSet('logic_sess_adult_boys') ?: $sessions[0]['Id'];
        }
    }

    // Fallback
    return $sessions[0]['Id'];
}

function formatName($name)
{

    return ucwords(strtolower(trim($name)));

}

function convertToUserTime($serverTimestamp)
{
    if (!$serverTimestamp || $serverTimestamp == '0000-00-00' || $serverTimestamp == '0000-00-00 00:00:00')
        return '-';
try {
        $tzString = getSet('timezone') ?: 'Asia/Karachi';
        $dateTime = new DateTime($serverTimestamp, new DateTimeZone('UTC'));
        $dateTime->setTimezone(new DateTimeZone($tzString));

        $datePart = $dateTime->format('d M Y');
        $hasTime = strlen($serverTimestamp) > 10;
        $timePart = $hasTime ? $dateTime->format('h:i A') : '';
        
        $tzLabel = explode('/', $tzString);
        $tzCity = end($tzLabel); // Extracts "Karachi" from "Asia/Karachi"

        return '<span class="ltr-date">' . $datePart .
            ($hasTime ? '<br>' . $timePart : '') .
            '<br><small>' . $tzCity . ' Time</small></span>';
    } catch (Exception $e) {
        return $serverTimestamp;
    }
}

function formatDate($date)
{

    return $date ? date('d/m/Y', strtotime($date)) : '-';

}



// --- Helper: Generate WhatsApp Message ---

function generateWhatsappMessage($student, $className, $sessionName, $startTime, $endTime, $startDate)
{

    $name = $student['Name'];

    return "$name, your class is $className in $sessionName and your class timings are $startTime - $endTime. Your class will start on $startDate";

}




// --- Helper: Auto Enroll Logic ---

function tryAutoEnroll($pdo, $studentId, $dob, $gender, $manualClassId = null, $fee = 0)
{
    // FIX 1: Ensure Fee is a number (Handle empty string case)
    $fee = (is_numeric($fee) && $fee !== '') ? $fee : 0;

    if (!can('enroll_student'))
        return 0;

    $sess = getActiveSessions($pdo);

    if (!$sess)
        return 0;

    $targetClass = null;

    if (!empty($manualClassId)) {
        $targetClass = $manualClassId;
    } else {
        $age = calculateAge($dob);
        $genderRaw = strtolower(trim($gender ?? ''));
        $type = (isset($genderRaw[0]) && $genderRaw[0] === 'm') ? 'BoysClass' : 'GirlsClass';

        $match = $pdo->prepare("SELECT Class FROM classmanifest WHERE EnrollmentType = ? AND ? BETWEEN MinAge AND MaxAge ORDER BY MinAge DESC LIMIT 1");
        $match->execute([$type, $age]);
        $cls = $match->fetch();
        $targetClass = $cls ? $cls['Class'] : null;
    }

    if ($targetClass) {
        $sessionId = pickSessionForStudent($sess, $dob, $gender);

        $classCap = getClassCapacity($pdo, $targetClass);
        $maxCap = ($classCap > 0) ? $classCap : (getSet('class_capacity') ?: 25);

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM enrollment WHERE Class = ? AND IsActive = 1");
        $stmtCount->execute([$targetClass]);
        if ($stmtCount->fetchColumn() >= $maxCap) {
            return 0;
        }

        $newEnrId = $pdo->query("SELECT COALESCE(MAX(Id), 0) + 1 FROM enrollment")->fetchColumn();
        $collectedBy = $_SESSION['user_id'] ?? null;

        // FIX 2: Removed 'created_at' from INSERT to prevent 500 Error if column is missing
        // It now matches the working 'manual_assign' logic
        $pdo->prepare("INSERT INTO enrollment (Id,StudentId,Class,EnrollmentSessionId,IsActive,enrollment_fee,individual_monthly_fee,collected_by) VALUES (?,?,?,?,1,?,?,?)")
            ->execute([$newEnrId, $studentId, $targetClass, $sessionId, $fee, $fee, $collectedBy]);

        logAction('AutoEnroll', "Student:$studentId Class:$targetClass Session:$sessionId Fee:$fee");

        return $newEnrId;
    }

    return 0;
}

// =========================================================
//  SERVER-SIDE DATA HANDLER (Paste BEFORE "Backend Actions")
// =========================================================

if (isset($_GET['action']) && ($_GET['action'] === 'fetch_students' || $_GET['action'] === 'fetch_session_students')) {
    if (!can('student_view')) {
        echo json_encode(['data' => []]);
        exit;
    }

    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    // Columns Tarteeb Ab Yeh Hai: Session(7), Class(8), Fee(9)
    $columns = [
        0 => 's.Id', 1 => 's.Name', 2 => 's.Paternity', 3 => null, 4 => 's.MobileNumberFather',
        5 => null, 6 => 's.DOB', 7 => 'es.Name', 8 => 'c.ClassName', 9 => 'e.enrollment_fee', 10 => null
    ];

    $orderBy = $columns[$orderColIndex] ?? 's.Id';

    $sqlBase = " FROM students s 
                 LEFT JOIN enrollment e ON s.Id = e.StudentId AND e.IsActive=1 
                 LEFT JOIN classmanifest c ON e.Class = c.Class 
                 LEFT JOIN enrollmentsession es ON e.EnrollmentSessionId = es.Id ";

    $where = " WHERE 1=1 ";
    if (!empty($_GET['session_id'])) {
        $where .= " AND e.EnrollmentSessionId = " . intval($_GET['session_id']);
    }

    $params = [];
    if (!empty($searchValue)) {
        $where .= " AND (
            CONVERT(s.Id USING utf8mb4) LIKE ? OR CONVERT(s.Name USING utf8mb4) LIKE ? OR
            CONVERT(s.Paternity USING utf8mb4) LIKE ? OR CONVERT(s.MobileNumberFather USING utf8mb4) LIKE ? OR
            CONVERT(c.ClassName USING utf8mb4) LIKE ? OR CONVERT(es.Name USING utf8mb4) LIKE ?
        )";
        $term = "%$searchValue%";
        for ($i = 0; $i < 6; $i++) $params[] = $term;
    }

    if (isset($_GET['columns'])) {
        foreach ($_GET['columns'] as $i => $col) {
            $colSearch = $col['search']['value'] ?? '';
            if ($colSearch !== '' && isset($columns[$i]) && $columns[$i] !== null) {
                $where .= " AND CONVERT(" . $columns[$i] . " USING utf8mb4) LIKE ? ";
                $params[] = "%$colSearch%";
            }
        }
    }

    $total = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $stmtFiltered = $pdo->prepare("SELECT COUNT(DISTINCT s.Id) $sqlBase $where");
    $stmtFiltered->execute($params);
    $filtered = $stmtFiltered->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    
    // FETCH DATA
    $sql = "SELECT s.*, c.ClassName, c.EnrollmentType, c.WhatsappLink, es.Name as SessionName, es.Timings, e.enrollment_fee, e.Id as EnrollmentId, e.EnrollmentSessionId, e.Class as EnrolledClass 
            $sqlBase $where GROUP BY s.Id ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

// Pre-fetch Classes properly (Sirf Active Sessions wali classes + Duplicate Fix)
    $allClasses = $pdo->query("SELECT * FROM classmanifest WHERE session_id IN (SELECT Id FROM enrollmentsession WHERE IsActive=1) ORDER BY EnrollmentType, ClassName")->fetchAll();
    $distinctClasses = [];
    $seenCls = [];
    foreach ($allClasses as $ac) {
        $uniqueKey = $ac['EnrollmentType'] . '_' . trim($ac['ClassName']);
        
        if (in_array($uniqueKey, $seenCls)) continue; 
        
        $seenCls[] = $uniqueKey;
        $distinctClasses[] = [
            'Class' => $ac['Class'], 
            'ClassName' => $ac['ClassName'], 
            'EnrollmentType' => $ac['EnrollmentType']
        ];
    }

    $allSessions = $pdo->query("SELECT Id, Name FROM enrollmentsession WHERE IsActive=1 ORDER BY SortOrder, Id")->fetchAll();

    $data = [];
    while ($r = $stmt->fetch()) {
        $id = $r['Id'];
        $age = calculateAge($r['DOB']);
        $currentSessionId = $r['EnrollmentSessionId'] ?? '';
        $currentClassId = $r['EnrolledClass'] ?? '';

        $missingFields = [];
        if (empty($r['MobileNumberFather']) && empty($r['MobileNumberMother'])) $missingFields[] = "Mobile";
        if (empty($r['DOB'])) $missingFields[] = "DOB";
        if (empty($r['Address'])) $missingFields[] = "Address";
        if (empty($r['Gender'])) $missingFields[] = "Gender";

        $missingStatus = empty($missingFields) ? '<span class="badge bg-success" style="font-size:0.7em">Complete</span>' : '<span class="text-danger" style="font-size:0.75em; font-weight:bold;">Missing: ' . implode(', ', $missingFields) . '</span>';

        $waHtml = '-';
        $waNumF = preg_replace('/[^0-9]/', '', $r['MobileNumberFather'] ?: $r['MobileNumberMother']);
        $waNumM = preg_replace('/[^0-9]/', '', $r['MobileNumberMother']);
        $sessName = $r['SessionName'] ?: 'Assigned Session';
        $timings = $r['Timings'] ?: 'Check Schedule';
        $startDate = getSet('start_date') ?: 'Upcoming Date';
        $groupLink = $r['WhatsappLink'];
        $rawMsg = "{$r['Name']} your class is {$r['ClassName']} in {$sessName} and your class timings are {$timings} . Your class will start on {$startDate}.";
        if (!empty($groupLink)) $rawMsg .= " Please join the WhatsApp group: $groupLink";

        $waMsg = rawurlencode($rawMsg);
        if ($waNumF) $waHtml = "<a href='https://api.whatsapp.com/send?phone=$waNumF&text=$waMsg' target='_blank' class='btn btn-success btn-sm py-0 mb-1'><i class='fab fa-whatsapp'></i> Chat</a>";
        if ($waNumM && $waNumM !== $waNumF) $waHtml .= "<br><a href='https://api.whatsapp.com/send?phone=$waNumM&text=$waMsg' target='_blank' class='btn btn-outline-success btn-sm py-0'><i class='fab fa-whatsapp'></i> Mom</a>";

        // --- 1. SESSION COLUMN ---
        $sessHtml = $r['SessionName'] ? "<span class='badge bg-info'>{$r['SessionName']}</span>" : "";
        if (can('enroll_student')) {
            $displaySess = $r['SessionName'] ? 'none' : 'block';
            if ($r['SessionName']) $sessHtml .= " <button type='button' class='btn btn-xs btn-outline-dark mt-1' onclick=\"document.getElementById('sess_edit_$id').style.display='block';this.style.display='none'\">Change</button>";
            $localSessionOpts = "";
            foreach ($allSessions as $sess) {
                $sel = ($sess['Id'] == $currentSessionId) ? 'selected' : '';
                $localSessionOpts .= "<option value='{$sess['Id']}' $sel>{$sess['Name']}</option>";
            }
            $sessHtml .= "<div id='sess_edit_$id' style='display:$displaySess'>
                <select class='form-select form-select-sm mt-1' id='sel_sess_$id'><option value=''>Session...</option>$localSessionOpts</select>
            </div>";
        } else {
            $sessHtml = $r['SessionName'] ?: '-';
        }

        // --- 2. CLASS COLUMN ---
        $classHtml = $r['ClassName'] ? "<span class='badge bg-success'>{$r['ClassName']}</span>" : "";
        if (can('enroll_student')) {
            $displayClass = $r['ClassName'] ? 'none' : 'block';
            if ($r['ClassName']) $classHtml .= " <button type='button' class='btn btn-xs btn-outline-dark mt-1' onclick=\"document.getElementById('cls_edit_$id').style.display='block';this.style.display='none'\">Change</button>";
            $localClassOpts = "";
            foreach ($distinctClasses as $dc) {
                $lbl = ($dc['EnrollmentType'] == 'BoysClass') ? '[B]' : '[G]';
                $sel = ($dc['Class'] == $currentClassId) ? 'selected' : '';
                $localClassOpts .= "<option value='{$dc['Class']}' $sel>$lbl {$dc['ClassName']}</option>";
            }
            $classHtml .= "<div id='cls_edit_$id' style='display:$displayClass'>
                <select class='form-select form-select-sm mt-1' id='sel_cls_$id'><option value=''>Class...</option>$localClassOpts</select>
            </div>";
        } else {
            $classHtml = $r['ClassName'] ?: '-';
        }

        // --- 3. FEE & SAVE BUTTON COLUMN ---
        $feeVal = number_format($r['enrollment_fee'] ?? 0);
        $feeHtml = $r['ClassName'] ? "<div id='fee_view_$id'><span class='badge bg-light text-dark border'>$feeVal</span>" : "";
        if (can('enroll_student')) {
            $displayFeeEdit = $r['ClassName'] ? 'none' : 'block';
            if ($r['ClassName']) {
                $feeHtml .= " <button type='button' class='btn btn-xs btn-outline-dark ms-1' onclick=\"document.getElementById('fee_edit_$id').style.display='block';document.getElementById('fee_view_$id').style.display='none';\">Edit</button></div>";
            }
            
            // The Unified "Save" Form
            $feeHtml .= "<div id='fee_edit_$id' style='display:$displayFeeEdit'>
                <form method='POST' class='d-flex align-items-center mt-1' onsubmit=\"
                    var s = document.getElementById('sel_sess_$id');
                    var c = document.getElementById('sel_cls_$id');
                    if(s && s.value) document.getElementById('hidden_sess_$id').value = s.value;
                    if(c && c.value) document.getElementById('hidden_cls_$id').value = c.value;
                    if(!document.getElementById('hidden_sess_$id').value){alert('Please select Session first!'); return false;}
                    if(!document.getElementById('hidden_cls_$id').value){alert('Please select Class first!'); return false;}
                \">
                    <input type='hidden' name='action' value='manual_assign'>
                    <input type='hidden' name='student_id' value='$id'>
                    <input type='hidden' name='session_id' id='hidden_sess_$id' value='$currentSessionId'>
                    <input type='hidden' name='class_id' id='hidden_cls_$id' value='$currentClassId'>
                    <input type='number' name='fee' class='form-control form-control-sm p-1 me-1' value='{$r['enrollment_fee']}' placeholder='Fee' style='width:65px'>
                    <button type='submit' class='btn btn-sm btn-primary py-0'>Save</button>
                    " . ($r['ClassName'] ? "<button type='button' class='btn btn-xs btn-danger ms-1' onclick=\"document.getElementById('fee_edit_$id').style.display='none';document.getElementById('fee_view_$id').style.display='block';\">x</button>" : "") . "
                </form>
            </div>";
        } else if (!$r['ClassName']) {
            $feeHtml = "-";
        }

        // --- 4. ACTIONS COLUMN (Result Button Removed) ---
        $actions = "";
        if (!empty($r['EnrollmentId'])) {
            $actions .= "<a class='btn btn-sm btn-warning py-0 me-1 mb-1 text-dark' href='?page=enrollment_slip&enrollment_id={$r['EnrollmentId']}' target='_blank'><i class='fas fa-print'></i> Slip</a>";
        }
        if (can('student_edit')) {
            $rJson = htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
            $actions .= "<button class='btn btn-sm btn-info py-0 me-1 mb-1' onclick='editStudent($rJson)'>Edit</button>";
        }
        if (can('delete_enrollment') && !empty($r['EnrollmentId'])) {
            $actions .= "<form method='POST' class='d-inline' onsubmit=\"return confirm('Unenroll?');\">".
                        "<input type='hidden' name='action' value='delete_enrollment'>".
                        "<input type='hidden' name='enrollment_id' value='{$r['EnrollmentId']}'>".
                        "<button type='submit' class='btn btn-sm btn-danger py-0 mb-1'>Unenroll</button></form>";
        } elseif (can('student_delete') && empty($r['EnrollmentId'])) {
            $actions .= "<form method='POST' class='d-inline' onsubmit=\"return confirm('Delete?');\"><input type='hidden' name='action' value='delete_student'><input type='hidden' name='student_id' value='$id'><button class='btn btn-sm btn-outline-danger py-0 mb-1'>x</button></form>";
        }

        // Output Array matching New Tarteeb (Session -> Class -> Fee)
        $data[] = [$id, $r['Name'], $r['Paternity'], $missingStatus, $r['MobileNumberFather'], $waHtml, $age, $sessHtml, $classHtml, $feeHtml, $actions];
    }
    
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_certificates') {
    if (!can('manage_certificates')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $columns = [0=>'c.issued_date', 1=>'s.Name', 2=>'c.title', 3=>'c.id'];
    $orderBy = $columns[$orderColIndex] ?? 'c.id';

    $sqlBase = " FROM certificates c 
                 JOIN students s ON c.student_id = s.Id 
                 JOIN enrollment e ON e.StudentId = s.Id AND e.IsActive = 1 
                 JOIN enrollmentsession es ON e.EnrollmentSessionId = es.Id AND es.IsActive = 1 ";
    $where = " WHERE 1=1 ";
    $params = [];
    if (!empty($searchValue)) {
        $where .= " AND (c.issued_date LIKE ? OR s.Name LIKE ? OR c.title LIKE ? OR c.type LIKE ? OR c.description LIKE ? )";
        $term = "%$searchValue%";
        for ($i = 0; $i < 5; $i++) $params[] = $term;
    }

    $total = $pdo->prepare("SELECT COUNT(DISTINCT c.id) $sqlBase");
    $total->execute();
    $total = $total->fetchColumn();

    $stmtFiltered = $pdo->prepare("SELECT COUNT(DISTINCT c.id) $sqlBase $where");
    $stmtFiltered->execute($params);
    $filtered = $stmtFiltered->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT c.id, c.issued_date, c.title, c.type, c.description, s.Name as StudentName, s.Paternity " .
           "$sqlBase $where GROUP BY c.id ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch()) {
        $id = $row['id'];
        $print = "<a href='?page=print_certificate&id=$id' target='_blank' class='btn btn-sm btn-outline-dark py-0 me-1' title='Print Certificate'><i class='fas fa-print'></i></a>";
        $delete = "<form method='POST' class='d-inline' onsubmit=\"return confirm('Delete this certificate permanently?');\">".
                  "<input type='hidden' name='action' value='delete_certificate'>".
                  "<input type='hidden' name='id' value='$id'>".
                  "<button class='btn btn-sm btn-outline-danger py-0'><i class='fas fa-trash'></i></button></form>";
        $actions = $print . $delete;
        $data[] = [
            date('d M Y', strtotime($row['issued_date'])),
            '<strong>'.htmlspecialchars($row['StudentName']).'</strong><br><small class="text-muted">s/o '.htmlspecialchars($row['Paternity']).'</small>',
            htmlspecialchars($row['title']),
            $actions
        ];
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_fee_report') {
    if (!can('view_fee_report')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $columns = [0=>'e.Id', 1=>'e.created_at', 2=>'s.Name', 3=>'c.ClassName', 4=>'u.name', 5=>'e.enrollment_fee'];
    $orderBy = $columns[$orderColIndex] ?? 'e.Id';

    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $collectorId = $_GET['collector_id'] ?? '';
    $showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';

    $sqlBase = " FROM enrollment e 
                 JOIN students s ON e.StudentId = s.Id 
                 LEFT JOIN classmanifest c ON e.Class = c.Class 
                 LEFT JOIN users u ON e.collected_by = u.id ";
    $whereBase = " WHERE e.created_at BETWEEN ? AND ? ";
    $baseParams = ["$startDate 00:00:00", "$endDate 23:59:59"];
    if (!$showAll) {
        $whereBase .= " AND e.enrollment_fee > 0 ";
    }
    if (!empty($collectorId)) {
        $whereBase .= " AND e.collected_by = ? ";
        $baseParams[] = $collectorId;
    }

    $where = $whereBase;
    $params = $baseParams;
    if (!empty($searchValue)) {
        $where .= " AND (e.Id LIKE ? OR e.created_at LIKE ? OR s.Name LIKE ? OR c.ClassName LIKE ? OR u.name LIKE ? OR e.enrollment_fee LIKE ?)";
        $term = "%$searchValue%";
        for ($i = 0; $i < 6; $i++) $params[] = $term;
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $whereBase");
    $totalStmt->execute($baseParams);
    $total = $totalStmt->fetchColumn();

    $filteredStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $where");
    $filteredStmt->execute($params);
    $filtered = $filteredStmt->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT e.Id, e.enrollment_fee, e.created_at, e.collected_by, s.Name, c.ClassName, u.name as Collector " .
           "$sqlBase $where ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch()) {
        $id = $row['Id'];
        $dateVal = date('d M Y H:i', strtotime($row['created_at']));
        $print = "<a href='?page=enrollment_slip&enrollment_id=$id' target='_blank' class='btn btn-sm btn-outline-secondary py-0' title='Print'><i class='fas fa-print'></i></a>";
        $edit = '';
        if (can('edit_fee')) {
            $rowJson = htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
            $edit = "<button class='btn btn-sm btn-info py-0 ms-1' onclick='editFee($rowJson, \"" . date('Y-m-d\\TH:i', strtotime($row['created_at'])) . "\")' title='Edit'><i class='fas fa-pencil'></i></button>";
        }
        $remove = '';
        if (can('delete_fee')) {
            $remove = "<form method='POST' class='d-inline' onsubmit=\"return confirm('Remove this fee entry? (Sets amount to 0)');\">".
                      "<input type='hidden' name='action' value='delete_fee_entry'>".
                      "<input type='hidden' name='id' value='$id'>".
                      "<button class='btn btn-sm btn-danger py-0 ms-1' title='Remove'><i class='fas fa-times'></i></button></form>";
        }
        $actions = $print . $edit . $remove;
        $data[] = [
            "#{$id}",
            $dateVal,
            htmlspecialchars($row['Name']),
            htmlspecialchars($row['ClassName'] ?? '-'),
            htmlspecialchars($row['Collector'] ?: 'System'),
            number_format($row['enrollment_fee']),
            $actions
        ];
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_student_details') {
    if (!can('enroll_student')) { echo json_encode(['error' => 'Permission denied']); exit; }
    $student_id = intval($_GET['student_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT DOB, Gender FROM students WHERE Id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) { echo json_encode(['error' => 'Student not found']); exit; }

    $activeSessions = getActiveSessions($pdo);
    $recommendedSessionId = (function_exists('pickSessionForStudent')) ? pickSessionForStudent($activeSessions, $student['DOB'], $student['Gender']) : 0;

    $recommendedClassId = '';
    if (!empty($student['DOB']) && !empty($student['Gender'])) {
        $age = calculateAge($student['DOB']);
        $genderRaw = strtolower(trim($student['Gender']));
        $type = (isset($genderRaw[0]) && $genderRaw[0] === 'm') ? 'BoysClass' : 'GirlsClass';
        $stmtClass = $pdo->prepare("SELECT Class FROM classmanifest WHERE EnrollmentType = ? AND ? BETWEEN MinAge AND MaxAge ORDER BY MinAge DESC LIMIT 1");
        $stmtClass->execute([$type, $age]);
        $recommendedClassId = $stmtClass->fetchColumn() ?: '';
    }

    // Fetch classes with their specific occupancy
    $stmt = $pdo->prepare("
        SELECT c.Class, c.ClassName, c.EnrollmentType,
        (SELECT COUNT(*) FROM enrollment e WHERE e.Class = c.Class AND e.IsActive = 1) as current_count
        FROM classmanifest c 
        ORDER BY c.ClassName ASC
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode([
        'session_id' => $recommendedSessionId,
        'class_id' => $recommendedClassId,
        'classes' => $classes
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_fee_history') {
    $enr_id = intval($_GET['enr_id']);
    
    // 1. Admission Fee Info
    $stmt = $pdo->prepare("SELECT enrollment_fee, individual_monthly_fee, EnrollmentDate FROM enrollment WHERE Id = ?");
    $stmt->execute([$enr_id]);
    $base = $stmt->fetch();
    
    // 2. Monthly Records
    $stmt = $pdo->prepare("SELECT * FROM monthly_fees WHERE EnrollmentId = ? ORDER BY Year DESC, Month DESC");
    $stmt->execute([$enr_id]);
    $history = $stmt->fetchAll();
    
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['base' => $base, 'history' => $history]);
    exit;
}

// Action: get_classes_for_session (Isay update karein)
if (isset($_GET['action']) && $_GET['action'] === 'get_classes_for_session') {
    $sessionId = intval($_GET['session_id'] ?? 0);
    $hasCapacity = classCapacityExists($pdo);
    $sql = "SELECT DISTINCT c.Class, c.ClassName, c.EnrollmentType";
    if ($hasCapacity) {
        $sql .= ", c.capacity";
    }
    $sql .= ", (SELECT COUNT(*) FROM enrollment e WHERE e.Class = c.Class AND e.IsActive = 1) AS current_count
            FROM classmanifest c 
            ORDER BY c.ClassName ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $classes = $stmt->fetchAll();
    if (!$hasCapacity) {
        foreach ($classes as &$cls) {
            $cls['capacity'] = 0;
        }
        unset($cls);
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($classes);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_attendance_report') {
    if (!can('view_attendance_report')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = trim($_GET['search']['value'] ?? '');
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $classId = $_GET['class_id'] ?? '';
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $activeSessions = getActiveSessions($pdo);
    $sids = implode(',', array_column($activeSessions, 'Id')) ?: '0';

    $columns = [0 => 's.Name'];
    $orderBy = $columns[$orderColIndex] ?? 's.Name';

    $baseSql = " FROM enrollment e JOIN students s ON e.StudentId = s.Id WHERE e.Class = ? AND e.IsActive = 1 AND e.EnrollmentSessionId IN ($sids) ";
    $totalStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
    $totalStmt->execute([$classId]);
    $total = $totalStmt->fetchColumn();

    $searchSql = "";
    $params = [$classId];
    if (!empty($searchValue)) {
        $searchSql = " AND s.Name LIKE ? ";
        $params[] = "%$searchValue%";
    }

    $filteredStmt = $pdo->prepare("SELECT COUNT(*) $baseSql $searchSql");
    $filteredStmt->execute($params);
    $filtered = $filteredStmt->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT e.Id as EnrId, s.Name $baseSql $searchSql ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $dateRange = [];
    $current = strtotime($startDate);
    $last = strtotime($endDate);
    while ($current <= $last) {
        $dateRange[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }

    $attendanceStmt = $pdo->prepare("SELECT a.enrollment_id, a.date, a.status FROM attendance a JOIN enrollment e ON a.enrollment_id = e.Id WHERE e.Class = ? AND e.IsActive = 1 AND e.EnrollmentSessionId IN ($sids) AND a.date BETWEEN ? AND ?");
    $attendanceStmt->execute([$classId, $startDate, $endDate]);
    $attendance = [];
    while ($row = $attendanceStmt->fetch()) {
        $attendance[$row['enrollment_id']][$row['date']] = $row['status'];
    }

    $data = [];
    while ($row = $stmt->fetch()) {
        $p = 0;
        $a = 0;
        $l = 0;
        $lv = 0;
        $totalMarked = 0;
        $rowData = [htmlspecialchars($row['Name'])];

        foreach ($dateRange as $date) {
            $status = $attendance[$row['EnrId']][$date] ?? '';
            $short = '-';
            if ($status === 'Present') {
                $short = 'P';
                $p++;
                $totalMarked++;
            } elseif ($status === 'Absent') {
                $short = 'A';
                $a++;
                $totalMarked++;
            } elseif ($status === 'Late') {
                $short = 'Lt';
                $l++;
                $totalMarked++;
            } elseif ($status === 'Leave') {
                $short = 'Lv';
                $lv++;
                $totalMarked++;
            }
            $rowData[] = $short;
        }

        $rowData[] = $p + $l;
        $rowData[] = $a;
        $rowData[] = $lv;
        $rowData[] = $totalMarked > 0 ? round((($p + $l) / $totalMarked) * 100) . '%' : '-';
        $rowData[] = (!$lv && $a == 0 && $totalMarked > 0) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>';
        $rowData[] = ($a == 0 && $totalMarked > 0) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>';

        $data[] = $rowData;
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_logs') {
    if (!can('view_logs')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $columns = [0=>'l.Id', 1=>'l.created_at', 2=>'u.name', 3=>'l.action', 4=>'l.details', 5=>'l.ip_address'];
    $orderBy = $columns[$orderColIndex] ?? 'l.Id';

    $sqlBase = " FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id ";
    $whereBase = " WHERE 1=1 ";
    $baseParams = [];
    $where = $whereBase;
    $params = $baseParams;
    if (!empty($searchValue)) {
        $where .= " AND (l.Id LIKE ? OR l.created_at LIKE ? OR u.name LIKE ? OR l.action LIKE ? OR l.details LIKE ? OR l.ip_address LIKE ?)";
        $term = "%$searchValue%";
        for ($i = 0; $i < 6; $i++) $params[] = $term;
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $whereBase");
    $totalStmt->execute($baseParams);
    $total = $totalStmt->fetchColumn();

    $filteredStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $where");
    $filteredStmt->execute($params);
    $filtered = $filteredStmt->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT l.*, u.name as UserName $sqlBase $where ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch()) {
        $data[] = [
            $row['Id'],
            convertToUserTime($row['created_at']),
            htmlspecialchars($row['UserName'] ?: 'System'),
            htmlspecialchars($row['action']),
            htmlspecialchars($row['details']),
            htmlspecialchars($row['ip_address'])
        ];
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_hasanaat_cards') {
    if (!can('hasanaat_view')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $columns = [0=>'h.CardNumber', 1=>'h.HolderName', 2=>'h.CardType', 3=>'h.TotalAmount', 4=>'Paid', 5=>'Remaining'];
    $orderBy = $columns[$orderColIndex] ?? 'h.Id';

    $sqlBase = " FROM hasanaat_cards h LEFT JOIN hasanaat_payments p ON p.CardId = h.Id ";
    $whereBase = " WHERE 1=1 ";
    $baseParams = [];
    $where = $whereBase;
    $params = $baseParams;
    if (!empty($searchValue)) {
        $where .= " AND (h.CardNumber LIKE ? OR h.HolderName LIKE ? OR h.CardType LIKE ? OR h.Mobile LIKE ? OR h.Reference LIKE ?)";
        $term = "%$searchValue%";
        for ($i = 0; $i < 5; $i++) $params[] = $term;
    }

    $filteredStmt = $pdo->prepare("SELECT COUNT(DISTINCT h.Id) $sqlBase $where");
    $filteredStmt->execute($params);
    $filtered = $filteredStmt->fetchColumn();

    $totalStmt = $pdo->prepare("SELECT COUNT(DISTINCT h.Id) $sqlBase $whereBase");
    $totalStmt->execute($baseParams);
    $total = $totalStmt->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT h.*, COALESCE(SUM(p.Amount),0) as Paid $sqlBase $where GROUP BY h.Id ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch()) {
        $bal = $row['TotalAmount'] - $row['Paid'];
        $statusColor = ($bal <= 0) ? 'success' : 'primary';
        $actions = '';
        if (can('hasanaat_pay')) {
            $actions .= "<button class='btn btn-xs btn-outline-danger me-1' onclick='openPay({$row['Id']}, \"{$row['CardNumber']}\")'>Redeem Cash</button>";
        }
        if (can('hasanaat_edit')) {
            $rowJson = htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
            $actions .= "<button class='btn btn-xs btn-info me-1' onclick='editCard($rowJson)'>Edit</button>";
        }
        if (can('hasanaat_delete')) {
            $actions .= "<form method='POST' class='d-inline' onsubmit='return confirm(\"Delete Card & History?\")'>".
                        "<input type='hidden' name='action' value='delete_card'>".
                        "<input type='hidden' name='card_id' value='{$row['Id']}'>".
                        "<button class='btn btn-xs btn-danger'>X</button></form>";
        }
        $data[] = [
            htmlspecialchars($row['CardNumber']),
            '<strong>' . htmlspecialchars($row['HolderName']) . '</strong><br><small class="text-muted">' . htmlspecialchars($row['Mobile']) . '</small>',
            htmlspecialchars($row['CardType']),
            number_format($row['TotalAmount']),
            number_format($row['Paid']),
            number_format($bal),
            "<span class='badge bg-$statusColor'>" . ($bal <= 0 ? 'Completed' : 'Active') . "</span>",
            $actions
        ];
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_exams') {
    if (!can('manage_exams')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $columns = [0=>'ex.date', 1=>'ex.name', 2=>'s.Name', 3=>'c.ClassName', 4=>'ex.total_marks', 5=>'ex.gift_threshold'];
    $orderBy = $columns[$orderColIndex] ?? 'ex.id';

    $sqlBase = " FROM exams ex JOIN classmanifest c ON ex.class_id=c.Class JOIN enrollmentsession s ON ex.session_id=s.Id WHERE s.IsActive = 1 ";
    $whereBase = " ";
    $baseParams = [];
    $where = $whereBase;
    $params = $baseParams;
    if (!empty($searchValue)) {
        $where .= " AND (ex.date LIKE ? OR ex.name LIKE ? OR s.Name LIKE ? OR c.ClassName LIKE ?)";
        $term = "%$searchValue%";
        for ($i = 0; $i < 4; $i++) $params[] = $term;
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $whereBase");
    $totalStmt->execute($baseParams);
    $total = $totalStmt->fetchColumn();

    $filteredStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $where");
    $filteredStmt->execute($params);
    $filtered = $filteredStmt->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT ex.*, c.ClassName, s.Name as SessName $sqlBase $where ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch()) {
        $rowJson = htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
        $actions = "<button type='button' class='btn btn-xs btn-info me-1' onclick='editExam($rowJson)'><i class='fas fa-pencil'></i></button>";
        $actions .= "<form method='POST' class='d-inline' onsubmit='return confirm(\"Delete Exam?\");'>".
                    "<input type='hidden' name='action' value='delete_exam'>".
                    "<input type='hidden' name='exam_id' value='{$row['id']}'>".
                    "<button class='btn btn-xs btn-danger'><i class='fas fa-trash'></i></button></form>";
        $data[] = [
            date('d M Y', strtotime($row['date'])),
            htmlspecialchars($row['name']),
            htmlspecialchars($row['SessName']),
            htmlspecialchars($row['ClassName']),
            htmlspecialchars($row['total_marks']),
            "<span class='badge bg-warning text-dark'>&gt;= " . htmlspecialchars($row['gift_threshold'] ?? 1500) . "</span>",
            $actions
        ];
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_reports') {
    if (!can('view_reports')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    $columns = [0=>'rk', 1=>'s.Name', 2=>'c.ClassName', 3=>'ex.name', 4=>'er.obtained_marks', 5=>'pct'];
    $orderBy = $columns[$orderColIndex] ?? 'rk';

    $activeSessions = getActiveSessions($pdo);
    $sids = implode(',', array_column($activeSessions, 'Id')) ?: '0';

    $sqlBase = " FROM exam_results er JOIN exams ex ON er.exam_id=ex.id JOIN enrollment e ON er.enrollment_id=e.id JOIN students s ON e.StudentId=s.Id JOIN classmanifest c ON e.Class=c.Class WHERE ex.session_id IN ($sids) ";
    $whereBase = " ";
    $baseParams = [];
    $where = $whereBase;
    $params = $baseParams;
    if (!empty($searchValue)) {
        $where .= " AND (s.Name LIKE ? OR c.ClassName LIKE ? OR ex.name LIKE ? )";
        $term = "%$searchValue%";
        for ($i = 0; $i < 3; $i++) $params[] = $term;
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $whereBase");
    $totalStmt->execute($baseParams);
    $total = $totalStmt->fetchColumn();

    $filteredStmt = $pdo->prepare("SELECT COUNT(*) $sqlBase $where");
    $filteredStmt->execute($params);
    $filtered = $filteredStmt->fetchColumn();

    $limitClause = ($length != -1) ? "LIMIT $start, $length" : "";
    $sql = "SELECT s.Name, c.ClassName, ex.name as Exam, er.obtained_marks, ex.total_marks, (er.obtained_marks/ex.total_marks*100) as pct, RANK() OVER (PARTITION BY ex.id ORDER BY er.obtained_marks DESC) as rk $sqlBase $where ORDER BY $orderBy $orderDir $limitClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch()) {
        $data[] = [
            "<span class='badge bg-warning text-dark'>{$row['rk']}</span>",
            htmlspecialchars($row['Name']),
            htmlspecialchars($row['ClassName']),
            htmlspecialchars($row['Exam']),
            htmlspecialchars($row['obtained_marks'] . '/' . $row['total_marks']),
            number_format($row['pct'], 1) . "%"
        ];
    }

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_ghosts') {
    if (!can('student_view')) { echo json_encode(['data' => []]); exit; }
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 50);
    $searchValue = $_GET['search']['value'] ?? '';

// Ye query un sab ko dikhayegi jo CURRENT ACTIVE session mein assigned nahi hain
$sqlBase = " FROM students s WHERE s.Id NOT IN (SELECT DISTINCT StudentId FROM enrollment WHERE IsActive = 1 AND EnrollmentSessionId IN (SELECT Id FROM enrollmentsession WHERE IsActive=1)) ";
    
    $params = [];
    if (!empty($searchValue)) {
        $sqlBase .= " AND (s.Id LIKE ? OR s.Name LIKE ? OR s.Paternity LIKE ? OR s.MobileNumberFather LIKE ?)";
        $term = "%$searchValue%";
        $params = [$term, $term, $term, $term];
    }

    $total = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $stmtFiltered = $pdo->prepare("SELECT COUNT(*) $sqlBase");
    $stmtFiltered->execute($params);
    $filtered = $stmtFiltered->fetchColumn();

    $sql = "SELECT s.Id, s.Name, s.Paternity, s.MobileNumberFather, s.MobileNumberMother $sqlBase ORDER BY s.Id DESC LIMIT $start, $length";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $data = [];
    while ($row = $stmt->fetch()) {
        $mobile = $row['MobileNumberFather'] ?: $row['MobileNumberMother'] ?: '-';
        $actions = (can('student_edit') ? "<button class='btn btn-sm btn-info py-0 me-1' onclick='editStudent(".json_encode($row).")'>Edit</button>" : "") .
                  (can('enroll_student') ? "<a class='btn btn-sm btn-success py-0' href='?page=enrollment&auto_student_id={$row['Id']}'>Enroll</a>" : "");
        $data[] = [$row['Id'], $row['Name'], $row['Paternity'], $mobile, $actions];
    }
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(["draw" => $draw, "recordsTotal" => $total, "recordsFiltered" => $filtered, "data" => $data]);
    exit;
}

// --- 4. BACKEND ACTIONS ---

if (isset($_POST['action']) && $_POST['action'] === 'generate_monthly_fees') {
    $month = date('m');
    $year = date('Y');
    // Sab active students uthao
    $active = $pdo->query("SELECT Id FROM enrollment WHERE IsActive=1")->fetchAll();
    
    foreach ($active as $st) {
        try {
            $pdo->prepare("INSERT IGNORE INTO monthly_fees (EnrollmentId, Month, Year, Amount) VALUES (?, ?, ?, ?)")
                ->execute([$st['Id'], $month, $year, getSet('default_monthly_fee') ?: 500]);
        } catch (Exception $e) {}
    }
    $msg = "Current Month Fees Generated!";
}

if (isset($_POST['action']) && $_POST['action'] === 'pay_monthly_fee') {
    $pdo->prepare("UPDATE monthly_fees SET Status='Paid', PaidDate=NOW(), CollectedBy=? WHERE Id=?")
        ->execute([$_SESSION['user_id'], $_POST['fee_id']]);
        
    // General Ledger mein entry auto-sync karna
    $pdo->prepare("INSERT INTO general_income (Title, Category, Amount, Date, ReceivedBy) VALUES (?, 'Monthly Fees', ?, NOW(), ?)")
        ->execute(['Fee from Enr ID: '.$_POST['enr_id'], $_POST['amount'], $_SESSION['user_id']]);
        
    $msg = "Monthly Fee Paid and Synced with Ledger!";
}

// --- HASANAAT CARD SYSTEM ACTIONS ---

if (isset($_POST['action']) && $_POST['action'] === 'save_card' && (can('hasanaat_add') || can('hasanaat_edit'))) {
    if (!empty($_POST['card_id'])) {
        // Edit
        $sql = "UPDATE hasanaat_cards SET CardNumber=?, HolderName=?, FatherName=?, Reference=?, Mobile=?, CardType=?, TotalAmount=?, IssueDate=?, Notes=? WHERE Id=?";
        $pdo->prepare($sql)->execute([$_POST['card_no'], $_POST['holder'], $_POST['father'], $_POST['ref'], $_POST['mobile'], $_POST['type'], $_POST['amount'], $_POST['date'], $_POST['notes'], $_POST['card_id']]);
        $msg = "Card Updated.";
    } else {
        // Add
        $sql = "INSERT INTO hasanaat_cards (CardNumber, HolderName, FatherName, Reference, Mobile, CardType, TotalAmount, IssueDate, Notes) VALUES (?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([$_POST['card_no'], $_POST['holder'], $_POST['father'], $_POST['ref'], $_POST['mobile'], $_POST['type'], $_POST['amount'], $_POST['date'], $_POST['notes']]);
        $msg = "New Card Issued.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_card' && can('hasanaat_delete')) {
    $pdo->prepare("DELETE FROM hasanaat_cards WHERE Id=?")->execute([$_POST['card_id']]);
    $msg = "Card Deleted.";
}

if (isset($_POST['action']) && $_POST['action'] === 'save_payment' && (can('hasanaat_pay') || can('hasanaat_pay_edit'))) {
    if (!empty($_POST['pay_id'])) {
        $pdo->prepare("UPDATE hasanaat_payments SET Amount=?, Date=?, Remarks=? WHERE Id=?")->execute([$_POST['amount'], $_POST['date'], $_POST['remarks'], $_POST['pay_id']]);
        $msg = "Payment Record Updated.";
    } else {
        $pdo->prepare("INSERT INTO hasanaat_payments (CardId, Amount, Date, ReceivedBy, Remarks) VALUES (?,?,?,?,?)")->execute([$_POST['card_id'], $_POST['amount'], $_POST['date'], $_SESSION['user_id'], $_POST['remarks']]);
        $msg = "Payment Received.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_payment' && can('hasanaat_pay_delete')) {
    $pdo->prepare("DELETE FROM hasanaat_payments WHERE Id=?")->execute([$_POST['pay_id']]);
    $msg = "Payment Deleted.";
}

// --- GENERAL INCOME ACTIONS ---
if (isset($_POST['action']) && $_POST['action'] === 'save_general_income') {
    if (!empty($_POST['inc_id'])) {
        // EDIT Mode
        if (can('income_edit')) {
            $pdo->prepare("UPDATE general_income SET Title=?, Category=?, Amount=?, Date=?, Description=? WHERE Id=?")->execute([$_POST['title'], $_POST['cat'], $_POST['amount'], $_POST['date'], $_POST['desc'], $_POST['inc_id']]);
            $msg = "Income Record Updated.";
        }
    } else {
        // ADD Mode
        if (can('income_add')) {
            $pdo->prepare("INSERT INTO general_income (Title, Category, Amount, Date, Description, ReceivedBy) VALUES (?,?,?,?,?,?)")->execute([$_POST['title'], $_POST['cat'], $_POST['amount'], $_POST['date'], $_POST['desc'], $_SESSION['user_id']]);
            $msg = "Income Added to Ledger.";
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_general_income' && can('income_delete')) {
    $pdo->prepare("DELETE FROM general_income WHERE Id=?")->execute([$_POST['inc_id']]);
    $msg = "Income Record Deleted.";
}
// --- EXPENSES & LEDGER ACTIONS ---

if (isset($_POST['action']) && $_POST['action'] === 'save_expense' && (can('expense_add') || can('expense_edit'))) {
    if (!empty($_POST['exp_id'])) {
        $pdo->prepare("UPDATE general_expenses SET Title=?, Category=?, Amount=?, Date=?, Description=? WHERE Id=?")->execute([$_POST['title'], $_POST['cat'], $_POST['amount'], $_POST['date'], $_POST['desc'], $_POST['exp_id']]);
        $msg = "Expense Updated.";
    } else {
        $pdo->prepare("INSERT INTO general_expenses (Title, Category, Amount, Date, Description, AddedBy) VALUES (?,?,?,?,?,?)")->execute([$_POST['title'], $_POST['cat'], $_POST['amount'], $_POST['date'], $_POST['desc'], $_SESSION['user_id']]);
        $msg = "Expense Added.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_expense' && can('expense_delete')) {
    $pdo->prepare("DELETE FROM general_expenses WHERE Id=?")->execute([$_POST['exp_id']]);
    $msg = "Expense Deleted.";
}

// --- TABARRUK ACTIONS (UPDATED) ---

if (isset($_POST['action']) && $_POST['action'] === 'save_tabarruk' && (can('tabarruk_add') || can('tabarruk_edit'))) {
    if (!empty($_POST['tb_id'])) {
        $pdo->prepare("UPDATE tabarruk SET date=?, item_name=?, quantity=?, total_cost=?, description=? WHERE id=?")->execute([$_POST['date'], $_POST['item'], $_POST['qty'], $_POST['cost'], $_POST['desc'], $_POST['tb_id']]);
        $msg = "Tabarruk Updated.";
    } else {
        $pdo->prepare("INSERT INTO tabarruk (date, item_name, quantity, total_cost, description) VALUES (?,?,?,?,?)")->execute([$_POST['date'], $_POST['item'], $_POST['qty'], $_POST['cost'], $_POST['desc']]);
        $msg = "Tabarruk Added.";
    }
}

// --- BUDGET PLAN ACTIONS ---
if (isset($_POST['action']) && $_POST['action'] === 'save_budget_plan' && can('budget_manage')) {
    if (!empty($_POST['bp_id'])) {
        $pdo->prepare("UPDATE budget_plan SET MonthYear=?, Category=?, TargetAmount=?, Type=? WHERE Id=?")->execute([$_POST['month'], $_POST['cat'], $_POST['amount'], $_POST['type'], $_POST['bp_id']]);
        $msg = "Budget Target Updated.";
    } else {
        $pdo->prepare("INSERT INTO budget_plan (MonthYear, Category, TargetAmount, Type) VALUES (?,?,?,?)")->execute([$_POST['month'], $_POST['cat'], $_POST['amount'], $_POST['type']]);
        $msg = "Budget Target Set.";
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'delete_budget_plan' && can('budget_manage')) {
    $pdo->prepare("DELETE FROM budget_plan WHERE Id=?")->execute([$_POST['bp_id']]);
    $msg = "Target Deleted.";
}

// --- DATABASE BACKUP ACTION ---
if (isset($_POST['action']) && $_POST['action'] === 'backup_database' && (can('backup_db') || $_SESSION['role'] === 'admin')) {

    // 1. Configuration
    $return = "";
    $allTables = [];
    $result = $pdo->query('SHOW TABLES');
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $allTables[] = $row[0];
    }

    // 2. Generate SQL
    foreach ($allTables as $table) {
        $result = $pdo->query('SELECT * FROM ' . $table);
        $num_fields = $result->columnCount();

        $return .= "DROP TABLE IF EXISTS " . $table . ";";
        $row2 = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM);
        $return .= "\n\n" . $row2[1] . ";\n\n";

        for ($i = 0; $i < $num_fields; $i++) {
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $return .= "INSERT INTO " . $table . " VALUES(";
for ($j = 0; $j < $num_fields; $j++) {
                    //  Agar value null ho to NULL likhega, empty string nahi
                    if (!isset($row[$j])) {
                        $return .= 'NULL';
                    } else {
                        $val = addslashes($row[$j]);
                        // line breaks ko theek karega taake file crash na ho
                        $val = str_replace("\n", "\\n", $val); 
                        $return .= '"' . $val . '"';
                    }
                    if ($j < ($num_fields - 1)) {
                        $return .= ',';
                    }
                }
                $return .= ");\n";
            }
        }
        $return .= "\n\n\n";
    }

    // 3. Force Download
    $fileName = 'db-backup-' . date('Y-m-d-H-i-s') . '.sql';
    ob_clean(); // Clean output buffer to prevent corruption
    header('Content-Type: application/octet-stream');
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"" . $fileName . "\"");
    echo $return;
    exit; // Stop script here
}



// --- ADD THIS: Bulk Unassign Teacher ---
if (isset($_POST['action']) && $_POST['action'] === 'unassign_all_classes' && can('manage_teachers')) {
    // 1. Get the Linked User ID
    $stmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ?");
    $stmt->execute([$_POST['teacher_id']]);
    $uid = $stmt->fetchColumn();

    // 2. Delete all their entries in class_teachers
    if ($uid) {
        $pdo->prepare("DELETE FROM class_teachers WHERE user_id = ?")->execute([$uid]);
        $msg = "All classes unassigned from this teacher.";
        $msgType = "warning";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete_ghosts' && can('student_delete')) {
    $pdo->exec("DELETE FROM students WHERE Id NOT IN (SELECT DISTINCT StudentId FROM enrollment)");
    $msg = "All ghost records deleted.";
    $msgType = "success";
}

// --- SMART MESSAGE INITIALIZER & LARGE FILE CATCHER ---
$msg = "";
$msgType = "success";

// Agar badi file upload hone ki wajah se POST data crash ho jaye, toh ye error dega
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $msg = "⚠️ Upload Failed: Aapki backup file ka size server ki limit se bada hai. Kripya CPanel/Server mein 'upload_max_filesize' ko badhayen.";
    $msgType = "danger";
}

$waButton = "";
$printButton = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // --- RESTORE DATABASE ACTION (Advanced) ---
    if (isset($_POST['action']) && $_POST['action'] === 'restore_database' && (can('backup_db') || $_SESSION['role'] === 'admin')) {
        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            
            $fileTmpPath = $_FILES['backup_file']['tmp_name'];
            $fileName = $_FILES['backup_file']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($fileExtension === 'sql') {
                try {
                    // SQL File ko read karein
                    $sqlScript = file_get_contents($fileTmpPath);

                    // NAYI LINE: MySQL ka Strict Mode temporary off karein
                    $pdo->exec("SET SESSION sql_mode = ''");
                    
                    // Foreign keys temporary band karein taake table drop ho sakein
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    
                    // Purana data urra kar Naya Backup Data Execute Karein
                    $pdo->exec($sqlScript); 
                    
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    
                    $msg = "✅ Success! Purana data wipe ho gaya aur Database Restore ho gaya hai.";
                    $msgType = "success";
                    logAction('Database Restore', "System restored successfully from file: $fileName");

                } catch (PDOException $e) {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); 
                    $msg = "⚠️ Restore Failed (Database Error): " . $e->getMessage();
                    $msgType = "danger";
                }
            } else {
                $msg = "⚠️ Invalid File! Sirf .sql file hi allow hai.";
                $msgType = "danger";
            }
        } else {
            $errCode = $_FILES['backup_file']['error'] ?? 'Unknown';
            $msg = "⚠️ File Upload Failed (Error Code: $errCode). File ka size limit se bada ho sakta hai.";
            $msgType = "danger";
        }
    }



    // LOGIN

    if (isset($_POST['action']) && $_POST['action'] === 'login') {

        $stmt = $pdo->prepare("SELECT * FROM users WHERE name = ?");

        $stmt->execute([$_POST['username']]);

        $u = $stmt->fetch();

        if ($u && password_verify($_POST['password'], $u['password'])) {

            $_SESSION['user_id'] = $u['id'];

            $_SESSION['role'] = $u['role'];

            $_SESSION['name'] = $u['name'];

            unset($_SESSION['my_permissions']);

            logAction('Login', "User: {$u['name']}");

            // Teachers should land on Attendance page. Try to preselect their assigned class.
            if (trim(strtolower($u['role'])) === 'teacher') {
                // First try the many-to-many assignment table
                $stmt = $pdo->prepare("SELECT class_id FROM class_teachers WHERE user_id = ? LIMIT 1");
                $stmt->execute([$u['id']]);
                $cid = $stmt->fetchColumn();

                // Fallback: older single-assignment field on classmanifest
                if (!$cid) {
                    $stmt = $pdo->prepare("SELECT Class FROM classmanifest WHERE assigned_user_id = ? LIMIT 1");
                    $stmt->execute([$u['id']]);
                    $cid = $stmt->fetchColumn();
                }

                if ($cid) {
                    redirect("?page=attendance&class_id=" . urlencode($cid));
                } else {
                    redirect('?page=attendance');
                }
            }

            redirect('?page=dashboard');

        } else {

            $msg = "Invalid Login";
            $msgType = "danger";

            logAction('Login Failed', "User: {$_POST['username']}");

        }

    }



    // --- FEE MANAGEMENT ACTIONS ---

    // 1. EDIT FEE RECORD
    if (isset($_POST['action']) && $_POST['action'] === 'edit_fee_record' && can('edit_fee')) {
        $pdo->prepare("UPDATE enrollment SET enrollment_fee=?, created_at=?, collected_by=? WHERE Id=?")
            ->execute([$_POST['amount'], $_POST['date'], $_POST['collector_id'], $_POST['id']]);
        $msg = "Fee Record Updated Successfully.";
        logAction('Edit Fee', "Updated Fee for Enrollment ID: {$_POST['id']}");
    }

    // 2. DELETE FEE (Set to 0)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_fee_entry' && can('delete_fee')) {
        // Hum record delete nahi kar rahe, bas fee 0 kar rahe hain taake report se hat jaye
        $pdo->prepare("UPDATE enrollment SET enrollment_fee = 0 WHERE Id=?")->execute([$_POST['id']]);
        $msg = "Fee Entry Removed (Amount set to 0).";
        logAction('Delete Fee', "Removed fee for Enrollment ID: {$_POST['id']}");
    }

    // 3. PRUNE FEES (Bulk Remove)
    if (isset($_POST['action']) && $_POST['action'] === 'prune_fees' && can('prune_fees')) {
        $start = $_POST['p_start'];
        $end = $_POST['p_end'];
        // Sirf selected range ki fees ko 0 karega
        $sql = "UPDATE enrollment SET enrollment_fee = 0 WHERE created_at BETWEEN ? AND ? AND enrollment_fee > 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["$start 00:00:00", "$end 23:59:59"]);
        $count = $stmt->rowCount();
        $msg = "Pruned $count Fee Records.";
        logAction('Prune Fees', "Cleared fees from $start to $end");
    }

    // --- PRUNE ATTENDANCE (Bulk Delete) ---
    if (isset($_POST['action']) && $_POST['action'] === 'prune_attendance' && can('delete_attendance')) {
        $start = $_POST['p_start'];
        $end = $_POST['p_end'];
        $cid = $_POST['p_class'] ?? '';

        // SQL: Delete from attendance table based on date range & optional class
        $sql = "DELETE a FROM attendance a 
                JOIN enrollment e ON a.enrollment_id = e.Id 
                WHERE a.date BETWEEN ? AND ?";
        $params = [$start, $end];

        // If a specific class is selected, restrict deletion to that class
        if (!empty($cid)) {
            $sql .= " AND e.Class = ?";
            $params[] = $cid;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();

        $msg = "Deleted $count attendance records.";
        $msgType = "warning";
        logAction('Prune Attendance', "Deleted $count records ($start to $end)");
    }

    // --- STUDENT CRUD (Fixed ID) ---

    if (isset($_POST['action']) && $_POST['action'] === 'add_student' && can('student_add')) {

        $name = formatName($_POST['name']);

        $father = formatName($_POST['paternity']);



        // Manual ID Generation Logic (Critical Fix)

        $newId = $pdo->query("SELECT COALESCE(MAX(Id), 0) + 1 FROM students")->fetchColumn();



        $sql = "INSERT INTO students (Id, Name, Paternity, MobileNumberFather, MobileNumberMother, DOB, Gender, Address, School, Notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $pdo->prepare($sql)->execute([$newId, $name, $father, $_POST['mobile_f'], $_POST['mobile_m'], $_POST['dob'], $_POST['gender'], $_POST['address'], $_POST['school'], $_POST['notes']]);



        logAction('Add Student', "Added: $name (ID: $newId)");

        $msg = "Student Added (ID: $newId).";

    }



    if (isset($_POST['action']) && $_POST['action'] === 'edit_student' && can('student_edit')) {

        $name = formatName($_POST['name']);
        $father = formatName($_POST['paternity']);

        // 1. Update Student Profile
        $pdo->prepare("UPDATE students SET Name=?, Paternity=?, MobileNumberFather=?, MobileNumberMother=?, DOB=?, Gender=?, Address=?, School=?, Notes=? WHERE id=?")
            ->execute([$name, $father, $_POST['mobile_f'], $_POST['mobile_m'], $_POST['dob'], $_POST['gender'], $_POST['address'], $_POST['school'], $_POST['notes'], $_POST['student_id']]);

        $msg = "Student Profile Updated.";

        // Enrollment handling removed from Edit Profile
    }

    // GHOST FIX

    if (isset($_POST['action']) && $_POST['action'] === 'delete_student' && can('student_delete')) {

        $sid = $_POST['student_id'];

        if (empty($sid) || $sid == 0) {
            $pdo->exec("DELETE FROM students WHERE Id = 0 OR Id IS NULL");
            $msg = "Ghost Records Cleaned.";
        } else {
            $pdo->prepare("DELETE FROM students WHERE Id=?")->execute([$sid]);
            $msg = "Deleted.";
        }

    }

    // --- DUPLICATES: Find / Merge / Delete ---

    if (isset($_POST['action']) && $_POST['action'] === 'merge_duplicate' && can('student_edit')) {

        $keep = (int) ($_POST['keep_id'] ?? 0);

        $remove = (int) ($_POST['remove_id'] ?? 0);

        if ($keep && $remove && $keep !== $remove) {

            try {

                $pdo->beginTransaction();

                // Move enrollments and related references

                $pdo->prepare("UPDATE enrollment SET StudentId = ? WHERE StudentId = ?")->execute([$keep, $remove]);

                $pdo->prepare("UPDATE prizes SET student_id = ? WHERE student_id = ?")->execute([$keep, $remove]);

                // If there are other tables referencing student id, add similar UPDATEs here

                // Finally delete the duplicate student record

                $pdo->prepare("DELETE FROM students WHERE Id = ?")->execute([$remove]);

                $pdo->commit();

                $msg = "Merged student $remove into $keep.";

                logAction('Merge Students', "Keep:$keep Remove:$remove");

            } catch (Exception $e) {

                $pdo->rollBack();

                $msg = "Merge Failed: " . $e->getMessage();

                $msgType = "danger";

            }

        } else {
            $msg = "Invalid selection.";
            $msgType = "danger";
        }

    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_duplicate' && can('student_delete')) {

        $sid = (int) ($_POST['student_id'] ?? 0);

        if ($sid > 0) {

            // Prevent deletion if student has active enrollments

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM enrollment WHERE StudentId = ?");
            $cnt->execute([$sid]);

            if ($cnt->fetchColumn() > 0) {
                $msg = "Cannot delete: Student has enrollments.";
                $msgType = "danger";
            } else {
                $pdo->prepare("DELETE FROM students WHERE Id = ?")->execute([$sid]);
                $msg = "Deleted.";
                logAction('Delete Student', "Deleted duplicate student $sid");
            }

        } else {
            $msg = "Invalid Student.";
            $msgType = "danger";
        }

    }
// --- 1. SETTINGS SAVE ACTION (NEW) ---
// --- 1. SETTINGS SAVE ACTION (UPDATED WITH LOGO UPLOAD) ---
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings' && $_SESSION['role'] === 'admin') {
        
        $logoUploaded = false;

        // Handle Logo Image Upload First
        if (isset($_FILES['inst_logo_file']) && $_FILES['inst_logo_file']['error'] === UPLOAD_ERR_OK) {
            if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
            $ext = strtolower(pathinfo($_FILES['inst_logo_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $logoName = 'logo_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['inst_logo_file']['tmp_name'], 'uploads/' . $logoName);
                
                // Save logo path to DB
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('inst_logo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                    ->execute(['uploads/' . $logoName]);
                $sys['inst_logo'] = 'uploads/' . $logoName;
                $logoUploaded = true;
            }
        }

        // Handle all other text settings
        if (isset($_POST['settings'])) {
            foreach ($_POST['settings'] as $key => $value) {
                // If we just uploaded a logo file, don't let the text input overwrite it
                if ($key === 'inst_logo' && $logoUploaded) {
                    continue;
                }

                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                    ->execute([$key, $value]);
                $sys[$key] = $value; // Update variable immediately
            }
        }
        $msg = "Settings & Branding Updated Successfully.";
    }

    // --- 2. SESSIONS MANAGEMENT (MERGED & COMPLETE) ---

if (isset($_POST['action']) && $_POST['action'] === 'add_session' && can('manage_sessions')) {
        if (empty(trim($_POST['session_name']))) {
            $msg = "Name Required"; $msgType = "danger";
        } else {
            // Ensure a proper Id is assigned
            try { 
                $newId = $pdo->query("SELECT COALESCE(MAX(Id), 0) + 1 FROM enrollmentsession")->fetchColumn(); 
            } catch (Exception $e) { 
                $newId = 0; 
            }

            // Get Timings from form
            $timings = isset($_POST['timings']) ? $_POST['timings'] : '';

            if ($newId && $newId > 0) {
                // Insert with ID and Timings
                $pdo->prepare("INSERT INTO enrollmentsession (Id, Name, Timings, IsActive) VALUES (?,?,?,0)")
                    ->execute([$newId, trim($_POST['session_name']), $timings]);
            } else {
                // Fallback: try regular insert with Timings
                $pdo->prepare("INSERT INTO enrollmentsession (Name, Timings, IsActive) VALUES (?,?,0)")
                    ->execute([trim($_POST['session_name']), $timings]);
            }
            $msg = "Session Added.";
        }
    }


// Agar real session hai, tou us se jura hua saara data force delete karein
// --- FORCE DELETE SESSION & GHOST WIPE ---
if (isset($_POST['action']) && $_POST['action'] === 'delete_session' && can('manage_sessions')) {
    $sid = $_POST['session_id'];
    
    // Agar real session hai, tou us se jura hua saara data force delete karein
    try {
        // Ignore errors if table doesn't exist
        try { $pdo->prepare("DELETE FROM activities_records WHERE session_id=?")->execute([$sid]); } catch(Exception $e) {}
        try { $pdo->prepare("DELETE FROM prizes WHERE session_id=?")->execute([$sid]); } catch(Exception $e) {}
        try { $pdo->prepare("DELETE FROM events WHERE session_id=?")->execute([$sid]); } catch(Exception $e) {}

        $pdo->prepare("DELETE FROM exam_results WHERE exam_id IN (SELECT id FROM exams WHERE session_id=?)")->execute([$sid]);
        $pdo->prepare("DELETE FROM exams WHERE session_id=?")->execute([$sid]);
        $pdo->prepare("DELETE FROM round_table_records WHERE session_id=?")->execute([$sid]);
        $pdo->prepare("DELETE FROM attendance WHERE enrollment_id IN (SELECT Id FROM enrollment WHERE EnrollmentSessionId=?)")->execute([$sid]);
        $pdo->prepare("DELETE FROM enrollment WHERE EnrollmentSessionId=?")->execute([$sid]);
        $pdo->prepare("DELETE FROM enrollmentsession WHERE Id=?")->execute([$sid]);
        
        $msg = "Session aur us se munsalik saara record mukammal delete ho gaya hai.";
    } catch (PDOException $e) {
        $msg = "Error deleting session: " . $e->getMessage();
        $msgType = "danger";
    }
}
    if (isset($_POST['action']) && $_POST['action'] === 'edit_session' && can('manage_sessions')) {
        // Updated: Save Name AND Timings
        $pdo->prepare("UPDATE enrollmentsession SET Name=?, Timings=? WHERE Id=?")
            ->execute([$_POST['session_name'], $_POST['timings'], $_POST['session_id']]);
        $msg = "Updated.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'toggle_session') {
        $pdo->prepare("UPDATE enrollmentsession SET IsActive = NOT IsActive WHERE Id=?")->execute([$_POST['session_id']]);
        $msg = "Toggled.";
    }

    // --- FIX: SYNC TEACHERS BUTTON (STRICT TEACHER ONLY) ---
// --- FIX: SYNC TEACHERS (Add Missing & Remove Unwanted) ---
    if (isset($_POST['action']) && $_POST['action'] === 'sync_teachers' && can('manage_teachers')) {

        // 1. Link existing Teacher Profiles to Users by Name (if user_id is missing)
        $pdo->exec("UPDATE teachers t 
                JOIN users u ON t.name = u.name 
                SET t.user_id = u.id 
                WHERE (t.user_id IS NULL OR t.user_id = 0)");

        // 2. Add Missing: Create Profiles for 'teacher' role
        $pdo->exec("INSERT INTO teachers (name, user_id, gender) 
                SELECT name, id, 'Male' 
                FROM users 
                WHERE role = 'teacher' 
                AND id NOT IN (SELECT COALESCE(user_id, 0) FROM teachers)");

        // 3. CLEANUP: Remove anyone from teachers table who is NOT a teacher
        // (This fixes the issue of "All Users" being added previously)
        $pdo->exec("DELETE t FROM teachers t 
                JOIN users u ON t.user_id = u.id 
                WHERE u.role != 'teacher'");

        $msg = "Teacher List Synced! (Non-teachers removed)";
    }

    // --- REPLACE existing if (isset($_POST['action']) && $_POST['action'] === 'add_teacher') block ---

    // --- UPDATED ADD TEACHER (Manual Password + Duplicate Check + Class Assign) ---

    if (isset($_POST['action']) && $_POST['action'] === 'add_teacher' && can('manage_teachers')) {

        // 1. Check if Username/Email already exists
        $check = $pdo->prepare("SELECT id FROM users WHERE name=? OR email=?");
        $check->execute([$_POST['username'], $_POST['email']]);

        if ($check->rowCount() > 0) {
            $msg = "Error: Username or Email already exists.";
            $msgType = "danger";
        } else {
            // 2. Create Login User
            // We capture the password typed in the form ($_POST['password'])
            // We MUST use password_hash, otherwise login will fail.
            $passHash = password_hash($_POST['password'], PASSWORD_DEFAULT);

            $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'teacher')")
                ->execute([$_POST['username'], $_POST['email'], $passHash]);

            $newUserId = $pdo->lastInsertId();

            // 3. Create Teacher Profile linked to User
            $pdo->prepare("INSERT INTO teachers (name, gender, phone, notes, user_id) VALUES (?,?,?,?,?)")
                ->execute([$_POST['username'], $_POST['gender'], $_POST['phone'], $_POST['notes'], $newUserId]);

            // 4. Assign Classes (If selected)
            if (!empty($_POST['assigned_classes'])) {
                foreach ($_POST['assigned_classes'] as $classId) {
                    $pdo->prepare("INSERT INTO class_teachers (class_id, user_id) VALUES (?,?)")
                        ->execute([$classId, $newUserId]);
                }
            }

            $msg = "Teacher Account Created Successfully.";
        }
    }

    // --- REPLACE existing 'edit_teacher' block ---
    if (isset($_POST['action']) && $_POST['action'] === 'edit_teacher' && can('manage_teachers')) {
        // 1. Get the linked User ID first
        $stmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $linkedUserId = $stmt->fetchColumn();

        // 2. Update Teacher Profile
        $pdo->prepare("UPDATE teachers SET name=?, gender=?, phone=?, notes=? WHERE id=?")
            ->execute([$_POST['name'], $_POST['gender'], $_POST['phone'], $_POST['notes'], $_POST['id']]);

        // 3. Update Linked Login Account (if exists)
        if ($linkedUserId) {
            $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([$_POST['name'], $linkedUserId]);
        }

        $msg = "Teacher Profile & Login Updated.";
    }

    // --- REPLACE existing 'delete_teacher' block ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_teacher' && can('manage_teachers')) {
        // 1. Get the linked User ID
        $stmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $linkedUserId = $stmt->fetchColumn();

        // 2. Delete from Teachers Table
        $pdo->prepare("DELETE FROM teachers WHERE id=?")->execute([$_POST['id']]);

        // 3. Cleanup: Delete Login Account & Class Assignments
        if ($linkedUserId) {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$linkedUserId]);
            $pdo->prepare("DELETE FROM class_teachers WHERE user_id=?")->execute([$linkedUserId]);
        }

        $msg = "Teacher & Linked Login Deleted.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_tabarruk' && can('manage_tabarruk')) {
        $pdo->prepare("INSERT INTO tabarruk (date,item_name,quantity,total_cost,description) VALUES (?,?,?,?,?)")->execute([$_POST['date'], $_POST['item'], $_POST['qty'], $_POST['cost'], $_POST['desc']]);
        $msg = "Recorded.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_tabarruk' && can('manage_tabarruk')) {
        $pdo->prepare("DELETE FROM tabarruk WHERE id=?")->execute([$_POST['id']]);
        $msg = "Deleted.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_prize' && can('manage_prizes')) {
        $info = $pdo->prepare("SELECT e.Class,e.EnrollmentSessionId FROM enrollment e WHERE e.StudentId=? AND e.IsActive=1 LIMIT 1");
        $info->execute([$_POST['student_id']]);
        $curr = $info->fetch();
        $cid = $curr['Class'] ?? null;
        $sid = $curr['EnrollmentSessionId'] ?? null;
        $pdo->prepare("INSERT INTO prizes (student_id,session_id,class_id,prize_name,reason,cost,date) VALUES (?,?,?,?,?,?,?)")->execute([$_POST['student_id'], $sid, $cid, $_POST['prize_name'], $curr['reason'], $_POST['cost'], $_POST['date']]);
        $msg = "Awarded.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_prize' && can('manage_prizes')) {
        $pdo->prepare("DELETE FROM prizes WHERE id=?")->execute([$_POST['id']]);
        $msg = "Deleted.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $uid = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $currHash = $stmt->fetchColumn();
        if (password_verify($_POST['current_password'], $currHash)) {
            if (!empty($_POST['new_password'])) {
                $newHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET name=?,email=?,password=? WHERE id=?")->execute([$_POST['username'], $_POST['email'], $newHash, $uid]);
            } else {
                $pdo->prepare("UPDATE users SET name=?,email=? WHERE id=?")->execute([$_POST['username'], $_POST['email'], $uid]);
            }
            $_SESSION['name'] = $_POST['username'];
            $msg = "Updated.";
        } else {
            $msg = "Wrong Password";
            $msgType = "danger";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'mass_unassign' && can('enroll_student')) {
        $activeSessions = getActiveSessions($pdo);
        if (empty($activeSessions)) {
            $msg = "No Active Session.";
            $msgType = "danger";
        } else {
            $sids = implode(',', array_column($activeSessions, 'Id'));
            $mode = $_POST['mode'];
            $delSql = "";
            if ($mode === 'all')
                $delSql = "DELETE FROM enrollment WHERE EnrollmentSessionId IN ($sids)";
            elseif ($mode === 'auto')
                $delSql = "DELETE FROM enrollment WHERE EnrollmentSessionId IN ($sids) AND batch_id LIKE 'auto_%'";
            elseif ($mode === 'manual')
                $delSql = "DELETE FROM enrollment WHERE SessionId IN ($sids) AND (batch_id IS NULL OR batch_id NOT LIKE 'auto_%)'";
            if ($delSql) {
                $stmt = $pdo->prepare($delSql);
                $stmt->execute();
                $count = $stmt->rowCount();
                logAction('Mass Unassign', "Removed $count records.");
                $msg = "Removed $count.";
                $msgType = "warning";
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'bulk_auto_assign' && can('enroll_student')) {
        $activeSessions = getActiveSessions($pdo);
        if (empty($activeSessions)) {
            $msg = "No Active Session.";
            $msgType = "danger";
        } else {
            // $mainSessionId = $activeSessions[0]['Id'];
            $batchId = 'auto_' . uniqid();
            $sql = "SELECT s.* FROM students s LEFT JOIN enrollment e ON s.Id=e.StudentId AND e.IsActive=1 WHERE e.Id IS NULL GROUP BY s.Id";
            $unassigned = $pdo->query($sql)->fetchAll();
            $count = 0;
            foreach ($unassigned as $stu) {
                if (!$stu['DOB'])
                    continue;
                $age = calculateAge($stu['DOB']);
                $type = ($stu['Gender'] == 'Male') ? 'BoysClass' : 'GirlsClass';
                $match = $pdo->prepare("SELECT Class FROM classmanifest WHERE EnrollmentType=? AND ? BETWEEN MinAge AND MaxAge ORDER BY MinAge DESC LIMIT 1");
                $match->execute([$type, $age]);
                $cls = $match->fetch();
                if ($cls) {
                    try {
                        $targetSessionId = pickSessionForStudent($activeSessions, $stu['DOB'], $stu['Gender']);
                        $check = $pdo->prepare("SELECT Id FROM enrollment WHERE StudentId=? AND EnrollmentSessionId=? LIMIT 1");
                        $check->execute([$stu['Id'], $targetSessionId]);
                        if (!$check->fetch()) {
                            $enrId = $pdo->query("SELECT COALESCE(MAX(Id),0)+1 FROM enrollment")->fetchColumn();
                            $pdo->prepare("INSERT INTO enrollment (Id,StudentId,Class,EnrollmentSessionId,IsActive,batch_id) VALUES (?,?,?,?,1,?)")->execute([$enrId, $stu['Id'], $cls['Class'], $targetSessionId, $batchId]);
                            $count++;
                        }
                    } catch (Exception $e) {
                    }
                }
            }
            logAction('Bulk Enroll', "Auto-assigned $count students.");
            $msg = "Assigned $count students.";
        }
    }

if (isset($_POST['action']) && $_POST['action'] === 'manual_assign' && can('enroll_student')) {
    $classId = $_POST['class_id'];
    $studentId = $_POST['student_id'];
    $enrollmentFee = !empty($_POST['fee']) ? $_POST['fee'] : 0;
    $monthlyFee = !empty($_POST['monthly_fee_fixed']) ? $_POST['monthly_fee_fixed'] : 0;

    $classCap = getClassCapacity($pdo, $classId);
    $maxCap = ($classCap > 0) ? $classCap : (getSet('class_capacity') ?: 25);

    $count = $pdo->prepare("SELECT COUNT(*) FROM enrollment WHERE Class = ? AND IsActive = 1");
    $count->execute([$classId]);
    if ($count->fetchColumn() >= $maxCap) {
        $msg = "❌ Error: Class capacity full ($maxCap)!";
        $msgType = "danger";
    } else {
        $currentEnrollment = $pdo->prepare("SELECT EnrollmentSessionId FROM enrollment WHERE StudentId = ? AND IsActive = 1");
        $currentEnrollment->execute([$studentId]);
        $existing = $currentEnrollment->fetch();

        $sessId = intval($_POST['session_id'] ?? 0);
        if (!$sessId && $existing) {
            $sessId = intval($existing['EnrollmentSessionId']);
        }

        $pdo->prepare("UPDATE enrollment SET IsActive = 0 WHERE StudentId = ?")->execute([$studentId]);
        
        $newEnrId = $pdo->query("SELECT COALESCE(MAX(Id),0)+1 FROM enrollment")->fetchColumn();
        $collectedBy = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO enrollment (Id, StudentId, Class, EnrollmentSessionId, IsActive, enrollment_fee, individual_monthly_fee, collected_by) VALUES (?, ?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([$newEnrId, $studentId, $classId, $sessId, $enrollmentFee, $monthlyFee, $collectedBy]);
        
        $msg = "✅ Student enrolled successfully!";
        $msgType = "success";
    }
}

// --- QUICK FEE UPDATE (From Student Table) ---
if (isset($_POST['action']) && $_POST['action'] === 'quick_fee_update' && can('enroll_student')) {
    $pdo->prepare("UPDATE enrollment SET enrollment_fee = ? WHERE StudentId = ? AND IsActive = 1")
        ->execute([$_POST['fee'], $_POST['student_id']]);
    $msg = "Fee Updated.";
}

// REPLACE:
// if (isset($_POST['action']) && $_POST['action'] === 'delete_enrollment' && can('enroll_student')) {

// WITH:
if (isset($_POST['action']) && $_POST['action'] === 'delete_enrollment' && can('delete_enrollment')) {
    $pdo->prepare("DELETE FROM enrollment WHERE Id=?")->execute([$_POST['enrollment_id']]);
    $msg = "Enrollment Removed.";
    logAction('Delete Enrollment', "Removed Enrollment ID: {$_POST['enrollment_id']}");
}

if (isset($_POST['action']) && $_POST['action'] === 'clean_ghost_enrollments' && can('enroll_student')) {
    $del = $pdo->exec("DELETE FROM enrollment WHERE StudentId NOT IN (SELECT Id FROM students)");
    $msg = "Cleaned $del Ghost Enrollments.";
}

if (isset($_POST['action']) && $_POST['action'] === 'prune_logs' && can('delete_logs')) {
    $range = $_POST['range'];
    $delSql = "";
    switch ($range) {
        case '1_day':
            $delSql = "DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 1 DAY";
            break;
        case '1_week':
            $delSql = "DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 1 WEEK";
            break;
        case '1_month':
            $delSql = "DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 1 MONTH";
            break;
        case '1_year':
            $delSql = "DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 1 YEAR";
            break;
        case 'all':
            $delSql = "TRUNCATE TABLE activity_logs";
            break;
    }
    if ($delSql) {
        $pdo->exec($delSql);
        logAction('System Purge', "Logs Pruned.");
        $msg = "Logs Pruned.";
    }
}

// --- EXAMS & BUDGET BACKEND ACTIONS ---
// --- UPDATE DYNAMIC PRIZE & GAME RATES ---
if (isset($_POST['action']) && $_POST['action'] === 'update_prize_settings' && can('manage_exams')) {
    $keys = ['prize_rate_present', 'prize_rate_late', 'prize_rate_pct', 'prize_round_to', 'currency_denominations', 'game_reward_1st', 'game_reward_2nd', 'game_reward_3rd'];
    foreach($keys as $k) {
        if(isset($_POST[$k])) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key=?");
            $chk->execute([$k]);
            if($chk->fetchColumn() > 0) {
                $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$_POST[$k], $k]);
            } else {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $_POST[$k]]);
            }
        }
    }
    $msg = "All Reward Rates & Denominations Updated Dynamically!";
    // Reload settings into global array instantly
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch()) { $sys[$row['setting_key']] = $row['setting_value']; }
}

if (isset($_POST['action']) && $_POST['action'] === 'create_exam' && can('manage_exams')) {
    try {
        $pdo->prepare("INSERT INTO exams (name,session_id,class_id,total_marks,date,att_start_date,att_end_date) VALUES (?,?,?,?,?,?,?)")
            ->execute([$_POST['exam_name'], $_POST['session_id'], $_POST['class_id'], (int)$_POST['total_marks'], $_POST['date'], $_POST['att_start_date'], $_POST['att_end_date']]);
        $msg = "Exam Created Successfully.";
    } catch (PDOException $e) { $msg = "Error creating exam: " . $e->getMessage(); $msgType = "danger"; }
}

if (isset($_POST['action']) && $_POST['action'] === 'edit_exam' && can('manage_exams')) {
    try {
        $pdo->prepare("UPDATE exams SET name=?, total_marks=?, date=?, session_id=?, class_id=?, att_start_date=?, att_end_date=? WHERE id=?")
            ->execute([$_POST['exam_name'], (int)$_POST['total_marks'], $_POST['date'], $_POST['session_id'], $_POST['class_id'], $_POST['att_start_date'], $_POST['att_end_date'], $_POST['exam_id']]);
        $msg = "Exam Updated.";
    } catch (PDOException $e) { $msg = "Error updating exam: " . $e->getMessage(); $msgType = "danger"; }
}

    if (isset($_POST['action']) && $_POST['action'] === 'delete_exam' && can('manage_exams')) {
        $pdo->prepare("DELETE FROM exams WHERE id=?")->execute([$_POST['exam_id']]);
        $pdo->prepare("DELETE FROM exam_results WHERE exam_id=?")->execute([$_POST['exam_id']]);
        $msg = "Exam & Results Deleted.";
    }

    // EXCEL JESA MUKAMMAL FORMULA (With Game Rewards & Dates)
    if (isset($_POST['action']) && $_POST['action'] === 'save_marks' && (can('manage_exams') || can('enter_marks') || $_SESSION['role'] === 'teacher')) {
        try {
            $examId = $_POST['exam_id'];
            $ex = $pdo->prepare("SELECT * FROM exams WHERE id=?");
            $ex->execute([$examId]);
            $examData = $ex->fetch();
            
            $rP = (float)(getSet('prize_rate_present') ?: 37);
            $rL = (float)(getSet('prize_rate_late') ?: 25);
            $rPct = (float)(getSet('prize_rate_pct') ?: 50);
            $roundVal = (int)(getSet('prize_round_to') ?: 10);

            foreach ($_POST['marks'] as $enr => $mk) {
                if ($mk !== '') {
                    $mk = (float)$mk;
                    $pct = ($examData['total_marks'] > 0) ? ($mk / $examData['total_marks']) * 100 : 0;
                    
                    // 1. Get Attendance ONLY between Start and End dates
                    $attQ = $pdo->prepare("SELECT SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) as p_count, SUM(CASE WHEN status='Late' THEN 1 ELSE 0 END) as l_count FROM attendance WHERE enrollment_id = ? AND date BETWEEN ? AND ?");
                    $attQ->execute([$enr, $examData['att_start_date'], $examData['att_end_date']]);
                    $att = $attQ->fetch();
                    
                    // 2. Get Game Rewards
                    $gameQ = $pdo->prepare("SELECT SUM(reward_amount) FROM round_table_records WHERE enrollment_id = ? AND session_id = ? AND date BETWEEN ? AND ?");
                    $gameQ->execute([$enr, $examData['session_id'], $examData['att_start_date'], $examData['att_end_date']]);
                    $totalGameReward = (int)$gameQ->fetchColumn();
                    
                    // 3. Final Payout Calculation
                    $attPayout = ($att['p_count'] * $rP) + ($att['l_count'] * $rL);
                    $examPayout = ($pct * $rPct);
                    $rawPayout = $attPayout + $examPayout + $totalGameReward;
                    $finalPayout = $roundVal > 0 ? round($rawPayout / $roundVal) * $roundVal : round($rawPayout);

                    $pdo->prepare("INSERT INTO exam_results (exam_id, enrollment_id, obtained_marks, calculated_payout) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE obtained_marks=VALUES(obtained_marks), calculated_payout=VALUES(calculated_payout)")
                        ->execute([$examId, $enr, $mk, $finalPayout]);
                }
            }
            $msg = "Marks Saved & Budgets Calculated Perfectly.";
        } catch (PDOException $e) { $msg = "Error: " . $e->getMessage(); $msgType = "danger"; }
    }

    // DAILY GAME WINNERS SAVE KARNA
    if (isset($_POST['action']) && $_POST['action'] === 'save_game_winners' && (can('manage_exams') || can('enter_marks') || $_SESSION['role'] === 'teacher')) {
        try {
            $sess = $_POST['session_id'];
            $cls = $_POST['class_id'];
            $dt = $_POST['game_date'];
            
            $r1 = (int)(getSet('game_reward_1st') ?: 70);
            $r2 = (int)(getSet('game_reward_2nd') ?: 50);
            $r3 = (int)(getSet('game_reward_3rd') ?: 30);
            
            $pdo->prepare("DELETE FROM round_table_records WHERE class_id=? AND session_id=? AND date=?")->execute([$cls, $sess, $dt]);

            if (!empty($_POST['pos_1'])) $pdo->prepare("INSERT INTO round_table_records (session_id, class_id, date, enrollment_id, position, reward_amount) VALUES (?,?,?,?,1,?)")->execute([$sess, $cls, $dt, $_POST['pos_1'], $r1]);
            if (!empty($_POST['pos_2'])) $pdo->prepare("INSERT INTO round_table_records (session_id, class_id, date, enrollment_id, position, reward_amount) VALUES (?,?,?,?,2,?)")->execute([$sess, $cls, $dt, $_POST['pos_2'], $r2]);
            if (!empty($_POST['pos_3'])) $pdo->prepare("INSERT INTO round_table_records (session_id, class_id, date, enrollment_id, position, reward_amount) VALUES (?,?,?,?,3,?)")->execute([$sess, $cls, $dt, $_POST['pos_3'], $r3]);
            
            $msg = "Daily Round Table Game Winners Saved Successfully!";
        } catch (PDOException $e) { $msg = "Error saving game: " . $e->getMessage(); $msgType = "danger"; }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_event' && can('event_manage')) {
        $pdo->prepare("INSERT INTO events (session_id,title,start_date,description) VALUES (?,?,?,?)")->execute([$_POST['session_id'], $_POST['title'], $_POST['date'], $_POST['description']]);
        $msg = "Created.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit_event' && can('event_manage')) {
        $pdo->prepare("UPDATE events SET title=?,start_date=?,description=? WHERE id=?")->execute([$_POST['title'], $_POST['date'], $_POST['description'], $_POST['event_id']]);
        $msg = "Updated.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_event' && can('event_manage')) {
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$_POST['event_id']]);
        $msg = "Deleted.";
    }

    // --- REPLACE existing 'add_class' and 'edit_class' blocks ---

if (isset($_POST['action']) && $_POST['action'] === 'add_class' && can('manage_classes')) {
        $newId = $pdo->query("SELECT MAX(CAST(Class AS UNSIGNED)) FROM classmanifest")->fetchColumn() + 1;
        $pdo->prepare("INSERT INTO classmanifest (Class,ClassName,EnrollmentType,MinAge,MaxAge,WhatsappLink,session_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$newId, $_POST['class_name'], $_POST['type'], $_POST['min_age'], $_POST['max_age'], $_POST['whatsapp_link'], $_POST['session_id']]);

        // Save Multiple Teachers
        if (!empty($_POST['assigned_teachers'])) {
            foreach ($_POST['assigned_teachers'] as $uid) {
                $pdo->prepare("INSERT INTO class_teachers (class_id, user_id) VALUES (?,?)")->execute([$newId, $uid]);
            }
        }
        $msg = "Class Added.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit_class' && can('manage_classes')) {
        $pdo->prepare("UPDATE classmanifest SET ClassName=?,EnrollmentType=?,MinAge=?,MaxAge=?,WhatsappLink=?,session_id=? WHERE Class=?")
            ->execute([$_POST['class_name'], $_POST['type'], $_POST['min_age'], $_POST['max_age'], $_POST['whatsapp_link'], $_POST['session_id'], $_POST['class_id']]);

        // Clear and Re-assign Teachers
        $pdo->prepare("DELETE FROM class_teachers WHERE class_id=?")->execute([$_POST['class_id']]);
        if (!empty($_POST['assigned_teachers'])) {
            foreach ($_POST['assigned_teachers'] as $uid) {
                $pdo->prepare("INSERT INTO class_teachers (class_id, user_id) VALUES (?,?)")->execute([$_POST['class_id'], $uid]);
            }
        }
        $msg = "Class Updated.";
    }

    // --- REPLACE existing 'delete_class' block ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_class'  && can('manage_classes')) {
        // 1. Remove Multi-Teacher Assignments first
        $pdo->prepare("DELETE FROM class_teachers WHERE class_id=?")->execute([$_POST['class_id']]);

        // 2. Delete the Class
        $pdo->prepare("DELETE FROM classmanifest WHERE Class=?")->execute([$_POST['class_id']]);

        $msg = "Class & Assignments Deleted.";
    }

if (isset($_POST['action']) && $_POST['action'] === 'generate_certificate' && can('manage_certificates')) {
        // 1. Handle Background Image
        $bgImage = !empty($_POST['existing_bg']) ? $_POST['existing_bg'] : null;
        if (isset($_FILES['new_bg_image']) && $_FILES['new_bg_image']['error'] === UPLOAD_ERR_OK) {
            if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
            $ext = pathinfo($_FILES['new_bg_image']['name'], PATHINFO_EXTENSION);
            $bgImage = 'cert_bg_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['new_bg_image']['tmp_name'], 'uploads/' . $bgImage);
        }

        // 2. NEW: Handle Custom Logo Image
        $certLogo = !empty($_POST['existing_logo']) ? $_POST['existing_logo'] : null;
        if (isset($_FILES['new_cert_logo']) && $_FILES['new_cert_logo']['error'] === UPLOAD_ERR_OK) {
            if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
            $ext = strtolower(pathinfo($_FILES['new_cert_logo']['name'], PATHINFO_EXTENSION));
            $certLogo = 'cert_logo_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['new_cert_logo']['tmp_name'], 'uploads/' . $certLogo);
        }

        // 3. Handle Custom Google Font
        $font = $_POST['font_family'];
        if ($font === 'custom' && !empty($_POST['custom_font'])) {
            $font = trim($_POST['custom_font']);
        }

        // Update INSERT Query to include cert_logo
// Update INSERT Query to include custom overrides
        $pdo->prepare("INSERT INTO certificates (student_id, type, title, description, issued_date, issued_by, bg_image, text_color, font_family, color_title, color_name, color_badge, bg_badge, cert_logo, custom_season, custom_session, custom_class) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $_POST['student_id'], 
                $_POST['type'], 
                $_POST['title'], 
                $_POST['description'], 
                $_POST['date'], 
                $_SESSION['name'], 
                $bgImage, 
                $_POST['text_color'], 
                $font,
                $_POST['color_title'],
                $_POST['color_name'],
                $_POST['color_badge'],
                $_POST['bg_badge'],
                $certLogo,
                $_POST['custom_season'],
                $_POST['custom_session'],
                $_POST['custom_class']
            ]);
        $msg = "Certificate Generated Successfully.";
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete_certificate' && can('manage_certificates')) {
        $pdo->prepare("DELETE FROM certificates WHERE id=?")->execute([$_POST['id']]);
        $msg = "Certificate Deleted.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_role' && can('manage_roles')) {
        try {
            $pdo->prepare("INSERT INTO system_roles (role_name) VALUES (?)")->execute([$_POST['role_name']]);
            $msg = "Role Added.";
        } catch (Exception $e) {
            $msg = "Exists.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_role' && can('manage_roles')) {
        if ($_POST['role_name'] != 'admin') {
            $pdo->prepare("DELETE FROM system_roles WHERE role_name=?")->execute([$_POST['role_name']]);
            $msg = "Deleted.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_role_permissions' && can('manage_roles')) {
        $p = implode(',', $_POST['perms'] ?? []);
        $pdo->prepare("UPDATE system_roles SET permissions=? WHERE role_name=?")->execute([$p, $_POST['role_name']]);
        $msg = "Perms Updated.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_user' && can('manage_users')) {
        $check = $pdo->prepare("SELECT id FROM users WHERE name=? OR email=?");
        $check->execute([$_POST['username'], $_POST['email']]);
        if ($check->rowCount() > 0) {
            $msg = "User/Email Exists";
            $msgType = "danger";
        } else {
            $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?, ?, ?, ?)")->execute([$_POST['username'], $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['role']]);
            $msg = "User Added.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit_user' && can('manage_users')) {
        if (!empty($_POST['password']))
            $pdo->prepare("UPDATE users SET name=?,password=?,role=? WHERE id=?")->execute([$_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['role'], $_POST['user_id']]);
        else
            $pdo->prepare("UPDATE users SET name=?,role=? WHERE id=?")->execute([$_POST['username'], $_POST['role'], $_POST['user_id']]);
        $msg = "Updated.";
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_user' && can('manage_users')) {
        if ($_POST['user_id'] != $_SESSION['user_id'])
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_POST['user_id']]);
        $msg = "Deleted.";
    }

if (isset($_POST['action']) && $_POST['action'] === 'attendance' && can('attendance_take')) {
        // PERMISSION CHECK: Restrict editing past dates
        if ($_POST['date'] < date('Y-m-d') && !can('attendance_edit_past') && $_SESSION['role'] !== 'admin') {
            $msg = "⚠️ Permission Denied: You cannot edit past attendance.";
            $msgType = "danger";
        } else {
            foreach ($_POST['status'] as $e => $s)
                $pdo->prepare("INSERT INTO attendance (enrollment_id,date,status,marked_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)")->execute([$e, $_POST['date'], $s, $_SESSION['user_id']]);
            $msg = "Attendance Saved.";
        }
    }

// --- DELETE / CLEAR ATTENDANCE ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_attendance' && can('attendance_take')) {
        // PERMISSION CHECK: Restrict deleting past dates
        if ($_POST['date'] < date('Y-m-d') && !can('attendance_edit_past') && $_SESSION['role'] !== 'admin') {
            $msg = "⚠️ Permission Denied: You cannot delete past attendance.";
            $msgType = "danger";
        } else {
            $sql = "DELETE a FROM attendance a 
                    JOIN enrollment e ON a.enrollment_id = e.Id 
                    WHERE e.Class = ? AND a.date = ?";
            $pdo->prepare($sql)->execute([$_POST['class_id'], $_POST['date']]);
            $msg = "Attendance Cleared for selected date.";
            $msgType = "warning";
        }
    }


 if (isset($_POST['action']) && $_POST['action'] === 'enroll' && can('enroll_student')) {
        $classIdToCheck = $_POST['class_id'];

        $currentCount = $pdo->prepare("SELECT COUNT(*) FROM enrollment WHERE Class = ? AND IsActive = 1");
        $currentCount->execute([$classIdToCheck]);
        $countVal = $currentCount->fetchColumn();

        $classCap = getClassCapacity($pdo, $classIdToCheck);
        $maxCap = ($classCap > 0) ? $classCap : (getSet('class_capacity') ?: 25);
        if ($countVal >= $maxCap) {
            $msg = "⚠️ <strong>Capacity Full!</strong> This class has reached $maxCap students. Please create a new Section (e.g., Class 1-B).";
            $msgType = "danger";
        } else {
            $sess = getActiveSessions($pdo);
            if ($sess) {
                // 1. Fetch Student & Class Info for WhatsApp Message
                $stmt = $pdo->prepare("SELECT s.Name, s.MobileNumberFather, s.MobileNumberMother, c.ClassName, c.WhatsappLink FROM students s LEFT JOIN classmanifest c ON c.Class = ? WHERE s.Id = ?");
                $stmt->execute([$_POST['class_id'], $_POST['student_id']]);
                $info = $stmt->fetch();

                // 2. FIX: Delete old active enrollment first to prevent duplicates!
                $pdo->prepare("DELETE FROM enrollment WHERE StudentId=? AND IsActive=1")->execute([$_POST['student_id']]);

                // 3. Use the selected session
                $correctSessionId = intval($_POST['session_id'] ?? 0);
                $sessName = 'Selected Session';
                $timings = 'Check Schedule';

                $fee = !empty($_POST['enrollment_fee']) ? $_POST['enrollment_fee'] : 0;
                $monthlyFee = !empty($_POST['monthly_fee_fixed']) ? $_POST['monthly_fee_fixed'] : 0;
                $enrId = $pdo->query("SELECT COALESCE(MAX(Id),0)+1 FROM enrollment")->fetchColumn();
                $collectedBy = $_SESSION['user_id'] ?? null;

                // 4. Insert new enrollment properly into the class's session
                $pdo->prepare("INSERT INTO enrollment (Id, StudentId, Class, EnrollmentSessionId, IsActive, enrollment_fee, individual_monthly_fee, collected_by) VALUES (?,?,?,?,1,?,?,?)")
                    ->execute([$enrId, $_POST['student_id'], $_POST['class_id'], $correctSessionId, $fee, $monthlyFee, $collectedBy]);

                $msg = "Enrolled successfully.";

                // 5. Dynamic WhatsApp logic (Matches Student Table)
                $ph = preg_replace('/[^0-9]/', '', $info['MobileNumberFather'] ?: $info['MobileNumberMother']);
                if ($ph) {
                    $startDate = getSet('start_date') ?: 'Upcoming Date';
                    $rawMsg = "{$info['Name']} your class is {$info['ClassName']} in {$sessName} and your class timings are {$timings} . Your class will start on {$startDate}.";
                    if (!empty($info['WhatsappLink'])) {
                        $rawMsg .= " Please join the WhatsApp group: {$info['WhatsappLink']}";
                    }
                    $waMsg = rawurlencode($rawMsg);
                    $waButton = "<a href='https://api.whatsapp.com/send?phone=$ph&text=$waMsg' target='_blank' class='btn btn-success btn-sm ms-2'><i class='fab fa-whatsapp'></i> Send WhatsApp</a>";
                }
            }
        }
    
    }

}



// --- Helper: Get all columns for students table ---

function getStudentColumns($pdo)
{

    $cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll();

    return array_column($cols, 'Field');

}



$page = $_GET['page'] ?? 'login';

if (!isLoggedIn())
    $page = 'login';

if ($page === 'logout') {
    logAction('Logout', "User logged out");
    session_destroy();
    redirect('?');
}

$activeSessions = getActiveSessions($pdo);



$available_permissions = [
    'student_view' => 'View Students', 'student_add' => 'Add Student', 'student_edit' => 'Edit Student', 'student_delete' => 'Delete Student',
    'enroll_student' => 'Enroll/Assign', 'delete_enrollment' => 'Un-Enroll',
    
    'attendance_take' => 'Take Attendance', 'attendance_edit_past' => 'Edit Past Attendance', 'view_attendance_report' => 'View Att. Report', 'delete_attendance' => 'Delete Att. Data',
    
    'hasanaat_view' => 'View Hasanaat Cards', 'hasanaat_add' => 'Add Card', 'hasanaat_edit' => 'Edit Card', 'hasanaat_delete' => 'Delete Card', 'hasanaat_pay' => 'Take Payment', 'hasanaat_pay_edit' => 'Edit Payment', 'hasanaat_pay_delete' => 'Delete Payment',
    
    'tabarruk_view' => 'View Tabarruk', 'tabarruk_add' => 'Add Tabarruk', 'tabarruk_edit' => 'Edit Tabarruk', 'tabarruk_delete' => 'Delete Tabarruk',
    
    'ledger_view' => 'View General Ledger', 'income_add' => 'Add Income', 'income_edit' => 'Edit Income', 'income_delete' => 'Delete Income', 'expense_add' => 'Add Expense', 'expense_edit' => 'Edit Expense', 'expense_delete' => 'Delete Expense',
    
    'budget_view' => 'View Budget Plan', 'budget_manage' => 'Manage Budget Targets',
    'view_fee_report' => 'View Fee Report', 'edit_fee' => 'Edit Fee', 'delete_fee' => 'Delete Fee', 'prune_fees' => 'Prune Fees',
    
// Nayi Permissions
    'manage_exams' => 'Manage Exams & Budget (Admin)', 'enter_marks' => 'Enter Exam Marks (Teachers)', 'view_reports' => 'View Results',
    'event_manage' => 'Manage Events & Calendar', 'manage_certificates' => 'Manage Certificates', 'manage_prizes' => 'Manage Prizes',
    
    'manage_roles' => 'Manage Roles', 'manage_users' => 'Manage Users', 'view_logs' => 'View Logs', 'delete_logs' => 'Delete Logs', 'backup_db' => 'Backup DB',
    'manage_sessions' => 'Manage Sessions', 'manage_classes' => 'Manage Classes', 'manage_teachers' => 'Manage Teachers',
    
    // Print Permissions
    'print_receipts' => 'Print Finance Receipts', 'print_slips' => 'Print Enrollment Slips & IDs'
];

if ($page === 'print_receipt' && (can('print_receipts') || $_SESSION['role'] === 'admin')):
    $type = $_GET['type'] ?? '';
    $id = $_GET['id'] ?? 0;
    $data = [];
    $title = "RECEIPT";

    if ($type == 'hasanaat') {
        $stmt = $pdo->prepare("SELECT p.*, h.CardNumber, h.HolderName FROM hasanaat_payments p JOIN hasanaat_cards h ON p.CardId=h.Id WHERE p.Id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $title = "HASANAAT INSTALLMENT RECEIPT";
            $data = [
                'Receipt No' => 'H-' . $row['Id'],
                'Date' => date('d-M-Y', strtotime($row['Date'])),
                'Card Number' => $row['CardNumber'],
                'Card Holder' => $row['HolderName'],
                'Amount Received' => number_format($row['Amount']),
                'Remarks' => $row['Remarks']
            ];
        }
    } elseif ($type == 'tabarruk') {
        $stmt = $pdo->prepare("SELECT * FROM tabarruk WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $title = "TABARRUK EXPENSE VOUCHER";
            $data = [
                'Voucher No' => 'TB-' . $row['id'],
                'Date' => date('d-M-Y', strtotime($row['date'])),
                'Item Name' => $row['item_name'],
                'Quantity' => $row['quantity'],
                'Total Cost' => number_format($row['total_cost']),
                'Description' => $row['description']
            ];
        }
    } elseif ($type == 'expense') {
        $stmt = $pdo->prepare("SELECT * FROM general_expenses WHERE Id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $title = "PAYMENT VOUCHER";
            $data = [
                'Voucher No' => 'EXP-' . $row['Id'],
                'Date' => date('d-M-Y', strtotime($row['Date'])),
                'Category' => $row['Category'],
                'Paid To/Title' => $row['Title'],
                'Amount Paid' => number_format($row['Amount']),
                'Description' => $row['Description']
            ];
        }
    } elseif ($type == 'income') {
        $stmt = $pdo->prepare("SELECT * FROM general_income WHERE Id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $title = "INCOME RECEIPT";
            $data = [
                'Receipt No' => 'INC-' . $row['Id'],
                'Date' => date('d-M-Y', strtotime($row['Date'])),
                'Category' => $row['Category'],
                'Received From' => $row['Title'],
                'Amount Received' => number_format($row['Amount']),
                'Description' => $row['Description']
            ];
        }
    }
    ?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Print Receipt</title>
        <style>
            body {
                font-family: 'Courier New', monospace;
                padding: 40px;
                text-align: center;
            }

            .receipt-box {
                max-width: 600px;
                margin: 0 auto;
                border: 2px solid #000;
                padding: 30px;
                text-align: left;
            }

            .header {
                text-align: center;
                margin-bottom: 20px;
            }

            .header h3 {
                margin: 0;
                text-transform: uppercase;
            }

            .header h5 {
                margin: 5px 0 0;
                border-bottom: 1px solid #000;
                display: inline-block;
                padding-bottom: 5px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
                font-size: 14px;
            }

            td {
                padding: 8px;
                vertical-align: top;
            }

            .label {
                font-weight: bold;
                width: 40%;
            }

            .val {
                border-bottom: 1px dotted #ccc;
            }

            .footer {
                margin-top: 50px;
                display: flex;
                justify-content: space-between;
            }

            .sig {
                border-top: 1px solid #000;
                width: 40%;
                text-align: center;
                font-size: 12px;
                padding-top: 5px;
            }

            @media print {
                .no-print {
                    display: none !important;
                }

                .receipt-box {
                    border: 2px solid #000;
                }
            }
        </style>
    </head>

    <body>
        <div class="receipt-box">
            <div class="header">
                <h3><?= strtoupper(getSet('inst_name') ?: 'MADRASA SYSTEM') ?></h3>
                <h5><?= $title ?></h5>
            </div>

            <?php if (empty($data)): ?>
                <p style="text-align:center; color:red;">Record not found or invalid ID.</p>
            <?php else: ?>
                <table>
                    <?php foreach ($data as $key => $val): ?>
                        <tr>
                            <td class="label"><?= $key ?>:</td>
                            <td class="val"><?= $val ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div class="footer">
                    <div class="sig">Accountant</div>
                    <div class="sig">Authorized Signature</div>
                </div>
            <?php endif; ?>

            <div class="text-center no-print" style="margin-top:30px; text-align:center;">
                <button onclick="window.print()" style="padding:10px 20px; cursor:pointer;">PRINT</button>
                <button onclick="window.close()" style="padding:10px 20px; cursor:pointer;">CLOSE</button>
            </div>
        </div>
    </body>

    </html>
    <?php exit; endif;
// --- Enrollment Slip Generation (DYNAMIC & PRESERVED DESIGN) ---

if (isLoggedIn() && isset($_GET['page']) && $_GET['page'] === 'enrollment_slip' && can('print_slips')) {

    $enrId = (int) ($_GET['enrollment_id'] ?? 0);

    // 1. Fetch Data (Updated to fetch Timings from DB)
    $stmt = $pdo->prepare("SELECT e.Id as enrollment_id, e.enrollment_fee, e.collected_by, e.created_at as enr_date, 
                           s.*, c.ClassName, es.Name as SessionName, es.Timings, u.name as CollectorName 
                           FROM enrollment e 
                           JOIN students s ON e.StudentId=s.Id 
                           LEFT JOIN classmanifest c ON e.Class=c.Class 
                           LEFT JOIN enrollmentsession es ON e.EnrollmentSessionId=es.Id 
                           LEFT JOIN users u ON e.collected_by = u.id
                           WHERE e.Id = ? LIMIT 1");
    $stmt->execute([$enrId]);
    $row = $stmt->fetch();

    if (!$row) {
        echo "<p>Enrollment not found.</p>";
        exit;
    }

    // 2. Data Preparation
    $studentName = formatName($row['Name']);
    $paternity = formatName($row['Paternity']);
    $className = htmlspecialchars($row['ClassName']);
    $sessionName = htmlspecialchars($row['SessionName']);
    $studentId = $row['Id'];
    $dob = formatDate($row['DOB']);
    $fee = number_format($row['enrollment_fee']);
    $admDate = $row['enr_date'] ? date('d/m/Y', strtotime($row['enr_date'])) : date('d/m/Y');
    
    // Dynamic Address from Settings (Not hardcoded in DB or PHP)
    $address = htmlspecialchars($row['Address'] ?? ''); 

    // Names
    $collector = formatName($row['CollectorName'] ?? 'System');
    $printer = ucwords(strtolower($_SESSION['name']));

    // Timings (Dynamic from DB)
    // Agar DB mein timing hai to wo dikhao, warna Dynamic Setting wali logic
    $timings = !empty($row['Timings']) ? $row['Timings'] : 'Check Schedule';

    // Phone Formatting
    function formatPhoneIntl($num)
    {
        $n = preg_replace('/[^0-9]/', '', $num);
        if (strlen($n) == 11 && $n[0] == '0') {
            return '+92-' . substr($n, 1, 3) . '-' . substr($n, 4, 3) . '-' . substr($n, 7);
        }
        if (strlen($n) == 12 && substr($n, 0, 2) == '92') {
            return '+92-' . substr($n, 2, 3) . '-' . substr($n, 5, 3) . '-' . substr($n, 8);
        }
        return $num;
    }

    $rawPhone = $row['MobileNumberFather'] ?: ($row['MobileNumberMother'] ?: '');
    $formattedPhone = formatPhoneIntl($rawPhone);
    
    // Dynamic Start Date
    $startDate = getSet('start_date');

    // 3. Grid Layout
    $renderGrid = function () use ($studentName, $paternity, $studentId, $dob, $className, $sessionName, $formattedPhone, $address, $startDate, $timings, $fee, $collector, $admDate, $printer) {
        return "
        <div class=\"data-grid\">
            <div class=\"data-row\"><span class=\"label\">Student Name</span><span class=\"value name-value\">$studentName</span></div>
            <div class=\"data-row\"><span class=\"label\">Date of Birth</span><span class=\"value\">$dob</span></div>
            
            <div class=\"data-row\"><span class=\"label\">Student ID</span><span class=\"value\" style=\"font-weight: 800;\">$studentId</span></div>
            <div class=\"data-row\"><span class=\"label\">Class</span><span class=\"value\">$className</span></div>

            <div class=\"grid-gap\"></div>

            <div class=\"data-row\"><span class=\"label\">Father's Name</span><span class=\"value\">$paternity</span></div>
            <div class=\"data-row\"><span class=\"label\">Phone Number</span><span class=\"value\">$formattedPhone</span></div>
            
            <div class=\"data-row full-width\"><span class=\"label\">Address</span><span class=\"value\" style=\"font-size:8px;\">$address</span></div>

            <div class=\"grid-gap\"></div>

            <div class=\"data-row\"><span class=\"label\">Start Date</span><span class=\"value\">$startDate</span></div>
            <div class=\"data-row\"><span class=\"label\">Class Timing</span><span class=\"value\">$timings</span></div>

            <div class=\"data-row\"><span class=\"label\">Fees Received</span><span class=\"value\">$fee</span></div>
            <div class=\"data-row\"><span class=\"label\">Admission Date</span><span class=\"value\">$admDate</span></div>
            
            <div class=\"grid-gap\"></div>
            
            <div class=\"data-row\"><span class=\"label\">Received By</span><span class=\"value\">$collector</span></div>
            <div class=\"data-row\"><span class=\"label\">Printed By</span><span class=\"value\">$printer</span></div>
        </div>";
    };

    $gridHtml = $renderGrid();

    // Fetch Dynamic Header Info
    $instName = getSet('inst_name');
    $instAddr = getSet('inst_address');
$instPhone = getSet('inst_phone');
    $instLogo = getSet('inst_logo') ?: 'logo.png'; // Dynamic Logo

    echo "<!doctype html>
<html>
<head>
<meta charset=\"utf-8\">
<title>ID Card & Slip</title>
<link href=\"https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap\" rel=\"stylesheet\">
<link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\">
<style>
    @page { size: A6; margin: 0; }
    html, body { 
        width: 105mm; 
        height: 148mm; 
        margin: 0; 
        padding: 0; 
        font-family: 'Roboto', sans-serif;
        -webkit-print-color-adjust: exact; 
        print-color-adjust: exact;
        color: #000 !important; /* STRICT BLACK */
        background: #fff;
    }
    
    .container {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
    }

    /* SECTION FRAME */
    .section {
        padding: 8px 10px;
        position: relative;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        height: 50%;
        box-sizing: border-box;
    }

    .border-frame {
        position: absolute;
        top: 4px; left: 4px; right: 4px; bottom: 4px;
        border: 2px solid #000;
        pointer-events: none;
        z-index: 2;
        border-radius: 4px;
    }

    /* SESSION BADGE - FIXED POSITIONING */
    .session-badge {
        position: absolute;
        top: 28px; /* Perfectly aligned with the Address/Phone Header */
        right: 10px;
        transform: translateY(-50%); /* Centers vertically on the 28px line */
        background: transparent;
        color: #000;
        font-family: 'Roboto', sans-serif;
        border: 2px solid #000;
        padding: 3px 10px;
        font-size: 8px;
        font-weight: 800;
        border-radius: 4px;
        z-index: 10;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        background: #fff;
    }

    /* HEADER - LAYOUT */
    .header-row {
        position: relative;
        z-index: 5;
        display: flex;
        justify-content: center; /* Center Content (Address) */
        align-items: center;     /* Middle Vertical */
        margin-bottom: 5px;
        border-bottom: 2px solid #000;
        padding-bottom: 6px;
        min-height: 40px;        /* Ensure space for Logo */
    }
    
    /* LOGO - ABSOLUTE LEFT */
    .logo-main {
        width: 80px; 
        height: auto;
        mix-blend-mode: multiply;
        position: absolute;
        left: 2px;
        top: 50%;
        transform: translateY(-50%); /* Perfectly Middle Vertical */
    }

    /* ADDRESS TEXT BLOCK - CENTER */
    .address-block {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center; 
        text-align: center;
        font-size: 8px;
        color: #000;
        font-weight: 500;
        line-height: 1.4;
        /* Padding ensures text doesn't overlap logo */
        padding-left: 85px; 
        padding-right: 85px; 
    }
    
    .addr-line {
        display: flex;
        align-items: center;
        white-space: nowrap;
    }
    
    .addr-icon {
        width: 12px;
        text-align: center;
        margin-right: 4px;
        font-size: 9px;
    }
    
    /* GRID & DATA */
    .content-area {
        position: relative;
        z-index: 5;
        padding: 2px 5px;
        flex-grow: 1;
    }

    .data-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2px 10px;
        font-size: 9px;
    }
    
    .data-row {
        display: flex;
        flex-direction: column;
    }
    
    .full-width {
        grid-column: span 2;
    }

    /* STANDARD BREAK SPACE */
    .grid-gap {
        grid-column: span 2;
        height: 0.1 px;
        border-bottom: 1px dotted #000;
        opacity: 0.2;
        margin: 1px 0 1px 0;
    }

    .label {
        font-size: 6px;
        text-transform: uppercase;
        color: #000;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin-bottom: 1px;
    }
    .value {
        font-weight: 500;
        font-size: 9px;
        border-bottom: 1px dotted #000;
        padding-bottom: 0;
        color: #000;
        min-height: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.2;
    }
    .name-value {
        font-weight: 900;
        font-size: 11px;
    }

    /* CUT LINE */
    .cut-line {
        height: 0;
        border-top: 1px dashed #000;
        position: relative;
        text-align: center;
        margin: 5px 0;
        z-index: 20;
    }
    .cut-icon {
        position: absolute;
        top: -8px;
        left: 50%;
        transform: translateX(-50%);
        background: #fff;
        padding: 0 5px;
        font-size: 10px;
        color: #000;
    }

</style>
</head>
<body>
<br>
    <div class=\"container\">
        
        <div class=\"section\">
            <div class=\"border-frame\"></div>
            
            <div class=\"session-badge\">$sessionName</div>

            <div class=\"header-row\">
                <img src=\"$instLogo\" alt=\"Logo\" class=\"logo-main\">
                
                <div class=\"address-block\">
                    <div class=\"addr-line\">
                        <i class=\"fas fa-map-marker-alt addr-icon\"></i> $instAddr
                    </div>
                    <div class=\"addr-line\">
                        <i class=\"fas fa-phone addr-icon\"></i> $instPhone
                    </div>
                </div>
            </div>
            
            <div class=\"content-area\">
                $gridHtml
            </div>
        </div>
        <br>

        <div class=\"cut-line\">
            <i class=\"fas fa-scissors cut-icon\"></i>
        </div>
<br>
        <div class=\"section\">
            <div class=\"border-frame\" style=\"border-style: double; border-width: 3px;\"></div>
            
            <div class=\"session-badge\">$sessionName</div>

            <div class=\"header-row\">
                 <img src=\"$instLogo\" alt=\"Logo\" class=\"logo-main\">
                
                <div class=\"address-block\">
                    <div class=\"addr-line\">
                        <i class=\"fas fa-map-marker-alt addr-icon\"></i> $instAddr
                    </div>
                    <div class=\"addr-line\">
                        <i class=\"fas fa-phone addr-icon\"></i> $instPhone
                    </div>
                </div>
            </div>

            <div class=\"content-area\">
                $gridHtml
            </div>
        </div>

    </div>
    <script>window.onload = function() { window.print(); }</script>
</body>
</html>";
    exit;
}

// --- Bulk Print ID Cards (DYNAMIC & MATCHING DESIGN) ---
if (isLoggedIn() && isset($_GET['page']) && $_GET['page'] === 'bulk_print_ids') {

    $sessId = (int) ($_GET['session_id'] ?? 0);

    $sName = $pdo->query("SELECT Name FROM enrollmentsession WHERE Id = $sessId")->fetchColumn();
    if (!$sName) die("Invalid Session");

    // Fetch with Timings
    $stmt = $pdo->prepare("SELECT DISTINCT e.Id as enrollment_id, e.enrollment_fee, e.collected_by, e.created_at as enr_date, 
                           s.*, c.ClassName, es.Name as SessionName, es.Timings, u.name as CollectorName 
                           FROM enrollment e 
                           JOIN students s ON e.StudentId=s.Id 
                           LEFT JOIN classmanifest c ON e.Class=c.Class 
                           LEFT JOIN enrollmentsession es ON e.EnrollmentSessionId=es.Id 
                           LEFT JOIN users u ON e.collected_by = u.id
                           WHERE e.EnrollmentSessionId = ? AND e.IsActive = 1
                           ORDER BY s.Name");
    $stmt->execute([$sessId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        echo "<p>No active students found in this session.</p>";
        exit;
    }

    if (!function_exists('fmtPhBulk')) {
        function fmtPhBulk($num) {
            $n = preg_replace('/[^0-9]/', '', $num);
            if (strlen($n) == 11 && $n[0] == '0') return '+92-' . substr($n, 1, 3) . '-' . substr($n, 4, 3) . '-' . substr($n, 7);
            if (strlen($n) == 12 && substr($n, 0, 2) == '92') return '+92-' . substr($n, 2, 3) . '-' . substr($n, 5, 3) . '-' . substr($n, 8);
            return $num;
        }
    }

    // Dynamic Global Settings for Header
$instAddr = getSet('inst_address');
    $instPhone = getSet('inst_phone');
    $startDateVal = getSet('start_date');
    $instLogo = getSet('inst_logo') ?: 'logo.png'; // Dynamic Logo

    echo "<!doctype html>
<html>
<head>
<meta charset=\"utf-8\">
<title>Bulk IDs - $sName</title>
<link href=\"https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap\" rel=\"stylesheet\">
<link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\">
<style>
    @page { size: A6; margin: 0; }
    html, body { 
        width: 105mm; height: 148mm; margin: 0; padding: 0; 
        font-family: 'Roboto', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact;
        color: #000 !important; background: #fff;
    }
    .id-card-wrapper { width: 105mm; height: 148mm; page-break-after: always; position: relative; overflow: hidden; }
    .container { width: 100%; height: 100%; display: flex; flex-direction: column; box-sizing: border-box; }
    .section { padding: 8px 10px; position: relative; display: flex; flex-direction: column; overflow: hidden; height: 50%; box-sizing: border-box; }
    .border-frame { position: absolute; top: 4px; left: 4px; right: 4px; bottom: 4px; border: 2px solid #000; z-index: 2; border-radius: 4px; pointer-events: none; }
    .session-badge { position: absolute; top: 28px; right: 10px; transform: translateY(-50%); border: 2px solid #000; padding: 3px 10px; font-size: 8px; font-weight: 800; border-radius: 4px; z-index: 10; text-transform: uppercase; background: #fff; }
    .header-row { position: relative; z-index: 5; display: flex; justify-content: center; align-items: center; margin-bottom: 5px; border-bottom: 2px solid #000; padding-bottom: 6px; min-height: 40px; }
    .logo-main { width: 80px; height: auto; position: absolute; left: 2px; top: 50%; transform: translateY(-50%); mix-blend-mode: multiply; }
    .address-block { text-align: center; font-size: 8px; font-weight: 500; line-height: 1.4; padding: 0 85px; }
    .addr-line { display: flex; align-items: center; white-space: nowrap; justify-content: center; }
    .addr-icon { width: 12px; margin-right: 4px; font-size: 9px; }
    .data-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 10px; font-size: 9px; }
    .data-row { display: flex; flex-direction: column; }
    .full-width { grid-column: span 2; }
    .grid-gap { grid-column: span 2; height: 0.1px; border-bottom: 1px dotted #000; opacity: 0.2; margin: 1px 0; }
    .label { font-size: 6px; text-transform: uppercase; font-weight: 800; margin-bottom: 1px; }
    .value { font-weight: 500; font-size: 9px; border-bottom: 1px dotted #000; min-height: 12px; line-height: 1.2; }
    .name-value { font-weight: 900; font-size: 11px; }
    .cut-line { height: 0; border-top: 1px dashed #000; position: relative; text-align: center; margin: 5px 0; z-index: 20; }
    .cut-icon { position: absolute; top: -8px; left: 50%; transform: translateX(-50%); background: #fff; padding: 0 5px; font-size: 10px; }
</style>
</head>
<body>";

    foreach ($rows as $row) {
        $studentName = formatName($row['Name']);
        $paternity = formatName($row['Paternity']);
        $className = htmlspecialchars($row['ClassName'] ?? '');
        $sessionName = htmlspecialchars($row['SessionName']);
        $studentId = $row['Id'];
        $dob = formatDate($row['DOB']);
        $fee = number_format($row['enrollment_fee']);
        $admDate = $row['enr_date'] ? date('d/m/Y', strtotime($row['enr_date'])) : date('d/m/Y');
        $address = htmlspecialchars($row['Address'] ?? '');
        $collector = formatName($row['CollectorName'] ?? 'System');
        $printer = ucwords(strtolower($_SESSION['name']));
        $formattedPhone = fmtPhBulk($row['MobileNumberFather'] ?: ($row['MobileNumberMother'] ?: ''));
        
        // Dynamic Timings
        $timings = !empty($row['Timings']) ? $row['Timings'] : 'Check Schedule';

        $gridHtml = "
        <div class=\"data-grid\">
            <div class=\"data-row\"><span class=\"label\">Student Name</span><span class=\"value name-value\">$studentName</span></div>
            <div class=\"data-row\"><span class=\"label\">Date of Birth</span><span class=\"value\">$dob</span></div>
            <div class=\"data-row\"><span class=\"label\">Student ID</span><span class=\"value\" style=\"font-weight: 800;\">$studentId</span></div>
            <div class=\"data-row\"><span class=\"label\">Class</span><span class=\"value\">$className</span></div>
            <div class=\"grid-gap\"></div>
            <div class=\"data-row\"><span class=\"label\">Father's Name</span><span class=\"value\">$paternity</span></div>
            <div class=\"data-row\"><span class=\"label\">Phone Number</span><span class=\"value\">$formattedPhone</span></div>
            <div class=\"data-row full-width\"><span class=\"label\">Address</span><span class=\"value\" style=\"font-size:8px;\">$address</span></div>
            <div class=\"grid-gap\"></div>
            <div class=\"data-row\"><span class=\"label\">Start Date</span><span class=\"value\">$startDateVal</span></div>
            <div class=\"data-row\"><span class=\"label\">Class Timing</span><span class=\"value\">$timings</span></div>
            <div class=\"data-row\"><span class=\"label\">Fees Received</span><span class=\"value\">$fee</span></div>
            <div class=\"data-row\"><span class=\"label\">Admission Date</span><span class=\"value\">$admDate</span></div>
            <div class=\"grid-gap\"></div>
            <div class=\"data-row\"><span class=\"label\">Received By</span><span class=\"value\">$collector</span></div>
            <div class=\"data-row\"><span class=\"label\">Printed By</span><span class=\"value\">$printer</span></div>
        </div>";

        echo "<br>
    <div class=\"id-card-wrapper\">
        <div class=\"container\">
            <div class=\"section\">
                <div class=\"border-frame\"></div>
                <div class=\"session-badge\">$sessionName</div>
                <div class=\"header-row\">
                    <img src=\"$instLogo\" alt=\"Logo\" class=\"logo-main\">
                    <div class=\"address-block\">
                        <div class=\"addr-line\"><i class=\"fas fa-map-marker-alt addr-icon\"></i> $instAddr</div>
                        <div class=\"addr-line\"><i class=\"fas fa-phone addr-icon\"></i> $instPhone</div>
                    </div>
                </div>
                <div class=\"content-area\">$gridHtml</div>
            </div>
            <br>
            <div class=\"cut-line\"><i class=\"fas fa-scissors cut-icon\"></i></div>
            <br>
            <div class=\"section\">
                <div class=\"border-frame\" style=\"border-style: double; border-width: 3px;\"></div>
                <div class=\"session-badge\">$sessionName</div>
                <div class=\"header-row\">
                     <img src=\"$instLogo\" alt=\"Logo\" class=\"logo-main\">
                    <div class=\"address-block\">
                        <div class=\"addr-line\"><i class=\"fas fa-map-marker-alt addr-icon\"></i> $instAddr</div>
                        <div class=\"addr-line\"><i class=\"fas fa-phone addr-icon\"></i> $instPhone</div>
                    </div>
                </div>
                <div class=\"content-area\">$gridHtml</div>
            </div>
        </div>
    </div>";
    }

    echo "<script>window.onload = function() { window.print(); }</script></body></html>";
    exit;
}

if (isLoggedIn() && isset($_GET['page']) && $_GET['page'] === 'print_student_result' && can('view_reports')) {

    $studentId = (int) ($_GET['student_id'] ?? 0);

    $active = $activeSessions[0] ?? null;

    if (!$active) {
        echo "<p>No active session.</p>";
        exit;
    }

    $sessId = $active['Id'];

    $stmt = $pdo->prepare("SELECT e.Id as enrollment_id, e.Class, c.ClassName, s.Name as SessionName, st.Name as StudentName, st.Paternity, st.DOB FROM enrollment e JOIN students st ON e.StudentId=st.Id LEFT JOIN classmanifest c ON e.Class=c.Class LEFT JOIN enrollmentsession s ON e.EnrollmentSessionId=s.Id WHERE e.StudentId=? AND e.EnrollmentSessionId=? LIMIT 1");

    $stmt->execute([$studentId, $sessId]);
    $en = $stmt->fetch();

    if (!$en) {
        echo "<p>Enrollment for student not found in active session.</p>";
        exit;
    }

    $exs = $pdo->prepare("SELECT id, name, total_marks FROM exams WHERE session_id = ? AND class_id = ? ORDER BY id");

    $exs->execute([$sessId, $en['Class']]);
    $exams = $exs->fetchAll();

    $studentName = htmlspecialchars($en['StudentName']);

    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Result</title><style>@page{size:A5;margin:10mm;}body{font-family:Arial} table{width:100%;border-collapse:collapse} th,td{border:1px solid #000;padding:5px}</style></head><body><h2>Result: {$studentName}</h2><p>Class: {$en['ClassName']}</p><table><thead><tr><th>Exam</th><th>Marks</th><th>Total</th><th>%</th></tr></thead><tbody>";

    $grandObt = 0;
    $grandTotal = 0;

    foreach ($exams as $exm) {

        $q = $pdo->prepare("SELECT er.obtained_marks FROM exam_results er JOIN enrollment e ON er.enrollment_id = e.Id WHERE er.exam_id = ? AND e.StudentId = ? LIMIT 1");

        $q->execute([$exm['id'], $studentId]);
        $obt = $q->fetchColumn();

        $obtF = ($obt === false || $obt === null) ? '-' : (float) $obt;

        $pct = ($obtF === '-') ? '-' : ($exm['total_marks'] ? number_format(($obtF / $exm['total_marks']) * 100, 1) : '0.0');

        if ($obtF !== '-') {
            $grandObt += $obtF;
            $grandTotal += (float) $exm['total_marks'];
        }

        echo "<tr><td>{$exm['name']}</td><td>$obtF</td><td>{$exm['total_marks']}</td><td>$pct%</td></tr>";

    }

    $overallPct = $grandTotal ? number_format(($grandObt / $grandTotal) * 100, 1) : '0.0';

    echo "</tbody><tfoot><tr><th>Total</th><th>$grandObt</th><th>$grandTotal</th><th>$overallPct%</th></tr></tfoot></table><br><button onclick=\"window.print()\">Print</button></body></html>";
    exit;

}

?>

<?php if ($page === 'print_certificate' && isset($_GET['id'])):
    $c = $pdo->prepare("
        SELECT c.*, s.Name, 
        (SELECT es.Name FROM enrollment e JOIN enrollmentsession es ON e.EnrollmentSessionId = es.Id WHERE e.StudentId = s.Id AND e.IsActive=1 LIMIT 1) as SessionName,
        (SELECT cm.ClassName FROM enrollment e JOIN classmanifest cm ON e.Class = cm.Class WHERE e.StudentId = s.Id AND e.IsActive=1 LIMIT 1) as ClassName
        FROM certificates c 
        JOIN students s ON c.student_id=s.Id 
        WHERE c.id=?
    ");
    $c->execute([$_GET['id']]);
    $cert = $c->fetch();
    
    $certTitle = !empty($cert['title']) ? $cert['title'] : getSet('cert_title');
    
    // Dynamic Colors Setup
    $colorBody = !empty($cert['text_color']) ? $cert['text_color'] : '#333333';
    $colorTitle = !empty($cert['color_title']) ? $cert['color_title'] : '#6b4c3a';
    $colorName = !empty($cert['color_name']) ? $cert['color_name'] : '#000000';
    $colorBadgeText = !empty($cert['color_badge']) ? $cert['color_badge'] : '#FFFFFF';
    $bgBadge = !empty($cert['bg_badge']) ? $cert['bg_badge'] : '#5A3A22';

    // Dynamic Google Fonts Handler
    $rawFont = !empty($cert['font_family']) ? $cert['font_family'] : "'Cinzel', serif";
    $gFontImportUrl = "https://fonts.googleapis.com/css2?family=Pinyon+Script&family=Cinzel:wght@400;700;800&family=Roboto:wght@400;700";
    
    if (strpos($rawFont, ',') === false && $rawFont !== 'Arial') {
        $urlParam = str_replace(' ', '+', $rawFont);
        $gFontImportUrl .= "&family=" . $urlParam . ":wght@400;700;800";
        $appliedFontCss = "font-family: '" . $rawFont . "', sans-serif;";
    } else {
        $appliedFontCss = "font-family: " . $rawFont . ";";
    }
    $gFontImportUrl .= "&display=swap";

// LOGIC: Check if Custom Logo exists, otherwise fallback to universal logo
    $finalLogo = !empty($cert['cert_logo']) ? 'uploads/' . $cert['cert_logo'] : (getSet('inst_logo') ?: 'logo.png');
    // Check if BG Image Exists
// Check if BG Image Exists
    $hasBg = !empty($cert['bg_image']);

    // --- NEW: HANDLE OVERRIDES & HIDING ---
    $finalSeason = !empty($cert['custom_season']) ? trim($cert['custom_season']) : getSet('inst_season');
    if (strtoupper($finalSeason) === 'HIDE' || strtoupper($finalSeason) === 'NONE') $finalSeason = '';

    $finalSession = !empty($cert['custom_session']) ? trim($cert['custom_session']) : $cert['SessionName'];
    if (strtoupper($finalSession) === 'HIDE' || strtoupper($finalSession) === 'NONE') $finalSession = '';

    $genderPrefix = '';
    if ($cert['EnrollmentType'] === 'BoysClass') $genderPrefix = 'BOYS: ';
    if ($cert['EnrollmentType'] === 'GirlsClass') $genderPrefix = 'GIRLS: ';
    $finalClass = !empty($cert['custom_class']) ? trim($cert['custom_class']) : ($genderPrefix . $cert['ClassName']);
    if (strtoupper($finalClass) === 'HIDE' || strtoupper($finalClass) === 'NONE') $finalClass = '';
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>Certificate - <?= htmlspecialchars($cert['Name']) ?></title>
    <style>
        @import url('<?= $gFontImportUrl ?>');

        /* Screen Styles (Centered) */
        body {
            margin: 0; padding: 0;
            display: flex; justify-content: center; align-items: center;
            height: 100vh; background: #525659; /* Standard PDF viewer background */
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important;
        }

        .cert-container {
            width: 297mm; height: 210mm; /* A4 Landscape */
            position: relative; text-align: center;
            box-sizing: border-box;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            /* If no background image, show white box with border */
            <?php if (!$hasBg): ?>
                background: #fff; border: 10px double <?= $colorTitle ?>;
            <?php else: ?>
                background: transparent; border: none;
            <?php endif; ?>
        }

        /* FORCE BACKGROUND IMAGE TO PRINT */
        .cert-bg-img {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: -1; /* Puts it behind all text */
            object-fit: cover;
        }

        /* DYNAMIC THEME (Controlled by form) */
        .dynamic-theme {
            <?= $appliedFontCss ?>
            color: <?= $colorBody ?>;
        }

        /* --- PRINT FIXES START --- */
        @media print {
            @page { size: A4 landscape; margin: 0; }
            body {
                display: block !important; height: auto !important;
                background: transparent !important; margin: 0 !important; padding: 0 !important;
            }
            .cert-container {
                width: 297mm !important; height: 210mm !important;
                box-shadow: none !important; margin: 0 !important; page-break-after: always;
                position: absolute; top: 0; left: 0;
            }
            .no-print { display: none !important; }
        }
        /* --- PRINT FIXES END --- */

        /* LAYOUT STYLES */
        .logo { width: 110px; height: auto; margin-bottom: 5px; margin-top: -30px; }
        
        .header {
            font-family: 'Cinzel', serif; font-size: 50px;
            font-weight: 700; text-transform: uppercase;
            margin-bottom: 5px; letter-spacing: 2px;
            color: <?= $colorTitle ?>;
        }

        /* Dynamic Poster Style Heading */
        .poster-heading {
            font-family: 'Cinzel', serif; /* Restored Professional classic font */
            background: linear-gradient(to right, #4a2f1d, #8e5b32, #4a2f1d); /* Restored Original Gradient */
            color: #FFFFFF;
            font-size: 34px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 12px 50px;
            border: 3px solid #D4AF37; /* Restored Gold border */
            border-radius: 50px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.4);
            display: inline-block;
            margin-bottom: 15px;
            letter-spacing: 3px;
        }

        /* Dynamic Class Sub-Heading */
        .class-name { 
            font-family: 'Arial', sans-serif; /* Restored Original Font */
            font-size: 22px; 
            font-weight: bold; 
            margin-bottom: 25px; 
            text-transform: uppercase; 
            letter-spacing: 2px;
            color: <?= $colorBody ?>;
            border-bottom: 2px dashed #8e5b32;
            padding-bottom: 5px;
            display: inline-block;
        }

        .sub-header { font-size: 22px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 2px; color: <?= $colorTitle ?>;}

        .name {
            font-family: 'Pinyon Script', cursive; /* Restored Pinyon Script */
            font-size: 85px;
            margin: 5px 0 15px 0; border-bottom: 1px solid rgba(0,0,0,0.2);
            display: inline-block; padding: 0 50px; line-height: 1;
            color: <?= $colorName ?>;
        }

        .body-text {
            font-size: 18px; line-height: 1.6;
            max-width: 75%; margin: 0 auto 30px auto;
        }

        .footer {
            width: 80%; display: flex; justify-content: space-between;
            position: absolute; bottom: 40px;
        }

        .sig-line {
            border-top: 1px solid <?= $colorBody ?>; width: 220px;
            padding-top: 5px; font-size: 14px; font-weight: bold;
            text-transform: uppercase;
        }
        
        /* Idara details at absolute bottom */
        .inst-details {
            position: absolute; bottom: 15px; width: 100%;
            font-family: 'Roboto', sans-serif; font-size: 11px;
            color: #333; opacity: 0.8;
        }
    </style>
</head>

<body onload="window.print()">
    <div class="cert-container dynamic-theme">
        
        <?php if ($hasBg): ?>
            <img src="uploads/<?= htmlspecialchars($cert['bg_image']) ?>" class="cert-bg-img" alt="Background">
        <?php endif; ?>

        <img src="<?= htmlspecialchars($finalLogo) ?>" class="logo">
        
        <div class="header"><?= getSet('inst_name') ?></div>
        
<?php if (!empty($finalSeason)): ?>
            <div class="poster-heading"><?= htmlspecialchars(strtoupper($finalSeason)) ?></div>
        <?php endif; ?>

        <?php if (!empty($finalSession)): ?>
            <div style="font-size: 20px; font-weight: bold; margin-bottom: 5px; text-transform: uppercase; color: <?= $bgBadge ?>;">
                <?= htmlspecialchars(strtoupper($finalSession)) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($finalClass)): ?>
            <div class="class-name dynamic-theme"><?= htmlspecialchars(strtoupper($finalClass)) ?></div>
        <?php endif; ?>

        <div class="sub-header dynamic-theme"><?= $certTitle ?></div>
        
        <div style="font-size: 16px;">PROUDLY PRESENTED TO</div>
        
        <div class="name"><?= $cert['Name'] ?></div>
        
        <div class="body-text dynamic-theme"><?= nl2br($cert['description']) ?></div>
        
        <div class="footer dynamic-theme">
            <div class="sig-line">DATE: <?= date('d M Y', strtotime($cert['issued_date'])) ?></div>
            <div class="sig-line"><?= getSet('cert_sign') ?></div>
        </div>

        <div class="inst-details">
            <?= getSet('inst_address') ?> | Phone: <?= getSet('inst_phone') ?>
        </div>

    </div>
</body>
</html>
<?php exit; endif; ?>

    <!DOCTYPE html>

    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= getSet('app_name') ?> | <?= getSet('inst_name') ?></title>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

        <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">

        <style>
            /* --- GLOBAL PRINT FIX --- */
            @media print {

                .sidebar,
                .navbar,
                .btn,
                .no-print,
                form,
                .modal,
                .offcanvas {
                    display: none !important;
                }

                .col-md-10,
                .col-md-8,
                .col-md-4,
                .card,
                .container-fluid,
                .row {
                    width: 100% !important;
                    flex: 0 0 100% !important;
                    max-width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    box-shadow: none !important;
                    border: none !important;
                }

                .card-header {
                    display: none !important;
                }

                body {
                    background: #fff !important;
                    overflow: visible !important;
                }
            }

            /* Add this inside your <style> tag */
            .ltr-date {
                direction: ltr;
                text-align: center;
                display: inline-block;
                line-height: 1.2em;
                background: rgba(0, 0, 0, 0.05);
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.85rem;
                color: #333;
                border: 1px solid #ddd;
                min-width: 100px;
            }

            .ltr-date small {
                display: block;
                font-size: 0.7em;
                color: #666;
                margin-top: 2px;
            }

            body {
                background-color: #f8f9fa;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                transition: background 0.3s, color 0.3s;
            }

            .sidebar {
                min-height: 100vh;
                background: #343a40;
                color: #fff;
                transition: background 0.3s;
            }

            .sidebar a {
                color: #ced4da;
                padding: 10px 20px;
                display: block;
                text-decoration: none;
                border-left: 4px solid transparent;
            }

            .sidebar a:hover,
            .sidebar a.active {
                background: #495057;
                color: #fff;
                border-left-color: #0d6efd;
            }

            .nav-label {
                font-size: 11px;
                text-transform: uppercase;
                color: #adb5bd;
                padding: 15px 20px 5px;
                font-weight: bold;
            }

            .card {
                border: none;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
                margin-bottom: 20px;
                transition: background 0.3s, color 0.3s;
            }

            .float-whatsapp {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 1000;
            }

            tfoot input {
                width: 100%;
                padding: 3px;
                box-sizing: border-box;
                font-size: 0.85rem;
            }



            body.dark-mode {
                background-color: #121212;
                color: #e0e0e0;
            }

            body.dark-mode .sidebar {
                background-color: #000;
            }

            body.dark-mode .card {
                background-color: #1e1e1e;
                color: #fff;
                border: 1px solid #333;
            }

            body.dark-mode .table {
                color: #fff;
                border-color: #333;
            }

            body.dark-mode .form-control,
            body.dark-mode .form-select {
                background-color: #2c2c2c;
                border-color: #444;
                color: #fff;
            }

            body.dark-mode .modal-content {
                background-color: #1e1e1e;
                color: #fff;
                border: 1px solid #444;
            }



            /* Mobile Menu */

            .offcanvas.bg-dark {
                background-color: #1a1a1a !important;
            }

            .offcanvas-body {
                padding: 0;
            }

            .offcanvas-body .menu-section {
                padding: 15px 20px 5px;
                color: #adb5bd;
                font-size: 0.8rem;
                text-transform: uppercase;
                font-weight: bold;
                border-bottom: 1px solid #333;
                background: #222;
            }

            .offcanvas-body .menu-link {
                display: flex;
                align-items: center;
                padding: 12px 20px;
                color: #e0e0e0;
                text-decoration: none;
                border-bottom: 1px solid #2a2a2a;
                transition: background 0.2s;
            }

            .offcanvas-body .menu-link:hover {
                background-color: #333;
                color: #fff;
            }

            .offcanvas-body .menu-link i {
                width: 25px;
                text-align: center;
                margin-right: 10px;
                color: #0d6efd;
            }

            .offcanvas-body .menu-link.logout {
                color: #ff6b6b;
            }

            .offcanvas-body .menu-link.logout i {
                color: #ff6b6b;
            }
        </style>

    </head>

    <body>



 <?php if ($page === 'login'): ?>

    <div class="d-flex justify-content-center align-items-center vh-100 bg-light">

        <div class="card shadow p-4" style="width: 380px;">
            
            <div class="text-center mb-4">
                <h4 class="fw-bold m-0"><?= getSet('app_name') ?></h4>
                <p class="text-muted small"><?= getSet('inst_name') ?></p>
            </div>

            <?php if ($msg): ?>
                <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
            <?php endif; ?>

            <form method="POST">

                <input type="hidden" name="action" value="login">

                <div class="mb-3">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>

                <button class="btn btn-primary w-100">Sign In</button>

            </form>

        </div>

    </div>

<?php else: ?>



<nav class="navbar navbar-dark bg-dark d-md-none mb-3 p-2 shadow">
    <div class="container-fluid d-flex justify-content-center position-relative">
        <span class="navbar-brand m-0"><i class="fas fa-school me-2"></i> <?= getSet('app_name') ?></span>
        
        <button class="navbar-toggler border-0 position-absolute end-0 me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
    </div>
</nav>



            <div class="offcanvas offcanvas-start bg-dark text-white w-75" tabindex="-1" id="mobileMenu">

                <div class="offcanvas-header border-bottom border-secondary">

                    <h5 class="offcanvas-title"><i class="fas fa-bars me-2"></i> Menu</h5>

                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>

                </div>
<div class="offcanvas-body">

                    <div class="p-3">
                        <button class="btn btn-outline-light btn-sm w-100 mb-3" onclick="toggleDarkMode()">
                            <i class="fas fa-moon me-2"></i> Toggle Theme
                        </button>
                        <a href="?page=profile" class="menu-link"><i class="fas fa-user-circle"></i> My Profile</a>
                    </div>

                    <div class="menu-section">Main</div>
                    <a href="?page=dashboard" class="menu-link"><i class="fas fa-home"></i> Dashboard</a>

                    <?php if (can('view_fee_report')): ?>
                        <a href="?page=fee_report" class="menu-link"><i class="fas fa-file-invoice-dollar"></i> Fee Report</a>
                    <?php endif; ?>

                    <a href="?page=age_calculator" class="menu-link"><i class="fas fa-calculator"></i> Age Calculator</a>
                    <a href="?page=duplicates" class="menu-link"><i class="fas fa-clone"></i> Duplicates</a>

                    <?php if (can('student_view')): ?>
                        <div class="menu-section">Academics</div>
                        <a href="?page=students" class="menu-link"><i class="fas fa-user-graduate"></i> Students</a>
                        <a href="?page=ghost_records" class="menu-link"><i class="fas fa-ghost"></i> Ghost Records</a>
                        <a href="?page=session_breakdown" class="menu-link"><i class="fas fa-layer-group me-2"></i> Session Details</a>
                        <?php if (can('manage_teachers')): ?><a href="?page=teachers" class="menu-link"><i class="fas fa-chalkboard-teacher"></i> Teachers</a><?php endif; ?>
                        <a href="?page=siblings" class="menu-link"><i class="fas fa-users"></i> Siblings</a>
                       
                    <?php endif; ?>

                    <div class="menu-section">Operations</div>
                    <?php if (can('attendance_take')): ?><a href="?page=attendance" class="menu-link"><i class="fas fa-calendar-check"></i> Attendance</a><?php endif; ?>
                    <?php if (can('event_manage')): ?><a href="?page=events" class="menu-link"><i class="fas fa-calendar-day"></i> Events</a><?php endif; ?>
                    <?php if (can('manage_prizes')): ?><a href="?page=prizes" class="menu-link"><i class="fas fa-trophy"></i> Prizes</a><?php endif; ?>

                    <div class="menu-section">Finance & Accounts</div>
                    <?php if (can('ledger_view')): ?>
                        <a href="?page=general_ledger" class="menu-link"><i class="fas fa-book"></i> General Ledger</a>
                    <?php endif; ?>
                    <?php if (can('hasanaat_view')): ?>
                        <a href="?page=hasanaat_cards" class="menu-link"><i class="fas fa-id-card"></i> Hasanaat Cards</a>
                    <?php endif; ?>
                    <?php if (can('tabarruk_view')): ?>
                        <a href="?page=tabarruk" class="menu-link"><i class="fas fa-utensils"></i> Tabarruk (Niaz)</a>
                    <?php endif; ?>
                    <?php if (can('budget_view')): ?>
                        <a href="?page=budget_plan" class="menu-link"><i class="fas fa-bullseye"></i> Budget Planning</a>
                    <?php endif; ?>

                    <div class="menu-section">Reports</div>
                    <a href="?page=exams" class="menu-link"><i class="fas fa-poll-h"></i> Exams</a>
                    <a href="?page=certificates" class="menu-link"><i class="fas fa-certificate me-2"></i> Certificates</a>

                    <?php if (can('view_attendance_report')): ?>
                        <a href="?page=attendance_report" class="menu-link"><i class="fas fa-calendar-alt me-2"></i> Attendance Report</a>
                    <?php endif; ?>

                    <?php if (can('view_reports')): ?><a href="?page=reports" class="menu-link"><i class="fas fa-file-alt"></i> Merit Lists</a><?php endif; ?>

                    <?php if (can('manage_roles') || can('manage_sessions')): ?>
                        <div class="menu-section">System</div>
                        <?php if (can('manage_roles')): ?><a href="?page=users" class="menu-link"><i class="fas fa-shield-alt"></i> Users</a><?php endif; ?>
                        <?php if (can('manage_sessions')): ?><a href="?page=sessions" class="menu-link"><i class="fas fa-clock"></i> Sessions</a><?php endif; ?>
                        <?php if (can('manage_classes')): ?><a href="?page=classes" class="menu-link"><i class="fas fa-chalkboard"></i> Classes</a><?php endif; ?>
                        <?php if (can('view_logs')): ?><a href="?page=logs" class="menu-link"><i class="fas fa-history"></i> Logs</a><?php endif; ?>

                        <?php if (can('backup_db') || $_SESSION['role'] === 'admin'): ?>
                            <a href="?page=backup" class="menu-link"><i class="fas fa-database"></i> Backup DB</a>
                        <?php endif; ?>

                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <a href="?page=settings" class="menu-link"><i class="fas fa-cogs"></i> Settings</a>
                        <?php endif; ?>
                    <?php endif; ?>

                


                    <div class="p-3 mt-2 border-top border-secondary">

                        <a href="?page=logout" class="menu-link logout"><i class="fas fa-sign-out-alt"></i> Logout</a>

                    </div>

                </div>

            </div>



            <div class="container-fluid">

                <div class="row">

                    <!-- SIDEBAR -->

<div class="col-md-2 sidebar p-0 d-none d-md-block">
    <div class="p-3 text-center bg-dark border-bottom border-secondary">
        <h5 class="m-0 fw-bold"><?= getSet('app_name') ?></h5>
        <div class="text-info small mt-1"><?= getSet('inst_name') ?></div>
        <hr class="my-2 opacity-25">
        <small class="text-muted">User: <?php echo $_SESSION['name']; ?></small>
    </div>

                        <div class="py-2">

                            <div class="px-3 mb-2"><button class="btn btn-outline-secondary btn-sm w-100"
                                    onclick="toggleDarkMode()"><i class="fas fa-adjust"></i> Toggle Theme</button></div>

                            <a href="?page=dashboard" class="<?= $page == 'dashboard' ? 'active' : '' ?>"><i
                                    class="fas fa-home me-2"></i> Dashboard</a>
                            <?php if (can('view_fee_report')): ?>
                                <a href="?page=fee_report" class="<?= $page == 'fee_report' ? 'active' : '' ?>"><i
                                        class="fas fa-file-invoice-dollar me-2"></i> Fee Report</a>
                            <?php endif; ?>

                            <a href="?page=profile" class="<?= $page == 'profile' ? 'active' : '' ?>"><i
                                    class="fas fa-user-edit me-2"></i> My Profile</a>

                            <a href="?page=age_calculator" class="<?= $page == 'age_calculator' ? 'active' : '' ?>"><i
                                    class="fas fa-calculator me-2"></i> Age Calculator</a>

                            <a href="?page=duplicates" class="<?= $page == 'duplicates' ? 'active' : '' ?>"><i
                                    class="fas fa-clone me-2"></i> Duplicates</a>


                            <?php if (can('manage_roles') || can('manage_sessions') || can('manage_classes') || can('view_logs')): ?>

                                <div class="nav-label">System</div>

                                <?php if (can('manage_roles')): ?><a href="?page=users"
                                        class="<?= $page == 'users' ? 'active' : '' ?>"><i class="fas fa-shield-alt me-2"></i>
                                        Users</a><?php endif; ?>

                                <?php if (can('manage_sessions')): ?><a href="?page=sessions"
                                        class="<?= $page == 'sessions' ? 'active' : '' ?>"><i class="fas fa-clock me-2"></i>
                                        Sessions</a><?php endif; ?>

                                <?php if (can('manage_classes')): ?><a href="?page=classes"
                                        class="<?= $page == 'classes' ? 'active' : '' ?>"><i class="fas fa-chalkboard me-2"></i>
                                        Classes</a><?php endif; ?>

                                <?php if (can('view_logs')): ?><a href="?page=logs"
                                        class="<?= $page == 'logs' ? 'active' : '' ?>"><i class="fas fa-history me-2"></i>
                                        Logs</a><?php endif; ?>

                                <?php if (can('backup_db') || $_SESSION['role'] === 'admin'): ?>
                                    <a href="?page=backup" class="<?= $page == 'backup' ? 'active' : '' ?>">
                                        <i class="fas fa-database me-2"></i> Backup DB
                                    </a>
                                <?php endif; ?>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
    <a href="?page=settings" class="<?= $page == 'settings' ? 'active' : '' ?>">
        <i class="fas fa-cogs me-2"></i> Settings
    </a>
<?php endif; ?>

                            <?php endif; ?>



                            <?php if (can('student_view')): ?>

                                <div class="nav-label">Academics</div>

                                <a href="?page=students" class="<?= $page == 'students' ? 'active' : '' ?>"><i
                                        class="fas fa-user-graduate me-2"></i> Students</a>

                                <a href="?page=ghost_records" class="<?= $page == 'ghost_records' ? 'active' : '' ?>"><i
                                        class="fas fa-ghost me-2"></i> Ghost Records</a>

                                <a href="?page=session_breakdown" class="<?= $page == 'session_breakdown' ? 'active' : '' ?>">
                                    <i class="fas fa-layer-group me-2"></i> Session Details
                                </a>


                                <?php if (can('manage_teachers')): ?><a href="?page=teachers"
                                        class="<?= $page == 'teachers' ? 'active' : '' ?>"><i
                                            class="fas fa-chalkboard-teacher me-2"></i>
                                        Teachers</a><?php endif; ?>

                                <a href="?page=siblings" class="<?= $page == 'siblings' ? 'active' : '' ?>"><i
                                        class="fas fa-users me-2"></i> Siblings</a>



                            <?php endif; ?>


                            <div class="nav-label">Operations</div>

                            <?php if (can('attendance_take')): ?><a href="?page=attendance"
                                    class="<?= $page == 'attendance' ? 'active' : '' ?>">
                                    <i class="fas fa-calendar-check me-2"></i> Attendance</a><?php endif; ?>

                            <?php if (can('event_manage')): ?><a href="?page=events"
                                    class="<?= $page == 'events' ? 'active' : '' ?>">
                                    <i class="fas fa-calendar-day me-2"></i> Events</a><?php endif; ?>

                            <?php if (can('manage_prizes')): ?><a href="?page=prizes"
                                    class="<?= $page == 'prizes' ? 'active' : '' ?>">
                                    <i class="fas fa-trophy me-2"></i> Prizes</a><?php endif; ?>

                            <div class="nav-label">Finance Module</div>

                            <?php if (can('ledger_view')): ?>
                                <a href="?page=general_ledger" class="<?= $page == 'general_ledger' ? 'active' : '' ?>">
                                    <i class="fas fa-book me-2"></i> General Ledger
                                </a>
                            <?php endif; ?>

                            <?php if (can('hasanaat_view')): ?>
                                <a href="?page=hasanaat_cards" class="<?= $page == 'hasanaat_cards' ? 'active' : '' ?>">
                                    <i class="fas fa-id-card me-2"></i> Hasanaat Cards
                                </a>
                            <?php endif; ?>

                            <?php if (can('tabarruk_view')): ?>
                                <a href="?page=tabarruk" class="<?= $page == 'tabarruk' ? 'active' : '' ?>">
                                    <i class="fas fa-utensils me-2"></i> Tabarruk (Niaz)
                                </a>
                            <?php endif; ?>

                            <?php if (can('budget_view')): ?>
                                <a href="?page=budget_plan" class="<?= $page == 'budget_plan' ? 'active' : '' ?>">
                                    <i class="fas fa-bullseye me-2"></i> Budget Planning
                                </a>
                            <?php endif; ?>



                            <div class="nav-label">Reports</div>

                            <a href="?page=exams"><i class="fas fa-poll-h me-2"></i> Exams</a>

                            <a href="?page=certificates" class="<?= $page == 'certificates' ? 'active' : '' ?>">
                                <i class="fas fa-certificate me-2"></i> Certificates
                            </a>

                            <?php if (can('view_attendance_report')): ?>
                                <a href="?page=attendance_report" class="<?= $page == 'attendance_report' ? 'active' : '' ?>">
                                    <i class="fas fa-calendar-alt me-2"></i> Attendance Report
                                </a>
                            <?php endif; ?>

                            <?php if (can('view_reports')): ?><a href="?page=reports"><i class="fas fa-file-alt me-2"></i>
                                    Merit
                                    Lists</a><?php endif; ?>



                            <div class="nav-label">Account</div>

                            <a href="?page=logout" class="text-danger"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>

                        </div>

                    </div>



                    <div class="col-md-10 p-4">

                        <div class="d-none d-md-block mb-3 text-end"><span class="text-muted small me-2">User:
                                <?php echo $_SESSION['name']; ?></span><a href="?page=logout"
                                class="btn btn-sm btn-outline-danger">Logout</a></div>



                        <?php if ($msg): ?>

                            <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show">

                                <?php echo $msg; ?>         <?php echo $waButton; ?>         <?php echo $printButton; ?>

                                <button class="btn-close" data-bs-dismiss="alert"></button>

                            </div>

                        <?php endif; ?>

                        <?php if ($page === 'session_breakdown' && can('student_view')): ?>
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5><i class="fas fa-layer-group me-2"></i>Session Breakdown</h5>
                                </div>
                                <div class="card-body">

                                    <ul class="nav nav-tabs" id="sessionTabs" role="tablist">
                                        <?php foreach ($activeSessions as $index => $sess): ?>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link <?= $index === 0 ? 'active' : '' ?>"
                                                    id="tab-<?= $sess['Id'] ?>" data-bs-toggle="tab"
                                                    data-bs-target="#pane-<?= $sess['Id'] ?>" type="button" role="tab">
                                                    <?= htmlspecialchars($sess['Name']) ?>
                                                </button>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>

                                    <div class="tab-content p-3 border border-top-0 rounded-bottom">
                                        <?php foreach ($activeSessions as $index => $sess): ?>
                                            <div class="tab-pane fade <?= $index === 0 ? 'show active' : '' ?>"
                                                id="pane-<?= $sess['Id'] ?>" role="tabpanel">

                                                <div class="alert alert-light border">
                                                    <strong><?= htmlspecialchars($sess['Name']) ?></strong> List
                                                </div>

                                                <div class="table-responsive">
                                                    <table id="table-sess-<?= $sess['Id'] ?>"
                                                        class="table table-bordered table-striped session-datatable"
                                                        style="width:100%" data-session-id="<?= $sess['Id'] ?>">
                                                        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Father's Name</th>
                <th>Status</th>
                <th>Mobile</th>
                <th>WhatsApp</th>
                <th>Age</th>
                <th>Session</th>
                <th>Class</th>
                <th>Enrollment Fee</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            </tbody>
        <tfoot>
            <tr>
                <th><input type="text" class="form-control form-control-sm" placeholder="ID" /></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Name" /></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Father" /></th>
                <th></th> <th><input type="text" class="form-control form-control-sm" placeholder="Mobile" /></th>
                <th></th> <th></th> <th><input type="text" class="form-control form-control-sm" placeholder="Session" /></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Class" /></th>
                <th></th> <th></th> </tr>
        </tfoot>
                                                        <tbody></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($page === 'fee_report' && can('view_fee_report')): ?>
                            <?php
                            $startDate = $_GET['start_date'] ?? date('Y-m-01');
                            $endDate = $_GET['end_date'] ?? date('Y-m-d');
                            $collectorId = $_GET['collector_id'] ?? ''; // ADDED: Get selected collector
                            $showAll = isset($_GET['show_all']) ? 1 : 0;

                            $whereClause = "WHERE e.created_at BETWEEN '$startDate 00:00:00' AND '$endDate 23:59:59'";
                            if (!$showAll)
                                $whereClause .= " AND e.enrollment_fee > 0";

                            // ADDED: Filter by Collector if selected
                            if (!empty($collectorId)) {
                                $whereClause .= " AND e.collected_by = '$collectorId'";
                            }

                            // Stats (Now includes the collector filter)
                            $stats = $pdo->query("SELECT SUM(e.enrollment_fee) as TotalAmt, COUNT(e.Id) as TotalCount 
                                  FROM enrollment e 
                                  JOIN students s ON e.StudentId = s.Id 
                                  $whereClause")->fetch();

                            // Fetch Users for Dropdown
                            $usersList = $pdo->query("SELECT id, name FROM users")->fetchAll();
                            ?>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="card bg-primary text-white h-100">
                                        <div class="card-body">
                                            <h6 class="text-white-50 text-uppercase">Total Collected</h6>
                                            <h2 class="mb-0 fw-bold"><?php echo number_format($stats['TotalAmt'] ?: 0); ?></h2>
                                            <small><?php echo date('d M', strtotime($startDate)) . ' - ' . date('d M', strtotime($endDate)); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-success text-white h-100">
                                        <div class="card-body">
                                            <h6 class="text-white-50 text-uppercase">Total Receipts</h6>
                                            <h2 class="mb-0 fw-bold"><?php echo number_format($stats['TotalCount'] ?: 0); ?>
                                            </h2>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow">
                                <div
                                    class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                                    <h5 class="mb-0 text-primary"><i class="fas fa-file-invoice-dollar me-2"></i>Fee Report</h5>

                                    <?php if (can('prune_fees')): ?>
                                        <button class="btn btn-danger btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#pruneFeeModal">
                                            <i class="fas fa-trash-alt me-1"></i> Prune / Bulk Clear
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body">
                                    <form method="GET"
                                        class="row g-2 mb-3 justify-content-end align-items-center bg-light p-2 rounded">
                                        <input type="hidden" name="page" value="fee_report">

                                        <div class="col-auto">
                                            <select name="collector_id" class="form-select form-select-sm">
                                                <option value="">All Collectors</option>
                                                <?php foreach ($usersList as $u): ?>
                                                    <option value="<?php echo $u['id']; ?>" <?php echo ($collectorId == $u['id']) ? 'selected' : ''; ?>>
                                                        <?php echo $u['name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-auto"><input type="date" name="start_date"
                                                class="form-control form-control-sm" value="<?php echo $startDate; ?>"></div>
                                        <div class="col-auto"><input type="date" name="end_date"
                                                class="form-control form-control-sm" value="<?php echo $endDate; ?>"></div>
                                        <div class="col-auto">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="show_all" value="1"
                                                    id="showAll" <?php echo $showAll ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="showAll">Show 0 Fees</label>
                                            </div>
                                        </div>
                                        <div class="col-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
                                    </form>

                                    <div class="table-responsive">
                                        <table id="feeReportTable" class="table table-hover align-middle datatable-export">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Date</th>
                                                    <th>Student</th>
                                                    <th>Class</th>
                                                    <th>Collected By</th>
                                                    <th>Amount</th>
                                                    <th class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tfoot>
                                                <tr>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="ID"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Date"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Student"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Class"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Collector"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Amount"></th>
                                                    <th></th>
                                                </tr>
                                            </tfoot>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT e.Id, e.enrollment_fee, e.created_at, e.collected_by, s.Name, c.ClassName, u.name as Collector 
                                    FROM enrollment e 
                                    JOIN students s ON e.StudentId = s.Id 
                                    LEFT JOIN classmanifest c ON e.Class = c.Class
                                    LEFT JOIN users u ON e.collected_by = u.id
                                    $whereClause
                                    GROUP BY e.Id  
                                    ORDER BY e.Id DESC";
                                                $rows = $pdo->query($sql)->fetchAll();
                                                foreach ($rows as $r):
                                                    $dateVal = date('Y-m-d\TH:i', strtotime($r['created_at'])); // ISO format for input
                                                    ?>
                                                    <tr>
                                                        <td>#<?php echo $r['Id']; ?></td>
                                                        <td><?php echo convertToUserTime($r['created_at']); ?></td>
                                                        <td class="fw-bold"><?php echo $r['Name']; ?></td>
                                                        <td><span
                                                                class="badge bg-light text-dark border"><?php echo $r['ClassName']; ?></span>
                                                        </td>
                                                        <td><?php echo $r['Collector'] ?: 'System'; ?></td>
                                                        <td class="fw-bold text-success">
                                                            <?php echo number_format($r['enrollment_fee']); ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="?page=enrollment_slip&enrollment_id=<?php echo $r['Id']; ?>"
                                                                target="_blank" class="btn btn-sm btn-outline-secondary py-0"
                                                                title="Print"><i class="fas fa-print"></i></a>

                                                            <?php if (can('edit_fee')): ?>
                                                                <button class="btn btn-sm btn-info py-0"
                                                                    onclick='editFee(<?php echo json_encode($r); ?>, "<?php echo $dateVal; ?>")'
                                                                    title="Edit"><i class="fas fa-pencil"></i></button>
                                                            <?php endif; ?>

                                                            <?php if (can('delete_fee')): ?>
                                                                <form method="POST" class="d-inline"
                                                                    onsubmit="return confirm('Remove this fee entry? (Sets amount to 0)');">
                                                                    <input type="hidden" name="action" value="delete_fee_entry">
                                                                    <input type="hidden" name="id" value="<?php echo $r['Id']; ?>">
                                                                    <button class="btn btn-sm btn-danger py-0" title="Remove"><i
                                                                            class="fas fa-times"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade" id="editFeeModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Fee Record</h5><button type="button" class="btn-close"
                                                data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="edit_fee_record">
                                            <input type="hidden" name="id" id="ef_id">

                                            <div class="mb-3">
                                                <label>Amount</label>
                                                <input type="number" name="amount" id="ef_amount" class="form-control" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Date & Time</label>
                                                <input type="datetime-local" name="date" id="ef_date" class="form-control"
                                                    required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Collected By</label>
                                                <select name="collector_id" id="ef_collector" class="form-select">
                                                    <option value="">System / Admin</option>
                                                    <?php foreach ($usersList as $u): ?>
                                                        <option value="<?php echo $u['id']; ?>"><?php echo $u['name']; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="modal fade" id="pruneFeeModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content border-danger">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Prune / Clear Fees</h5><button type="button"
                                                class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>Warning:</strong> This will set "Enrollment Fee" to 0 for ALL records in
                                                the selected range. This cannot be undone.
                                            </div>
                                            <input type="hidden" name="action" value="prune_fees">
                                            <div class="row">
                                                <div class="col-6"><label>From</label><input type="date" name="p_start"
                                                        class="form-control" required></div>
                                                <div class="col-6"><label>To</label><input type="date" name="p_end"
                                                        class="form-control" required></div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button class="btn btn-danger w-100">Confirm Clear Fees</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <script>
                                function editFee(data, dateVal) {
                                    new bootstrap.Modal(document.getElementById('editFeeModal')).show();
                                    document.getElementById('ef_id').value = data.Id;
                                    document.getElementById('ef_amount').value = data.enrollment_fee;
                                    document.getElementById('ef_date').value = dateVal;
                                    document.getElementById('ef_collector').value = data.collected_by;
                                }
                            </script>
                        <?php endif; ?>

                        <!-- DUPLICATES MODULE -->
                        <?php if ($page === 'duplicates' && can('student_view')): ?>
                            <div class="card">
                                <div class="card-header">Duplicate Finder</div>
                                <div class="card-body">
                                    <div class="alert alert-info">This tool identifies students with the same Name + DOB or same
                                        Phone Number. You can safely merge or delete duplicates.</div>
                                    <form method="post" style="display:inline-block;margin-bottom:10px;">
                                        <input type="hidden" name="action" value="merge_all_duplicates">
                                        <button type="submit" class="btn btn-warning"
                                            onclick="return confirm('Are you sure you want to merge all duplicates? This cannot be undone.')">Merge
                                            All Duplicates</button>
                                    </form>
                                    <table class="table table-bordered datatable-basic">
                                        <thead>
                                            <tr>
                                                <th>Group</th>
                                                <th>Student ID</th>
                                                <th>Name</th>
                                                <th>DOB</th>
                                                <th>Phone</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $sql = "SELECT s1.Id as StudentId, s1.Name, s1.DOB, s1.MobileNumberFather, s1.MobileNumberMother, 
                                    GROUP_CONCAT(s2.Id) as DuplicateIds
                                    FROM students s1
                                    JOIN students s2 ON s1.Id != s2.Id AND (s1.Name = s2.Name AND s1.DOB = s2.DOB OR s1.MobileNumberFather = s2.MobileNumberFather)
                                    GROUP BY s1.Id";
                                            $duplicates = $pdo->query($sql)->fetchAll();
                                            foreach ($duplicates as $dup) {
                                                $dupIds = explode(',', $dup['DuplicateIds']);
                                                foreach ($dupIds as $dupId) {
                                                    echo "<tr>
                                        <td>{$dup['StudentId']}</td>
                                        <td>{$dupId}</td>
                                        <td>{$dup['Name']}</td>
                                        <td>{$dup['DOB']}</td>
                                        <td>{$dup['MobileNumberFather']}</td>
                                        <td>
                                            <form method='POST' class='d-inline'>
                                                <input type='hidden' name='action' value='merge_duplicate'>
                                                <input type='hidden' name='keep_id' value='{$dup['StudentId']}'>
                                                <input type='hidden' name='remove_id' value='{$dupId}'>
                                                <button class='btn btn-sm btn-success'>Merge</button>
                                            </form>
                                            <form method='POST' class='d-inline' onsubmit='return confirm(\"Delete this duplicate?\");'>
                                                <input type='hidden' name='action' value='delete_duplicate'>
                                                <input type='hidden' name='student_id' value='{$dupId}'>
                                                <button class='btn btn-sm btn-danger'>Delete</button>
                                            </form>
                                        </td>
                                    </tr>";
                                                }
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($page === 'general_ledger' && can('ledger_view')): ?>
                            <?php
                            $start = $_GET['start'] ?? date('Y-m-01');
                            $end = $_GET['end'] ?? date('Y-m-d');

                            // 1. Fetch Income
$fees = $pdo->query("SELECT SUM(enrollment_fee) FROM enrollment WHERE enrollment_fee > 0 AND created_at BETWEEN '$start 00:00:00' AND '$end 23:59:59'")->fetchColumn() ?: 0;
$genIncome = $pdo->query("SELECT SUM(Amount) FROM general_income WHERE Date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0; 

// 2. Fetch Expenses (Hasanaat ab kharcha hai)
$hasanaat = $pdo->query("SELECT SUM(Amount) FROM hasanaat_payments WHERE Date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
$tabarruk = $pdo->query("SELECT SUM(total_cost) FROM tabarruk WHERE date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
$generalExp = $pdo->query("SELECT SUM(Amount) FROM general_expenses WHERE Date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;

$totalIncome = $fees + $genIncome;
$totalExpense = $tabarruk + $generalExp + $hasanaat;
                            $balance = $totalIncome - $totalExpense;

                            // 3. Ledger Query (With RawTitle and RawDesc for Modals)
                            $sql = "
        SELECT 'Fee Collection' as Type, CONCAT('Student ID: ', StudentId) as Ref, created_at as Date, enrollment_fee as Credit, 0 as Debit, 0 as ID, '' as RawTitle, '' as RawDesc FROM enrollment WHERE enrollment_fee > 0 AND created_at BETWEEN '$start 00:00:00' AND '$end 23:59:59'
        UNION ALL
        SELECT 'Hasanaat Payment (Redeemed)', CONCAT('Card #', (SELECT CardNumber FROM hasanaat_cards WHERE Id=hasanaat_payments.CardId)), Date, 0, Amount, Id, '' as RawTitle, '' as RawDesc FROM hasanaat_payments WHERE Date BETWEEN '$start' AND '$end'
        UNION ALL
        SELECT 'General Income', CONCAT(Title, ' - ', IFNULL(Description, '')), Date, Amount, 0, Id, Title as RawTitle, Description as RawDesc FROM general_income WHERE Date BETWEEN '$start' AND '$end'
        UNION ALL
        SELECT 'Tabarruk', CONCAT(item_name, ' - ', IFNULL(description, '')), date, 0, total_cost, id, item_name as RawTitle, description as RawDesc FROM tabarruk WHERE date BETWEEN '$start' AND '$end'
        UNION ALL
        SELECT 'Expense', CONCAT(Title, ' - ', IFNULL(Description, '')), Date, 0, Amount, Id, Title as RawTitle, Description as RawDesc FROM general_expenses WHERE Date BETWEEN '$start' AND '$end'
        ORDER BY Date DESC
    ";
                            $ledger = $pdo->query($sql)->fetchAll();
                            ?>

                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card bg-success text-white p-3">
                                        <h3><?= number_format($totalIncome) ?></h3><small>Total Income</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-danger text-white p-3">
                                        <h3><?= number_format($totalExpense) ?></h3><small>Total Expense</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-primary text-white p-3">
                                        <h3><?= number_format($balance) ?></h3><small>Net Balance</small>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow">
                                <div class="card-header d-flex justify-content-between">
                                    <h5>General Ledger</h5>
                                    <div>
                                        <?php if (can('income_add')): ?>
                                            <button class="btn btn-success btn-sm me-2" onclick="editIncome({})">+ Add Misc
                                                Income</button>
                                        <?php endif; ?>

                                        <?php if (can('expense_add')): ?>
                                            <button class="btn btn-danger btn-sm" onclick="editExpense({})">+ Add Expense</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <form class="mb-3 d-flex gap-2">
                                        <input type="hidden" name="page" value="general_ledger">
                                        <input type="date" name="start" value="<?= $start ?>" class="form-control"
                                            style="width:auto">
                                        <input type="date" name="end" value="<?= $end ?>" class="form-control"
                                            style="width:auto">
                                        <button class="btn btn-primary">Filter</button>
                                    </form>

                                    <table class="table table-bordered table-striped datatable-export">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Details</th>
                                                <th class="text-success">Credit (In)</th>
                                                <th class="text-danger">Debit (Out)</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ledger as $l):
                                                // Print Link Logic
                                                $printLink = "";
                                                if ($l['Type'] == 'Hasanaat Payment')
                                                    $printLink = "?page=print_receipt&type=hasanaat&id=" . $l['ID'];
                                                if ($l['Type'] == 'Tabarruk')
                                                    $printLink = "?page=print_receipt&type=tabarruk&id=" . $l['ID'];
                                                if ($l['Type'] == 'Expense')
                                                    $printLink = "?page=print_receipt&type=expense&id=" . $l['ID'];
                                                if ($l['Type'] == 'General Income')
                                                    $printLink = "?page=print_receipt&type=income&id=" . $l['ID'];
                                                ?>
                                                <tr>
                                                    <td><?= date('d M Y', strtotime($l['Date'])) ?></td>
                                                    <td><?= $l['Type'] ?></td>
                                                    <td><?= $l['Ref'] ?></td>
                                                    <td class="text-end"><?= $l['Credit'] > 0 ? number_format($l['Credit']) : '-' ?>
                                                    </td>
                                                    <td class="text-end"><?= $l['Debit'] > 0 ? number_format($l['Debit']) : '-' ?>
                                                    </td>
                                                    <td class="text-center" style="white-space: nowrap;">
                                                        <?php if ($printLink): ?>
                                                            <a href="<?= $printLink ?>" target="_blank"
                                                                class="btn btn-xs btn-secondary"><i class="fas fa-print"></i></a>
                                                        <?php endif; ?>

                                                        <?php if ($l['Type'] == 'General Income'): ?>
                                                            <?php if (can('income_edit')): ?>
                                                                <button class="btn btn-xs btn-info"
                                                                    onclick="editIncome({Id:<?= $l['ID'] ?>, Title:'<?= addslashes($l['RawTitle']) ?>', Desc:'<?= addslashes($l['RawDesc']) ?>', Amount:<?= $l['Credit'] ?>})"><i
                                                                        class="fas fa-pencil"></i></button>
                                                            <?php endif; ?>
                                                            <?php if (can('income_delete')): ?>
                                                                <form method="POST" class="d-inline"
                                                                    onsubmit="return confirm('Delete Income?');">
                                                                    <input type="hidden" name="action" value="delete_general_income">
                                                                    <input type="hidden" name="inc_id" value="<?= $l['ID'] ?>">
                                                                    <button class="btn btn-xs btn-danger"><i
                                                                            class="fas fa-trash"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <?php if ($l['Type'] == 'Expense'): ?>
                                                            <?php if (can('expense_edit')): ?>
                                                                <button class="btn btn-xs btn-info"
                                                                    onclick="editExpense({Id:<?= $l['ID'] ?>, Title:'<?= addslashes($l['RawTitle']) ?>', Desc:'<?= addslashes($l['RawDesc']) ?>', Amount:<?= $l['Debit'] ?>})"><i
                                                                        class="fas fa-pencil"></i></button>
                                                            <?php endif; ?>
                                                            <?php if (can('expense_delete')): ?>
                                                                <form method="POST" class="d-inline"
                                                                    onsubmit="return confirm('Delete Expense?');">
                                                                    <input type="hidden" name="action" value="delete_expense">
                                                                    <input type="hidden" name="exp_id" value="<?= $l['ID'] ?>">
                                                                    <button class="btn btn-xs btn-danger"><i
                                                                            class="fas fa-trash"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="modal fade" id="incModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header bg-success text-white">
                                            <h5 class="modal-title">Add Miscellaneous Income</h5>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="save_general_income">
                                            <input type="hidden" name="inc_id" id="gi_id">
                                            <div class="mb-2"><label>Date</label><input type="date" name="date" id="gi_date"
                                                    class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                                            <div class="mb-2"><label>Title (Source)</label><input type="text" name="title"
                                                    id="gi_title" class="form-control" required
                                                    placeholder="e.g. Donation from XYZ"></div>
                                            <div class="mb-2"><label>Category</label>
                                                <select name="cat" id="gi_cat" class="form-select">
                                                    <?= getOptions('opt_income_cats') ?>
                                                </select>
                                            </div>
                                            <div class="mb-2"><label>Amount</label><input type="number" name="amount"
                                                    id="gi_amt" class="form-control" required></div>
                                            <div class="mb-2"><label>Description</label><textarea name="desc" id="gi_desc"
                                                    class="form-control"></textarea></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-success">Save Income</button></div>
                                    </form>
                                </div>
                            </div>

                            <div class="modal fade" id="expModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Expense Entry</h5>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="save_expense">
                                            <input type="hidden" name="exp_id" id="ge_id">
                                            <div class="mb-2"><label>Date</label><input type="date" name="date" id="ge_date"
                                                    class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                                            <div class="mb-2"><label>Title</label><input type="text" name="title" id="ge_title"
                                                    class="form-control" required></div>
                                            <div class="mb-2"><label>Category</label>
                                                <select name="cat" id="ge_cat" class="form-select">
                                                    <?= getOptions('opt_expense_cats') ?>
                                                </select>
                                            </div>
                                            <div class="mb-2"><label>Amount</label><input type="number" name="amount"
                                                    id="ge_amt" class="form-control" required></div>
                                            <div class="mb-2"><label>Description</label><textarea name="desc" id="ge_desc"
                                                    class="form-control"></textarea></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-danger">Save Expense</button></div>
                                    </form>
                                </div>
                            </div>

<script>
function editIncome(d) {
    document.getElementById('gi_id').value = d.Id || '';
    document.getElementById('gi_title').value = d.Title || '';
    document.getElementById('gi_desc').value = d.Desc || ''; // FIXED
    document.getElementById('gi_amt').value = d.Amount || '';

    new bootstrap.Modal(document.getElementById('incModal')).show();
}
function editExpense(d) {
    document.getElementById('ge_id').value = d.Id || '';
    document.getElementById('ge_title').value = d.Title || '';
    document.getElementById('ge_desc').value = d.Desc || ''; // FIXED
    document.getElementById('ge_amt').value = d.Amount || '';

    new bootstrap.Modal(document.getElementById('expModal')).show();
}                            </script>
                        <?php endif; ?>

                        <?php if ($page === 'hasanaat_cards' && can('hasanaat_view')): ?>
                            <div class="card shadow">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5>Hasanaat Cards Management</h5>
                                    <?php if (can('hasanaat_add')): ?><button class="btn btn-success btn-sm"
                                            onclick="editCard({})">+ Issue New Card</button><?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <table id="hasanaatCardsTable" class="table table-hover datatable-basic">
                                        <thead>
                                            <tr>
                                                <th>Card #</th>
                                                <th>Holder</th>
                                                <th>Type</th>
                                                <th>Total Value (Rs)</th>
                                                <th>Redeemed (Rs)</th>
                                                <th>Remaining (Rs)</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tfoot>
                                            <tr>
                                                <th><input type="text" class="form-control form-control-sm" placeholder="Card #"></th>
                                                <th><input type="text" class="form-control form-control-sm" placeholder="Holder"></th>
                                                <th><input type="text" class="form-control form-control-sm" placeholder="Type"></th>
                                                <th><input type="text" class="form-control form-control-sm" placeholder="Total"></th>
                                                <th><input type="text" class="form-control form-control-sm" placeholder="Redeemed"></th>
                                                <th><input type="text" class="form-control form-control-sm" placeholder="Remaining"></th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                        <tbody>
                                            <?php
                                            $cards = $pdo->query("SELECT h.*, (SELECT COALESCE(SUM(Amount),0) FROM hasanaat_payments WHERE CardId=h.Id) as Paid FROM hasanaat_cards h ORDER BY h.Id DESC")->fetchAll();
                                            foreach ($cards as $c):
                                                $bal = $c['TotalAmount'] - $c['Paid'];
                                                $statusColor = ($bal <= 0) ? 'success' : 'primary';
                                                ?>
                                                <tr>
                                                    <td class="fw-bold"><?= $c['CardNumber'] ?></td>
                                                    <td><?= $c['HolderName'] ?><br><small
                                                            class="text-muted"><?= $c['Mobile'] ?></small></td>
                                                    <td><?= $c['CardType'] ?></td>
                                                    <td><?= number_format($c['TotalAmount']) ?></td>
                                                    <td class="text-success"><?= number_format($c['Paid']) ?></td>
                                                    <td class="text-danger fw-bold"><?= number_format($bal) ?></td>
                                                    <td><span
                                                            class="badge bg-<?= $statusColor ?>"><?= $bal <= 0 ? 'Completed' : 'Active' ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if (can('hasanaat_pay')): ?>
                                                            <button class="btn btn-xs btn-outline-danger"
                                                                onclick="openPay(<?= $c['Id'] ?>, '<?= $c['CardNumber'] ?>')">Redeem Cash</button>
                                                        <?php endif; ?>
                                                        <?php if (can('hasanaat_edit')): ?>
                                                            <button class="btn btn-xs btn-info"
                                                                onclick='editCard(<?= json_encode($c) ?>)'>Edit</button>
                                                        <?php endif; ?>
                                                        <?php if (can('hasanaat_delete')): ?>
                                                            <form method="POST" class="d-inline"
                                                                onsubmit="return confirm('Delete Card & History?')">
                                                                <input type="hidden" name="action" value="delete_card">
                                                                <input type="hidden" name="card_id" value="<?= $c['Id'] ?>">
                                                                <button class="btn btn-xs btn-danger">X</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="modal fade" id="cardModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Card Details</h5>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="save_card">
                                            <input type="hidden" name="card_id" id="hc_id">
                                            <div class="row">
                                                <div class="col-6 mb-2"><label>Card No</label><input type="text" name="card_no"
                                                        id="hc_no" class="form-control" required></div>
                                                <div class="col-6 mb-2"><label>Type</label>
                                                    <select name="type" id="hc_type" class="form-select">
                                                        <?= getOptions('opt_card_types') ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="mb-2"><label>Holder Name</label><input type="text" name="holder"
                                                    id="hc_holder" class="form-control" required></div>
                                            <div class="mb-2"><label>Father Name</label><input type="text" name="father"
                                                    id="hc_father" class="form-control"></div>
                                            <div class="mb-2"><label>Total Card Value (Rs)</label><input type="number"
                                                    name="amount" id="hc_amt" class="form-control" placeholder="e.g. 50, 100" required></div>
                                            <div class="mb-2"><label>Date</label><input type="date" name="date" id="hc_date"
                                                    class="form-control" value="<?= date('Y-m-d') ?>"></div>
                                            <div class="mb-2"><label>Mobile</label><input type="text" name="mobile"
                                                    id="hc_mobile" class="form-control"></div>
                                            <div class="mb-2"><label>Reference</label><input type="text" name="ref" id="hc_ref"
                                                    class="form-control"></div>
                                            <div class="mb-2"><label>Notes</label><textarea name="notes" id="hc_notes"
                                                    class="form-control"></textarea></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-primary">Save Card</button></div>
                                    </form>
                                </div>
                            </div>

                            <div class="modal fade" id="payModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content border-danger">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Redeem Points (Give Cash)</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="save_payment">
                                            <input type="hidden" name="card_id" id="hp_card_id">
                                            <h6 class="text-center bg-light p-2 border" id="hp_card_display"></h6>
                                            <div class="mb-2"><label>Amount</label><input type="number" name="amount"
                                                    class="form-control" required></div>
                                            <div class="mb-2"><label>Date</label><input type="date" name="date"
                                                    class="form-control" value="<?= date('Y-m-d') ?>"></div>
                                            <div class="mb-2"><label>Remarks</label><input type="text" name="remarks"
                                                    class="form-control"></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-success">Save Payment</button></div>
                                    </form>
                                </div>
                            </div>

                            <script>
                                function editCard(d) {
                                    document.getElementById('hc_id').value = d.Id || '';
                                    document.getElementById('hc_no').value = d.CardNumber || '';
                                    document.getElementById('hc_holder').value = d.HolderName || '';
                                    document.getElementById('hc_father').value = d.FatherName || '';
                                    document.getElementById('hc_amt').value = d.TotalAmount || '';
                                    document.getElementById('hc_mobile').value = d.Mobile || '';
                                    document.getElementById('hc_ref').value = d.Reference || '';
                                    document.getElementById('hc_notes').value = d.Notes || '';
                                    new bootstrap.Modal(document.getElementById('cardModal')).show();
                                }
                                function openPay(id, no) {
                                    document.getElementById('hp_card_id').value = id;
                                    document.getElementById('hp_card_display').innerText = "Card Number: " + no;
                                    new bootstrap.Modal(document.getElementById('payModal')).show();
                                }
                            </script>
                        <?php endif; ?>


                        <!-- DASHBOARD: DETAILED STATS -->

                        <?php if ($page === 'dashboard'): ?>

                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="card p-3 border-start border-4 border-primary">
                                        <h4 id="hijriDateSmall" class="mb-0 fw-bold" style="color: #1a3c34;">Loading...</h4>
                                        <span class="text-muted small">Islamic Date</span>
                                    </div>
                                </div>

                                <script>
                                    document.addEventListener("DOMContentLoaded", function () {
                                        // 1. Get Today's Date & Adjust (-1 Day)
                                        const date = new Date();
                                        date.setDate(date.getDate() - 1);

                                        // 2. Define Islamic Month Names Manually (To fix "August" bug)
                                        const islamicMonths = [
                                            "Muharram", "Safar", "Rabi' al-Awwal", "Rabi' al-Thani",
                                            "Jumada al-Ula", "Jumada al-Akhira", "Rajab", "Sha'ban",
                                            "Ramadan", "Shawwal", "Dhu al-Qi'dah", "Dhu al-Hijjah"
                                        ];

                                        try {
                                            // 3. Request ONLY numbers from the browser (bypasses the name bug)
                                            const formatter = new Intl.DateTimeFormat('en-u-ca-islamic-civil', {
                                                day: 'numeric',
                                                month: 'numeric',
                                                year: 'numeric'
                                            });

                                            const parts = formatter.formatToParts(date);
                                            let day, month, year;

                                            // Extract the numbers safely
                                            parts.forEach(p => {
                                                if (p.type === 'day') day = p.value;
                                                if (p.type === 'month') month = p.value;
                                                if (p.type === 'year') year = p.value.split(' ')[0]; // Remove 'AH' or 'BC' text
                                            });

                                            // 4. Show Correct Date (Map Month Number 8 -> Sha'ban)
                                            const finalDate = islamicMonths[month - 1] + " " + day + ", " + year;
                                            document.getElementById('hijriDateSmall').innerText = finalDate;

                                        } catch (e) {
                                            // Fallback if browser is extremely old
                                            document.getElementById('hijriDateSmall').innerText = "Sha'ban 9, 1447";
                                        }
                                    });
                                </script>
                                <div class="col-md-3">
                                    <div class="card p-3 border-start border-4 border-success">
                                        <h3><?php echo count($activeSessions); ?></h3><span class="text-muted">Active
                                            Sessions</span>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="card p-3 border-start border-4 border-info">
                                        <h5><?php echo date('l'); ?></h5><span
                                            class="text-muted"><?php echo date('d M Y'); ?></span>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="card p-3 border-start border-4 border-warning">
                                        <h3 id="digitalClock">00:00:00</h3><span class="text-muted">Current Time</span>
                                    </div>
                                </div>

                            </div>

                            <script>setInterval(function () { const d = new Date(); document.getElementById('digitalClock').innerText = d.toLocaleTimeString(); }, 1000);</script>


                            <div class="row mt-4">

                                <div class="col-md-7">
                                    <div class="card h-100">
                                        <div class="card-header bg-dark text-white">Active Session Statistics (Class & Gender)
                                        </div>
                                        <div class="card-body">
                                            <?php if (empty($activeSessions)): ?>
                                                <p class="text-muted">No Active Sessions.</p>
                                            <?php else: ?>
                                                <div class="accordion" id="sessStatsAcc">
                                                    <?php foreach ($activeSessions as $idx => $sess):
                                                        $sid = $sess['Id'];

                                                        // 1. Get Total Unique Enrollments (Header Count)
                                                        $totalEnrolled = $pdo->query("SELECT COUNT(DISTINCT Id) FROM enrollment WHERE EnrollmentSessionId = $sid AND IsActive = 1")->fetchColumn();

                                                        // 2. Breakdown Query (Improved to find Gender Issues)
                                                        $sql = "SELECT 
                                CASE 
                                    WHEN s.Id IS NULL THEN '⚠️ Error: Student Deleted' 
                                    WHEN c.ClassName IS NULL THEN 'Unassigned' 
                                    ELSE c.ClassName 
                                END as ClassName, 
                                
                                COUNT(DISTINCT CASE WHEN s.Gender='Male' THEN e.Id END) as Boys,
                                COUNT(DISTINCT CASE WHEN s.Gender='Female' THEN e.Id END) as Girls,
                                -- Count students with Missing/Invalid Gender
                                COUNT(DISTINCT CASE WHEN (s.Gender NOT IN ('Male','Female') OR s.Gender IS NULL) AND s.Id IS NOT NULL THEN e.Id END) as Unknown,
                                COUNT(DISTINCT e.Id) as Total,
                                
                                -- Collect Info for fixing issues (Ghosts, Unassigned, No Gender)
                                GROUP_CONCAT(DISTINCT 
                                    CASE 
                                        WHEN s.Id IS NULL THEN CONCAT('EnrollID:', e.Id) 
                                        WHEN c.ClassName IS NULL THEN CONCAT(s.Name, ' (ID:', s.Id, ')') 
                                        -- ADDED: Identify students with missing gender
                                        WHEN (s.Gender NOT IN ('Male','Female') OR s.Gender IS NULL) THEN CONCAT(s.Name, ' [No Gender] (ID:', s.Id, ')')
                                        ELSE NULL 
                                    END SEPARATOR ', '
                                ) as ProblemDetails

                                FROM enrollment e 
                                LEFT JOIN students s ON e.StudentId = s.Id 
                                LEFT JOIN classmanifest c ON e.Class = c.Class 
                                WHERE e.EnrollmentSessionId = $sid AND e.IsActive = 1
                                GROUP BY 
                                    CASE 
                                        WHEN s.Id IS NULL THEN '⚠️ Error: Student Deleted' 
                                        WHEN c.ClassName IS NULL THEN 'Unassigned' 
                                        ELSE c.ClassName 
                                    END
                                ORDER BY (s.Id IS NULL) DESC, (c.ClassName IS NULL) DESC, c.ClassName ASC";

                                                        $stats = $pdo->query($sql)->fetchAll();
                                                        ?>
                                                        <div class="accordion-item">
                                                            <h2 class="accordion-header">
                                                                <button
                                                                    class="accordion-button <?php echo $idx > 0 ? 'collapsed' : ''; ?>"
                                                                    type="button" data-bs-toggle="collapse"
                                                                    data-bs-target="#sess<?php echo $sid; ?>">
                                                                    <strong><?php echo htmlspecialchars($sess['Name']); ?></strong>
                                                                    &nbsp;
                                                                    <span class="badge bg-secondary"><?php echo $totalEnrolled; ?>
                                                                        Enrolled</span>
                                                                </button>
                                                            </h2>
                                                            <div id="sess<?php echo $sid; ?>"
                                                                class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>"
                                                                data-bs-parent="#sessStatsAcc">
                                                                <div class="accordion-body p-0">
                                                                    <table class="table table-sm table-striped mb-0">
                                                                        <thead>
                                                                            <tr>
                                                                                <th style="width: 40%;">Class</th>
                                                                                <th>Total</th>
                                                                                <th>Boys</th>
                                                                                <th>Girls</th>
                                                                                <th>Unknown</th>
                                                                                <th>Status</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php foreach ($stats as $row):
                                                                                $rowClass = "";
                                                                                $statusBadge = "";
                                                                                $displayName = htmlspecialchars($row['ClassName']);

                                                                                // HANDLE GHOST RECORDS (Deleted Students)
                                                                                if ($row['ClassName'] === '⚠️ Error: Student Deleted') {
                                                                                    $rowClass = "table-danger border-danger";
                                                                                    $statusBadge = '<form method="POST" onsubmit="return confirm(\'Clean these ghost records?\')">
                                                        <input type="hidden" name="action" value="clean_ghost_enrollments">
                                                        <button class="btn btn-xs btn-outline-danger">Clean Fix</button>
                                                    </form>';
                                                                                    $displayName = "<strong>⚠️ Ghost Records</strong><br><small class='text-danger'>" . $row['ProblemDetails'] . "</small>";
                                                                                }
                                                                                // HANDLE UNASSIGNED CLASS
                                                                                elseif ($row['ClassName'] === 'Unassigned') {
                                                                                    $rowClass = "table-warning border-warning";
                                                                                    $statusBadge = '<span class="badge bg-warning text-dark">Unassigned</span>';
                                                                                    $displayName = "<strong>⚠️ Unassigned:</strong><br><small class='text-dark'>" . $row['ProblemDetails'] . "</small>";
                                                                                }
                                                                                // HANDLE MISSING GENDER (The mismatch cause)
                                                                                elseif ($row['Unknown'] > 0) {
                                                                                    $rowClass = "table-info border-info";
                                                                                    $statusBadge = '<span class="badge bg-info text-dark">Fix Gender</span>';
                                                                                    $displayName .= "<br><small class='text-danger'><strong>Missing Gender:</strong> " . $row['ProblemDetails'] . "</small>";
                                                                                }
                                                                                // NORMAL CLASS (Gender Specific Status)
                                                                                else {
                                                                                    $maxCap = getSet('class_capacity') ?: 25;
                                                                                    $boysFull = ($row['Boys'] >= $maxCap);
                                                                                    $girlsFull = ($row['Girls'] >= $maxCap);

                                                                                    if ($boysFull && $girlsFull) {
                                                                                        // Agar dono full hain
                                                                                        $statusBadge = '<span class="badge bg-danger" style="font-size:0.7em">Full</span>';
                                                                                    } elseif ($boysFull) {
                                                                                        // Agar sirf Boys full hain
                                                                                        $statusBadge = '<span class="badge bg-warning text-dark" style="font-size:0.7em">Boys Full</span>';
                                                                                    } elseif ($girlsFull) {
                                                                                        // Agar sirf Girls full hain
                                                                                        $statusBadge = '<span class="badge bg-warning text-dark" style="font-size:0.7em">Girls Full</span>';
                                                                                    } else {
                                                                                        // Agar jagah hai
                                                                                        $statusBadge = '<span class="badge bg-success" style="font-size:0.7em">Open</span>';
                                                                                    }
                                                                                }
                                                                                ?>
                                                                                <tr class="<?php echo $rowClass; ?>">
                                                                                    <td><?php echo $displayName; ?></td>
                                                                                    <td><strong><?php echo $row['Total']; ?></strong></td>
                                                                                    <td class="text-primary"><?php echo $row['Boys']; ?>
                                                                                    </td>
                                                                                    <td class="text-danger"><?php echo $row['Girls']; ?>
                                                                                    </td>
                                                                                    <td class="text-muted">
                                                                                        <?php echo $row['Unknown'] > 0 ? $row['Unknown'] : '-'; ?>
                                                                                    </td>
                                                                                    <td><?php echo $statusBadge; ?></td>
                                                                                </tr>
                                                                            <?php endforeach; ?>
                                                                            <?php if (empty($stats))
                                                                                echo "<tr><td colspan='6' class='text-center'>No enrollments found.</td></tr>"; ?>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>



                                <div class="col-md-5">

                                    <?php $jsClasses = $pdo->query("SELECT c.ClassName, c.EnrollmentType, c.MinAge, c.MaxAge FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1")->fetchAll(); ?>

                                    <div class="card mb-3">

                                        <div class="card-header bg-primary text-white">Age Calculator Tool<br><small>(Calculate age as of <?= date('jS F', strtotime('2024-'.(getSet('cutoff_month')?:'05').'-'.(getSet('cutoff_day')?:'31'))) ?> of the current year)</small></div>

                                        <div class="card-body">

                                            <div class="mb-3"><label>DOB</label><input type="date" id="dash_calc_dob"
                                                    class="form-control" onchange="dashCalculateClass()"></div>

                                            <div class="mb-3"><label>Gender</label><select id="dash_calc_gender"
                                                    class="form-select" onchange="dashCalculateClass()">
                                                    <option value="Male">Boy</option>
                                                    <option value="Female">Girl</option>
                                                </select></div>

                                            <div class="alert alert-info text-center fw-bold" id="dash_calc_result">Enter DOB to
                                                see
                                                class.</div>

                                        </div>

                                    </div>

                                    <script>

                                        const dClasses = <?php echo json_encode($jsClasses); ?>;

                                        function dashCalculateClass() {

                                            const dobStr = document.getElementById('dash_calc_dob').value;

                                            if (!dobStr) return;

                                            const dob = new Date(dobStr);
const cutoffDay = <?= getSet('cutoff_day') ?: '31' ?>;
                                            const cutoffMonth = <?= (getSet('cutoff_month') ?: '05') ?> - 1; // JS months 0-11
                                            const currentYear = new Date().getFullYear();
                                            const targetDate = new Date(currentYear, cutoffMonth, cutoffDay);
                                            let age = targetDate.getFullYear() - dob.getFullYear();
                                            const m = targetDate.getMonth() - dob.getMonth();
                                            if (m < 0 || (m === 0 && targetDate.getDate() < dob.getDate())) {
                                                age--;
                                            }

                                            const gender = document.getElementById('dash_calc_gender').value;

                                            const type = (gender === 'Male') ? 'BoysClass' : 'GirlsClass';

                                            let match = "No class found for Age " + age;

                                            for (let c of dClasses) { if (c.EnrollmentType === type && age >= c.MinAge && age <= c.MaxAge) { match = "Recommended: <strong>" + c.ClassName + "</strong> (Age " + age + ")"; break; } }

                                            document.getElementById('dash_calc_result').innerHTML = match;

                                        }

                                    </script>

                                    <div class="card">
                                        <div class="card-header">Total Headcounts</div>
                                        <div class="card-body">

                                            <ul class="list-group list-group-flush">

                                                <li class="list-group-item d-flex justify-content-between"><span>Boys
                                                        (Active)</span> <span class="badge bg-primary rounded-pill">
                                                        <?php echo $pdo->query("SELECT COUNT(DISTINCT s.Id) FROM students s JOIN enrollment e ON s.Id=e.StudentId WHERE s.Gender='Male' AND e.IsActive=1")->fetchColumn(); ?>
                                                    </span>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between"><span>Girls
                                                        (Active)</span> <span class="badge bg-danger rounded-pill">
                                                        <?php echo $pdo->query("SELECT COUNT(DISTINCT s.Id) FROM students s JOIN enrollment e ON s.Id=e.StudentId WHERE s.Gender='Female' AND e.IsActive=1")->fetchColumn(); ?>
                                                    </span>
                                                </li>
                                                <li class="list-group-item d-flex justify-content-between"><span>Teachers</span>
                                                    <span
                                                        class="badge bg-success rounded-pill"><?php echo $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn(); ?></span>
                                                </li>

                                            </ul>

                                        </div>
                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>


<?php if ($page === 'backup' && (can('backup_db') || $_SESSION['role'] === 'admin')): ?>
    <div class="row justify-content-center g-4">
        
        <div class="col-md-5">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-warning text-dark text-center py-3">
                    <h5 class="mb-0"><i class="fas fa-download me-2"></i>System Backup</h5>
                </div>
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <p class="text-muted mb-4">
                        Generate a full <strong>.sql</strong> backup file containing all your database records, students, fees, attendance, and settings.
                    </p>
                    <form method="POST" target="_blank">
                        <input type="hidden" name="action" value="backup_database">
                        <button class="btn btn-warning btn-lg w-100 fw-bold shadow-sm">
                            <i class="fas fa-cloud-download-alt me-2"></i> Download Backup
                        </button>
                    </form>
                    <div class="alert alert-info mt-4 text-start small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Note:</strong> Save this file securely. It can be used to restore the system in case of data loss.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card h-100 shadow-sm border-0" style="border-top: 4px solid #dc3545 !important;">
                <div class="card-header bg-white text-danger text-center py-3 border-bottom">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>System Restore</h5>
                </div>
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <div class="alert alert-danger small text-start mb-4">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>EXTREME DANGER:</strong> Restoring a backup will <b>DELETE and OVERWRITE</b> all current system data. This action cannot be undone!
                    </div>
                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('⚠️ WARNING: Are you absolutely sure you want to WIPE the current database and RESTORE from the uploaded file?');">
                        <input type="hidden" name="action" value="restore_database">
                        
                        <div class="mb-3 text-start">
                            <label class="form-label fw-bold text-muted">Select Backup File (.sql)</label>
                            <input class="form-control" type="file" name="backup_file" accept=".sql" required>
                        </div>
                        
                        <button type="submit" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm">
                            <i class="fas fa-database me-2"></i> Restore Database
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
<?php endif; ?>


                        <!-- TEACHERS MODULE -->

                        <?php if ($page === 'teachers' && can('manage_teachers')): ?>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">Add Teacher</div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="add_teacher">

                                                <div class="mb-2">
                                                    <label>Username (Login Name)</label>
                                                    <input type="text" name="username" class="form-control" required
                                                        placeholder="e.g. ustad_ali">
                                                </div>

                                                <div class="mb-2">
                                                    <label>Email (Login ID)</label>
                                                    <input type="email" name="email" class="form-control" required
                                                        placeholder="teacher@madrasa.com">
                                                </div>

                                                <div class="mb-2">
                                                    <label>Password</label>
                                                    <input type="text" name="password" class="form-control" required
                                                        placeholder="Enter custom password">
                                                </div>

                                                <div class="mb-2">
                                                    <label>Assign Classes (Optional)</label>
                                                    <select name="assigned_classes[]" class="form-select" multiple size="4">
                                                        <?php
                                                        // Fix: Added GROUP BY to remove duplicates & added Gender Label
                                                        $allC = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 GROUP BY c.ClassName, c.EnrollmentType ORDER BY c.ClassName");
                                                        foreach ($allC as $c) {
                                                            $lbl = ($c['EnrollmentType'] == 'BoysClass') ? '[B]' : '[G]';
                                                            echo "<option value='{$c['Class']}'>$lbl {$c['ClassName']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                    <small class="text-muted">Hold Ctrl to select multiple</small>
                                                </div>

                                                <div class="mb-2">
                                                    <label>Gender</label>
                                                    <select name="gender" class="form-select">
                                                        <option>Male</option>
                                                        <option>Female</option>
                                                    </select>
                                                </div>
                                                <div class="mb-2"><label>Phone</label><input type="text" name="phone"
                                                        class="form-control"></div>
                                                <div class="mb-2"><label>Notes</label><textarea name="notes"
                                                        class="form-control"></textarea></div>

                                                <button class="btn btn-success w-100">Create Teacher Account</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-8">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span>Staff List</span>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="sync_teachers">
                                                <button class="btn btn-sm btn-warning">
                                                    <i class="fas fa-sync"></i> Sync with Users
                                                </button>
                                            </form>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-hover datatable-export">
                                                    <thead>
                                                        <tr>
                                                            <th>ID</th>
                                                            <th>Name</th>
                                                            <th>Assigned Classes</th>
                                                            <th>Phone</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        // UPDATED QUERY: Added 'DISTINCT' to prevent duplicate class names
                                                        $sql = "SELECT t.*, 
                   GROUP_CONCAT(DISTINCT cm.ClassName SEPARATOR ', ') as AssignedClasses
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN class_teachers ct ON t.user_id = ct.user_id
            LEFT JOIN classmanifest cm ON ct.class_id = cm.Class
            WHERE u.role = 'teacher'
            GROUP BY t.id
            ORDER BY t.name";

                                                        $t = $pdo->query($sql);
                                                        while ($r = $t->fetch()): ?>
                                                            <tr>
                                                                <td><?php echo $r['id']; ?></td>
                                                                <td>
                                                                    <strong><?php echo htmlspecialchars($r['name']); ?></strong><br>
                                                                    <small
                                                                        class="text-muted"><?php echo htmlspecialchars($r['gender']); ?></small>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($r['AssignedClasses'])): ?>
                                                                        <span class="badge bg-success"
                                                                            style="white-space: normal; text-align:left;">
                                                                            <?php echo htmlspecialchars($r['AssignedClasses']); ?>
                                                                        </span>

                                                                        <form method="POST" class="d-inline mt-1"
                                                                            onsubmit="return confirm('Unassign <?php echo addslashes($r['name']); ?> from ALL classes?');">
                                                                            <input type="hidden" name="action"
                                                                                value="unassign_all_classes">
                                                                            <input type="hidden" name="teacher_id"
                                                                                value="<?php echo $r['id']; ?>">
                                                                            <button class="btn btn-sm btn-outline-warning py-0"
                                                                                title="Unassign All Classes">
                                                                                <i class="fas fa-unlink"></i>
                                                                            </button>
                                                                        </form>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">- No Classes -</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($r['phone']); ?></td>
                                                                <td>
                                                                    <button class="btn btn-sm btn-info py-0"
                                                                        onclick='editTeacher(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>)'>
                                                                        Edit
                                                                    </button>

                                                                    <form method="POST" class="d-inline"
                                                                        onsubmit="return confirm('Delete Teacher Account?');">
                                                                        <input type="hidden" name="action" value="delete_teacher">
                                                                        <input type="hidden" name="id"
                                                                            value="<?php echo $r['id']; ?>">
                                                                        <button class="btn btn-sm btn-danger py-0">x</button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal fade" id="editTeacherModal">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Teacher</h5>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit_teacher">
                                                <input type="hidden" name="id" id="et_id">
                                                <div class="mb-2"><label>Name</label><input type="text" name="name" id="et_name"
                                                        class="form-control" required></div>
                                                <div class="mb-2"><label>Gender</label><select name="gender" id="et_gender"
                                                        class="form-select">
                                                        <option>Male</option>
                                                        <option>Female</option>
                                                    </select></div>
                                                <div class="mb-2"><label>Phone</label><input type="text" name="phone"
                                                        id="et_phone" class="form-control"></div>
                                                <div class="mb-2"><label>Notes</label><textarea name="notes" id="et_notes"
                                                        class="form-control"></textarea></div>
                                                <div class="alert alert-info small">To change assigned classes, please use the
                                                    "Classes" tab or the "Unassign" button in the list.</div>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
                                        </form>
                                    </div>
                                </div>

                                <script>
                                    function editTeacher(d) {
                                        document.getElementById('et_id').value = d.id;
                                        document.getElementById('et_name').value = d.name;
                                        document.getElementById('et_gender').value = d.gender;
                                        document.getElementById('et_phone').value = d.phone;
                                        document.getElementById('et_notes').value = d.notes;
                                        new bootstrap.Modal(document.getElementById('editTeacherModal')).show();
                                    }
                                </script>

                            </div>
                        <?php endif; ?>

                        <!-- TABARRUK (DAILY NIAZ) -->

                        <?php if ($page === 'tabarruk' && can('tabarruk_view')): ?>
                            <div class="card shadow">
                                <div class="card-header d-flex justify-content-between">
                                    <h5>Tabarruk / Niaz</h5>
                                    <?php if (can('tabarruk_add')): ?><button class="btn btn-primary btn-sm"
                                            onclick="editTabarruk({})">+ Add Entry</button><?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <table class="table table-bordered datatable-export">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Item</th>
                                                <th>Qty</th>
                                                <th>Cost</th>
                                                <th>Notes</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $tb = $pdo->query("SELECT * FROM tabarruk ORDER BY date DESC");
                                            while ($r = $tb->fetch()): ?>
                                                <tr>
                                                    <td><?= convertToUserTime($r['date']) ?></td>
                                                    <td><?= $r['item_name'] ?></td>
                                                    <td><?= $r['quantity'] ?></td>
                                                    <td><?= number_format($r['total_cost']) ?></td>
                                                    <td><?= $r['description'] ?></td>
                                                    <td>
                                                        <?php if (can('tabarruk_edit')): ?>
                                                            <button class="btn btn-xs btn-info"
                                                                onclick='editTabarruk(<?= json_encode($r) ?>)'>Edit</button>
                                                        <?php endif; ?>
                                                        <?php if (can('tabarruk_delete')): ?>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                                                <input type="hidden" name="action" value="delete_tabarruk">
                                                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                                <button class="btn btn-xs btn-danger">Del</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="modal fade" id="tbModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Tabarruk Entry</h5>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="save_tabarruk">
                                            <input type="hidden" name="tb_id" id="tb_id">
                                            <div class="mb-2"><label>Date</label><input type="date" name="date" id="tb_date"
                                                    class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                                            <div class="mb-2"><label>Item</label><input type="text" name="item" id="tb_item"
                                                    class="form-control" required></div>
                                            <div class="mb-2"><label>Quantity</label><input type="number" name="qty" id="tb_qty"
                                                    class="form-control"></div>
                                            <div class="mb-2"><label>Total Cost</label><input type="number" name="cost"
                                                    id="tb_cost" class="form-control" required></div>
                                            <div class="mb-2"><label>Notes</label><textarea name="desc" id="tb_desc"
                                                    class="form-control"></textarea></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
                                    </form>
                                </div>
                            </div>
                            <script>
                                function editTabarruk(d) {
                                    document.getElementById('tb_id').value = d.id || '';
                                    document.getElementById('tb_date').value = d.date || '<?= date('Y-m-d') ?>';
                                    document.getElementById('tb_item').value = d.item_name || '';
                                    document.getElementById('tb_qty').value = d.quantity || '';
                                    document.getElementById('tb_cost').value = d.total_cost || '';
                                    document.getElementById('tb_desc').value = d.description || '';
                                    new bootstrap.Modal(document.getElementById('tbModal')).show();
                                }
                            </script>
                        <?php endif; ?>

                        <?php if ($page === 'budget_plan' && can('budget_view')): ?>
                            <div class="card shadow">
                                <div class="card-header d-flex justify-content-between">
                                    <h5>Budget Planning (Targets)</h5>
                                    <?php if (can('budget_manage')): ?><button class="btn btn-primary btn-sm"
                                            onclick="editBudget({})">Set New Target</button><?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-info small">Use this to set goals (e.g., "Expected Donations:
                                        500k"). See General Ledger for actuals.</div>
                                    <table class="table table-bordered datatable-basic">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Category</th>
                                                <th>Type</th>
                                                <th>Target Amount</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $plans = $pdo->query("SELECT * FROM budget_plan ORDER BY MonthYear DESC")->fetchAll();
                                            foreach ($plans as $p): ?>
                                                <tr>
                                                    <td><?= $p['MonthYear'] ?></td>
                                                    <td><?= $p['Category'] ?></td>
                                                    <td><span
                                                            class="badge bg-<?= $p['Type'] == 'Income' ? 'success' : 'danger' ?>"><?= $p['Type'] ?></span>
                                                    </td>
                                                    <td><?= number_format($p['TargetAmount']) ?></td>
                                                    <td>
                                                        <?php if (can('budget_manage')): ?>
                                                            <button class="btn btn-xs btn-info"
                                                                onclick='editBudget(<?= json_encode($p) ?>)'>Edit</button>
                                                            <form method="POST" class="d-inline"
                                                                onsubmit="return confirm('Delete Target?')">
                                                                <input type="hidden" name="action" value="delete_budget_plan">
                                                                <input type="hidden" name="bp_id" value="<?= $p['Id'] ?>">
                                                                <button class="btn btn-xs btn-danger">X</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="modal fade" id="budgetModal">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Budget Target</h5>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="save_budget_plan">
                                            <input type="hidden" name="bp_id" id="bp_id">
                                            <div class="mb-2"><label>Month (YYYY-MM)</label><input type="month" name="month"
                                                    id="bp_month" class="form-control" required></div>
                                            <div class="mb-2"><label>Type</label><select name="type" id="bp_type"
                                                    class="form-select">
                                                    <option>Income</option>
                                                    <option>Expense</option>
                                                </select></div>
                                            <div class="mb-2"><label>Category</label><input type="text" name="cat" id="bp_cat"
                                                    class="form-control" required></div>
                                            <div class="mb-2"><label>Target Amount</label><input type="number" name="amount"
                                                    id="bp_amt" class="form-control" required></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-primary">Set Target</button></div>
                                    </form>
                                </div>
                            </div>
                            <script>
                                function editBudget(d) {
                                    document.getElementById('bp_id').value = d.Id || '';
                                    document.getElementById('bp_month').value = d.MonthYear || '<?= date('Y-m') ?>';
                                    document.getElementById('bp_type').value = d.Type || 'Income';
                                    document.getElementById('bp_cat').value = d.Category || '';
                                    document.getElementById('bp_amt').value = d.TargetAmount || '';
                                    new bootstrap.Modal(document.getElementById('budgetModal')).show();
                                }
                            </script>
                        <?php endif; ?>
                        <!-- PRIZES DISTRIBUTION -->

                        <?php if ($page === 'prizes' && can('manage_prizes')): ?>

                            <div class="row">

                                <div class="col-md-4">

                                    <div class="card">
                                        <div class="card-header">Award Prize</div>
                                        <div class="card-body">

                                            <form method="POST">

                                                <input type="hidden" name="action" value="add_prize">

                                                <div class="mb-2"><label>Date</label><input type="date" name="date"
                                                        class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>

                                                <div class="mb-2"><label>Student</label><select name="student_id"
                                                        class="form-select select2"><?php $st = $pdo->query("SELECT Id, Name, FatherName FROM students");

                                                        $st = $pdo->query("SELECT Id, Name FROM students ORDER BY Name");
                                                        while ($s = $st->fetch())
                                                            echo "<option value='{$s['Id']}'>{$s['Name']} (ID: {$s['Id']})</option>"; ?></select>
                                                </div>

                                                <div class="mb-2"><label>Prize Name</label><input type="text" name="prize_name"
                                                        class="form-control" required></div>

                                                <div class="mb-2"><label>Reason</label>
                                                    <select name="reason" class="form-select">
                                                        <?= getOptions('opt_prize_reasons') ?>
                                                    </select>
                                                </div>

                                                <div class="mb-2"><label>Cost</label><input type="number" name="cost"
                                                        step="0.01" class="form-control" value="0"></div>

                                                <button class="btn btn-primary w-100">Award Prize</button>

                                            </form>

                                        </div>
                                    </div>

                                </div>

                                <div class="col-md-8">

                                    <div class="card">
                                        <div class="card-header">Prize History</div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-bordered datatable-export">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Student</th>
                                                            <th>Prize</th>
                                                            <th>Reason</th>
                                                            <th>Cost</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tfoot>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Student</th>
                                                            <th>Prize</th>
                                                            <th>Reason</th>
                                                            <th>Cost</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </tfoot>
                                                    <tbody>
                                                        <?php $pz = $pdo->query("SELECT p.*, s.Name FROM prizes p JOIN students s ON p.student_id=s.Id ORDER BY p.date DESC");
                                                        while ($r = $pz->fetch()): ?>
                                                            <tr>
                                                                <td><?php echo convertToUserTime($r['date']); ?></td>
                                                                <td><?php echo $r['Name']; ?></td>
                                                                <td><?php echo $r['prize_name']; ?></td>
                                                                <td><?php echo $r['reason']; ?></td>
                                                                <td><?php echo $r['cost']; ?></td>
                                                                <td>
                                                                    <form method="POST" onsubmit="return confirm('Delete?');"><input
                                                                            type="hidden" name="action" value="delete_prize"><input
                                                                            type="hidden" name="id"
                                                                            value="<?php echo $r['id']; ?>"><button
                                                                            class="btn btn-sm btn-danger py-0">x</button></form>
                                                                </td>
                                                            </tr><?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-2">
                                        <div class="col-6">
                                            <div class="alert alert-success">Total Prize Cost:
                                                <strong><?php echo number_format($pdo->query("SELECT SUM(cost) FROM prizes")->fetchColumn()); ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="alert alert-warning">Avg Cost/Student:
                                                <strong><?php echo number_format($pdo->query("SELECT AVG(cost) FROM prizes WHERE cost > 0")->fetchColumn(), 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>



                        <?php if ($page === 'profile'): ?>

                            <?php $me = $pdo->query("SELECT * FROM users WHERE id=" . $_SESSION['user_id'])->fetch(); ?>

                            <div class="card" style="max-width:500px; margin:auto">

                                <div class="card-header bg-primary text-white">Update My Profile</div>

                                <div class="card-body">

                                    <form method="POST">

                                        <input type="hidden" name="action" value="update_profile">

                                        <div class="mb-3"><label>Username</label><input type="text" name="username"
                                                class="form-control" value="<?php echo htmlspecialchars($me['name']); ?>"
                                                required></div>

                                        <div class="mb-3"><label>Email</label><input type="email" name="email"
                                                class="form-control" value="<?php echo htmlspecialchars($me['email']); ?>"
                                                required></div>

                                        <div class="mb-3"><label>New Password (leave blank to keep current)</label><input
                                                type="password" name="new_password" class="form-control"></div>

                                        <hr>

                                        <div class="mb-3"><label class="text-danger">Current Password (Required to
                                                save)</label><input type="password" name="current_password" class="form-control"
                                                required></div>

                                        <button class="btn btn-primary w-100">Update Profile</button>

                                    </form>

                                </div>

                            </div>

                        <?php endif; ?>



                        <?php if ($page === 'age_calculator'): ?>

                            <div class="card" style="max-width: 600px; margin: auto;">

                                <div class="card-header bg-primary text-white">Class Age Calculator<br><small>(Calculate age as of <?= date('jS F', strtotime('2024-'.(getSet('cutoff_month')?:'05').'-'.(getSet('cutoff_day')?:'31'))) ?> of the current year)</small></div>

                                <div class="card-body">

                                    <div class="mb-3"><label>DOB</label><input type="date" id="page_calc_dob"
                                            class="form-control" onchange="pageCalculateClass()"></div>

                                    <div class="mb-3"><label>Gender</label><select id="page_calc_gender" class="form-select"
                                            onchange="pageCalculateClass()">
                                            <option value="Male">Boy</option>
                                            <option value="Female">Girl</option>
                                        </select></div>

                                    <div class="alert alert-info text-center" id="page_calc_result">Enter DOB to see suggested
                                        class.</div>

                                </div>

                            </div>

                            <?php $jsClasses = $pdo->query("SELECT c.ClassName, c.EnrollmentType, c.MinAge, c.MaxAge FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1")->fetchAll(); ?>

                            <script>

                                const pClasses = <?php echo json_encode($jsClasses); ?>;

                                function pageCalculateClass() {

                                    const dobStr = document.getElementById('page_calc_dob').value;

                                    if (!dobStr) return;

                                    const dob = new Date(dobStr);
const cutoffDay = <?= getSet('cutoff_day') ?: '31' ?>;
                                            const cutoffMonth = <?= (getSet('cutoff_month') ?: '05') ?> - 1; // JS months 0-11
                                            const currentYear = new Date().getFullYear();
                                            const targetDate = new Date(currentYear, cutoffMonth, cutoffDay);
                                    let age = targetDate.getFullYear() - dob.getFullYear();
                                    const m = targetDate.getMonth() - dob.getMonth();
                                    if (m < 0 || (m === 0 && targetDate.getDate() < dob.getDate())) {
                                        age--;
                                    }

                                    const gender = document.getElementById('page_calc_gender').value;

                                    const type = (gender === 'Male') ? 'BoysClass' : 'GirlsClass';

                                    let match = "No class found for Age " + age;

                                    for (let c of pClasses) { if (c.EnrollmentType === type && age >= c.MinAge && age <= c.MaxAge) { match = "Recommended: <strong>" + c.ClassName + "</strong> (Age " + age + ")"; break; } }

                                    document.getElementById('page_calc_result').innerHTML = match;

                                }

                            </script>

                        <?php endif; ?>



                        <!-- STUDENTS (Fixed Duplicates + WA + Change Class + Mass Actions) -->

                        <?php if ($page === 'students' && can('student_view')): ?>

                            <div class="card">
                                <div class="card-header bg-white d-flex justify-content-between  align-items-center">

                                    <h5>Student Database</h5>

                                    <div>

                                        <?php if (can('enroll_student')): ?>

                                            <div class="btn-group me-2">

                                                <button type="button" class="btn btn-warning btn-sm dropdown-toggle"
                                                    data-bs-toggle="dropdown">Danger Zone / Mass Actions</button>

                                                <ul class="dropdown-menu">

                                                    <li>
                                                        <form method="POST"
                                                            onsubmit="return confirm('Assign ALL unassigned students based on age?');">
                                                            <input type="hidden" name="action" value="bulk_auto_assign"><button
                                                                class="dropdown-item">Run Bulk Auto-Assign</button>
                                                        </form>
                                                    </li>

                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>

                                                    <li>
                                                        <form method="POST"
                                                            onsubmit="return confirm('⚠️ WIPE ALL ACTIVE ENROLLMENTS? This cannot be undone.');">
                                                            <input type="hidden" name="action" value="mass_unassign"><input
                                                                type="hidden" name="mode" value="all"><button
                                                                class="dropdown-item text-danger">Unassign ALL (Active
                                                                Session)</button>
                                                        </form>
                                                    </li>

                                                    <li>
                                                        <form method="POST"
                                                            onsubmit="return confirm('Remove only Auto-Assigned students?');"><input
                                                                type="hidden" name="action" value="mass_unassign"><input
                                                                type="hidden" name="mode" value="auto"><button
                                                                class="dropdown-item text-warning">Unassign Auto-Assigned
                                                                Only</button></form>
                                                    </li>

                                                    <li>
                                                        <form method="POST"
                                                            onsubmit="return confirm('Remove only Manually Assigned students?');">
                                                            <input type="hidden" name="action" value="mass_unassign"><input
                                                                type="hidden" name="mode" value="manual"><button
                                                                class="dropdown-item text-warning">Unassign Manually
                                                                Assigned</button>
                                                        </form>
                                                    </li>

                                                </ul>

                                            </div>

                                        <?php endif; ?>

                                        <?php if (can('student_add')): ?><button class="btn btn-primary btn-sm"
                                                data-bs-toggle="modal" data-bs-target="#studentModal" onclick="clearStudentForm()">+
                                                Add Student</button><?php endif; ?>

                                    </div>

                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <?php
                                        $allClassesRaw = $pdo->query("SELECT * FROM classmanifest ORDER BY EnrollmentType, ClassName")->fetchAll();
                                        $allClasses = [];
                                        $seenCls = [];
                                        foreach ($allClassesRaw as $c) {
                                            if (!in_array($c['Class'], $seenCls)) {
                                                $seenCls[] = $c['Class'];
                                                $allClasses[] = $c;
                                            }
                                        }
                                        ?>
<div class="table-responsive">
    <table id="serverSideStudentsTable" class="table table-bordered table-striped table-hover align-middle datatable-basic" style="width:100%;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Father's Name</th>
                <th>Status</th>
                <th>Mobile</th>
                <th>WhatsApp</th>
                <th>Age</th>
                <th>Session</th>
                <th>Class</th>
                <th>Enrollment Fee</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            </tbody>
        <tfoot>
            <tr>
                <th><input type="text" class="form-control form-control-sm" placeholder="Search ID" /></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Search Name" /></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Search Father" /></th>
                <th></th> <th><input type="text" class="form-control form-control-sm" placeholder="Search Mobile" /></th>
                <th></th> <th></th> <th><input type="text" class="form-control form-control-sm" placeholder="Search Session" /></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Search Class" /></th>
                <th></th> <th></th> </tr>
        </tfoot>
    </table>
</div>
                                    </div>
                                </div>

                                <div class="modal fade" id="waModal">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Send WhatsApp</h5>
                                            </div>
                                            <div class="modal-body"><input type="hidden" id="wa_phone"><textarea id="wa_msg"
                                                    class="form-control" rows="4" placeholder="Type message..."></textarea>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-success" onclick="sendWa()">Send
                                                    Now</button></div>
                                        </div>
                                    </div>
                                </div>

 

                                <script>

                                    function showAssign(id) { document.getElementById('assign_box_' + id).style.display = 'block'; }

                                    function openWhatsapp(phone) { document.getElementById('wa_phone').value = phone; new bootstrap.Modal(document.getElementById('waModal')).show(); }

                                    function sendWa() { const p = document.getElementById('wa_phone').value; const m = encodeURIComponent(document.getElementById('wa_msg').value); window.open(`https://api.whatsapp.com/send?phone=${p}&text=${m}`, '_blank'); }

                                    function clearStudentForm() { document.getElementById('st_action').value = 'add_student'; document.getElementById('stModalTitle').innerText = 'Add Student'; document.getElementById('st_name').value = ''; }

                                    function editStudent(d) {
                                        new bootstrap.Modal(document.getElementById('studentModal')).show();
                                        document.getElementById('st_action').value = 'edit_student';
                                        document.getElementById('stModalTitle').innerText = 'Edit Student';

                                        document.getElementById('st_id').value = d.Id || d.id;
                                        document.getElementById('st_name').value = d.Name;
                                        document.getElementById('st_pat').value = d.Paternity;
                                        document.getElementById('st_mob_f').value = d.MobileNumberFather;
                                        document.getElementById('st_mob_m').value = d.MobileNumberMother;
                                        document.getElementById('st_dob').value = d.DOB;
                                        document.getElementById('st_gender').value = d.Gender;

                                        document.getElementById('st_addr').value = d.Address || d.address || '';
                                        document.getElementById('st_school').value = d.School || d.school || '';
                                        document.getElementById('st_notes').value = d.Notes || d.notes || '';
                                    }
                                </script>



                            <?php endif; ?>

<?php if ($page === 'ghost_records' && can('student_view')): ?>
    <div class="card shadow-sm border-danger">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 text-danger fw-bold"><i class="fas fa-ghost me-2"></i>Ghost Records (Unenrolled Students)</h5>
            <?php if (can('student_delete')): ?>
                <form method="POST" onsubmit="return confirm('⚠️ DELETE ALL students who have never been enrolled?');">
                    <input type="hidden" name="action" value="bulk_delete_ghosts">
                    <button class="btn btn-danger btn-sm fw-bold"><i class="fas fa-trash-sweep me-1"></i> Bulk Delete All Ghosts</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="ghostTable" class="table table-hover table-bordered">
                    <thead class="table-light">
                        <tr><th>ID</th><th>Name</th><th>Father</th><th>Mobile</th><th>Actions</th></tr>
                    </thead>
                    <tfoot>
                        <tr><th><input type="text" class="form-control form-control-sm" placeholder="ID"></th><th><input type="text" class="form-control form-control-sm" placeholder="Name"></th><th><input type="text" class="form-control form-control-sm" placeholder="Father"></th><th><input type="text" class="form-control form-control-sm" placeholder="Mobile"></th><th></th></tr>
                    </tfoot>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>


<?php endif; ?>



                            <!-- LOGS -->

                            <?php if ($page === 'logs' && can('view_logs')): ?>

                                <div class="card">
                                    <div class="card-header bg-white d-flex justify-content-between">
                                        <h5>Logs</h5><?php if (can('delete_logs')): ?><button class="btn btn-danger btn-sm"
                                                data-bs-toggle="modal" data-bs-target="#pruneModal">Prune</button><?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table id="logsTable" class="table table-bordered table-striped datatable-export">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Date</th>
                                                        <th>User</th>
                                                        <th>Action</th>
                                                        <th>Details</th>
                                                        <th>IP</th>
                                                    </tr>
                                                </thead>
                                                <tfoot>
                                                    <tr>
                                                        <th><input type="text" class="form-control form-control-sm" placeholder="ID"></th>
                                                        <th><input type="text" class="form-control form-control-sm" placeholder="Date"></th>
                                                        <th><input type="text" class="form-control form-control-sm" placeholder="User"></th>
                                                        <th><input type="text" class="form-control form-control-sm" placeholder="Action"></th>
                                                        <th><input type="text" class="form-control form-control-sm" placeholder="Details"></th>
                                                        <th><input type="text" class="form-control form-control-sm" placeholder="IP"></th>
                                                    </tr>
                                                </tfoot>
                                                <tbody>
                                                    <?php $logs = $pdo->query("SELECT l.*, u.name as UserName FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.id DESC LIMIT 2000");
                                                    while ($r = $logs->fetch()): ?>
                                                        <tr>
                                                            <td><?php echo $r['id']; ?></td>
                                                            <td><?php echo convertToUserTime($r['created_at']); ?></td>
                                                            <td><?php echo $r['UserName'] ?: 'System'; ?></td>
                                                            <td class="fw-bold"><?php echo $r['action']; ?></td>
                                                            <td><?php echo $r['details']; ?></td>
                                                            <td><small><?php echo $r['ip_address']; ?></small></td>
                                                        </tr><?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal fade" id="pruneModal">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Prune Logs</h5>
                                            </div>
                                            <div class="modal-body"><input type="hidden" name="action" value="prune_logs">
                                                <div class="d-grid gap-2"><button type="submit" name="range" value="1_day"
                                                        class="btn btn-outline-secondary">Older than 24h</button><button
                                                        type="submit" name="range" value="1_week"
                                                        class="btn btn-outline-secondary">Older than 1 Week</button><button
                                                        type="submit" name="range" value="1_month"
                                                        class="btn btn-outline-secondary">Older than 1 Month</button><button
                                                        type="submit" name="range" value="1_year"
                                                        class="btn btn-outline-secondary">Older than 1 Year</button><button
                                                        type="submit" name="range" value="all" class="btn btn-danger"
                                                        onclick="return confirm('Wipe ALL?');">DELETE ALL</button></div>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                            <?php endif; ?>



                            <?php if ($page === 'siblings' && can('student_view')): ?>

                                <div class="card">
                                    <div class="card-header">Sibling Finder</div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-bordered datatable-basic">
                                                <thead>
                                                    <tr>
                                                        <th>Father's Mobile</th>
                                                        <th>Count</th>
                                                        <th>Student Names</th>
                                                    </tr>
                                                </thead>

                                                <tbody>
                                                    <?php
                                                    $sql = "SELECT MobileNumberFather, COUNT(*) as cnt, 
            GROUP_CONCAT(CONCAT(Id, ':', Name) SEPARATOR '|') as raw_kids 
            FROM students 
            WHERE MobileNumberFather IS NOT NULL AND MobileNumberFather != '' 
            GROUP BY MobileNumberFather HAVING cnt > 1";
                                                    $res = $pdo->query($sql);

                                                    while ($r = $res->fetch()):
                                                        // Process the raw string "101:Ali|102:Ahmed"
                                                        $kidsArray = explode('|', $r['raw_kids']);
                                                        $links = [];
                                                        foreach ($kidsArray as $k) {
                                                            list($id, $name) = explode(':', $k);
                                                            // Create a clickable link to open student profile
                                                            // Note: You need a JS function openStudentProfile(id)
                                                            $links[] = "$name";
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $r['MobileNumberFather']; ?></td>
                                                            <td><span class="badge bg-info"><?php echo $r['cnt']; ?></span></td>
                                                            <td><?php echo implode(', ', $links); ?></td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                            <?php endif; ?>



                            <?php if ($page === 'users' && can('manage_roles')): ?>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="card">
                                            <div class="card-header">Users</div>
                                            <div class="card-body"><button class="btn btn-primary btn-sm mb-2"
                                                    data-bs-toggle="modal" data-bs-target="#addUserModal">Add User</button>
                                                <table class="table table-sm datatable-basic">
                                                    <thead>
                                                        <tr>
                                                            <th>Name</th>
                                                            <th>Role</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $us = $pdo->query("SELECT * FROM users");
                                                        while ($r = $us->fetch()): ?>
                                                            <tr>
                                                                <td><?php echo $r['name']; ?></td>
                                                                <td><?php echo $r['role']; ?></td>
                                                                <td><button class="btn btn-xs btn-info py-0"
                                                                        onclick="editUser(<?php echo $r['id']; ?>,'<?php echo $r['name']; ?>','<?php echo $r['role']; ?>')">E</button>
                                                                    <?php if ($r['id'] != $_SESSION['user_id']): ?>
                                                                        <form method="POST" class="d-inline"
                                                                            onsubmit="return confirm('Del?');"><input type="hidden"
                                                                                name="action" value="delete_user"><input type="hidden"
                                                                                name="user_id" value="<?php echo $r['id']; ?>"><button
                                                                                class="btn btn-xs btn-outline-danger py-0">x</button>
                                                                        </form><?php endif; ?>
                                                                </td>
                                                            </tr><?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card">
                                            <div class="card-header">Manage Roles</div>
                                            <div class="card-body">
                                                <form method="POST" class="mb-3 d-flex"><input type="hidden" name="action"
                                                        value="add_role"><input type="text" name="role_name"
                                                        class="form-control form-control-sm me-1" placeholder="New Role"
                                                        required><button class="btn btn-sm btn-success">Add</button></form>
                                                <ul class="list-group">
                                                    <?php $roles = $pdo->query("SELECT * FROM system_roles");
                                                    while ($rol = $roles->fetch()): ?>
                                                        <li
                                                            class="list-group-item d-flex justify-content-between align-items-center p-2">
                                                            <span><?php echo ucfirst($rol['role_name']); ?></span><?php if ($rol['role_name'] != 'admin'): ?>
                                                                <form method="POST"><input type="hidden" name="action"
                                                                        value="delete_role"><input type="hidden" name="role_name"
                                                                        value="<?php echo $rol['role_name']; ?>"><button
                                                                        class="btn btn-xs btn-outline-danger">Del</button></form>
                                                            <?php endif; ?>
                                                        </li><?php endwhile; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card">
                                            <div class="card-header">Permissions</div>
                                            <div class="card-body">
                                                <div class="accordion" id="permAcc">
                                                    <?php $roles = $pdo->query("SELECT * FROM system_roles WHERE role_name != 'admin'");
                                                    while ($rol = $roles->fetch()):
                                                        $currPerms = $rol['permissions'] ? explode(',', $rol['permissions']) : []; ?>
                                                        <div class="accordion-item">
                                                            <h2 class="accordion-header"><button
                                                                    class="accordion-button collapsed py-2" type="button"
                                                                    data-bs-toggle="collapse"
                                                                    data-bs-target="#c<?php echo $rol['id']; ?>"><?php echo ucfirst($rol['role_name']); ?></button>
                                                            </h2>
                                                            <div id="c<?php echo $rol['id']; ?>" class="accordion-collapse collapse"
                                                                data-bs-parent="#permAcc">
                                                                <div class="accordion-body p-2">
                                                                    <form method="POST"><input type="hidden" name="action"
                                                                            value="update_role_permissions"><input type="hidden"
                                                                            name="role_name"
                                                                            value="<?php echo $rol['role_name']; ?>">
                                                                        <div class="row g-1">
                                                                            <?php foreach ($available_permissions as $k => $v): ?>
                                                                                <div class="col-12"><label class="small"><input
                                                                                            type="checkbox" name="perms[]"
                                                                                            value="<?php echo $k; ?>" <?php echo in_array($k, $currPerms) ? 'checked' : ''; ?>>
                                                                                        <?php echo $v; ?></label></div>
                                                                            <?php endforeach; ?>
                                                                        </div><button
                                                                            class="btn btn-sm btn-success w-100 mt-2">Save</button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div><?php endwhile; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal fade" id="addUserModal">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Add User</h5>
                                            </div>
                                            <div class="modal-body"><input type="hidden" name="action" value="add_user"><input
                                                    type="text" name="username" class="form-control mb-2" placeholder="Username"
                                                    required><input type="email" name="email" class="form-control mb-2"
                                                    placeholder="Email" required><input type="password" name="password"
                                                    class="form-control mb-2" placeholder="Password"
                                                    required><label>Role</label><select name="role" class="form-select"><?php $rs = $pdo->query("SELECT role_name FROM system_roles");
                                                    while ($ro = $rs->fetch())
                                                        echo "<option>{$ro['role_name']}</option>"; ?></select>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
                                        </form>
                                    </div>
                                </div>
                                <div class="modal fade" id="editUserModal">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit User</h5>
                                            </div>
                                            <div class="modal-body"><input type="hidden" name="action" value="edit_user"><input
                                                    type="hidden" name="user_id" id="eu_id"><input type="text" name="username"
                                                    id="eu_name" class="form-control mb-2" required><input type="password"
                                                    name="password" class="form-control mb-2"
                                                    placeholder="New Password (Optional)"><select name="role" id="eu_role"
                                                    class="form-select"><?php $rs = $pdo->query("SELECT role_name FROM system_roles");
                                                    while ($ro = $rs->fetch())
                                                        echo "<option>{$ro['role_name']}</option>"; ?></select>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
                                        </form>
                                    </div>
                                </div>
                                <script>function editUser(id, n, r) { document.getElementById('eu_id').value = id; document.getElementById('eu_name').value = n; document.getElementById('eu_role').value = r; new bootstrap.Modal(document.getElementById('editUserModal')).show(); }</script>

                            <?php endif; ?>

<?php if ($page === 'settings' && $_SESSION['role'] === 'admin'): ?>
    <div class="card shadow" style="max-width: 900px; margin: auto;">
        <div class="card-header bg-dark text-white"><i class="fas fa-cogs me-2"></i> System Configuration</div>
        <div class="card-body">
<form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_settings">
                
                <h5 class="text-primary border-bottom pb-2">1. Institute Info & Branding</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="fw-bold small">Software Name</label>
                        <input type="text" name="settings[app_name]" class="form-control" value="<?= getSet('app_name') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold small">Institute Name</label>
                        <input type="text" name="settings[inst_name]" class="form-control" value="<?= getSet('inst_name') ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="fw-bold small">Address (For Slips/Certificates)</label>
                        <input type="text" name="settings[inst_address]" class="form-control" value="<?= getSet('inst_address') ?>">
                    </div>
<div class="col-md-6">
                        <label class="fw-bold small">Phone</label>
                        <input type="text" name="settings[inst_phone]" class="form-control" value="<?= getSet('inst_phone') ?>">
                    </div>
<div class="col-md-12 mt-3 p-3 bg-light border rounded">
                        <label class="fw-bold small text-primary mb-2"><i class="fas fa-image me-1"></i> Institute Logo Settings</label>
                        
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if(getSet('inst_logo')): ?>
                                <div class="bg-white p-1 border rounded shadow-sm">
                                    <img src="<?= htmlspecialchars(getSet('inst_logo')) ?>" alt="Current Logo" style="height: 60px; max-width: 150px; object-fit: contain;">
                                </div>
                            <?php endif; ?>
                        </div>

                        <label class="small fw-bold text-muted mb-1">1. Choose from existing files in 'uploads' folder:</label>
                        <select name="settings[inst_logo]" id="logo_selector" class="form-select form-select-sm mb-3">
                            <option value="logo.png" <?= (getSet('inst_logo') == 'logo.png') ? 'selected' : '' ?>>-- Default (logo.png from main folder) --</option>
                            <?php 
                            // SCAN THE UPLOADS FOLDER DIRECTLY
                            if (!is_dir('uploads')) { @mkdir('uploads', 0777, true); }
                            if (is_dir('uploads')) {
                                $files = scandir('uploads');
                                foreach ($files as $file) {
                                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                                        $val = 'uploads/' . $file;
                                        $sel = (getSet('inst_logo') == $val) ? 'selected' : '';
                                        echo "<option value=\"$val\" $sel>uploads/$file</option>";
                                    }
                                }
                            }
                            ?>
                        </select>

                        <label class="small fw-bold text-muted mb-1">2. OR Upload a new logo (will save to uploads folder):</label>
                        <input type="file" name="inst_logo_file" class="form-control form-control-sm mb-3" accept="image/*">
                        
                        <label class="small fw-bold text-muted mb-1">3. OR enter an external link (URL):</label>
                        <input type="text" class="form-control form-control-sm" placeholder="e.g. https://link.com/logo.png" 
                               onchange="document.getElementById('logo_selector').innerHTML += '<option value=\'' + this.value + '\' selected>' + this.value + '</option>';">
                    </div>
<div class="col-md-6 mt-2">
                        <label class="fw-bold small">Current Season / Main Event Title</label>
                        <input type="text" name="settings[inst_season]" class="form-control" value="<?= getSet('inst_season') ?>" placeholder="e.g. RAMZAN CLASS 1446 H.">
                    </div>
                <h5 class="text-primary border-bottom pb-2 mt-4">2. Certificates & Slips</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="fw-bold small">Certificate Main Title</label>
                        <input type="text" name="settings[cert_title]" class="form-control" value="<?= getSet('cert_title') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold small">Signature Text</label>
                        <input type="text" name="settings[cert_sign]" class="form-control" value="<?= getSet('cert_sign') ?>">
                    </div>
                </div>

 <h5 class="text-primary border-bottom pb-2 mt-4">3. Logic & Rules</h5>
                <div class="row g-3 mb-3">
<div class="col-md-4">
                        <label class="fw-bold small">Timezone</label>
                        <select name="settings[timezone]" class="form-select select2">
                            <?php foreach(timezone_identifiers_list() as $tz): ?>
                                <option value="<?= $tz ?>" <?= getSet('timezone') == $tz ? 'selected' : '' ?>><?= $tz ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small">Age Policy Cut-off (DD & MM)</label>
                        <div class="input-group">
                            <input type="number" name="settings[cutoff_day]" class="form-control" placeholder="Date (31)" value="<?= getSet('cutoff_day') ?: '31' ?>">
                            <input type="number" name="settings[cutoff_month]" class="form-control" placeholder="Month (05)" value="<?= getSet('cutoff_month') ?: '05' ?>">
                        </div>
                        <small class="text-muted">Auto Year: (<?= date('Y') ?>)</small>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small">Class Capacity</label>
                        <input type="number" name="settings[class_capacity]" class="form-control" value="<?= getSet('class_capacity') ?>">
                    </div>
                    
                    <div class="col-md-4">
                        <label class="fw-bold small">Session Start Date (For WhatsApp)</label>
                        <input type="text" name="settings[start_date]" class="form-control" value="<?= getSet('start_date') ?>" placeholder="e.g. 14 Feb 2026">
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small">Kids Maximum Age</label>
                        <input type="number" name="settings[kids_max_age]" class="form-control" value="<?= getSet('kids_max_age') ?: '9' ?>" placeholder="e.g. 9, 12">
                    </div>
                </div>
                <h5 class="text-primary border-bottom pb-2 mt-4">4. Dynamic Dropdowns</h5>
                <div class="mb-2">
                    <label class="fw-bold small">Expense Categories</label>
                    <textarea name="settings[opt_expense_cats]" class="form-control" rows="1"><?= getSet('opt_expense_cats') ?></textarea>
                </div>
                <div class="mb-2">
                    <label class="fw-bold small">Income Categories</label>
                    <textarea name="settings[opt_income_cats]" class="form-control" rows="1"><?= getSet('opt_income_cats') ?></textarea>
                </div>
<div class="mb-2">
                    <label class="fw-bold small">Prize Reasons</label>
                    <textarea name="settings[opt_prize_reasons]" class="form-control" rows="1"><?= getSet('opt_prize_reasons') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="fw-bold small">Hasanaat Card Types</label>
                    <textarea name="settings[opt_card_types]" class="form-control" rows="1"><?= getSet('opt_card_types') ?></textarea>
                </div>
                
                <div class="col-md-2 mb-3">
                        <label class="fw-bold small">Kids Min Age</label>
                        <input type="number" name="settings[kids_min_age]" class="form-control" value="<?= getSet('kids_min_age') ?: '5' ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="fw-bold small">Kids Max Age</label>
                        <input type="number" name="settings[kids_max_age]" class="form-control" value="<?= getSet('kids_max_age') ?: '9' ?>">
                    </div>
                <h5 class="text-primary border-bottom pb-2 mt-4">5. Session Logic (4-Way)</h5>
                <div class="alert alert-info py-1 small">Select sessions for each group. For Mixed Kids, select same session.</div>
                <?php $sessList = $pdo->query("SELECT Id, Name FROM enrollmentsession WHERE IsActive=1")->fetchAll(); ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="small fw-bold">Kids Boys (<?= getSet('kids_min_age') ?: '5' ?> - <?= getSet('kids_max_age') ?: '9' ?>)</label>
                        <select name="settings[logic_sess_kid_boys]" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach($sessList as $s): ?>
                                <option value="<?= $s['Id'] ?>" <?= getSet('logic_sess_kid_boys') == $s['Id'] ? 'selected' : '' ?>><?= $s['Name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold">Kids Girls (<?= getSet('kids_min_age') ?: '5' ?> - <?= getSet('kids_max_age') ?: '9' ?>)</label>
                        <select name="settings[logic_sess_kid_girls]" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach($sessList as $s): ?>
                                <option value="<?= $s['Id'] ?>" <?= getSet('logic_sess_kid_girls') == $s['Id'] ? 'selected' : '' ?>><?= $s['Name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold">Adult Boys (<?= (getSet('kids_max_age') ?: 9) + 1 ?>+)</label>
                        <select name="settings[logic_sess_adult_boys]" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach($sessList as $s): ?>
                                <option value="<?= $s['Id'] ?>" <?= getSet('logic_sess_adult_boys') == $s['Id'] ? 'selected' : '' ?>><?= $s['Name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold">Adult Girls (<?= (getSet('kids_max_age') ?: 9) + 1 ?>+)</label>
                        <select name="settings[logic_sess_adult_girls]" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach($sessList as $s): ?>
                                <option value="<?= $s['Id'] ?>" <?= getSet('logic_sess_adult_girls') == $s['Id'] ? 'selected' : '' ?>><?= $s['Name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
<h5 class="text-primary border-bottom pb-2 mt-4">Prize Budget & Denomination Settings</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="fw-bold small">Present Day Rate (Rs)</label>
                        <input type="number" name="settings[prize_rate_present]" class="form-control" value="<?= getSet('prize_rate_present') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold small">Late Day Rate (Rs)</label>
                        <input type="number" name="settings[prize_rate_late]" class="form-control" value="<?= getSet('prize_rate_late') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold small">Per % Rate (Rs)</label>
                        <input type="number" name="settings[prize_rate_pct]" class="form-control" value="<?= getSet('prize_rate_pct') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold small">Round Values To</label>
                        <input type="number" name="settings[prize_round_to]" class="form-control" value="<?= getSet('prize_round_to') ?>" placeholder="e.g. 10">
                    </div>
                    <div class="col-md-12">
                        <label class="fw-bold small">Currency Denominations (Comma Separated, Descending Order)</label>
                        <input type="text" name="settings[currency_denominations]" class="form-control" value="<?= getSet('currency_denominations') ?>">
                        <small class="text-muted">Example: 5000,1000,500,100,75,50,20,10</small>
                    </div>
                </div>
                <button class="btn btn-primary w-100 mt-4">Save Configuration</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($page === 'classes' && can('manage_classes')): ?>
                                <?php 
                                $activeSessions = getActiveSessions($pdo); 
                                // NAYA: Sab sessions load karo taake ghalti theek ki ja sakay
                                $allSessions = $pdo->query("SELECT * FROM enrollmentsession ORDER BY Id DESC")->fetchAll();
                                ?>

                                <div class="card shadow-sm border-0">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                                        <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-layer-group me-2"></i>Manage Classes</h5>
                                        <?php if (!empty($activeSessions)): ?>
                                        <button class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addClassModal">
                                            <i class="fas fa-plus me-1"></i> Add Class
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <?php if(empty($activeSessions)): ?>
                                            <div class="alert alert-danger shadow-sm border-danger">
                                                <h5 class="fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>No Active Session Found</h5>
                                                <p class="mb-0">Aap koi class tab tak nahi dekh saktay jab tak koi Session active na ho.</p>
                                            </div>
                                        <?php else: ?>
                                            <table class="table table-striped datatable-basic align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Session</th>
                                                        <th>Name</th>
                                                        <th>Type</th>
                                                        <th>Assigned Teachers</th>
                                                        <th>Age Range</th>
                                                        <th>WhatsApp</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    // NAYA: Active Session ki classes + Orphan classes (jin ka koi session nahi) dono dikhayen
                                                    $clRaw = $pdo->query("
                                                        SELECT c.*, 
                                                               es.Name as SessionName,
                                                               GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') as TeacherNames,
                                                               GROUP_CONCAT(DISTINCT u.id) as TeacherIds 
                                                        FROM classmanifest c 
                                                        LEFT JOIN enrollmentsession es ON c.session_id = es.Id 
                                                        LEFT JOIN class_teachers ct ON c.Class = ct.class_id 
                                                        LEFT JOIN users u ON ct.user_id = u.id 
                                                        WHERE c.session_id IS NULL OR c.session_id = 0 OR es.IsActive = 1
                                                        GROUP BY c.Class 
                                                        ORDER BY c.EnrollmentType, c.ClassName
                                                    ")->fetchAll();

                                                    foreach ($clRaw as $c): ?>
                                                        <tr>
                                                            <td><?php echo $c['Class']; ?></td>
                                                            <td>
                                                                <?php if(empty($c['session_id']) || $c['session_id'] == 0): ?>
                                                                    <span class="badge bg-danger shadow-sm">No Session</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-info text-dark shadow-sm"><?php echo $c['SessionName']; ?></span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="fw-bold text-dark"><?php echo $c['ClassName']; ?></td>
                                                            <td>
                                                                <?php echo ($c['EnrollmentType'] == 'BoysClass') ? '<span class="badge bg-primary">Boys</span>' : '<span class="badge bg-danger">Girls</span>'; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($c['TeacherNames']): ?>
                                                                    <i class="fas fa-chalkboard-teacher text-muted small"></i> <?php echo $c['TeacherNames']; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted small">- No Teacher -</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo $c['MinAge'] . ' - ' . $c['MaxAge']; ?> yrs</td>
                                                            <td>
    <?php if(!empty($c['WhatsappLink'])): ?>
        <a href="<?= $c['WhatsappLink'] ?>" target="_blank" class="text-success small"><i class="fab fa-whatsapp"></i> Link</a>
    <?php else: ?>
        <span class="text-muted small">None</span>
    <?php endif; ?>
</td>
<td>
    <?php $cJson = htmlspecialchars(json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
    <button class="btn btn-sm btn-info py-0" onclick="editClass(<?= $cJson ?>)">Edit</button>

                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete Class?');">
                                                                    <input type="hidden" name="action" value="delete_class">
                                                                    <input type="hidden" name="class_id" value="<?php echo $c['Class']; ?>">
                                                                    <button class="btn btn-sm btn-danger shadow-sm">Del</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($activeSessions)): ?>
<div class="modal fade" id="addClassModal">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-primary">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Class</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" name="action" value="add_class">
                <div class="mb-3">
                    <label>Session</label>
                    <select name="session_id" class="form-select" required>
                        <option value="">Select Session</option>
                        <?php foreach($allSessions as $s) echo "<option value='{$s['Id']}'>{$s['Name']}</option>"; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label>Class Name</label>
                    <input type="text" name="class_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Type</label>
                    <select name="type" class="form-select">
                        <option value="BoysClass">Boys</option>
                        <option value="GirlsClass">Girls</option>
                    </select>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label>Min Age</label>
                        <input type="number" name="min_age" class="form-control" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label>Max Age</label>
                        <input type="number" name="max_age" class="form-control" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label><i class="fab fa-whatsapp text-success"></i> WhatsApp Group Link</label>
                    <input type="url" name="whatsapp_link" class="form-control" placeholder="https://chat.whatsapp.com/...">
                </div>
                <div class="mb-3">
                    <label>Assign Teachers</label>
                    <select name="assigned_teachers[]" class="form-select" multiple size="3">
                        <?php
                        $users = $pdo->query("SELECT id, name FROM users WHERE role='teacher' OR role='admin' ORDER BY name");
                        foreach ($users as $u) echo "<option value='{$u['id']}'>{$u['name']}</option>";
                        ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary fw-bold">Save Class</button></div>
        </form>
    </div>
</div>

 <div class="modal fade" id="editClassModal">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-info">
            <div class="modal-header bg-info text-dark">
                <h5 class="modal-title">Edit Class</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" name="action" value="edit_class">
                <input type="hidden" name="class_id" id="ec_id">
                <div class="mb-3">
                    <label>Session</label>
                    <select name="session_id" id="ec_sess" class="form-select" required>
                        <?php foreach ($allSessions as $s) echo "<option value='{$s['Id']}'>{$s['Name']}</option>"; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label>Class Name</label>
                    <input type="text" name="class_name" id="ec_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Type</label>
                    <select name="type" id="ec_type" class="form-select" required>
                        <option value="BoysClass">Boys</option>
                        <option value="GirlsClass">Girls</option>
                    </select>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label>Min Age</label>
                        <input type="number" name="min_age" id="ec_min_age" class="form-control" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label>Max Age</label>
                        <input type="number" name="max_age" id="ec_max_age" class="form-control" required>
                    </div>
                </div>
<div class="mt-2">
    <label class="form-label small">WhatsApp Group Link</label>
    <input type="url" name="whatsapp_link" id="edit_class_whatsapp" class="form-control form-control-sm" placeholder="https://...">
</div>

                                                
                                                <div class="mb-3">
                                                    <label>Assign Teachers</label>
                                                    <select name="assigned_teachers[]" id="ec_teachers" class="form-select" multiple size="3">
                                                        <?php
                                                        $users = $pdo->query("SELECT id, name FROM users");
                                                        foreach ($users as $u) echo "<option value='{$u['id']}'>{$u['name']}</option>";
                                                        ?>
                                                    </select>
                                                </div>
                                            
                                            <div class="modal-footer"><button class="btn btn-info fw-bold">Update Class</button></div>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <script>
                                   function editClass(data) {
    document.getElementById('ec_id').value = data.Class;
    document.getElementById('ec_name').value = data.ClassName;
    document.getElementById('ec_type').value = data.EnrollmentType;
    document.getElementById('ec_min_age').value = data.MinAge;
    document.getElementById('ec_max_age').value = data.MaxAge;
    if (document.getElementById('edit_class_whatsapp')) {
        document.getElementById('edit_class_whatsapp').value = data.WhatsappLink;
    }
    
    if (data.session_id) {
        let sessDropdown = document.getElementById('ec_sess');
        if (sessDropdown) sessDropdown.value = data.session_id;
    }

    var select = document.getElementById('ec_teachers');
    if (select) {
        for (var i = 0; i < select.options.length; i++) { select.options[i].selected = false; }
        if (data.TeacherIds) {
            var ids = String(data.TeacherIds).split(',');
            for (var i = 0; i < select.options.length; i++) {
                if (ids.includes(select.options[i].value)) select.options[i].selected = true;
            }
        }
    }
    new bootstrap.Modal(document.getElementById('editClassModal')).show();
}
                                </script>
                            <?php endif; ?>



                            <?php if ($page === 'sessions' && can('manage_sessions')): ?>

                                <div class="card">
                                    <div class="card-header">Manage Sessions 
                                        <form method="POST" class="d-inline float-end">
    <input type="hidden" name="action" value="add_session">
    <input type="text" name="session_name" class="form-control form-control-sm d-inline w-auto" placeholder="Name" required>
    <input type="text" name="timings" class="form-control form-control-sm d-inline w-auto" placeholder="Timings (e.g. 5:45-7:15 PM)">
    <button class="btn btn-sm btn-success">+</button>
</form>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Name</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $ses = $pdo->query("SELECT * FROM enrollmentsession ORDER BY Id DESC");
                                                while ($s = $ses->fetch()): ?>
<tr>
                            <td><?php echo $s['Id']; ?></td>
                            <td><?php echo $s['Name']; ?></td>
                            <td><?php echo $s['IsActive'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?>
                            </td>
                            <td><?php echo $s['Timings'] ? htmlspecialchars($s['Timings']) : '-'; ?></td>
                            <td>
                                                            <form method="POST" class="d-inline"><input type="hidden" name="action"
                                                                    value="toggle_session"><input type="hidden" name="session_id"
                                                                    value="<?php echo $s['Id']; ?>"><?php if ($s['IsActive']): ?><button
                                                                        class="btn btn-sm btn-outline-warning">Deactivate</button><?php else: ?><button
                                                                        class="btn btn-sm btn-primary">Activate</button><?php endif; ?>
                                                            </form> <button class="btn btn-sm btn-info"
                                                                onclick="editSession(<?= $s['Id']; ?>, '<?= addslashes($s['Name']); ?>', '<?= addslashes($s['Timings']); ?>')">Edit</button>
<form method="POST" class="d-inline" onsubmit="return confirm('Delete Session?');">
    <input type="hidden" name="action" value="delete_session">
    <input type="hidden" name="session_id" value="<?php echo $s['Id']; ?>">
    <button class="btn btn-sm btn-danger">Del</button>
</form>                                                            <!-- NEW: Bulk Print ID Cards -->
                                                            <a href="?page=bulk_print_ids&session_id=<?php echo $s['Id']; ?>"
                                                                target="_blank" class="btn btn-sm btn-secondary"
                                                                title="Bulk Print IDs"><i class="fas fa-id-card"></i> IDs</a>
                                                        </td>
                                                    </tr><?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

<div class="modal fade" id="editSessionModal">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Session</h5></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_session">
                <input type="hidden" name="session_id" id="es_id">
                <div class="mb-2"><label>Name</label><input type="text" name="session_name" id="es_name" class="form-control" required></div>
                <div class="mb-2"><label>Timings</label><input type="text" name="timings" id="es_timings" class="form-control" placeholder="e.g. 5:45-7:15 PM"></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
        </form>
    </div>
</div>
<script>
function editSession(id, name, timings) { 
    document.getElementById('es_id').value = id; 
    document.getElementById('es_name').value = name; 
    document.getElementById('es_timings').value = timings || ''; // Timings fill karega
    new bootstrap.Modal(document.getElementById('editSessionModal')).show(); 
}
</script>
                            <?php endif; ?>



                            <?php if ($page === 'reports' && can('view_reports')): ?>

                                <div class="card">
                                    <div class="card-header">Merit Lists</div>
                                    <div class="card-body">
                                        <table id="reportsTable" class="table table-bordered table-striped datatable-export">
                                            <thead>
                                                <tr>
                                                    <th>Rank</th>
                                                    <th>Name</th>
                                                    <th>Class</th>
                                                    <th>Exam</th>
                                                    <th>Score</th>
                                                    <th>%</th>
                                                </tr>
                                            </thead>
                                            <tfoot>
                                                <tr>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Rank"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Name"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Class"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Exam"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="Score"></th>
                                                    <th><input type="text" class="form-control form-control-sm" placeholder="%"></th>
                                                </tr>
                                            </tfoot>
                                            <tbody>
                                                <?php if (!empty($activeSessions)) {
                                                    $sids = implode(',', array_column($activeSessions, 'Id'));
                                                    $sql = "SELECT s.Name, c.ClassName, ex.name as Exam, er.obtained_marks, ex.total_marks, (er.obtained_marks/ex.total_marks*100) as pct, RANK() OVER (PARTITION BY ex.id ORDER BY er.obtained_marks DESC) as rk FROM exam_results er JOIN exams ex ON er.exam_id=ex.id JOIN enrollment e ON er.enrollment_id=e.id JOIN students s ON e.StudentId=s.Id JOIN classmanifest c ON e.Class=c.Class WHERE ex.session_id IN ($sids)";
                                                    $stmt = $pdo->prepare($sql);
                                                    $stmt->execute();
                                                    while ($r = $stmt->fetch()) {
                                                        echo "<tr><td><span class='badge bg-warning text-dark'>{$r['rk']}</span></td><td>{$r['Name']}</td><td>{$r['ClassName']}</td><td>{$r['Exam']}</td><td>{$r['obtained_marks']}/{$r['total_marks']}</td><td>" . number_format($r['pct'], 1) . "%</td></tr>";
                                                    }
                                                } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            <?php endif; ?>

                            <?php if ($page === 'attendance' && can('attendance_take')): ?>

                                <div class="card">
                                    <div class="card-header">
                                        <form method="GET" class="row g-2 align-items-center"><input type="hidden" name="page"
                                                value="attendance">
                                            <div class="col-auto"><input type="date" name="date" id="att_date"
                                                    class="form-control" value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>">
                                            </div>
                                            <div class="col-auto"><span class="badge bg-secondary" id="day_badge">Today</span>
                                            </div>
                                            <div class="col-auto"><select name="class_id" class="form-select"
                                                    onchange="this.form.submit()">
                                                    <option value="">Select Class...</option>
                                                    <?php
                                                    // REPLACED LOGIC FOR CLASS SELECTION IN ATTENDANCE
                                                    $userId = $_SESSION['user_id'];
                                                    $userRole = $_SESSION['role'];

                                                    // If Admin/Manager show all, else show only assigned classes
if ($userRole === 'admin' || $userRole === 'manager') {
                                                        $cl = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.ClassName");
                                                    } else {
                                                        // Specific Teacher (UPDATED for Multi-Teacher)
                                                        $cl = $pdo->prepare("
        SELECT c.* FROM classmanifest c 
        JOIN class_teachers ct ON c.Class = ct.class_id 
        JOIN enrollmentsession s ON c.session_id = s.Id 
        WHERE ct.user_id = ? AND s.IsActive = 1 
        ORDER BY c.ClassName
    ");
                                                    
                                                        $cl->execute([$userId]);
                                                    }

                                                    // ... Loop through $cl to show options ...
                                                    $shownCls = [];
                                                    foreach ($cl as $c) {
                                                        if (in_array($c['Class'], $shownCls))
                                                            continue;
                                                        $shownCls[] = $c['Class'];
                                                        $sel = ($_GET['class_id'] ?? '') == $c['Class'] ? 'selected' : '';
                                                        echo "<option value='{$c['Class']}' $sel>" . ($c['EnrollmentType'] == 'BoysClass' ? '[B]' : '[G]') . " {$c['ClassName']}</option>";
                                                    } ?>
                                                </select></div>
                                        </form>
                                    </div>
                                    <div class="card-body">
                                        <script>document.getElementById('att_date').addEventListener('change', function () { const d = new Date(this.value); document.getElementById('day_badge').innerText = d.toLocaleDateString('en-US', { weekday: 'long' }); }); document.dispatchEvent(new Event('DOMContentLoaded'));</script>
                                        <?php if (!empty($_GET['class_id']) && !empty($activeSessions)): ?>
                                            <form method="POST"><input type="hidden" name="action" value="attendance"><input
                                                    type="hidden" name="date" value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>">
                                                <table class="table table-bordered table-sm">
                                                    <thead class="table-light">
                                                        <tr>
<th>ID</th>
                        <th>Name</th>
                        <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $sess_ids = implode(',', array_column($activeSessions, 'Id'));
                                                        $sql = "SELECT e.Id as eid, s.Name, s.Id as sid 
        FROM enrollment e 
        JOIN students s ON e.StudentId = s.Id 
        WHERE e.Class = ? 
        AND e.EnrollmentSessionId IN ($sess_ids) 
        AND e.IsActive = 1 
        GROUP BY s.Id 
        ORDER BY s.Name ASC";
                                                        $stmt = $pdo->prepare($sql);
                                                        $stmt->execute([$_GET['class_id']]);
                                                        while ($r = $stmt->fetch()):
                                                            $ex = $pdo->prepare("SELECT status FROM attendance WHERE enrollment_id=? AND date=?");
                                                            $ex->execute([$r['eid'], $_GET['date'] ?? date('Y-m-d')]);
                                                            $st = $ex->fetchColumn() ?: 'Present'; ?>
                                                            <tr>
                                                                <td><?php echo $r['sid']; ?></td>
                                                                <td><?php echo $r['Name']; ?></td>
                                                                <td><label><input type="radio" name="status[<?php echo $r['eid']; ?>]"
                                                                            value="Present" <?php echo $st == 'Present' ? 'checked' : ''; ?>>
                                                                        Present</label> &nbsp; <label><input type="radio"
                                                                            name="status[<?php echo $r['eid']; ?>]" value="Absent" <?php echo $st == 'Absent' ? 'checked' : ''; ?>> Absent</label>
                                                                    &nbsp;
                                                                    <label><input type="radio" name="status[<?php echo $r['eid']; ?>]"
                                                                            value="Late" <?php echo $st == 'Late' ? 'checked' : ''; ?>>
                                                                        Late</label> &nbsp; <label><input type="radio"
                                                                            name="status[<?php echo $r['eid']; ?>]" value="Leave" <?php echo $st == 'Leave' ? 'checked' : ''; ?>> Leave</label>
                                                                </td>
                                                            </tr><?php endwhile; ?>
                                                    </tbody>
                                                </table><button class="btn btn-success mt-2">Save Attendance</button>
                                            </form>

                                            <form method="POST" class="d-inline"
                                                onsubmit="return confirm('⚠️ Are you sure you want to CLEAR all attendance for this date? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_attendance">
                                                <input type="hidden" name="class_id" value="<?php echo $_GET['class_id']; ?>">
                                                <input type="hidden" name="date"
                                                    value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>">
                                                <button class="btn btn-outline-danger mt-2 ms-2"><i
                                                        class="fas fa-trash-alt me-1"></i> Clear Attendance</button>
                                            </form>

                                        <?php else:
                                            echo "<p class='text-muted'>Select a Class.</p>";
                                        endif; ?>
                                    </div>
                                </div>

                            <?php endif; ?>

 <?php if ($page === 'certificates'): ?>
                                <?php
                                $rawStudents = $pdo->query("SELECT s.Id, s.Name, s.Paternity, e.Class, e.EnrollmentSessionId FROM enrollment e JOIN students s ON e.StudentId = s.Id WHERE e.IsActive = 1 AND e.EnrollmentSessionId IN (SELECT Id FROM enrollmentsession WHERE IsActive=1) ORDER BY s.Name")->fetchAll();
                                
                                // FIX: Remove Duplicate Classes so Javascript Filtering works flawlessly
                                $rawClassesRaw = $pdo->query("SELECT c.Class, c.ClassName, c.EnrollmentType FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.EnrollmentType, c.ClassName")->fetchAll();
                                $rawClasses = []; $seenCls = [];
                                foreach ($rawClassesRaw as $c) {
                                    if (!in_array($c['Class'], $seenCls)) {
                                        $seenCls[] = $c['Class'];
                                        $rawClasses[] = $c;
                                    }
                                }
// FIX: Read directly from the 'uploads' folder so user can just put files via cPanel
$existingBgs = [];
if (!is_dir('uploads')) { @mkdir('uploads', 0777, true); }
if (is_dir('uploads')) {
    $files = scandir('uploads');
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $existingBgs[] = $file;
        }
    }
}
                                ?>

                                <div class="row">
                                    <div class="col-md-4 no-print">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-dark text-white">
                                                <h6 class="mb-0"><i class="fas fa-certificate me-2"></i>Generate Certificate</h6>
                                            </div>
                                            <div class="card-body">
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="generate_certificate">

    <div class="mb-2">
        <label class="small fw-bold text-muted">1. Select Session</label>
        <select name="session_id" id="cert_session" class="form-select form-select-sm" onchange="filterStudents()">
            <?php foreach ($activeSessions as $sess): ?>
                <option value="<?= $sess['Id'] ?>"><?= $sess['Name'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>

<div class="mb-2">
        <label class="small fw-bold text-muted">2. Select Class</label>
        <select id="cert_class" class="form-select form-select-sm" onchange="filterStudents()">
            <option value="all">-- All Classes --</option>
            <?php foreach ($rawClasses as $c):
                $lbl = ($c['EnrollmentType'] == 'BoysClass') ? '👦 [BOYS]' : '👧 [GIRLS]';
                ?>
                <option value="<?= $c['Class'] ?>"><?= $lbl . ' ' . $c['ClassName'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-2">
        <label class="small fw-bold text-muted">3. Select Student</label>
        <select name="student_id" id="cert_student" class="form-select form-select-sm" required>
            <option value="">-- Select Student --</option>
        </select>
    </div>

    <hr class="my-2">

    <div class="mb-2">
        <label class="small fw-bold">Certificate Type / Title</label>
        <select name="type" class="form-select form-select-sm" onchange="updateCertTitle(this.value)">
            <option value="Custom">Custom / General</option>
            <option value="Position">Position Holder</option>
            <option value="Attendance">100% Attendance</option>
        </select>
    </div>

    <div class="mb-2">
        <input type="text" name="title" id="cert_title" class="form-control form-control-sm" placeholder="e.g. Certificate of Appreciation" required>
    </div>

    <div class="mb-2">
        <textarea name="description" id="cert_desc" class="form-control form-control-sm" rows="3" placeholder="Description..."></textarea>
    </div>


    <div class="mb-2 p-2 border rounded bg-light">
        <label class="small fw-bold text-primary"><i class="fas fa-edit"></i> Text & Style Overrides</label>
        <p class="small text-muted mb-2" style="font-size: 11px; line-height: 1.2;">
            Leave blank to use defaults. Type a new name to change it just for this certificate, or type <b>HIDE</b> to completely remove it from the certificate.
        </p>
        <p class="small text-muted mb-2" style="font-size: 11px; line-height: 1.2;">
            <b>Tip:</b> The "Main Poster Heading" box controls the big beautiful gradient box. You can put anything in it!
        </p>
        
        <div class="mb-2 pb-2 border-bottom">
            <label class="small fw-bold text-dark" style="font-size: 11px;">1. Main Poster Heading (Gradient Box):</label>
            <input type="text" id="override_season" name="custom_season" class="form-control form-control-sm mb-1" placeholder="Season (e.g. RAMZAN CLASS 1447 H)">
            
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-xs btn-outline-success" style="font-size: 10px;" onclick="shiftToPoster('session')">
                    <i class="fas fa-arrow-up"></i> Put Session Here
                </button>
                <button type="button" class="btn btn-xs btn-outline-primary" style="font-size: 10px;" onclick="shiftToPoster('class')">
                    <i class="fas fa-arrow-up"></i> Put Class Here
                </button>
                <button type="button" class="btn btn-xs btn-outline-danger" style="font-size: 10px;" onclick="document.getElementById('override_season').value='HIDE'">
                    <i class="fas fa-eye-slash"></i> Hide Box
                </button>
            </div>
        </div>

        <div class="mb-2">
            <label class="small fw-bold text-dark" style="font-size: 11px;">2. Sub-Heading 1 (Colored Text):</label>
            <input type="text" id="override_session" name="custom_session" class="form-control form-control-sm" placeholder="Session (e.g. Morning Shift)">
        </div>

        <div class="mb-1">
            <label class="small fw-bold text-dark" style="font-size: 11px;">3. Sub-Heading 2 (Underlined Text):</label>
            <input type="text" id="override_class" name="custom_class" class="form-control form-control-sm" placeholder="Class (e.g. Boys Senior Section)">
        </div>

        <script>
            function shiftToPoster(type) {
                if(type === 'session') {
                    let sess = document.getElementById('cert_session');
                    if(sess.value) {
                        document.getElementById('override_season').value = sess.options[sess.selectedIndex].text;
                        document.getElementById('override_session').value = 'HIDE';
                    } else {
                        alert("Please select a Session from the top dropdown first!");
                    }
                } else if (type === 'class') {
                    let cls = document.getElementById('cert_class');
                    if(cls.value && cls.value !== 'all') {
                        // Emoji aur tags ko remove kar ke saaf text nikalta hai
                        let clsText = cls.options[cls.selectedIndex].text.replace(/👦 \[BOYS\] |👧 \[GIRLS\] /g, '');
                        document.getElementById('override_season').value = clsText;
                        document.getElementById('override_class').value = 'HIDE';
                    } else {
                        alert("Please select a specific Class from the top dropdown first!");
                    }
                }
            }
        </script>
    </div>

    <div class="mb-2 p-2 border bg-light rounded">
        <label class="small fw-bold text-primary"><i class="fas fa-image"></i> Background Image</label>
        <select name="existing_bg" class="form-select form-select-sm mb-1">
            <option value="">-- Blank / No Image --</option>
            <?php foreach($existingBgs as $bg): ?>
                <option value="<?= $bg ?>">Use: <?= $bg ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted d-block mb-1">Or upload a new one:</small>
        <input type="file" name="new_bg_image" class="form-control form-control-sm" accept="image/*">
    </div>
<div class="mb-2 p-2 border bg-light rounded">
        <label class="small fw-bold text-primary"><i class="fas fa-shield-alt"></i> Certificate Logo (Optional)</label>
        <select name="existing_logo" class="form-select form-select-sm mb-1">
            <option value="">-- Use Default Universal Logo --</option>
            <?php foreach($existingBgs as $img): ?>
                <option value="<?= $img ?>">Use: <?= $img ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted d-block mb-1">Or upload a new logo:</small>
        <input type="file" name="new_cert_logo" class="form-control form-control-sm" accept="image/*">
    </div>
    <div class="mb-2">
        <label class="small fw-bold text-primary"><i class="fas fa-font"></i> Font Style</label>
        <select name="font_family" id="font_preset" class="form-select form-select-sm mb-1" onchange="checkCustomFont()">
            <option value="'Cinzel', serif">Cinzel (Classic)</option>
            <option value="'Roboto', sans-serif">Roboto (Modern)</option>
            <option value="'Pinyon Script', cursive">Pinyon Script (Cursive)</option>
            <option value="Arial, sans-serif">Arial</option>
            <option value="custom" class="fw-bold">-- Type Google Font Name --</option>
        </select>
        <input type="text" name="custom_font" id="custom_font_input" class="form-control form-control-sm" placeholder="e.g. Oswald, Montserrat" style="display:none;">
    </div>

    <div class="mb-3 p-2 border rounded">
        <label class="small fw-bold text-primary d-block mb-2"><i class="fas fa-palette"></i> Colors</label>
        <div class="row g-2">
            <div class="col-6"><label class="small" style="font-size:10px;">Sub-Title</label><input type="color" name="color_title" class="form-control form-control-color w-100 p-0" value="#6b4c3a"></div>
            <div class="col-6"><label class="small" style="font-size:10px;">Student Name</label><input type="color" name="color_name" class="form-control form-control-color w-100 p-0" value="#000000"></div>
            <div class="col-6"><label class="small" style="font-size:10px;">Body Text</label><input type="color" name="text_color" class="form-control form-control-color w-100 p-0" value="#333333"></div>
            <div class="col-6"><label class="small" style="font-size:10px;">Badge BG</label><input type="color" name="bg_badge" class="form-control form-control-color w-100 p-0" value="#5A3A22"></div>
            <div class="col-6"><label class="small" style="font-size:10px;">Badge Text</label><input type="color" name="color_badge" class="form-control form-control-color w-100 p-0" value="#FFFFFF"></div>
        </div>
    </div>

    <div class="mb-2">
        <label class="small fw-bold">Issued Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
    </div>

    <button class="btn btn-primary btn-sm w-100">
        <i class="fas fa-print me-1"></i> Generate & Save
    </button>
</form>
<script>
    function checkCustomFont() {
        document.getElementById('custom_font_input').style.display = 
            (document.getElementById('font_preset').value === 'custom') ? 'block' : 'none';
    }
</script> 
</div>
                                        </div>
                                    </div>

                                    <div class="col-md-8">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0">Issued Certificates History</h6>
                                                <button onclick="window.print()"
                                                    class="btn btn-sm btn-outline-secondary no-print"><i
                                                        class="fas fa-print"></i> Print List</button>
                                            </div>
                                            <div class="card-body p-0">
                                                <div class="table-responsive">
                                                    <table id="certificatesHistoryTable" class="table table-striped table-hover mb-0"
                                                        style="font-size: 0.9rem;">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Date</th>
                                                                <th>Student</th>
                                                                <th>Title</th>
                                                                <th class="no-print">Action</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody></tbody>
                                                        <tfoot>
                                                            <tr>
                                                                <th><input type="text" class="form-control form-control-sm" placeholder="Date"></th>
                                                                <th><input type="text" class="form-control form-control-sm" placeholder="Student"></th>
                                                                <th><input type="text" class="form-control form-control-sm" placeholder="Title"></th>
                                                                <th></th>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                    const allStudents = <?php echo json_encode($rawStudents); ?>;

                                    function filterStudents() {
                                        const sessId = document.getElementById('cert_session').value;
                                        const classId = document.getElementById('cert_class').value;
                                        const studentSelect = document.getElementById('cert_student');

                                        studentSelect.innerHTML = '<option value="">-- Select Student --</option>';

                                        const filtered = allStudents.filter(s => {
                                            const matchSess = (s.EnrollmentSessionId == sessId);
                                            const matchClass = (classId === 'all') || (s.Class == classId);
                                            return matchSess && matchClass;
                                        });

                                        if (filtered.length === 0) {
                                            studentSelect.innerHTML += '<option disabled>No students found in this Class/Session</option>';
                                        } else {
                                            filtered.forEach(s => {
                                                const opt = document.createElement('option');
                                                opt.value = s.Id;
                                                opt.text = s.Name + " s/o " + s.Paternity; // Display Father Name in Dropdown
                                                studentSelect.appendChild(opt);
                                            });
                                        }
                                    }

                                    function updateCertTitle(type) {
                                        const titleMap = {
                                            'Position': 'Certificate of Achievement',
                                            'Attendance': 'Certificate of 100% Attendance',
                                            'Hifz': 'Hifz Completion Certificate',
                                            'Nazra': 'Nazra Completion Award',
                                            'Custom': ''
                                        };
                                        const descMap = {
                                            'Position': 'This certificate is proudly presented to the student for achieving a top position in the annual exams.',
                                            'Attendance': 'For maintaining 100% punctuality and attendance throughout the academic session.',
                                            'Hifz': 'Awarded for the successful completion of Hifz-ul-Quran with Tajweed.',
                                            'Nazra': 'Awarded for completing the recitation of the Holy Quran (Nazra).',
                                            'Custom': ''
                                        };

                                        document.getElementById('cert_title').value = titleMap[type] || '';
                                        document.getElementById('cert_desc').value = descMap[type] || '';
                                    }

                                    window.addEventListener('DOMContentLoaded', filterStudents);
                                </script>
                            <?php endif; ?>


                            <?php if ($page === 'attendance_report' && can('view_attendance_report')): ?>
                                <?php
                                $classId = $_GET['class_id'] ?? '';

                                // FIX: Use Date Range instead of Month
                                $startDate = $_GET['start_date'] ?? date('Y-m-01'); // Default: 1st of current month
                                $endDate = $_GET['end_date'] ?? date('Y-m-d');     // Default: Today
                        
                                // Generate array of dates for table headers
                                $dateRange = [];
                                $current = strtotime($startDate);
                                $last = strtotime($endDate);
                                while ($current <= $last) {
                                    $dateRange[] = date('Y-m-d', $current);
                                    $current = strtotime('+1 day', $current);
                                }

                                // Filter Unique Classes
                                $clRaw = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.EnrollmentType, c.ClassName")->fetchAll();
                                $classes = [];
                                $seenCls = [];
                                foreach ($clRaw as $c) {
                                    if (!in_array($c['Class'], $seenCls)) {
                                        $seenCls[] = $c['Class'];
                                        $classes[] = $c;
                                    }
                                }

                                $studentsList = [];
                                if ($classId) {
                                    $activeSessions = getActiveSessions($pdo);
                                    $sids = implode(',', array_column($activeSessions, 'Id'));

                                    if ($sids) {
                                        $sqlSt = "SELECT e.Id as EnrId, s.Name 
                      FROM enrollment e 
                      JOIN students s ON e.StudentId = s.Id 
                      WHERE e.Class = ? AND e.EnrollmentSessionId IN ($sids) AND e.IsActive = 1
                      ORDER BY s.Name";
                                        $stmt = $pdo->prepare($sqlSt);
                                        $stmt->execute([$classId]);
                                        $studentsList = $stmt->fetchAll();
                                    }
                                }
                                ?>


                                <div class="card shadow">
                                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0 text-primary"><i class="fas fa-chart-line me-2"></i>Attendance Report
                                        </h5>

                                        <?php if (can('delete_attendance')): ?>
                                            <button class="btn btn-danger btn-sm" data-bs-toggle="modal"
                                                data-bs-target="#pruneAttModal">
                                                <i class="fas fa-trash-alt me-1"></i> Prune / Clear Data
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">

                                        <form method="GET" class="row g-3 mb-4 bg-light p-3 rounded align-items-end">
                                            <input type="hidden" name="page" value="attendance_report">

                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">Select Class</label>
                                                <select name="class_id" class="form-select" onchange="this.form.submit()">
                                                    <option value="">-- Select Class --</option>
                                                    <?php foreach ($classes as $c): ?>
                                                        <option value="<?php echo $c['Class']; ?>" <?php echo $classId == $c['Class'] ? 'selected' : ''; ?>>
                                                            <?php echo ($c['EnrollmentType'] == 'BoysClass' ? '[B]' : '[G]') . ' ' . $c['ClassName']; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label fw-bold">From</label>
                                                <input type="date" name="start_date" class="form-control"
                                                    value="<?php echo $startDate; ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label fw-bold">To</label>
                                                <input type="date" name="end_date" class="form-control"
                                                    value="<?php echo $endDate; ?>">
                                            </div>

                                            <div class="col-md-2 text-end">
                                                <button class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>
                                                    Filter</button>
                                            </div>
                                        </form>
                                        <?php if ($classId && empty($studentsList)): ?>
                                            <div class="alert alert-warning">No active students found in this class.</div>
                                        <?php elseif ($classId): ?>

                                            <div class="table-responsive">
                                                <table id="attendanceReportTable" class="table table-bordered table-sm text-center table-hover"
                                                    style="font-size: 0.8rem;">
                                                    <thead class="table-dark">
                                                        <tr>
                                                            <th class="text-start" style="min-width: 150px;">Student Name</th>
                                                            <?php foreach ($dateRange as $date):
                                                                $dayLabel = date('d', strtotime($date));
                                                                $fullLabel = date('M d', strtotime($date));
                                                                ?>
                                                                <th style="width: 35px; font-size: 0.75rem;"
                                                                    class="text-muted bg-light"
                                                                    title="<?php echo $fullLabel; ?>">
                                                                    <?php echo $dayLabel; ?><br>
                                                                    <small style="font-size: 0.6rem; opacity: 0.7;"><?php echo substr(date('D', strtotime($date)), 0, 1); ?></small>
                                                                </th>
                                                            <?php endforeach; ?>
                                                            <th class="bg-success text-white" title="Present">P</th>
                                                            <th class="bg-danger text-white" title="Absent">A</th>
                                                            <th class="bg-warning text-dark" title="Leave">L</th>
                                                            <th class="bg-info text-dark" title="Percentage">%</th>
                                                            <th class="bg-primary text-white" title="Full Attendance Prize Eligible">FA</th>
                                                            <th class="bg-primary text-white" title="ZB">ZB</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                    </table>
                                            </div>

                                            <div class="mt-3 small text-muted">
                                                <span
                                                    class="badge bg-success-subtle text-success border border-success me-2">P</span>
                                                Present
                                                <span
                                                    class="badge bg-danger-subtle text-danger border border-danger me-2 ms-3">A</span>
                                                Absent
                                                <span
                                                    class="badge bg-warning-subtle text-warning border border-warning me-2 ms-3">Lt</span>
                                                Late
                                                <span class="badge bg-info-subtle text-info border border-info me-2 ms-3">Lv</span>
                                                Leave
                                                                                                <span class="badge bg-info-subtle text-info border border-info me-2 ms-3">FA</span>
                                                Full Attendance Prize Eligible: No late marks and attended all marked classes.
                                                                                                <span class="badge bg-info-subtle text-info border border-info me-2 ms-3">ZB</span>
                                                Ziyarat Ballot Eligible: Attended all classes (no absents), late is allowed.
                                            </div>

                                        <?php else: ?>
                                            <div class="text-center py-5 text-muted">
                                                <i class="fas fa-chalkboard-teacher fa-3x mb-3"></i>
                                                <p>Please select a Class and Month to generate the report.</p>
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                                <div class="modal fade" id="pruneAttModal">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content border-danger">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Clear
                                                    Attendance</h5>
                                                <button type="button" class="btn-close btn-close-white"
                                                    data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-warning">
                                                    <strong>Warning:</strong> This will PERMANENTLY delete attendance records
                                                    for the selected range. This cannot be undone.
                                                </div>
                                                <input type="hidden" name="action" value="prune_attendance">

                                                <div class="mb-3">
                                                    <label class="form-label">Class (Optional)</label>
                                                    <select name="p_class" class="form-select">
                                                        <option value="">All Classes (Delete Everything)</option>
                                                        <?php foreach ($classes as $c): ?>
                                                            <option value="<?php echo $c['Class']; ?>">
                                                                <?php echo $c['ClassName']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="text-muted">Leave "All Classes" to wipe system-wide
                                                        attendance.</small>
                                                </div>

                                                <div class="row">
                                                    <div class="col-6">
                                                        <label class="form-label">From Date</label>
                                                        <input type="date" name="p_start" class="form-control" required
                                                            value="<?php echo $startDate; ?>">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">To Date</label>
                                                        <input type="date" name="p_end" class="form-control" required
                                                            value="<?php echo $endDate; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn btn-danger w-100">Confirm Delete</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>




                            <?php if ($page === 'events' && can('event_manage')): ?>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="card">
                                            <div class="card-header">Add Event</div>
                                            <div class="card-body">
                                                <form method="POST"><input type="hidden" name="action" value="add_event">
                                                    <div class="mb-2"><label>Session</label><select name="session_id"
                                                            class="form-select"><?php foreach ($activeSessions as $s)
                                                                echo "<option value='{$s['Id']}'>{$s['Name']}</option>"; ?></select>
                                                    </div>
                                                    <div class="mb-2"><label>Title</label><input type="text" name="title"
                                                            class="form-control" required></div>
                                                    <div class="mb-2"><label>Date</label><input type="date" name="date"
                                                            class="form-control" required></div>
                                                    <div class="mb-2"><label>Details</label><textarea name="description"
                                                            class="form-control"></textarea></div><button
                                                        class="btn btn-success w-100">Create</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="card">
                                            <div class="card-header">Calendar</div>
                                            <div class="card-body">
                                                <table class="table table-bordered datatable-basic">
                                                    <thead>
                                                        <tr>
                                                            <th>Session</th>
                                                            <th>Date</th>
                                                            <th>Event</th>
                                                            <th>Details</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tfoot>
                                                        <tr>
                                                            <th>Session</th>
                                                            <th>Date</th>
                                                            <th>Event</th>
                                                            <th>Details</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </tfoot>
                                                    <tbody>
                                                        <?php $sids = implode(',', array_column($activeSessions, 'Id'));
                                                        if ($sids) {
                                                            $ev = $pdo->query("SELECT e.*, es.Name as SName FROM events e JOIN enrollmentsession es ON e.session_id=es.Id WHERE e.session_id IN ($sids) ORDER BY e.start_date DESC");
                                                            while ($r = $ev->fetch()): ?>
                                                                <tr>
                                                                    <td><?php echo $r['SName']; ?></td>
                                                                    <td><?php echo convertToUserTime($r['start_date']); ?></td>
                                                                    <td class="fw-bold"><?php echo $r['title']; ?></td>
                                                                    <td><?php echo $r['description']; ?></td>
                                                                    <td><button class="btn btn-xs btn-info"
                                                                            onclick="editEvent(<?php echo htmlspecialchars(json_encode($r)); ?>)"><i
                                                                                class="fas fa-pencil"></i></button>
                                                                        <form method="POST" class="d-inline"
                                                                            onsubmit="return confirm('Del?');"><input type="hidden"
                                                                                name="action" value="delete_event"><input type="hidden"
                                                                                name="event_id" value="<?php echo $r['id']; ?>"><button
                                                                                class="btn btn-xs btn-danger"><i
                                                                                    class="fas fa-trash"></i></button></form>
                                                                    </td>
                                                                </tr><?php endwhile;
                                                        } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal fade" id="editEventModal">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Event</h5>
                                            </div>
                                            <div class="modal-body"><input type="hidden" name="action" value="edit_event"><input
                                                    type="hidden" name="event_id" id="ee_id">
                                                <div class="mb-2"><label>Title</label><input type="text" name="title"
                                                        id="ee_title" class="form-control"></div>
                                                <div class="mb-2"><label>Date</label><input type="date" name="date" id="ee_date"
                                                        class="form-control"></div>
                                                <div class="mb-2"><label>Desc</label><input type="text" name="description"
                                                        id="ee_desc" class="form-control"></div>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
                                        </form>
                                    </div>
                                </div>

                                <script>function editEvent(d) { new bootstrap.Modal(document.getElementById('editEventModal')).show(); document.getElementById('ee_id').value = d.id; document.getElementById('ee_title').value = d.title; document.getElementById('ee_date').value = d.start_date; document.getElementById('ee_desc').value = d.description; }</script>

                            <?php endif; ?>



         



 <?php if ($page == 'exams'): ?>
    <?php 
        // Helper: Currency Breakdown Logic
        function calcNotes($amount, $denomsArray) {
            $notes = array_fill_keys($denomsArray, 0);
            $rem = $amount;
            foreach ($denomsArray as $note) {
                $note = (int)trim($note);
                if ($note > 0 && $rem >= $note) {
                    $count = floor($rem / $note);
                    $notes[$note] = $count;
                    $rem -= ($count * $note);
                }
            }
            $notes['Remaining'] = $rem;
            return $notes;
        }
        $rawDenoms = getSet('currency_denominations') ?: '1000,500,100,75,50,20,10';
        $denominations = array_map('intval', explode(',', $rawDenoms));
        rsort($denominations);
        
        // Auto-Create Activities Table & Exam Columns dynamically
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `activities_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `session_id` VARCHAR(50),
                `class_id` VARCHAR(50),
                `date` DATE,
                `enrollment_id` INT,
                `reward_type` VARCHAR(50),
                `qty` INT
            )");
            $pdo->exec("ALTER TABLE exams ADD COLUMN gift_threshold INT DEFAULT 1500");
            $pdo->exec("ALTER TABLE exams ADD COLUMN gift_deduction INT DEFAULT 1500");
        } catch (Exception $e) {}
        
        // --- ROLE CHECKS ---
        $canManage = can('manage_exams') || $_SESSION['role'] === 'admin';
        $canEnter  = can('enter_marks') || $_SESSION['role'] === 'teacher' || $canManage;

        // --- SMART TAB SWITCHING LOGIC ---
        $activeTab = 'manage'; 
        if (!$canManage && $canEnter) {
            $activeTab = 'enter'; 
        }
        if (!empty($_GET['tab'])) {
            $activeTab = $_GET['tab'];
        } elseif (!empty($_GET['exam_id']) || !empty($_POST['exam_id'])) {
            $activeTab = 'enter';
        } elseif (!empty($_GET['budget_session']) || !empty($_GET['sheet_exam_id'])) {
            $activeTab = 'budget';
        } elseif (!empty($_POST['action']) && $_POST['action'] === 'save_game_winners') {
            $activeTab = 'game';
        } elseif (!empty($_POST['action']) && $_POST['action'] === 'save_activities') {
            $activeTab = 'activities';
        } elseif (!empty($_GET['act_session'])) {
            $activeTab = 'activities';
        } elseif (!empty($_GET['game_session'])) {
            $activeTab = 'game';
        }

        // --- UPDATE DYNAMIC PRIZE RATES ---
        if (!empty($_POST['action']) && $_POST['action'] === 'update_prize_settings' && $canManage) {
            $keys = ['prize_rate_present', 'prize_rate_late', 'prize_rate_pct', 'prize_round_to', 'currency_denominations', 'game_reward_1st', 'game_reward_2nd', 'game_reward_3rd'];
            foreach($keys as $k) {
                if(isset($_POST[$k])) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key=?");
                    $chk->execute([$k]);
                    if($chk->fetchColumn() > 0) {
                        $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$_POST[$k], $k]);
                    } else {
                        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $_POST[$k]]);
                    }
                }
            }
            $msg = "Reward Rates & Denominations Updated!";
            $stmt = $pdo->query("SELECT * FROM system_settings");
            while ($row = $stmt->fetch()) { $sys[$row['setting_key']] = $row['setting_value']; }
        }

        // --- CREATE EXAM ---
        if (!empty($_POST['action']) && $_POST['action'] === 'create_exam' && $canManage) {
            try {
                $pdo->prepare("INSERT INTO exams (name,session_id,class_id,total_marks,date,att_start_date,att_end_date,gift_threshold,gift_deduction) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['exam_name'], $_POST['session_id'], $_POST['class_id'], (int)$_POST['total_marks'], $_POST['date'], $_POST['att_start_date'], $_POST['att_end_date'], (int)$_POST['gift_threshold'], (int)$_POST['gift_deduction']]);
                $msg = "Exam Created Successfully.";
            } catch (PDOException $e) { $msg = "Error creating exam."; $msgType = "danger"; }
        }

        // --- EDIT EXAM ---
        if (!empty($_POST['action']) && $_POST['action'] === 'edit_exam' && $canManage) {
            try {
                $pdo->prepare("UPDATE exams SET name=?, total_marks=?, date=?, session_id=?, class_id=?, att_start_date=?, att_end_date=?, gift_threshold=?, gift_deduction=? WHERE id=?")
                    ->execute([$_POST['exam_name'], (int)$_POST['total_marks'], $_POST['date'], $_POST['session_id'], $_POST['class_id'], $_POST['att_start_date'], $_POST['att_end_date'], (int)$_POST['gift_threshold'], (int)$_POST['gift_deduction'], $_POST['exam_id']]);
                $msg = "Exam Updated.";
            } catch (PDOException $e) { $msg = "Error updating exam."; $msgType = "danger"; }
        }

        // --- DELETE EXAM ---
        if (!empty($_POST['action']) && $_POST['action'] === 'delete_exam' && $canManage) {
            $pdo->prepare("DELETE FROM exams WHERE id=?")->execute([$_POST['exam_id']]);
            $pdo->prepare("DELETE FROM exam_results WHERE exam_id=?")->execute([$_POST['exam_id']]);
            $msg = "Exam & Results Deleted.";
        }

        // --- SAVE MARKS ---
        if (!empty($_POST['action']) && $_POST['action'] === 'save_marks' && $canEnter) {
            try {
                $examId = $_POST['exam_id'];
                foreach ($_POST['marks'] as $enr => $mk) {
                    if ($mk !== '') {
                        $mk = (float)$mk;
                        $pdo->prepare("INSERT INTO exam_results (exam_id, enrollment_id, obtained_marks) VALUES (?,?,?) ON DUPLICATE KEY UPDATE obtained_marks=VALUES(obtained_marks)")
                            ->execute([$examId, $enr, $mk]);
                    }
                }
                $msg = "Marks Saved. Budgets will auto-calculate in the Sheet.";
            } catch (PDOException $e) { $msg = "Error: " . $e->getMessage(); $msgType = "danger"; }
        }

        // --- SAVE DAILY GAME ---
        if (!empty($_POST['action']) && $_POST['action'] === 'save_game_winners' && $canEnter) {
            try {
                $sess = $_POST['session_id'];
                $cls = $_POST['class_id'];
                $dt = $_POST['game_date'];
                $r1 = (int)(getSet('game_reward_1st') ?: 70);
                $r2 = (int)(getSet('game_reward_2nd') ?: 50);
                $r3 = (int)(getSet('game_reward_3rd') ?: 30);
                
                $pdo->prepare("DELETE FROM round_table_records WHERE class_id=? AND session_id=? AND date=?")->execute([$cls, $sess, $dt]);

                if (!empty($_POST['pos_1'])) $pdo->prepare("INSERT INTO round_table_records (session_id, class_id, date, enrollment_id, position, reward_amount) VALUES (?,?,?,?,1,?)")->execute([$sess, $cls, $dt, $_POST['pos_1'], $r1]);
                if (!empty($_POST['pos_2'])) $pdo->prepare("INSERT INTO round_table_records (session_id, class_id, date, enrollment_id, position, reward_amount) VALUES (?,?,?,?,2,?)")->execute([$sess, $cls, $dt, $_POST['pos_2'], $r2]);
                if (!empty($_POST['pos_3'])) $pdo->prepare("INSERT INTO round_table_records (session_id, class_id, date, enrollment_id, position, reward_amount) VALUES (?,?,?,?,3,?)")->execute([$sess, $cls, $dt, $_POST['pos_3'], $r3]);
                
                $msg = "Daily Round Table Game Winners Saved!";
            } catch (PDOException $e) { $msg = "Error saving game."; $msgType = "danger"; }
        }

        // --- SAVE DAILY ACTIVITIES (HASNAAT / CASH) ---
        if (!empty($_POST['action']) && $_POST['action'] === 'save_activities' && $canEnter) {
            try {
                $sess = $_POST['session_id'];
                $cls = $_POST['class_id'];
                $dt = $_POST['activity_date'];
                
                $pdo->prepare("DELETE FROM activities_records WHERE class_id=? AND session_id=? AND date=?")->execute([$cls, $sess, $dt]);

                foreach ($_POST['reward_type'] as $enr => $type) {
                    $qty = (int)$_POST['reward_qty'][$enr];
                    if (!empty($type) && $qty > 0) {
                        $pdo->prepare("INSERT INTO activities_records (session_id, class_id, date, enrollment_id, reward_type, qty) VALUES (?,?,?,?,?,?)")
                            ->execute([$sess, $cls, $dt, $enr, $type, $qty]);
                    }
                }
                $msg = "Daily Activities & Hasnaat Cards Saved!";
            } catch (PDOException $e) { $msg = "Error saving activities."; $msgType = "danger"; }
        }
    ?>

    <ul class="nav nav-tabs mb-3" id="examTabs">
        <?php if($canManage): ?>
        <li class="nav-item"><button type="button" class="nav-link <?= $activeTab == 'manage' ? 'active' : '' ?> fw-bold" data-bs-toggle="tab" data-bs-target="#manage-exams"><i class="fas fa-tasks me-2"></i>Manage Exams</button></li>
        <?php endif; ?>
        
        <?php if($canEnter): ?>
        <li class="nav-item"><button type="button" class="nav-link <?= $activeTab == 'enter' ? 'active' : '' ?> fw-bold" data-bs-toggle="tab" data-bs-target="#mark-entry"><i class="fas fa-edit me-2"></i>Enter Marks</button></li>
        <li class="nav-item"><button type="button" class="nav-link <?= $activeTab == 'game' ? 'active' : '' ?> fw-bold text-primary" data-bs-toggle="tab" data-bs-target="#daily-game"><i class="fas fa-trophy me-2"></i>Daily Round Table</button></li>
        <li class="nav-item"><button type="button" class="nav-link <?= $activeTab == 'activities' ? 'active' : '' ?> fw-bold text-warning" data-bs-toggle="tab" data-bs-target="#daily-activities"><i class="fas fa-star me-2"></i>Daily Activities (Hasnaat)</button></li>
        <?php endif; ?>
        
        <?php if($canManage): ?>
        <li class="nav-item"><button type="button" class="nav-link <?= $activeTab == 'budget' ? 'active' : '' ?> fw-bold text-success" data-bs-toggle="tab" data-bs-target="#budget-report"><i class="fas fa-file-excel me-2"></i>Result & Budget Sheet</button></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">
        <?php if($canManage): ?>
        <div class="tab-pane fade <?= $activeTab == 'manage' ? 'show active' : '' ?>" id="manage-exams">
            <div class="row">
                <div class="col-md-4">
                    <div class="card shadow-sm mb-3 border-warning">
                        <div class="card-header bg-warning text-dark fw-bold"><i class="fas fa-cog me-2"></i>Dynamic Prize Settings</div>
                        <div class="card-body bg-light">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_prize_settings">
                                <div class="row g-2">
                                    <div class="col-6"><label class="small fw-bold">Present (Rs)</label><input type="number" step="0.01" name="prize_rate_present" class="form-control form-control-sm" value="<?= getSet('prize_rate_present') ?: 37 ?>"></div>
                                    <div class="col-6"><label class="small fw-bold">Late (Rs)</label><input type="number" step="0.01" name="prize_rate_late" class="form-control form-control-sm" value="<?= getSet('prize_rate_late') ?: 25 ?>"></div>
                                    <div class="col-6"><label class="small fw-bold">Per % (Rs)</label><input type="number" step="0.01" name="prize_rate_pct" class="form-control form-control-sm" value="<?= getSet('prize_rate_pct') ?: 50 ?>"></div>
                                    <div class="col-6"><label class="small fw-bold">Round To</label><input type="number" name="prize_round_to" class="form-control form-control-sm" value="<?= getSet('prize_round_to') ?: 10 ?>"></div>
                                    
                                    <div class="col-12 mt-2 border-top pt-2"><label class="small fw-bold text-primary">Daily Game Rewards</label></div>
                                    <div class="col-4"><label class="small">1st Prize</label><input type="number" name="game_reward_1st" class="form-control form-control-sm" value="<?= getSet('game_reward_1st') ?: 70 ?>"></div>
                                    <div class="col-4"><label class="small">2nd Prize</label><input type="number" name="game_reward_2nd" class="form-control form-control-sm" value="<?= getSet('game_reward_2nd') ?: 50 ?>"></div>
                                    <div class="col-4"><label class="small">3rd Prize</label><input type="number" name="game_reward_3rd" class="form-control form-control-sm" value="<?= getSet('game_reward_3rd') ?: 30 ?>"></div>
                                    
                                    <div class="col-12 mt-2 border-top pt-2"><label class="small fw-bold text-success">Denominations</label><input type="text" name="currency_denominations" class="form-control form-control-sm" value="<?= getSet('currency_denominations') ?: '1000,500,100,75,50,20,10' ?>"></div>
                                    <div class="col-12 mt-3"><button class="btn btn-sm btn-dark w-100">Update All Settings</button></div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">Create New Exam</div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="create_exam">
                                <div class="mb-2"><label>Session</label>
                                    <select name="session_id" class="form-select" required>
                                        <option value="">Select Session...</option>
                                        <?php foreach ($activeSessions as $s) echo "<option value='{$s['Id']}'>{$s['Name']}</option>"; ?>
                                    </select>
                                </div>
                                <div class="mb-2"><label>Class</label>
                                    <select name="class_id" class="form-select" required>
                                        <option value="">Select Class...</option>
                                        <?php 
                                        $cl = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.EnrollmentType, c.ClassName")->fetchAll();
                                        $seen = []; foreach ($cl as $c) { if(!in_array($c['Class'], $seen)) { $seen[] = $c['Class']; echo "<option value='{$c['Class']}'>".($c['EnrollmentType']=='BoysClass'?'[B] ':'[G] ')."{$c['ClassName']}</option>"; } }
                                        ?>
                                    </select>
                                </div>
                                <div class="mb-2"><label>Exam Name</label><input type="text" name="exam_name" class="form-control" placeholder="e.g. Mid Term" required></div>
                                <div class="mb-2"><label>Total Marks</label><input type="number" name="total_marks" class="form-control" required></div>
                                <div class="mb-2"><label>Exam Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                                
                                <div class="alert alert-secondary p-2 mt-3 mb-2">
                                    <label class="small fw-bold mb-1 text-danger">Calculation Range (Dates):</label>
                                    <div class="row g-2">
                                        <div class="col-6"><small>From Date</small><input type="date" name="att_start_date" class="form-control form-control-sm" required></div>
                                        <div class="col-6"><small>To Date</small><input type="date" name="att_end_date" class="form-control form-control-sm" required></div>
                                    </div>
                                </div>

                                <div class="alert alert-warning p-2 mt-2 mb-2 border-warning">
                                    <label class="small fw-bold mb-1 text-dark"><i class="fas fa-gift me-1"></i> Gift Rules (For this Exam Only):</label>
                                    <div class="row g-2">
                                        <div class="col-6"><small>Give Gift IF Total >=</small><input type="number" name="gift_threshold" class="form-control form-control-sm border-warning" value="1500" required></div>
                                        <div class="col-6"><small>Deduct Amount (Rs)</small><input type="number" name="gift_deduction" class="form-control form-control-sm border-warning" value="1500" required></div>
                                    </div>
                                </div>

                                <button class="btn btn-primary w-100 mt-2">Create Exam</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card shadow-sm">
                        <div class="card-header">All Exams</div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="examsTable" class="table table-bordered datatable-basic">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Name</th>
                                            <th>Session</th>
                                            <th>Class</th>
                                            <th>Marks</th>
                                            <th>Gift Rules</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tfoot>
                                        <tr>
                                            <th><input type="text" class="form-control form-control-sm" placeholder="Date"></th>
                                            <th><input type="text" class="form-control form-control-sm" placeholder="Name"></th>
                                            <th><input type="text" class="form-control form-control-sm" placeholder="Session"></th>
                                            <th><input type="text" class="form-control form-control-sm" placeholder="Class"></th>
                                            <th><input type="text" class="form-control form-control-sm" placeholder="Marks"></th>
                                            <th></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                    <tbody>
                                        <?php 
                                        $ex = $pdo->query("SELECT ex.*, c.ClassName, s.Name as SessName FROM exams ex JOIN classmanifest c ON ex.class_id=c.Class JOIN enrollmentsession s ON ex.session_id=s.Id WHERE s.IsActive = 1 ORDER BY ex.id DESC");
                                        while ($r = $ex->fetch()): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($r['date'])) ?></td>
                                                <td class="fw-bold"><?= $r['name'] ?></td>
                                                <td><?= $r['SessName'] ?></td>
                                                <td><?= $r['ClassName'] ?></td>
                                                <td><?= $r['total_marks'] ?></td>
                                                <td><span class="badge bg-warning text-dark">>= <?= $r['gift_threshold'] ?? 1500 ?></span></td>
                                                <td style="white-space:nowrap;">
                                                    <button type="button" class="btn btn-xs btn-info" onclick='editExam(<?= json_encode($r) ?>)'><i class="fas fa-pencil"></i></button>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete Exam?');">
                                                        <input type="hidden" name="action" value="delete_exam">
                                                        <input type="hidden" name="exam_id" value="<?= $r['id'] ?>">
                                                        <button class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($canEnter): ?>
        <div class="tab-pane fade <?= $activeTab == 'enter' ? 'show active' : '' ?>" id="mark-entry">
            <div class="card shadow-sm border-info">
                <div class="card-header bg-info text-white">
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="page" value="exams">
                        <div class="col-md-4">
                            <label class="small fw-bold">Select Exam to Enter Marks</label>
                            <select name="exam_id" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Choose Exam --</option>
                                <?php 
                                $exs = $pdo->query("SELECT ex.id, ex.name, c.ClassName, s.Name as sname FROM exams ex JOIN classmanifest c ON ex.class_id=c.Class JOIN enrollmentsession s ON ex.session_id=s.Id WHERE s.IsActive = 1 ORDER BY ex.id DESC");
                                while($e = $exs->fetch()) {
                                    $sel = ($_GET['exam_id'] ?? '') == $e['id'] ? 'selected' : '';
                                    echo "<option value='{$e['id']}' $sel>{$e['name']} - {$e['ClassName']} ({$e['sname']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="card-body">
                    <?php if(!empty($_GET['exam_id'])): 
                        $currExam = $pdo->prepare("SELECT * FROM exams WHERE id=?");
                        $currExam->execute([$_GET['exam_id']]);
                        $cEx = $currExam->fetch();
                        if($cEx):
                    ?>
                        <div class="alert alert-success small">
                            <strong>Clean View:</strong> Yahan sirf Exam ke numbers enter karein. Attendance, Game aur Hasnaat Activities auto-sync hongi!
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_marks">
                            <input type="hidden" name="exam_id" value="<?= $cEx['id'] ?>">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-dark text-center">
                                        <tr>
                                            <th class="text-start">Student Name</th>
                                            <th style="width:250px;">Obtained Marks (Out of <?= $cEx['total_marks'] ?>)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $stmt = $pdo->prepare("SELECT e.Id as enr_id, s.Name, er.obtained_marks 
                                            FROM enrollment e JOIN students s ON e.StudentId=s.Id 
                                            LEFT JOIN exam_results er ON e.Id = er.enrollment_id AND er.exam_id = ?
                                            WHERE e.Class=? AND e.EnrollmentSessionId=? AND e.IsActive=1 ORDER BY s.Name ASC");
                                        $stmt->execute([$cEx['id'], $cEx['class_id'], $cEx['session_id']]);
                                        while ($r = $stmt->fetch()):
                                        ?>
                                        <tr>
                                            <td class="fw-bold text-start"><?= $r['Name'] ?></td>
                                            <td><input type="number" step="0.1" name="marks[<?= $r['enr_id'] ?>]" class="form-control text-center" value="<?= $r['obtained_marks'] ?>"></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end"><button class="btn btn-success px-5"><i class="fas fa-save me-2"></i>Save Marks</button></div>
                        </form>
                    <?php endif; else: echo "<p class='text-muted'>Please select an exam to enter data.</p>"; endif; ?>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab == 'activities' ? 'show active' : '' ?>" id="daily-activities">
            <div class="card shadow-sm border-warning mb-4">
                <div class="card-header bg-warning text-dark fw-bold"><i class="fas fa-star me-2"></i> Record Daily Activities & Hasnaat Cards</div>
                <div class="card-body">
                    <form method="GET" class="row g-2 mb-4 align-items-end">
                        <input type="hidden" name="page" value="exams">
                        <input type="hidden" name="tab" value="activities">
                        
                        <div class="col-md-3">
                            <label class="small fw-bold">Session</label>
                            <select name="act_session" class="form-select" required onchange="this.form.submit()">
                                <option value="">Select Session...</option>
                                <?php foreach ($activeSessions as $s): ?>
                                    <option value="<?= $s['Id'] ?>" <?= ($_GET['act_session']??'') == $s['Id'] ? 'selected':'' ?>><?= $s['Name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Class</label>
                            <select name="act_class" class="form-select" required onchange="this.form.submit()">
                                <option value="">Select Class...</option>
                                <?php 
                                $clQuery = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.EnrollmentType, c.ClassName")->fetchAll();
                                $seen_act = []; 
                                foreach ($clQuery as $c) { 
                                    if(!in_array($c['Class'], $seen_act)) { 
                                        $seen_act[] = $c['Class']; 
                                        $sel = ($_GET['act_class']??'') == $c['Class'] ? 'selected':'';
                                        echo "<option value='{$c['Class']}' $sel>".($c['EnrollmentType']=='BoysClass'?'[B] ':'[G] ')."{$c['ClassName']}</option>"; 
                                    } 
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Date</label>
                            <input type="date" name="act_date" class="form-control" value="<?= $_GET['act_date'] ?? date('Y-m-d') ?>" required onchange="this.form.submit()">
                        </div>
                    </form>

                    <?php if(!empty($_GET['act_session']) && !empty($_GET['act_class'])): 
                        $stQ = $pdo->prepare("SELECT e.Id as enr_id, s.Name FROM enrollment e JOIN students s ON e.StudentId=s.Id WHERE e.Class=? AND e.EnrollmentSessionId=? AND e.IsActive=1 ORDER BY s.Name ASC");
                        $stQ->execute([$_GET['act_class'], $_GET['act_session']]);
                        $students = $stQ->fetchAll();
                        
                        $searchDate = $_GET['act_date'] ?? date('Y-m-d');
                        $recQ = $pdo->prepare("SELECT enrollment_id, reward_type, qty FROM activities_records WHERE class_id=? AND session_id=? AND date=?");
                        $recQ->execute([$_GET['act_class'], $_GET['act_session'], $searchDate]);
                        $existing = [];
                        while($r = $recQ->fetch()) {
                            $existing[$r['enrollment_id']] = ['type' => $r['reward_type'], 'qty' => $r['qty']];
                        }
                    ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_activities">
                            <input type="hidden" name="session_id" value="<?= $_GET['act_session'] ?>">
                            <input type="hidden" name="class_id" value="<?= $_GET['act_class'] ?>">
                            <input type="hidden" name="activity_date" value="<?= $searchDate ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead class="table-warning">
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Reward Type (Hasnaat / Cash)</th>
                                            <th>Quantity / Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($students as $st): 
                                            $extType = $existing[$st['enr_id']]['type'] ?? '';
                                            $extQty = $existing[$st['enr_id']]['qty'] ?? '';
                                        ?>
                                        <tr>
                                            <td class="fw-bold"><?= $st['Name'] ?></td>
                                            <td>
                                                <select name="reward_type[<?= $st['enr_id'] ?>]" class="form-select border-warning">
                                                    <option value="">-- No Activity --</option>
                                                    <option value="Hasnaat_10" <?= $extType=='Hasnaat_10'?'selected':'' ?>>Hasnaat Card (10 Rs)</option>
                                                    <option value="Hasnaat_20" <?= $extType=='Hasnaat_20'?'selected':'' ?>>Hasnaat Card (20 Rs)</option>
                                                    <option value="Hasnaat_50" <?= $extType=='Hasnaat_50'?'selected':'' ?>>Hasnaat Card (50 Rs)</option>
                                                    <option value="Hasnaat_100" <?= $extType=='Hasnaat_100'?'selected':'' ?>>Hasnaat Card (100 Rs)</option>
                                                    <option value="Cash" <?= $extType=='Cash'?'selected':'' ?>>Direct Cash (Rs)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="reward_qty[<?= $st['enr_id'] ?>]" class="form-control border-warning" placeholder="Enter Count or Amount" value="<?= $extQty ?>">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end"><button type="submit" class="btn btn-warning fw-bold"><i class="fas fa-save me-1"></i> Save Activities for <?= date('d M Y', strtotime($searchDate)) ?></button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="tab-pane fade <?= $activeTab == 'game' ? 'show active' : '' ?>" id="daily-game">
            <div class="card shadow-sm border-primary mb-4">
                <div class="card-header bg-primary text-white"><i class="fas fa-trophy me-2"></i> Record Daily Game Winners</div>
                <div class="card-body">
                    <form method="GET" class="row g-2 mb-4 align-items-end">
                        <input type="hidden" name="page" value="exams">
                        <input type="hidden" name="tab" value="game">
                        <div class="col-md-3">
                            <label class="small fw-bold">Session</label>
                            <select name="game_session" class="form-select" required onchange="this.form.submit()">
                                <option value="">Select Session...</option>
                                <?php foreach ($activeSessions as $s): ?>
                                    <option value="<?= $s['Id'] ?>" <?= ($_GET['game_session']??'') == $s['Id'] ? 'selected':'' ?>><?= $s['Name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Class</label>
                            <select name="game_class" class="form-select" required onchange="this.form.submit()">
                                <option value="">Select Class...</option>
                                <?php 
                                $clQuery = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.EnrollmentType, c.ClassName")->fetchAll();
                                $seen_game = []; 
                                foreach ($clQuery as $c) { 
                                    if(!in_array($c['Class'], $seen_game)) { 
                                        $seen_game[] = $c['Class']; 
                                        $sel = ($_GET['game_class']??'') == $c['Class'] ? 'selected':'';
                                        echo "<option value='{$c['Class']}' $sel>".($c['EnrollmentType']=='BoysClass'?'[B] ':'[G] ')."{$c['ClassName']}</option>"; 
                                    } 
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Date</label>
                            <input type="date" name="game_date" class="form-control" value="<?= $_GET['game_date'] ?? date('Y-m-d') ?>" required onchange="this.form.submit()">
                        </div>
                    </form>

                    <?php if(!empty($_GET['game_session']) && !empty($_GET['game_class'])): 
                        $stQ = $pdo->prepare("SELECT e.Id as enr_id, s.Name FROM enrollment e JOIN students s ON e.StudentId=s.Id WHERE e.Class=? AND e.EnrollmentSessionId=? AND e.IsActive=1 ORDER BY s.Name ASC");
                        $stQ->execute([$_GET['game_class'], $_GET['game_session']]);
                        $students = $stQ->fetchAll();
                        
                        $searchDate = $_GET['game_date'] ?? date('Y-m-d');
                        $recQ = $pdo->prepare("SELECT position, enrollment_id FROM round_table_records WHERE class_id=? AND session_id=? AND date=?");
                        $recQ->execute([$_GET['game_class'], $_GET['game_session'], $searchDate]);
                        $existing = [];
                        while($r = $recQ->fetch()) $existing[$r['position']] = $r['enrollment_id'];
                    ?>
                        <form method="POST" class="bg-light p-3 border rounded shadow-sm">
                            <input type="hidden" name="action" value="save_game_winners">
                            <input type="hidden" name="session_id" value="<?= $_GET['game_session'] ?>">
                            <input type="hidden" name="class_id" value="<?= $_GET['game_class'] ?>">
                            <input type="hidden" name="game_date" value="<?= $searchDate ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="fw-bold text-success mb-1"><i class="fas fa-medal me-1"></i> 1st Position (<?= getSet('game_reward_1st') ?: 70 ?> Rs)</label>
                                    <select name="pos_1" class="form-select border-success">
                                        <option value="">-- Select Winner --</option>
                                        <?php foreach($students as $st): ?>
                                            <option value="<?= $st['enr_id'] ?>" <?= ($existing[1]??0) == $st['enr_id'] ? 'selected':'' ?>><?= $st['Name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="fw-bold text-primary mb-1"><i class="fas fa-medal me-1"></i> 2nd Position (<?= getSet('game_reward_2nd') ?: 50 ?> Rs)</label>
                                    <select name="pos_2" class="form-select border-primary">
                                        <option value="">-- Select Winner --</option>
                                        <?php foreach($students as $st): ?>
                                            <option value="<?= $st['enr_id'] ?>" <?= ($existing[2]??0) == $st['enr_id'] ? 'selected':'' ?>><?= $st['Name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="fw-bold text-warning mb-1"><i class="fas fa-medal me-1"></i> 3rd Position (<?= getSet('game_reward_3rd') ?: 30 ?> Rs)</label>
                                    <select name="pos_3" class="form-select border-warning">
                                        <option value="">-- Select Winner --</option>
                                        <?php foreach($students as $st): ?>
                                            <option value="<?= $st['enr_id'] ?>" <?= ($existing[3]??0) == $st['enr_id'] ? 'selected':'' ?>><?= $st['Name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-4 text-end">
                                <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Winners</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($canManage): ?>
        <div class="tab-pane fade <?= $activeTab == 'budget' ? 'show active' : '' ?>" id="budget-report">
            <div class="card shadow-sm border-success mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-file-excel me-2"></i> Detailed Result & Budget Sheet</span>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-light no-print"><i class="fas fa-print"></i> Print Sheet</button>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-2 mb-4 no-print align-items-end">
                        <input type="hidden" name="page" value="exams">
                        <input type="hidden" name="tab" value="budget">
                        <div class="col-md-5">
                            <label class="small fw-bold">Select Exam to View Detailed Sheet</label>
                            <select name="sheet_exam_id" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Select Exam --</option>
                                <?php 
                                $exs = $pdo->query("SELECT ex.id, ex.name, c.ClassName, s.Name as sname FROM exams ex JOIN classmanifest c ON ex.class_id=c.Class JOIN enrollmentsession s ON ex.session_id=s.Id WHERE s.IsActive = 1 ORDER BY ex.id DESC");
                                while($e = $exs->fetch()) {
                                    $sel = ($_GET['sheet_exam_id'] ?? '') == $e['id'] ? 'selected' : '';
                                    echo "<option value='{$e['id']}' $sel>{$e['name']} - {$e['ClassName']} ({$e['sname']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </form>

                    <?php if(!empty($_GET['sheet_exam_id'])): 
                        $exQ = $pdo->prepare("SELECT ex.*, c.ClassName FROM exams ex LEFT JOIN classmanifest c ON ex.class_id=c.Class WHERE ex.id=?");
                        $exQ->execute([$_GET['sheet_exam_id']]);
                        $sExam = $exQ->fetch();

                        $sql = "SELECT e.Id as enr_id, s.Name, s.Paternity, er.obtained_marks, er.calculated_payout 
                                FROM enrollment e 
                                JOIN students s ON e.StudentId=s.Id 
                                LEFT JOIN exam_results er ON e.Id = er.enrollment_id AND er.exam_id = ? 
                                WHERE e.Class = ? AND e.EnrollmentSessionId = ? AND e.IsActive = 1 ORDER BY s.Name ASC";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$sExam['id'], $sExam['class_id'], $sExam['session_id']]);
                        $results = $stmt->fetchAll();

                        $rP = (float)(getSet('prize_rate_present') ?: 37);
                        $rL = (float)(getSet('prize_rate_late') ?: 25);
                        $rPct = (float)(getSet('prize_rate_pct') ?: 50);
                        $roundVal = (int)(getSet('prize_round_to') ?: 10);
                        
                        // Fetching Gift Rules from THIS SPECIFIC EXAM (Dynamic, not hardcoded!)
                        $giftThreshold = (int)($sExam['gift_threshold'] ?? 1500);
                        $giftDeduction = (int)($sExam['gift_deduction'] ?? 1500);
                        
                        $grandTotalPayout = 0;
                        $grandNotes = array_fill_keys($denominations, 0);

                        $processedResults = [];
                        
                        // --- LIVE AUTO-SYNC ENGINE ---
                        foreach($results as $row) {
                            $attQ = $pdo->prepare("SELECT SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) as p_count, SUM(CASE WHEN status='Late' THEN 1 ELSE 0 END) as l_count FROM attendance WHERE enrollment_id = ? AND date BETWEEN ? AND ?");
                            $attQ->execute([$row['enr_id'], $sExam['att_start_date'], $sExam['att_end_date']]);
                            $att = $attQ->fetch();
                            $pCount = (int)$att['p_count'];
                            $lCount = (int)$att['l_count'];

                            $gameQ = $pdo->prepare("SELECT SUM(reward_amount) FROM round_table_records WHERE enrollment_id = ? AND session_id = ? AND date BETWEEN ? AND ?");
                            $gameQ->execute([$row['enr_id'], $sExam['session_id'], $sExam['att_start_date'], $sExam['att_end_date']]);
                            $gameAmt = (int)$gameQ->fetchColumn();
                            
                            $actQ = $pdo->prepare("SELECT reward_type, SUM(qty) as total_qty FROM activities_records WHERE enrollment_id = ? AND session_id = ? AND date BETWEEN ? AND ? GROUP BY reward_type");
                            $actQ->execute([$row['enr_id'], $sExam['session_id'], $sExam['att_start_date'], $sExam['att_end_date']]);
                            $activities = $actQ->fetchAll();
                            
                            $h10 = 0; $h20 = 0; $h50 = 0; $h100 = 0; $actCash = 0;
                            foreach($activities as $act) {
                                if ($act['reward_type'] == 'Hasnaat_10') $h10 += $act['total_qty'];
                                if ($act['reward_type'] == 'Hasnaat_20') $h20 += $act['total_qty'];
                                if ($act['reward_type'] == 'Hasnaat_50') $h50 += $act['total_qty'];
                                if ($act['reward_type'] == 'Hasnaat_100') $h100 += $act['total_qty'];
                                if ($act['reward_type'] == 'Cash') $actCash += $act['total_qty'];
                            }
                            $hasnaatAmt = ($h10 * 10) + ($h20 * 20) + ($h50 * 50) + ($h100 * 100);

                            $obt = (float)$row['obtained_marks'];
                            $pct = ($sExam['total_marks'] > 0) ? ($obt / $sExam['total_marks']) * 100 : 0;
                            
                            $attRs = ($pCount * $rP) + ($lCount * $rL);
                            $examRs = $pct * $rPct;
                            
                            // Total Before Gift
                            $rawPayout = $attRs + $examRs + $gameAmt + $actCash + $hasnaatAmt;
                            
                            // EXAM-SPECIFIC GIFT LOGIC (Dynamic)
                            $getsGift = ($rawPayout >= $giftThreshold);
                            $giftIcon = $getsGift ? "✓" : "✗";
                            
                            $remainingAmt = $getsGift ? ($rawPayout - $giftDeduction) : $rawPayout;
                            if ($remainingAmt < 0) $remainingAmt = 0;
                            
                            $liveTotalPayout = $roundVal > 0 ? round($remainingAmt / $roundVal) * $roundVal : round($remainingAmt);

                            if ($liveTotalPayout != $row['calculated_payout']) {
                                $pdo->prepare("INSERT INTO exam_results (exam_id, enrollment_id, obtained_marks, calculated_payout) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE calculated_payout=VALUES(calculated_payout)")
                                    ->execute([$sExam['id'], $row['enr_id'], $obt, $liveTotalPayout]);
                            }

                            $grandTotalPayout += $liveTotalPayout;
                            $studentNotes = calcNotes($liveTotalPayout, $denominations);
                            foreach($denominations as $d) { $grandNotes[$d] += $studentNotes[$d]; }
                            
                            $row['live_pre'] = $pCount;
                            $row['live_attRs'] = $attRs;
                            $row['live_pct'] = $pct;
                            $row['live_examRs'] = $examRs;
                            $row['live_gameAmt'] = $gameAmt + $actCash;
                            $row['live_hasnaat'] = ['10'=>$h10, '20'=>$h20, '50'=>$h50, '100'=>$h100];
                            $row['live_hasnaatAmt'] = $hasnaatAmt;
                            $row['live_rawTotal'] = $rawPayout;
                            $row['live_giftIcon'] = $giftIcon;
                            $row['live_remaining'] = $remainingAmt;
                            $row['live_totalPayout'] = $liveTotalPayout;
                            $row['notes'] = $studentNotes;
                            
                            $processedResults[] = $row;
                        }
                    ?>
                        <div class="row mb-4 text-center">
                            <div class="col-md-3">
                                <div class="card bg-success text-white shadow h-100">
                                    <div class="card-body d-flex flex-column justify-content-center">
                                        <h6>Total Cash Distributed</h6>
                                        <h3 class="mb-0">Rs. <?= number_format($grandTotalPayout) ?></h3>
                                        <small class="text-light opacity-75 mt-1"><i class="fas fa-sync fa-spin me-1"></i> Live Auto-Synced</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <div class="card bg-dark text-white shadow h-100">
                                    <div class="card-body py-2">
                                        <h6 class="mb-2 border-bottom pb-1 border-secondary">Bank Notes Requirement (Grand Total)</h6>
                                        <div class="d-flex justify-content-between flex-wrap">
                                            <?php foreach($denominations as $d): ?>
                                                <div class="px-2"><small class="text-muted"><?= $d ?>x</small><br><b class="text-warning fs-5"><?= $grandNotes[$d] ?></b></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover text-center align-middle" style="font-size: 0.75rem; white-space: nowrap;">
                                <thead class="table-dark">
                                    <tr>
                                        <th rowspan="2" class="text-start">Name</th>
                                        <th colspan="2">Paper</th>
                                        <th colspan="2">Attend</th>
                                        <th colspan="4" class="text-warning border-warning">Hasnaat Cards</th>
                                        <th rowspan="2">Game/Cash</th>
                                        <th rowspan="2" class="bg-secondary">Total</th>
                                        <th rowspan="2" class="text-danger" title=">= <?= $giftThreshold ?> Rs">Gift (<?= $giftThreshold ?>)</th>
                                        <th rowspan="2" class="text-info">Remain</th>
                                        <th rowspan="2" class="bg-success">Payout</th>
                                        <th colspan="<?= count($denominations) ?>" class="border-start">Denominations</th>
                                        <th rowspan="2" class="no-print">Action</th>
                                    </tr>
                                    <tr>
                                        <th>Mks</th><th>Rs</th>
                                        <th>Pre</th><th>Rs</th>
                                        <th class="border-warning"><small>10s</small></th><th class="border-warning"><small>20s</small></th><th class="border-warning"><small>50s</small></th><th class="border-warning"><small>100s</small></th>
                                        <?php foreach($denominations as $d): ?><th class="border-start"><?= $d ?></th><?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($processedResults as $row): ?>
                                    <tr>
                                        <td class="text-start fw-bold"><?= $row['Name'] ?></td>
                                        <td><?= $row['obtained_marks'] ?: 0 ?></td>
                                        <td class="fw-bold"><?= number_format($row['live_examRs']) ?></td>
                                        
                                        <td><?= $row['live_pre'] ?></td>
                                        <td class="fw-bold"><?= number_format($row['live_attRs']) ?></td>
                                        
                                        <td><?= $row['live_hasnaat']['10'] ?: '-' ?></td>
                                        <td><?= $row['live_hasnaat']['20'] ?: '-' ?></td>
                                        <td><?= $row['live_hasnaat']['50'] ?: '-' ?></td>
                                        <td><?= $row['live_hasnaat']['100'] ?: '-' ?></td>
                                        
                                        <td><?= number_format($row['live_gameAmt']) ?></td>
                                        
                                        <td class="bg-light fw-bold"><?= number_format($row['live_rawTotal']) ?></td>
                                        <td class="text-danger fw-bold fs-6"><?= $row['live_giftIcon'] ?></td>
                                        <td class="text-info fw-bold"><?= number_format($row['live_remaining']) ?></td>
                                        <td class="bg-success text-white fw-bold fs-6"><?= number_format($row['live_totalPayout']) ?></td>
                                        
                                        <?php foreach($denominations as $d): ?>
                                            <td class="border-start"><?= $row['notes'][$d] > 0 ? $row['notes'][$d] : '-' ?></td>
                                        <?php endforeach; ?>
                                        <td class="no-print">
                                            <button type="button" onclick='printSlip(<?= json_encode([
                                                "name" => $row["Name"],
                                                "fname" => $row["Paternity"] ?: "N/A",
                                                "className" => $sExam["ClassName"] ?: "Madrasa Class",
                                                "pre" => $row["live_pre"],
                                                "attRs" => $row["live_attRs"],
                                                "obt" => $row["obtained_marks"] ?: 0,
                                                "totalMarks" => $sExam["total_marks"],
                                                "pct" => number_format($row["live_pct"], 1),
                                                "examRs" => $row["live_examRs"],
                                                "hasnaatRs" => $row["live_hasnaatAmt"],
                                                "gameRs" => $row["live_gameAmt"],
                                                "rawTotal" => $row["live_rawTotal"],
                                                "giftIcon" => $row["live_giftIcon"],
                                                "remaining" => $row["live_remaining"],
                                                "total" => $row["live_totalPayout"]
                                            ]) ?>)' class="btn btn-sm btn-primary"><i class="fas fa-print"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: echo "<p class='text-muted'>Select an exam to view the detailed Excel sheet.</p>"; endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div> <?php if($canManage): ?>
    <div class="modal fade" id="editExamModal">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Exam</h5></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_exam">
                    <input type="hidden" name="exam_id" id="exm_id">
                    
                    <div class="mb-2"><label>Session</label>
                        <select name="session_id" id="exm_sess" class="form-select" required>
                            <?php foreach ($activeSessions as $s) echo "<option value='{$s['Id']}'>{$s['Name']}</option>"; ?>
                        </select>
                    </div>
                    <div class="mb-2"><label>Class</label>
                        <select name="class_id" id="exm_class" class="form-select" required>
                            <?php 
                            $clQuery = $pdo->query("SELECT c.* FROM classmanifest c JOIN enrollmentsession s ON c.session_id = s.Id WHERE s.IsActive = 1 ORDER BY c.EnrollmentType, c.ClassName")->fetchAll();
                            $seen_mod = []; 
                            foreach ($clQuery as $c) { 
                                if(!in_array($c['Class'], $seen_mod)) { 
                                    $seen_mod[] = $c['Class']; 
                                    echo "<option value='{$c['Class']}'>".($c['EnrollmentType']=='BoysClass'?'[B] ':'[G] ')."{$c['ClassName']}</option>"; 
                                } 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="mb-2"><label>Exam Name</label><input type="text" name="exam_name" id="exm_name" class="form-control" required></div>
                    <div class="mb-2"><label>Total Marks</label><input type="number" name="total_marks" id="exm_marks" class="form-control" required></div>
                    <div class="mb-2"><label>Exam Date</label><input type="date" name="date" id="exm_date" class="form-control" required></div>
                    
                    <div class="row g-2 mb-2 bg-light p-2 border rounded">
                         <div class="col-12"><label class="small fw-bold">Calculation Range (Dates):</label></div>
                         <div class="col-6"><small>From</small><input type="date" name="att_start_date" id="exm_att_start" class="form-control form-control-sm" required></div>
                         <div class="col-6"><small>To</small><input type="date" name="att_end_date" id="exm_att_end" class="form-control form-control-sm" required></div>
                    </div>

                    <div class="alert alert-warning p-2 mt-2 mb-0 border-warning">
                        <label class="small fw-bold mb-1 text-dark"><i class="fas fa-gift me-1"></i> Gift Rules (For this Exam Only):</label>
                        <div class="row g-2">
                            <div class="col-6"><small>Give Gift IF Total >=</small><input type="number" name="gift_threshold" id="exm_gift_th" class="form-control form-control-sm border-warning" required></div>
                            <div class="col-6"><small>Deduct Amount (Rs)</small><input type="number" name="gift_deduction" id="exm_gift_ded" class="form-control form-control-sm border-warning" required></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary">Update Exam</button></div>
            </form>
        </div>
    </div>

<div class="modal fade" id="printSettingsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-primary shadow-lg">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-print me-2"></i> Print Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <h6 class="fw-bold mb-2 text-dark">1. Select Paper Size:</h6>
                    <select id="print_paper_size" class="form-select border-primary mb-4">
                        <option value="A4">A4 Size (Standard Portrait)</option>
                        <option value="A5">A5 Size (Half Page / Compact)</option>
                        <option value="A3">A3 Size (Large Print)</option>
                        <option value="Envelope">Envelope (Landscape / Lifafa)</option>
                    </select>

                    <h6 class="fw-bold mb-2 text-dark">2. Items to Show on Slip:</h6>
                    <div class="bg-white p-3 rounded border">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="p_chk_att" checked>
                            <label class="form-check-label fw-bold text-secondary">Attendance</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="p_chk_exam" checked>
                            <label class="form-check-label fw-bold text-secondary">Exam Result</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="p_chk_hasnaat" checked>
                            <label class="form-check-label fw-bold text-secondary">Hasnaat Cards</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="p_chk_game" checked>
                            <label class="form-check-label fw-bold text-secondary">Game / Cash</label>
                        </div>
                        <hr>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="p_chk_gift" checked>
                            <label class="form-check-label fw-bold text-danger">Gift Status & Deduction</label>
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle"></i> Untick karne se item slip par chup jayega, lekin Final Cash wohi rahega jo system ne calculate kiya hai.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success px-4 fw-bold" onclick="executePrint()"><i class="fas fa-print me-2"></i> Generate Print</button>
                </div>
            </div>
        </div>
    </div>

<div class="modal fade" id="feeCardModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 text-white p-4" style="background: linear-gradient(135deg, #0f172a, #334155);">
                <div class="d-flex align-items-center">
                    <div class="bg-white p-2 rounded-circle me-3 shadow-sm" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-file-invoice-dollar text-dark fs-4"></i>
                    </div>
                    <div>
                        <h4 class="modal-title fw-bold mb-0" id="fc_title" style="letter-spacing: -0.5px;">Student Fee Card</h4>
                        <small class="text-white-50" id="fc_subtitle">Financial Overview</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="background: #f8fafc;">
                <div id="fc_header" class="row g-3 mb-4"></div>
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="background: white;">
                            <thead style="background: #f1f5f9;">
                                <tr>
                                    <th class="border-0 px-4 py-3 text-secondary fw-semibold text-uppercase" style="font-size: 0.85rem;">Billing Period</th>
                                    <th class="border-0 px-4 py-3 text-secondary fw-semibold text-uppercase" style="font-size: 0.85rem;">Payable Amount</th>
                                    <th class="border-0 px-4 py-3 text-secondary fw-semibold text-uppercase" style="font-size: 0.85rem;">Status</th>
                                    <th class="border-0 px-4 py-3 text-secondary fw-semibold text-uppercase" style="font-size: 0.85rem;">Paid Date</th>
                                </tr>
                            </thead>
                            <tbody id="fc_body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewFeeCard(enrId, name) {
    document.getElementById('fc_title').innerText = name;
    document.getElementById('fc_subtitle').innerText = "Enrollment ID: " + enrId;
    
    $.getJSON('?action=get_fee_history&enr_id=' + enrId, function(data) {
        let header = `
            <div class="col-sm-6">
                <div class="card border-0 shadow-sm transition-all" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-radius: 12px; transition: transform 0.2s;">
                    <div class="card-body p-3 d-flex align-items-center">
                        <i class="fas fa-hand-holding-usd fs-1 text-success me-3 opacity-50"></i>
                        <div>
                            <div class="text-success fw-bold small text-uppercase" style="letter-spacing: 0.5px;">Admission Fee</div>
                            <div class="fs-4 fw-bold text-dark">Rs. ${data.base.enrollment_fee}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="card border-0 shadow-sm transition-all" style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border-radius: 12px; transition: transform 0.2s;">
                    <div class="card-body p-3 d-flex align-items-center">
                        <i class="fas fa-calendar-check fs-1 text-primary me-3 opacity-50"></i>
                        <div>
                            <div class="text-primary fw-bold small text-uppercase" style="letter-spacing: 0.5px;">Monthly Fixed Fee</div>
                            <div class="fs-4 fw-bold text-dark">Rs. ${data.base.individual_monthly_fee}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        $('#fc_header').html(header);
        
        let html = '';
        if(data.history.length === 0) {
            html = '<tr><td colspan="4" class="text-center py-5"><i class="fas fa-folder-open fs-1 text-black-50 mb-3 d-block opacity-50"></i><span class="text-muted fw-medium">No monthly records generated yet.</span></td></tr>';
        } else {
            data.history.forEach(function(r) {
                let statusBadge = r.Status === 'Paid' 
                    ? '<span class="badge bg-success-subtle text-success border border-success fw-bold px-3 py-2 rounded-pill"><i class="fas fa-check-circle me-1"></i> Paid</span>' 
                    : '<span class="badge bg-danger-subtle text-danger border border-danger fw-bold px-3 py-2 rounded-pill"><i class="fas fa-times-circle me-1"></i> Unpaid</span>';
                
                let dateDisplay = r.PaidDate 
                    ? `<span class="text-dark fw-medium"><i class="far fa-calendar-check text-muted me-2"></i>${r.PaidDate}</span>` 
                    : '<span class="text-muted"><i class="fas fa-minus text-black-50"></i></span>';
                
                html += `<tr class="border-bottom border-light">
                    <td class="px-4 py-3"><span class="fw-bold text-dark"><i class="far fa-clock me-2 text-muted"></i>${r.Month}/${r.Year}</span></td>
                    <td class="px-4 py-3"><span class="fw-bold fs-6 text-dark">Rs. ${r.Amount}</span></td>
                    <td class="px-4 py-3">${statusBadge}</td>
                    <td class="px-4 py-3">${dateDisplay}</td>
                </tr>`;
            });
        }
        $('#fc_body').html(html);
        new bootstrap.Modal(document.getElementById('feeCardModal')).show();
    });
}
</script>

    <script>
    let globalPrintData = null;

    // Button dabane par ab direct print nahi hoga, modal khulega
    function printSlip(data) {
        globalPrintData = data;
        let myModal = new bootstrap.Modal(document.getElementById('printSettingsModal'));
        myModal.show();
    }

    // Modal mein "Generate Print" dabane par asal print chalega
    function executePrint() {
        let data = globalPrintData;
        let paperSize = document.getElementById('print_paper_size').value;
        
        let showAtt = document.getElementById('p_chk_att').checked;
        let showExam = document.getElementById('p_chk_exam').checked;
        let showHasnaat = document.getElementById('p_chk_hasnaat').checked;
        let showGame = document.getElementById('p_chk_game').checked;
        let showGift = document.getElementById('p_chk_gift').checked;

        let giftColor = data.giftIcon === '✓' ? '#198754' : '#dc3545';
        let giftText = data.giftIcon === '✓' ? 'Qualified' : 'Not Qualified';
        let deductedAmount = data.rawTotal - data.remaining;

        // Paper Size CSS Logic
        let pageCss = '';
        let wrapperCss = '';
        let tableFontSize = '14px';
        let titleFontSize = '24px';
        let totalFontSize = '24px';
        let tdPadding = '10px 0';
        
        if (paperSize === 'A4') {
            pageCss = '@page { size: A4 portrait; margin: 15mm; }';
            wrapperCss = 'max-width: 450px; margin: 0 auto; border: 2px solid #222; padding: 25px;';
        } else if (paperSize === 'A5') {
            pageCss = '@page { size: A5 portrait; margin: 10mm; }';
            wrapperCss = 'max-width: 100%; border: 1px solid #222; padding: 15px;';
            tableFontSize = '12px';
            titleFontSize = '20px';
            totalFontSize = '20px';
            tdPadding = '6px 0';
        } else if (paperSize === 'A3') {
            pageCss = '@page { size: A3 portrait; margin: 20mm; }';
            wrapperCss = 'max-width: 600px; margin: 0 auto; border: 3px solid #222; padding: 40px;';
            tableFontSize = '18px';
            titleFontSize = '32px';
            totalFontSize = '30px';
            tdPadding = '15px 0';
        } else if (paperSize === 'Envelope') {
            pageCss = '@page { size: DL landscape; margin: 5mm; }';
            wrapperCss = 'max-width: 100%; border: none; padding: 5px;';
            tableFontSize = '11px';
            titleFontSize = '18px';
            totalFontSize = '18px';
            tdPadding = '4px 0';
        }

        // Dynamic Rows Logic
        let rowsHtml = '';
        if (showAtt) rowsHtml += `<tr><td class="td-desc">Attendance</td><td class="td-detail">${data.pre} Days</td><td class="td-amt">Rs. ${data.attRs}</td></tr>`;
        if (showExam) rowsHtml += `<tr><td class="td-desc">Exam Result</td><td class="td-detail">${data.obt} / ${data.totalMarks} (${data.pct}%)</td><td class="td-amt">Rs. ${data.examRs}</td></tr>`;
        if (showHasnaat) rowsHtml += `<tr><td class="td-desc">Hasnaat Cards</td><td class="td-detail">-</td><td class="td-amt">Rs. ${data.hasnaatRs}</td></tr>`;
        if (showGame) rowsHtml += `<tr><td class="td-desc">Game / Cash</td><td class="td-detail">-</td><td class="td-amt">Rs. ${data.gameRs}</td></tr>`;

        // Dynamic Gift Logic
        let giftHtml = '';
        if (showGift) {
            if (data.giftIcon === '✓') {
                giftHtml = `
                    <div class="sum-row" style="color: #dc3545;">
                        <div class="gift-label">Common Gift Deduction <span style="color:#198754; font-weight:900;">(✓)</span>:</div>
                        <div class="sum-amt">- Rs. ${deductedAmount}</div>
                    </div>
                `;
            } else {
                giftHtml = `
                    <div class="sum-row" style="color: #888;">
                        <div class="gift-label">Common Gift <span style="font-weight:900;">(✗)</span>:</div>
                        <div class="sum-amt">- Rs. 0</div>
                    </div>
                `;
            }
        }

        let printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>Result Slip - ${data.name}</title>
                <style>
                    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                    ${pageCss}
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #525659; display: flex; justify-content: center; margin: 0; }
                    .slip-container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); width: 100%; }
                    .print-btn-container { text-align: center; margin-bottom: 20px; }
                    .btn { background: #0d6efd; color: white; border: none; padding: 10px 20px; font-size: 16px; border-radius: 5px; cursor: pointer; font-weight: bold; }
                    
                    .slip-wrapper { background: #fff; ${wrapperCss} }
                    .school-name { text-align: center; font-size: ${titleFontSize}; font-weight: 900; text-transform: uppercase; margin: 0 0 15px 0; color: #111; border-bottom: 2px solid #222; padding-bottom: 10px; }
                    
                    .info-box { margin-bottom: 15px; font-size: ${tableFontSize}; line-height: 1.6; }
                    .info-row { display: flex; }
                    .info-label { width: 80px; font-weight: bold; color: #555; }
                    .info-val { flex: 1; text-transform: capitalize; border-bottom: 1px solid #eee; font-weight: 700; color: #000; }
                    
                    table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: ${tableFontSize}; }
                    th { text-align: left; padding: 6px 0; border-bottom: 2px solid #222; color: #222; font-weight: bold; text-transform: uppercase; font-size: 0.85em; }
                    td { padding: ${tdPadding}; border-bottom: 1px solid #eee; color: #333; }
                    .td-desc { font-weight: bold; color: #222; }
                    .td-detail { text-align: center; color: #777; font-size: 0.9em; }
                    .td-amt { text-align: right; font-weight: bold; font-size: 1.05em; color: #000; }
                    
                    .summary-box { border-top: 2px dashed #bbb; padding-top: 10px; margin-top: 5px; }
                    .sum-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: ${tableFontSize}; align-items: center; }
                    .sum-label { font-weight: bold; color: #444; }
                    .sum-amt { font-weight: bold; font-size: 1.1em; }
                    
                    .final-payout { background: #111; color: #fff; text-align: center; padding: 12px; font-size: ${totalFontSize}; font-weight: 900; margin-top: 15px; border-radius: 6px; }
                    .footer { text-align: center; font-size: 0.8em; font-style: italic; color: #888; margin-top: 20px; }
                    
                    @media print {
                        body { background: #fff !important; padding: 0 !important; display: block !important; margin: 0 !important; }
                        .slip-container { box-shadow: none !important; padding: 0 !important; margin: 0 !important; width: 100% !important; }
                        .print-btn-container { display: none !important; }
                        .slip-wrapper { margin: 0 auto !important; }
                        .final-payout { background-color: #111 !important; color: #fff !important; }
                    }
                </style>
            </head>
            <body>
                <div class="slip-container">
                    <div class="print-btn-container">
                        <button class="btn" onclick="window.print()">🖨️ Print Slip Now</button>
                    </div>
                    <div class="slip-wrapper">
                        <h2 class="school-name">${data.className}</h2>
                        
                        <div class="info-box">
                            <div class="info-row"><div class="info-label">Name:</div><div class="info-val">${data.name}</div></div>
                            <div class="info-row"><div class="info-label">Father:</div><div class="info-val">${data.fname}</div></div>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th style="text-align: center;">Details</th>
                                    <th style="text-align: right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rowsHtml}
                            </tbody>
                        </table>
                        
                        <div class="summary-box">
                            <div class="sum-row">
                                <div class="sum-label">Gross Total:</div>
                                <div class="sum-amt">Rs. ${data.rawTotal}</div>
                            </div>
                            
                            ${giftHtml}
                            
                            <div class="sum-row" style="margin-top: 8px; border-top: 1px solid #eee; padding-top: 8px;">
                                <div class="sum-label" style="color: #444;">Subtotal Balance:</div>
                                <div class="sum-amt" style="color: #444;">Rs. ${data.remaining}</div>
                            </div>
                        </div>
                        
                        <div class="final-payout">
                            CASH: Rs. ${data.total}
                        </div>
                        
                        <div class="footer">JazakAllah Khair</div>
                    </div>
                </div>
                <script>
                    setTimeout(function() { window.print(); }, 500);
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
        
        // Modal ko wapas band kar dena
        let myModalEl = document.getElementById('printSettingsModal');
        let modal = bootstrap.Modal.getInstance(myModalEl);
        modal.hide();
    }
    </script>

    <script>
        // Modal logic for Edit Exam
        function editExam(d) { 
            document.getElementById('exm_id').value = d.id; 
            document.getElementById('exm_name').value = d.name; 
            document.getElementById('exm_marks').value = d.total_marks; 
            document.getElementById('exm_date').value = d.date; 
            document.getElementById('exm_sess').value = d.session_id; 
            document.getElementById('exm_class').value = d.class_id; 
            document.getElementById('exm_att_start').value = d.att_start_date; 
            document.getElementById('exm_att_end').value = d.att_end_date; 
            document.getElementById('exm_gift_th').value = d.gift_threshold || 1500; 
            document.getElementById('exm_gift_ded').value = d.gift_deduction || 1500; 
            new bootstrap.Modal(document.getElementById('editExamModal')).show(); 
        }
    </script>
    <?php endif; ?>
<?php endif; ?>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

                               <!-- UPDATED STUDENT MODAL -->

                                <div class="modal fade" id="studentModal">
                                    <div class="modal-dialog modal-lg">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="stModalTitle">Add Student</h5>
                                            </div>

                                            <div class="modal-body">

                                                <input type="hidden" name="action" id="st_action" value="add_student"><input
                                                    type="hidden" name="student_id" id="st_id">

                                                <div class="row g-2">

                                                    <div class="col-md-6"><label>Name</label><input type="text" name="name"
                                                            id="st_name" class="form-control" required></div>

                                                    <div class="col-md-6"><label>Father Name</label><input type="text"
                                                            name="paternity" id="st_pat" class="form-control" required></div>

                                                    <div class="col-md-6"><label>Mobile (Father)</label><input type="text"
                                                            name="mobile_f" id="st_mob_f" class="form-control"></div>

                                                    <div class="col-md-6"><label>Mobile (Mother)</label><input type="text"
                                                            name="mobile_m" id="st_mob_m" class="form-control"></div>

                                                    <div class="col-md-4"><label>DOB</label><input type="date" name="dob"
                                                            id="st_dob" class="form-control" required></div>

                                                    <div class="col-md-4"><label>Gender</label><select name="gender"
                                                            id="st_gender" class="form-select" required>
                                                            <option value="" selected disabled>Select Gender</option>
                                                            <option>Male</option>
                                                            <option>Female</option>
                                                        </select></div>

                                                    <div class="col-md-12"><label>Address</label><textarea name="address"
                                                            id="st_addr" class="form-control" rows="2" required></textarea>
                                                    </div>

                                                    <div class="col-md-6"><label>Previous School</label><input type="text"
                                                            name="school" id="st_school" class="form-control"></div>

<div class="col-md-6"><label>Notes</label><textarea name="notes"
                                                            id="st_notes" class="form-control" rows="2"></textarea></div>

                                                </div>

                                            </div>

                                            <div class="modal-footer">
                                                <button class="btn btn-primary fw-bold" type="submit">Save Student</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

            <script src="https://code.jquery.com/jquery-3.7.0.js"></script>

            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

            <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

            <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

            <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>

            <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>

            <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

            <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

            <script>
            $(document).ready(function() {
                // Initialize select2 for dropdown search
                if($('.select2').length) {
                    $('.select2').select2({ width: '100%' });
                }
            });
            </script>
            
            <?php if (!empty($_GET['auto_edit_id']) && $page === 'students' && can('student_edit')): 
                $autoEditStu = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                $autoEditStu->execute([$_GET['auto_edit_id']]);
                $autoEditData = $autoEditStu->fetch(PDO::FETCH_ASSOC);
                if($autoEditData):
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(() => editStudent(<?php echo json_encode($autoEditData); ?>), 600);
            });
            </script>
            <?php endif; endif; ?>

            <script>

                // --- 1. GLOBAL HELPER FUNCTIONS ---

                function toggleDarkMode() {
                    document.body.classList.toggle('dark-mode');
                    localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
                }

                function showAssign(id) {
                    var box = document.getElementById('assign_box_' + id);
                    if (box) {
                        box.style.display = (box.style.display === 'none') ? 'block' : 'none';
                    }
                }

                function clearStudentForm() {
                    var form = document.querySelector('#studentModal form');
                    if (form) {
                        form.reset();
                        form.querySelector('input[name="action"]').value = 'add_student';
                        form.querySelector('input[name="student_id"]').value = '';
                        document.querySelector('#studentModal .modal-title').innerText = 'Add Student';
                    }
                }

                function editStudent(data) {
                    var modalEl = document.getElementById('studentModal');
                    if (!modalEl) {
                        alert("Error: Student Modal not found in HTML.");
                        return;
                    }
                    var form = modalEl.querySelector('form');

                    try {
                        form.querySelector('input[name="action"]').value = 'edit_student';
                        form.querySelector('input[name="student_id"]').value = data.Id || data.id;
                        form.querySelector('input[name="name"]').value = data.Name || '';
                        form.querySelector('input[name="paternity"]').value = data.Paternity || '';
                        form.querySelector('input[name="mobile_f"]').value = data.MobileNumberFather || '';
                        form.querySelector('input[name="mobile_m"]').value = data.MobileNumberMother || '';
                        form.querySelector('input[name="dob"]').value = data.DOB || '';
                        form.querySelector('select[name="gender"]').value = data.Gender || '';
                        form.querySelector('textarea[name="address"]').value = data.Address || '';
                        form.querySelector('input[name="school"]').value = data.School || '';
                        form.querySelector('textarea[name="notes"]').value = data.Notes || '';

                        document.querySelector('#stModalTitle').innerText = 'Edit Student';
                        var myModal = new bootstrap.Modal(modalEl);
                        myModal.show();
                    } catch (err) {
                        console.error("Edit Error:", err);
                    }
                }

                // --- 2. DATATABLES INITIALIZATION ---

                document.addEventListener("DOMContentLoaded", function () {
                    if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');
                    var scrollpos = localStorage.getItem('scrollpos');
                    if (scrollpos) window.scrollTo(0, scrollpos);
                });

                window.onbeforeunload = function (e) {
                    localStorage.setItem('scrollpos', window.scrollY);
                };

                $(document).ready(function () {
                    if ($('#serverSideStudentsTable').length) {
                        var table = $('#serverSideStudentsTable').DataTable({
                            // 1. ENABLE SERVER SIDE
                            serverSide: true,
                            processing: true,

                            // 2. CONNECT TO THE PHP HANDLER (From Step 1)
ajax: {
                                url: '?action=fetch_students&source=main',
                                type: 'GET',
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                    alert('Error loading data.');
                                },
                                dataSrc: function (json) {
                                    if (json.error) { alert('Error: ' + json.error); return []; }
                                    return json.data;
                                }
                            },

                            // 3. OPTIONS
                            dom: 'Blfrtip',
                            buttons: ['excel', 'csv', { extend: 'print', autoPrint: true }],
                            stateSave: true,
                            order: [[0, 'desc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        pageLength: 10,
                            columns: [
                                { width: '5%' },   // 0: ID
                                { width: '12%' },  // 1: Name
                                { width: '12%' },  // 2: Father
                                { width: '5%', orderable: false, searchable: false }, // 3: Status
                                { width: '10%' },  // 4: Mobile
                                { width: '5%', orderable: false, searchable: false }, // 5: WA
                                { width: '5%' },   // 6: Age
                                { width: '9%' },   // 7: Class
                                { width: '7%' },   // 8: Fee (Naya Add Hua)
                                { width: '8%' },   // 9: Session
                                { width: '22%', orderable: false, searchable: false } // 10: Actions
                            ],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    var title = $(column.header()).text();
                                    if (title !== "Action" && title !== "WhatsApp" && title !== "Age") {
                                        $('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" />')
                                            .appendTo($(column.footer()).empty())
                                            .on('keyup change clear', function () {
                                                if (column.search() !== this.value) column.search(this.value).draw();
                                            });
                                    }
                                });
                            }
                        });
                    }
                });
            </script>

            <script>

                document.addEventListener('DOMContentLoaded', function () {

                    document.querySelectorAll('table').forEach(function (tbl) {

                        var headers = Array.from(tbl.querySelectorAll('thead th')).map(function (th) { return th.textContent.trim(); }).join('|');

                        if (headers.includes('Student') && headers.includes('Session')) {

                            tbl.querySelectorAll('tbody tr').forEach(function (tr) {

                                var idTd = tr.querySelector('td'); if (!idTd) return; var id = idTd.textContent.trim();

                                var actionCell = tr.querySelector('td:last-child');

                                if (actionCell && !actionCell.querySelector('.enroll-slip-btn')) {

                                    var a = document.createElement('a'); a.className = 'btn btn-sm btn-outline-primary py-0 me-1 enroll-slip-btn'; a.href = '?page=enrollment_slip&enrollment_id=' + encodeURIComponent(id); a.target = '_blank'; a.textContent = 'Slip'; actionCell.insertBefore(a, actionCell.firstChild);

                                }

                            });

                        }

                        if (headers.includes('Exam') && headers.includes('Marks') && tbl.querySelector('input[name="action"][value="delete_exam"]')) {

                            tbl.querySelectorAll('tbody tr').forEach(function (tr) {

                                var idTd = tr.querySelector('td'); if (!idTd) return; var id = idTd.textContent.trim();

                                var actionCell = tr.querySelector('td:last-child');

                                if (actionCell && !actionCell.querySelector('.exam-print-btn')) {

                                    var a = document.createElement('a'); a.className = 'btn btn-xs btn-secondary exam-print-btn me-1'; a.href = '?page=print_result&exam_id=' + encodeURIComponent(id); a.target = '_blank'; a.textContent = 'Print'; actionCell.insertBefore(a, actionCell.firstChild);

                                }

                            });

                        }

                    });

                });

            </script>

            <script>
                $(document).ready(function () {

                    // 0. Initialize Enrollment Table with Footer Search
                    $('.datatable-enrollment').DataTable({
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        pageLength: 10,
                        stateSave: true,
                        destroy: true,
                        initComplete: function () {
                            this.api().columns().every(function () {
                                var that = this;
                                $('input', this.footer()).on('keyup change clear', function () {
                                    if (that.search() !== this.value) {
                                        that.search(this.value).draw();
                                    }
                                });
                            });
                        }
                    });

                    // 1. Initialize Basic Tables (Duplicates, Teachers, Classes, etc.)
                    // We use the :not() selector to explicitly exclude server-side tables.
                    $('.datatable-basic:not(#serverSideStudentsTable):not(#examsTable):not(#hasanaatCardsTable)').DataTable({
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        pageLength: 10,
                        stateSave: true,
                        destroy: true // Ensures if it was already running, it refreshes clean
                    });

                    // 2. Initialize Export Tables (Attendance Reports, Fee Reports, etc.)
                    $('.datatable-export:not(#serverSideStudentsTable):not(#feeReportTable):not(#logsTable):not(#reportsTable)').DataTable({
                        dom: 'Bfrtip',
                        buttons: ['copy', 'csv', 'excel', 'print'],
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        pageLength: 10,
                        stateSave: true,
                        destroy: true
                    });

                    // 3. Initialize Session Breakdown Tables
                    $('.session-datatable').each(function () {
                        var tableId = $(this).attr('id');
                        var sessionId = $(this).data('session-id');
                        $('#' + tableId).DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_session_students&session_id=' + sessionId,
                                type: 'GET',
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                }
                            },
                            columns: [
                                { width: '5%' },   // 0: ID
                                { width: '12%' },  // 1: Name
                                { width: '12%' },  // 2: Father
                                { width: '5%', orderable: false, searchable: false }, // 3: Status
                                { width: '10%' },  // 4: Mobile
                                { width: '5%', orderable: false, searchable: false }, // 5: WhatsApp
                                { width: '4%' },   // 6: Age
                                { width: '10%' },  // 7: Session
                                { width: '10%' },  // 8: Class
                                { width: '12%', orderable: false, searchable: false }, // 9: Fee & Save
                                { width: '15%', orderable: false, searchable: false }  // 10: Actions
                            ],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                            pageLength: 10,
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    });

                    // 4. Initialize Fee Report Table
                    if ($('#feeReportTable').length) {
                        $('#feeReportTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_fee_report',
                                type: 'GET',
                                data: function (d) {
                                    d.start_date = $('input[name="start_date"]').val() || '';
                                    d.end_date = $('input[name="end_date"]').val() || '';
                                    d.collector_id = $('select[name="collector_id"]').val() || '';
                                    d.show_all = $('#showAll').is(':checked') ? 1 : 0;
                                },
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                },
                                dataSrc: function (json) {
                                    if (json.error) { alert('Error: ' + json.error); return []; }
                                    return json.data;
                                }
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            order: [[0, 'desc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 10,
                            columns: [
                                { width: '8%' },
                                { width: '12%' },
                                { width: '20%' },
                                { width: '15%' },
                                { width: '15%' },
                                { width: '10%' },
                                { width: '20%', orderable: false, searchable: false }
                            ],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                    // 5. Initialize Logs Table
                    if ($('#logsTable').length) {
                        $('#logsTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_logs',
                                type: 'GET',
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                }
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            order: [[0, 'desc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 10,
                            columns: [
                                { width: '8%' },
                                { width: '18%' },
                                { width: '18%' },
                                { width: '18%' },
                                { width: '25%' },
                                { width: '13%' }
                            ],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                    // 6. Initialize Hasanaat Cards Table
                    if ($('#hasanaatCardsTable').length) {
                        $('#hasanaatCardsTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_hasanaat_cards',
                                type: 'GET',
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                }
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            order: [[0, 'desc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 10,
                            columns: [
                                { width: '12%' },
                                { width: '22%' },
                                { width: '12%' },
                                { width: '12%' },
                                { width: '12%' },
                                { width: '12%' },
                                { width: '10%' },
                                { width: '10%', orderable: false, searchable: false }
                            ],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                    // 7. Initialize Attendance Report Table
                    if ($('#attendanceReportTable').length) {
                        $('#attendanceReportTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_attendance_report',
                                type: 'GET',
                                data: function (d) {
                                    d.class_id = $('select[name="class_id"]').val() || '';
                                    d.start_date = $('input[name="start_date"]').val() || '';
                                    d.end_date = $('input[name="end_date"]').val() || '';
                                },
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                }
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            order: [[0, 'asc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 25,
                            scrollX: true
                        });
                    }

                    // 8. Initialize Exams Table
                    if ($('#examsTable').length) {
                        $('#examsTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_exams',
                                type: 'GET',
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                }
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            order: [[0, 'desc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 10,
                            columns: [
                                { width: '12%' },
                                { width: '22%' },
                                { width: '16%' },
                                { width: '16%' },
                                { width: '12%' },
                                { width: '12%' },
                                { width: '10%', orderable: false, searchable: false }
                            ],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                    // 8. Initialize Reports Table
                    if ($('#reportsTable').length) {
                        $('#reportsTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_reports',
                                type: 'GET',
                                error: function (xhr, error, thrown) {
                                    console.error('AJAX Error:', error, thrown);
                                }
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            order: [[0, 'asc']],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 10,
                            columns: [
                                { width: '8%' },
                                { width: '28%' },
                                { width: '18%' },
                                { width: '18%' },
                                { width: '14%' },
                                { width: '14%' }
                            ],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                    // 9. Initialize Ghost Records Table
                    if ($('#ghostTable').length) {
                        $('#ghostTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: '?action=fetch_ghosts',
                            columns: [
                                { width: "10%" },
                                { width: "25%" },
                                { width: "25%" },
                                { width: "20%" },
                                { width: "20%", orderable: false, searchable: false }
                            ],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                            pageLength: 10,
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            initComplete: function () {
                                this.api().columns().every(function () {
                                    var column = this;
                                    var input = $('input', this.footer());
                                    input.on('keyup change', function () {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                    // 5. Initialize Certificates History Table
                    if ($('#certificatesHistoryTable').length) {
                        $('#certificatesHistoryTable').DataTable({
                            serverSide: true,
                            processing: true,
                            ajax: {
                                url: '?action=fetch_certificates',
                                type: 'GET'
                            },
                            dom: 'Bfrtip',
                            buttons: ['excel', 'print'],
                            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                            pageLength: 10,
                            order: [[0, 'desc']],
                            columns: [
                                { width: '15%' },
                                { width: '35%' },
                                { width: '35%' },
                                { width: '15%', orderable: false, searchable: false }
                            ],
                            initComplete: function() {
                                this.api().columns().every(function() {
                                    var column = this;
                                    $('input', this.footer()).on('keyup change clear', function() {
                                        if (column.search() !== this.value) {
                                            column.search(this.value).draw();
                                        }
                                    });
                                });
                            }
                        });
                    }

                });
            </script>


</body>

    </html>

