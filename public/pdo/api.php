<?php

header('Content-Type: application/json');



$host = 'aws-1-ap-southeast-2.pooler.supabase.com';
$port = "5432"; 
$user = 'postgres.gnxwhyjuqgopzrfhhbwb';
$pass = '9CRmtcg24Ew21dZA';
$dbname = 'postgres'; 
$charset = 'utf8';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     http_response_code(500);
     echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
     exit();
}

// --- INPUT HANDLING ---
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit();
}

$action = $data['action'];
$user_id = $data['user_id'] ?? null;

if (!$user_id && $action !== 'fetchOrCreateProfile') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User ID required for this action']);
    exit();
}


// --- API HANDLER ---
switch ($action) {
    case 'test':
        echo json_encode(['success' => true, 'message' => 'API is working']);
        break;

    case 'saveTemplateState':
        $templateData = $data['template_state'] ?? [];
        
        // Validate required fields
        $requiredFields = ['frame_size', 'shot_count', 'effects']; 
        foreach ($requiredFields as $field) {
            if (!isset($templateData[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required template field: {$field}"]);
                exit();
            }
        }
        
        // Extract data - map frontend keys to DB fields
        $sticker = $templateData['sticker'] ?? null;
        $text = $templateData['text'] ?? [];
        $font = $templateData['font'] ?? 'Inter';
        $text_color = $templateData['text_color'] ?? '#000000';
        $background = $templateData['background'] ?? '#ffffff';
        $show_logo = (bool)($templateData['show_logo'] ?? false);
        $show_date = (bool)($templateData['show_date'] ?? false);
        $show_time = (bool)($templateData['show_time'] ?? false);
        $frame_size = $templateData['frame_size'];
        $shot_count = (string)$templateData['shot_count'];
        $share = (bool)($templateData['share'] ?? false);
        $effects = $templateData['effects'];
        $template_img = $templateData['template_img'] ?? null;

        try {
            $sql = "INSERT INTO saved_templates (
                        user_id, sticker, text, font, text_color, background, 
                        show_logo, show_date, show_time, 
                        frame_size, shot_count, share, effects, template_img
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, 
                        ?, ?, ?,
                        ?, ?, ?, ?, ?
                    ) RETURNING template_id";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id, $sticker, json_encode($text), $font, $text_color, $background,
                $show_logo, $show_date, $show_time,
                $frame_size, $shot_count, $share, $effects, $template_img
            ]);

            $newTemplate = $stmt->fetch();

            echo json_encode([
                'success' => true, 
                'message' => 'Template saved successfully', 
                'template_id' => $newTemplate['template_id']
            ]);
            
        } catch (\PDOException $e) {
            http_response_code(500);
            error_log("Database INSERT error: " . $e->getMessage());
            echo json_encode(["success"=> false, "error" => "Database error in saveTemplateState: " . $e->getMessage()]);
        }
        break;


    case 'fetchOrCreateProfile':
        $email = $data['email'] ?? null;
        $display_name = $data['display_name'] ?? null;
        
        try {
            // 1. Try to fetch the existing profile
            $stmt = $pdo->prepare("SELECT username, bio, profile_img_url FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch();

            if ($profile) {
                // Profile exists
                echo json_encode(['success' => true, 'data' => $profile, 'created' => false]);
            } else {
                // Profile does not exist: create a new record
                $defaultUsername = $display_name ?: ($email ? explode('@', $email)[0] : "User_" . substr($user_id, 0, 4));
                $defaultBio = 'Welcome to your new profile!';
                
                $stmt = $pdo->prepare("INSERT INTO users (user_id, username, bio, profile_img_url) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $defaultUsername, $defaultBio, '']);
                
                // Return the newly created default profile
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'username' => $defaultUsername, 
                        'bio' => $defaultBio, 
                        'profile_img_url' => ''
                    ],
                    'created' => true
                ]);
            }
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchOrCreateProfile: ' . $e->getMessage()]);
        }
        break;

    case 'saveProfileChanges':
        $updateData = $data['data'] ?? [];
        $username = $updateData['username'] ?? null;
        $bio = $updateData['bio'] ?? null;
        $profile_img_url = $updateData['profile_img_url'] ?? null;

        if (!$username || $bio === null || $profile_img_url === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required profile fields']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, bio = ?, profile_img_url = ? WHERE user_id = ?");
            $stmt->execute([$username, $bio, $profile_img_url, $user_id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
            } else {
                // This case handles if the user_id exists but nothing was changed (0 rows affected)
                // or if the user_id doesn't exist. We assume fetchOrCreateProfile runs first.
                echo json_encode(['success' => true, 'message' => 'Profile fields were the same or profile not found (after initial fetch).']);
            }
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in saveProfileChanges: ' . $e->getMessage()]);
        }
        break;

    case 'fetchActivityLog':
        try {
            $stmt = $pdo->prepare("SELECT action, created_at FROM activity WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user_id]);
            $activities = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $activities]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchActivityLog: ' . $e->getMessage()]);
        }
        break;

    case 'addActivity':
        $activity_desc = $data['activity'] ?? null;

        if (!$activity_desc) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing activity description']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO activity (user_id, action, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$user_id, $activity_desc]);

            echo json_encode(['success' => true, 'message' => 'Activity logged successfully']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in addActivity: ' . $e->getMessage()]);
        }
        break;

        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
        break;
}
?>


