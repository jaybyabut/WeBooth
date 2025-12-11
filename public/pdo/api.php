<?php
error_reporting(0);
ini_set('display_errors', 0);

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

// Allow certain actions without a specific user_id (admin-level or public stats)
if (!$user_id && !in_array($action, ['fetchOrCreateProfile', 'test', 'fetchUsageStats', 'fetchUsers', 'fetchUserPhotoCount', 'fetchActivityLog', 'updateUser', 'deleteUser'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User ID required for this action']);
    exit();
}


// --- API HANDLER ---
switch ($action) {
    case 'test':
        echo json_encode(['success' => true, 'message' => 'API is working']);
        break;

    case 'fetchUsers':
        try {
            $search = $data['search'] ?? null; // optional text search
            $role = $data['role'] ?? null;     // optional role filter
            $adminOnly = null; // default to null (no filter)
            
            // Only apply admin_only filter if it's explicitly provided and not empty
            if (isset($data['admin_only']) && $data['admin_only'] !== '') {
                $adminOnly = filter_var($data['admin_only'], FILTER_VALIDATE_BOOLEAN);
            }

            $conditions = [];
            $params = [];

            if ($search) {
                $conditions[] = "(username ILIKE ? OR email ILIKE ? OR bio ILIKE ?)";
                $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
            }
            if ($role) {
                $conditions[] = "(role = ?)";
                $params[] = $role;
            }
            if ($adminOnly !== null) {
                $conditions[] = "(admin = ?)";
                $params[] = $adminOnly ? 1 : 0; // Cast boolean to integer for PostgreSQL
            }

            $where = count($conditions) ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $sql = "SELECT user_id, created_at, username, email, profile_img_url, provider, role, bio, admin, status FROM users $where ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $users]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchUsers: ' . $e->getMessage()]);
        }
        break;

    case 'updateUser':
        // Admin updates arbitrary user fields
        $target_user_id = $data['target_user_id'] ?? null;
        $update = $data['update'] ?? [];

        if (!$target_user_id || empty($update) || !is_array($update)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing target_user_id or update payload']);
            break;
        }

        try {
            // Whitelist updatable columns
            $allowed = ['username','email','profile_img_url','provider','role','bio','admin','status'];
            $sets = [];
            $params = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $update)) {
                    $updateVal = $update[$col];
                    if ($col === 'admin') {
                        // Convert to boolean - handle both boolean and string inputs
                        // Convert to 0 or 1 for proper PostgreSQL binding
                        $val = ($updateVal === true || $updateVal === 'true' || $updateVal === 1 || $updateVal === '1') ? 1 : 0;
                        $sets[] = "$col = ?";
                        $params[] = $val;
                    } else {
                        // Skip empty values for non-admin fields
                        if ($updateVal === '' || $updateVal === null) {
                            continue;
                        }
                        $sets[] = "$col = ?";
                        $params[] = $updateVal;
                    }
                }
            }
            if (empty($sets)) {
                echo json_encode(['success' => true, 'message' => 'No changes']);
                break;
            }
            $params[] = $target_user_id;
            $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true, 'message' => 'User updated']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in updateUser: ' . $e->getMessage()]);
        }
        break;

    case 'deleteUser':
        $target_user_id = $data['target_user_id'] ?? null;
        if (!$target_user_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing target_user_id']);
            break;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            echo json_encode(['success' => true, 'message' => 'User deleted']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in deleteUser: ' . $e->getMessage()]);
        }
        break;

    case 'fetchUsageStats':
        try {
            // Define time boundaries (Last 30 days needed for charts)
            $start_of_current_week = (new DateTime('last Sunday'))->format('Y-m-d H:i:s');
            $start_of_previous_week = (new DateTime('last Sunday - 7 days'))->format('Y-m-d H:i:s');
            $start_of_last_30_days = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
            
            // --- 1. Total User Count & Weekly Growth Components ---
            $stmt = $pdo->prepare("SELECT COUNT(user_id) AS total FROM users");
            $stmt->execute();
            $total_users = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(user_id) AS count FROM users WHERE created_at >= ?");
            $stmt->execute([$start_of_current_week]);
            $current_week_users = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(user_id) AS count FROM users WHERE created_at >= ? AND created_at < ?");
            $stmt->execute([$start_of_previous_week, $start_of_current_week]);
            $previous_week_users = (int)$stmt->fetchColumn();

            // --- 2. Total Photos Captured & Weekly Growth Components ---
            $stmt = $pdo->prepare("SELECT COUNT(post_id) AS total FROM post"); 
            $stmt->execute();
            $total_photos = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(post_id) AS count FROM post WHERE created_at >= ?");
            $stmt->execute([$start_of_current_week]);
            $current_week_photos = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(post_id) AS count FROM post WHERE created_at >= ? AND created_at < ?");
            $stmt->execute([$start_of_previous_week, $start_of_current_week]);
            $previous_week_photos = (int)$stmt->fetchColumn();
            
            // --- 3. Daily User Signups (for User Growth Chart) ---
            $sql_daily_signups = "
                SELECT 
                    TO_CHAR(DATE_TRUNC('day', created_at), 'YYYY-MM-DD') AS date,
                    COUNT(user_id) AS count
                FROM users
                WHERE created_at >= ?
                GROUP BY 1
                ORDER BY 1;
            ";
            $stmt = $pdo->prepare($sql_daily_signups);
            $stmt->execute([$start_of_last_30_days]);
            $daily_signups = $stmt->fetchAll();
            
            // --- 4. User Status Distribution (for Pie Chart) ---
            $sql_status_distribution = "
                SELECT 
                    COALESCE(status, 'Unknown') AS status,
                    COUNT(*) AS count
                FROM users
                GROUP BY status
                ORDER BY count DESC;
            ";
            $stmt = $pdo->query($sql_status_distribution);
            $status_distribution = $stmt->fetchAll();

            // --- 5. Provider Distribution (for Donut Chart) ---
            $sql_provider_distribution = "
                SELECT 
                    COALESCE(provider, 'Unknown') AS provider,
                    COUNT(*) AS count
                FROM users
                GROUP BY provider
                ORDER BY count DESC;
            ";
            $stmt = $pdo->query($sql_provider_distribution);
            $provider_distribution = $stmt->fetchAll();

            // --- 6. Top Favorited Templates (for Bar Chart) ---
            $sql_top_favorites = "
                SELECT 
                    COALESCE(st.name, 'Unknown') AS title,
                    COUNT(ft.favorite_id) AS favorite_count
                FROM favorite_template ft
                JOIN saved_templates st ON st.template_id = ft.template_id
                GROUP BY st.name
                ORDER BY favorite_count DESC
                LIMIT 20;
            ";
            $stmt = $pdo->query($sql_top_favorites);
            $top_favorited_templates = $stmt->fetchAll();

            // --- 5. Growth Calculation Helper Function ---
            $calculate_growth = function($current, $previous) {
                if ($previous > 0) {
                    $growth = (($current - $previous) / $previous) * 100;
                } elseif ($current > 0) {
                    $growth = 100;
                } else {
                    $growth = 0;
                }
                return round($growth, 1);
            };

            // --- 6. Prepare Final Response Data ---
            $response_data = [
                'total_users' => $total_users,
                'user_growth_percentage' => $calculate_growth($current_week_users, $previous_week_users),
                'daily_user_signups' => $daily_signups,
                // Photo metrics retained for backward compatibility if needed
                'total_photos' => $total_photos,
                'photo_growth_percentage' => $calculate_growth($current_week_photos, $previous_week_photos),
                // New distributions
                'status_distribution' => $status_distribution,
                'provider_distribution' => $provider_distribution,
                'top_favorited_templates' => $top_favorited_templates,
            ];

            echo json_encode(['success' => true, 'data' => $response_data]);

        } catch (\PDOException $e) {
            http_response_code(500);
            error_log("Database error in fetchUsageStats: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error in fetchUsageStats: ' . $e->getMessage()]);
        }
        break;

    case 'fetchPhotoLibrary':
        try {
            
            $sql = "SELECT post_id AS id, image_url AS photo_url, title, description, created_at FROM post WHERE user_id = ? ORDER BY created_at DESC";
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

    case 'fetchUserPhotoCount':
        // Fetch the count of posts for a specific user
        $target_user_id = $data['target_user_id'] ?? null;
        
        if (!$target_user_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing target_user_id']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare("SELECT COUNT(post_id) AS photo_count FROM post WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $result = $stmt->fetch();
            
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in fetchUserPhotoCount: ' . $e->getMessage()]);
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
            // Insert post with title and description

            $sql = "INSERT INTO post (user_id, image_url, title, description, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING post_id";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id, 
                $image_url,
                $title,
                $description
            ]);
            
            $newPostId = $stmt->fetchColumn(); // Get the newly created post_id

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
            // 1. Try to fetch the existing profile, including the 'admin' and 'status' columns
            $stmt = $pdo->prepare("SELECT username, bio, profile_img_url, admin, status FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch();

            if ($profile) {
                // Profile exists
                echo json_encode(['success' => true, 'data' => $profile, 'created' => false]);
            } else {
                // Profile does not exist: create a new record
                $defaultUsername = $display_name ?: ($email ? explode('@', $email)[0] : "User_" . substr($user_id, 0, 4));
                $defaultBio = 'Welcome to your new profile!';
                // Default admin status to FALSE for new users
                $defaultAdmin = false; // <<< Assuming new users are not admins
                $defaultStatus = 'active'; // Default status is 'active'
                
                // Create new user with status field
                $stmt = $pdo->prepare("INSERT INTO users (user_id, username, bio, profile_img_url, admin, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $defaultUsername, $defaultBio, '', $defaultAdmin, $defaultStatus]);
                
                // Return the newly created default profile
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'username' => $defaultUsername, 
                        'bio' => $defaultBio, 
                        'profile_img_url' => '',
                        'admin' => $defaultAdmin,
                        'status' => $defaultStatus
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
            // If target_user_id is provided, fetch for that specific user
            // Otherwise, fetch all activity logs (for audit trail)
            if (isset($data['target_user_id'])) {
                $target_user_id = $data['target_user_id'];
                $stmt = $pdo->prepare("SELECT action, created_at, user_id FROM activity WHERE user_id = ? ORDER BY created_at DESC");
                $stmt->execute([$target_user_id]);
            } else {
                // Fetch all activity logs for audit trail
                $stmt = $pdo->prepare("SELECT action, created_at, user_id FROM activity ORDER BY created_at DESC");
                $stmt->execute();
            }
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

    // Admin-only template management endpoints
    case 'adminFetchAllTemplates':
        try {
            $stmt = $pdo->prepare("SELECT st.template_id, st.name, st.frame_size, st.shot_count, st.template_img, st.effects, st.share, st.created_at, st.user_id,
                                          COALESCE(u.username, u.email, st.user_id) AS owner_name
                                     FROM saved_templates st
                                LEFT JOIN users u ON u.user_id = st.user_id
                                 ORDER BY st.created_at DESC");
            $stmt->execute();
            $templates = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $templates]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in adminFetchAllTemplates: ' . $e->getMessage()]);
        }
        break;

    case 'adminUpdateTemplate':
        $template_id = $data['template_id'] ?? null;
        $update = $data['update'] ?? [];
        if (!$template_id || empty($update)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing template_id or update payload']);
            break;
        }
        try {
            $allowed = ['name','share'];
            $sets = [];
            $params = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $update)) {
                    $val = $update[$col];
                    if ($col === 'share') {
                        $val = ($val === true || $val === 'true' || $val === 1 || $val === '1') ? 1 : 0;
                    }
                    $sets[] = "$col = ?";
                    $params[] = $val;
                }
            }
            if (empty($sets)) {
                echo json_encode(['success' => true, 'message' => 'No changes']);
                break;
            }
            $params[] = $template_id;
            $sql = "UPDATE saved_templates SET " . implode(', ', $sets) . " WHERE template_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Template updated']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in adminUpdateTemplate: ' . $e->getMessage()]);
        }
        break;

    case 'adminDeleteTemplate':
        $template_id = $data['template_id'] ?? null;
        if (!$template_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing template_id']);
            break;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM saved_templates WHERE template_id = ?");
            $stmt->execute([$template_id]);
            echo json_encode(['success' => true, 'message' => 'Template deleted']);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error in adminDeleteTemplate: ' . $e->getMessage()]);
        }
        break;

        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
        break;
}
?>
