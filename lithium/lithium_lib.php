<?php
define("ENV", "qa");

define("PARTITION", 3500000);
define("PARTITION_MAX", 141820260);
define("PARTITION_LIMITS", 1000);

$config = array(
    'qa'   => array(
        'lithium_dbhost' => 'lithium-db.stg1',
        'lithium_dbname' => 'lithium_ivuk',
        'lithium_dbuser' => 'lithium',
        'lithium_dbpass' => 'l1th14m',   
        'drupal_dbhost' => 'lithium-db.stg1',
        'drupal_dbname' => 'drupal_mbrddb_migration_uk_qa',
        'drupal_dbuser' => 'migrator',
        'drupal_dbpass' => 'm1gr@ateme!'
     ),
    'prod' => array(
        'lithium_dbhost' => 'lithium-db.stg1',
        'lithium_dbname' => 'lithium_ivuk',
        'lithium_dbuser' => 'lithium',
        'lithium_dbpass' => 'l1th14m', 
        'drupal_dbhost' => '',
        'drupal_dbname' => '',
        'drupal_dbuser' => '',
        'drupal_dbpass' => ''
     )
);


function db_query($sql, &$conn) {
    $result = mysql_query($sql, $conn);
    if(!$result) {
        die('invalid query: [' .$sql. '] ['. mysql_error($conn).'] ['. mysql_errno($conn).']');
    }
    return $result;
}

function escape($str) {
    return mysql_real_escape_string($str);
}

function int2vancode($i = 0) {
    $num = base_convert((int) $i, 10, 36);
    $length = strlen($num);
    return chr($length + ord('0') - 1) . $num;
}

function vancode2int($c = '00') {
    return base_convert(substr($c, 1), 36, 10);
}

function isDeleted($dec) {
    $bin = decbin($dec);
    $pos = strlen($bin) - 1;
    return ( ($pos >= 0) && isset($bin[$pos]) && $bin[$pos] == 1 );    
}

function isReadOnly($dec) {
    $bin = decbin($dec);
    $pos = strlen($bin) - 2; 
    return ( ($pos >= 0) && isset($bin[$pos]) && $bin[$pos] == 1 );   
}

function isRejected($dec) {
    $bin = decbin($dec);
    $pos = strlen($bin) - 25;    
    return ( ($pos >= 0) && isset($bin[$pos]) && $bin[$pos] == 1 );
}

function cleanDate($date) {
    // boo Lithium
    return round( $date / 1000 );
}

function timer($str) {
    echo $str . ": " . time() ."\n";
    
}

function fold_duplicate_users($user_id, $conn) {
    $result = db_query("SELECT nlogin FROM users_duplicates WHERE user_id = $user_id", $conn);
    if(mysql_num_rows($result) == 0) return $user_id;
    $row = mysql_fetch_assoc($result);
    $result = db_query("SELECT MIN(user_id) AS user_id FROM users_duplicates WHERE nlogin = '{$row['nlogin']}'", $conn);
    $row = mysql_fetch_assoc($result);
    return $row['user_id'];
    
}

?>