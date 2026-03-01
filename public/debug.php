<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YT Archiver</title>
</head>
<body>
 
<?php
echo 'REMOTE_ADDR: ' . $_SERVER['REMOTE_ADDR'] . '<br>';
echo 'CF_CONNECTING_IP: ' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? 'not set') . '<br>';
echo 'X_FORWARDED_FOR: ' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'not set') . '<br>';
echo 'X_REAL_IP: ' . ($_SERVER['HTTP_X_REAL_IP'] ?? 'not set') . '<br>';
?>

</body>
</html>
