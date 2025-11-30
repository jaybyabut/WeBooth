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

    echo "Connected to Supabase PostgreSQL!";
} catch (PDOException $e) {
    echo $e->getMessage();
}
