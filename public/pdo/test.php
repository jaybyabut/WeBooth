<?php
$host = "aws-1-ap-southeast-2.pooler.supabase.com";  
$port = "5432";
$dbname = "postgres";       
$user = "postgres.gnxwhyjuqgopzrfhhbwb";            
$password = "9CRmtcg24Ew21dZA";     

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Connected to Supabase PostgreSQL!<br>";

    // Prepare your SELECT query
    $stmt = $pdo->prepare("SELECT admin FROM users WHERE userid = :userid");
    $stmt->execute([
        ":userid" => "ku3PmqkpYtMXV0lflYHlusPyRT23"
    ]);

    // Fetch result as an associative array
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        echo "<pre>";
        print_r($admin);
        echo "</pre>";
    } else {
        echo "No admin found with that userid.";
    }

} catch (PDOException $e) {
    echo $e->getMessage();
}
?>