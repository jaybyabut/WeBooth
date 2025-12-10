<?php

header('Content-Type: application/json');

// --- START CORS FIX ---
// 1. Allow access from the specific origin where your front-end is running.
// For local development, allow all origins. Tighten this in production.
header('Access-Control-Allow-Origin: *');

// 2. Allow the necessary methods and headers for complex requests (POST).
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 3. Handle the OPTIONS request (the preflight check)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(); // Stop script execution after sending the headers
}
// --- END CORS FIX ---

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

    case 'fetchPhotoLibrary':
        try {
            
            $sql = "SELECT post_id AS id, image_url AS photo_url, created_at FROM post WHERE user_id = ? ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            $posts = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $posts]);
            
        } catch (\PDOException $e) {
            http_response_code(500);
            error_log("Database SELECT error for photo library: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error in fetchPhotoLibrary: ' . $e->getMessage()]);
        }
        break;

    case 'createPost':
        // Required fields: user_id is already checked before the switch
        $image_url = $data['image_url'] ?? null;
        $title = $data['title'] ?? null; // Optional: can be null
        $description = $data['description'] ?? null; // Optional: can be null
        $share_to_community = $data['share_to_community'] ?? false; // Optional: defaults to false
        $template_hash_id = $data['template_hash_id'] ?? null; // Optional

        if (!$image_url) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required image_url for post.']);
            exit();
        }

        try {
            // NOTE: The 'share_to_community' and 'template_hash_id' fields 
            // are not in your provided 'post' table schema, but I'll 
            // include them in the query as placeholders for future extension, 
            // assuming you might add them or adjust the schema.
            // For now, only the fields in your schema are strictly inserted.

            $sql = "INSERT INTO post (user_id, image_url, created_at) VALUES (?, ?, NOW()) RETURNING post_id";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id, 
                $image_url
            ]);
            
            $newPostId = $stmt->fetchColumn(); // Get the newly created post_id

            // Optional: If you want to store title/description/share/template_hash_id, 
            // the 'post' table schema needs to be updated in Supabase. 
            // The logic above fulfills the schema provided in your request.

            echo json_encode([
                'success' => true, 
                'message' => 'Post created successfully', 
                'post_id' => $newPostId,
                'image_url' => $image_url,
                'title' => $title,
                'description' => $description,
                'share_to_community' => $share_to_community,
            ]);
            
        } catch (\PDOException $e) {
            http_response_code(500);
            error_log("Database INSERT error for post: " . $e->getMessage());
            echo json_encode(["success"=> false, "error" => "Database error in createPost: " . $e->getMessage()]);
        }
        break;

    case 'saveTemplateState':
        $templateData = $data['template_state'] ?? [];
        $template_id = $data['template_id'] ?? null; // <<< NEW: Expect the hash ID from JS
        
        if (!$template_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Missing required template ID."]);
            exit();
        }
        
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
        $name = $templateData['name'] ?? null;

        // Normalize payloads
        $stickerValue = $sticker === null ? null : (is_string($sticker) ? $sticker : json_encode($sticker));
        $textValue = $text === null ? null : (is_string($text) ? $text : json_encode($text));

        try {
            // Check if a template with this ID already exists for the user
            $stmt_check = $pdo->prepare("SELECT template_id FROM saved_templates WHERE user_id = ? AND template_id = ?");
            $stmt_check->execute([$user_id, $template_id]);
            
            if ($stmt_check->fetch()) {
                // Update existing template
                $sql = "UPDATE saved_templates SET 
                            sticker = ?, text = ?, font = ?, text_color = ?, background = ?,
                            show_logo = ?, show_date = ?, show_time = ?,
                            frame_size = ?, shot_count = ?, share = ?, effects = ?, template_img = ?, name = ?
                        WHERE user_id = ? AND template_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $stickerValue, $textValue, $font, $text_color, $background,
                    $show_logo, $show_date, $show_time,
                    $frame_size, $shot_count, $share, $effects, $template_img, $name,
                    $user_id, $template_id
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Template updated successfully',
                    'template_id' => $template_id
                ]);
                break;
            }

            // Template does not exist, perform INSERT
            $sql = "INSERT INTO saved_templates (
                        template_id, user_id, sticker, text, font, text_color, background, 
                        show_logo, show_date, show_time, 
                        frame_size, shot_count, share, effects, template_img, name
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, 
                        ?, ?, ?,
                        ?, ?, ?, ?, ?, ?
                    )";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $template_id, $user_id, $stickerValue, $textValue, $font, $text_color, $background,
                $show_logo, $show_date, $show_time,
                $frame_size, $shot_count, $share, $effects, $template_img, $name
            ]);

            echo json_encode([
                'success' => true, 
                'message' => 'Template saved successfully', 
                'template_id' => $template_id
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
            // 1. Try to fetch the existing profile, including the 'admin' column
            // --- MODIFICATION HERE ---
            $stmt = $pdo->prepare("SELECT username, bio, profile_img_url, admin FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch();

            if ($profile) {
                // Profile exists
                // --- MODIFICATION HERE ---
                echo json_encode(['success' => true, 'data' => $profile, 'created' => false]);
            } else {
                // Profile does not exist: create a new record
                $defaultUsername = $display_name ?: ($email ? explode('@', $email)[0] : "User_" . substr($user_id, 0, 4));
                $defaultBio = 'Welcome to your new profile!';
                // Default admin status to FALSE for new users
                $defaultAdmin = false; // <<< Assuming new users are not admins
                
                // --- MODIFICATION HERE ---
                $stmt = $pdo->prepare("INSERT INTO users (user_id, username, bio, profile_img_url, admin) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $defaultUsername, $defaultBio, '', $defaultAdmin]);
                
                // Return the newly created default profile
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'username' => $defaultUsername, 
                        'bio' => $defaultBio, 
                        'profile_img_url' => '',
                        'admin' => $defaultAdmin // <<< Including default admin status
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

    case 'addFavoriteTemplate':
        $template_id = $data['template_id'] ?? null;

        if (!$template_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing template ID']);
            exit();
        }

        try {
            // Avoid duplicate favorites per user/template
            $stmt_check = $pdo->prepare("SELECT favorite_id FROM favorite_template WHERE user_id = ? AND template_id = ?");
            $stmt_check->execute([$user_id, $template_id]);
            $existing = $stmt_check->fetch();

            if ($existing) {
                echo json_encode(['success' => true, 'message' => 'Already favorited', 'favorite_id' => $existing['favorite_id']]);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO favorite_template (user_id, template_id) VALUES (?, ?) RETURNING favorite_id");
            $stmt->execute([$user_id, $template_id]);
            $favoriteId = $stmt->fetchColumn();

            echo json_encode(['success' => true, 'favorite_id' => $favoriteId]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in addFavoriteTemplate: ' . $e->getMessage()]);
        }
        break;

    case 'removeFavoriteTemplate':
        $template_id = $data['template_id'] ?? null;

        if (!$template_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing template ID']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM favorite_template WHERE user_id = ? AND template_id = ?");
            $stmt->execute([$user_id, $template_id]);

            echo json_encode(['success' => true, 'message' => 'Favorite removed']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in removeFavoriteTemplate: ' . $e->getMessage()]);
        }
        break;

    case 'fetchFavoriteTemplates':
        try {
            $stmt = $pdo->prepare("SELECT ft.favorite_id, ft.template_id, st.name, st.frame_size, st.shot_count, st.template_img, st.effects, st.share, st.sticker, st.text, st.background
                                    FROM favorite_template ft
                                    JOIN saved_templates st ON st.template_id = ft.template_id
                                    WHERE ft.user_id = ?
                                    ORDER BY ft.favorite_id DESC");
            $stmt->execute([$user_id]);
            $favorites = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $favorites]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchFavoriteTemplates: ' . $e->getMessage()]);
        }
        break;

    case 'deleteSavedTemplate':
        $template_id = $data['template_id'] ?? null;

        if (!$template_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing template ID']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM saved_templates WHERE user_id = ? AND template_id = ?");
            $stmt->execute([$user_id, $template_id]);

            echo json_encode(['success' => true, 'message' => 'Template deleted successfully']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in deleteSavedTemplate: ' . $e->getMessage()]);
        }
        break;

    case 'fetchSavedTemplates':
        try {
            $stmt = $pdo->prepare("SELECT template_id, name, frame_size, shot_count, template_img, effects, share, sticker, text, background, created_at FROM saved_templates WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user_id]);
            $templates = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $templates]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchSavedTemplates: ' . $e->getMessage()]);
        }
        break;

    case 'fetchSharedTemplates':
        try {
            $stmt = $pdo->prepare("SELECT template_id, name, frame_size, shot_count, template_img, effects, share, sticker, text, background, created_at, user_id FROM saved_templates WHERE share = TRUE ORDER BY created_at DESC");
            $stmt->execute();
            $templates = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $templates]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchSharedTemplates: ' . $e->getMessage()]);
        }
        break;

        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
        break;
}
?>
