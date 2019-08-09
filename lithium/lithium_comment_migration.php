<?php

set_time_limit(0);

require_once 'lithium_lib.php';

// db connections

echo "\n".date("D M j G:i:s T Y");
echo "\nstarting...\n\n...connecting to db...";

$lithium_conn = mysql_connect($config[ENV]['lithium_dbhost'], $config[ENV]['lithium_dbuser'], $config[ENV]['lithium_dbpass']) 
    or die("lithium db connection failed!");
mysql_select_db($config[ENV]['lithium_dbname'], $lithium_conn);
mysql_set_charset('utf8', $lithium_conn);

$drupal_conn = mysql_connect($config[ENV]['drupal_dbhost'], $config[ENV]['drupal_dbuser'], $config[ENV]['drupal_dbpass']) 
    or die("drupal db connection failed!");
mysql_select_db($config[ENV]['drupal_dbname'], $drupal_conn);
mysql_set_charset('utf8', $drupal_conn);


if(!isset($argv[1]))  die("missing command line params:  php lithium_node_migration.php min");
    
    
echo "\nstarting to import comments...";

// root nodes.  also add node-to-taxonomy mapping


$min = $argv[1];
$max = $min + PARTITION;
   

// pick up from where it last left off?
$sql = "SELECT MAX(cid) AS new_min FROM comment WHERE cid >= $min AND cid < $max ";
$result = db_query($sql, $drupal_conn);
$row = mysql_fetch_assoc($result);
if(is_numeric($row['new_min'])) {
    $min = $row['new_min'];
}



// just another comment
// parent is the root, which means comment is not a reply-to-a-reply, drupal expects parent pid value to be zero
// using subselects to avoid joins/temporary tables

$sql = "SELECT DISTINCT 
			m.*, 
			(SELECT c.subject FROM message2_content c WHERE c.unique_id = m.unique_id) AS subject, 
			(SELECT c.body FROM message2_content c WHERE c.unique_id = m.unique_id) AS body, 
			(SELECT u1.unique_id FROM unique_id_lookup u1 WHERE u1.node_id = m.node_id and u1.id = m.parent_id) AS parent_unique_id, 
			(SELECT u2.unique_id FROM unique_id_lookup u2 WHERE u2.node_id = m.node_id and u2.id = m.root_id) as root_unique_id, 
			(SELECT u.login_canon FROM users_dec u WHERE u.id = m.user_id) AS login_canon, 
			(SELECT n.deleted FROM nodes n WHERE n.node_id = m.node_id) AS deleted,
			(SELECT n.hidden FROM nodes n WHERE n.node_id = m.node_id) AS hidden,
			(SELECT n.hidden_ancestor FROM nodes n WHERE n.node_id = m.node_id) AS hidden_ancestor,
			(SELECT n.deleted FROM nodes n WHERE n.node_id = m.node_id) AS n_deleted 
			FROM message2 m  
				WHERE m.id != m.root_id 
				AND m.unique_id >= $min AND m.unique_id < $max ";
for($j=0; $j < PARTITION; $j=$j+PARTITION_LIMITS) {
    $limit_offset = $j;
    $limit_count = PARTITION_LIMITS;
    
    $start = microtime(true);
    $result = db_query($sql . "LIMIT $limit_offset, $limit_count ", $lithium_conn);
    $end = microtime(true);
    echo "READ lithium comment $limit_offset, $limit_count rows: " . ($end - $start) . "\n";
    
    $found = false;
    while ($row = mysql_fetch_assoc($result)) {
        $found = true;
        $start = microtime(true);
    
        // comment
        if($row['parent_unique_id'] == $row['root_unique_id']) {
            
            $row['subject'] = escape(html_entity_decode($row['subject']));
            $row['body'] = escape(html_entity_decode($row['body']));
            $row['edit_date'] = cleanDate($row['edit_date']);
            $row['post_date'] = cleanDate($row['post_date']);
            $row['login_canon'] = escape($row['login_canon']);
            
            // any other comments at this level
            $thread_components = array();
            $thread_result = db_query("SELECT thread FROM comment c WHERE pid = 0 AND nid = {$row['root_unique_id']} ORDER BY thread DESC LIMIT 1", $drupal_conn);
            $thread_row = mysql_fetch_assoc($thread_result);
            if(mysql_num_rows($thread_result)) {
                $thread_parent = $thread_row['thread'];
                $thread_parent = substr($thread_parent, 0, strlen($thread_parent)-1);        // chop off trailing slash
                $sequence = vancode2int($thread_parent) + 1;
                
            } else {
                $sequence = 1;
            }
                       
            
            $status = ( $row['deleted'] == 1 || $row['n_deleted'] == 1 || $row['hidden'] == 1 || $row['hidden_ancestor'] == 1 || isDeleted($row['attributes']) || isRejected($row['attributes']) ) ? 0 : 1;
            $changed = ($row['edit_date'] == 0) ? $row['post_date'] : $row['edit_date'];
            
            $thread = int2vancode($sequence) . '/';
    
            $row['user_id'] = fold_duplicate_users($row['user_id'], $lithium_conn);
            
            // FIXME on duplicate key...
            db_query("INSERT INTO comment (cid, pid, nid, uid, subject, created, changed, status, thread, name, language) VALUES
            		({$row['unique_id']}, 0, {$row['root_unique_id']}, {$row['user_id']}, '{$row['subject']}', {$row['post_date']}, $changed, $status, '$thread', '{$row['login_canon']}', 'und')
            		ON DUPLICATE KEY UPDATE status = $status", $drupal_conn);
            // FIXME wysiwg     
            db_query("INSERT IGNORE INTO field_data_comment_body (entity_type, bundle, deleted, entity_id, revision_id, language, delta, comment_body_value, comment_body_format) VALUES
            		('comment', 'comment_node_forum', 0, {$row['unique_id']}, {$row['unique_id']}, 'und', 0, '{$row['body']}', 'wysiwyg')", $drupal_conn);
            db_query("INSERT IGNORE INTO field_revision_comment_body (entity_type, bundle, deleted, entity_id, revision_id, language, delta, comment_body_value, comment_body_format) VALUES
            		('comment', 'comment_node_forum', 0, {$row['unique_id']}, {$row['unique_id']}, 'und', 0, '{$row['body']}', 'wysiwyg')", $drupal_conn);

            // update counts
            if($status == 1) {            
                $count_result = db_query("SELECT 1 FROM forum_index WHERE nid = {$row['root_unique_id']}", $drupal_conn);
                if(mysql_num_rows($count_result)) {            
                    db_query("UPDATE forum_index SET comment_count = comment_count + 1 WHERE nid = {$row['root_unique_id']}", $drupal_conn);
                } else {
                    db_query("INSERT IGNORE INTO forum_index 
                        (nid, title, tid, sticky, created, comment_count) VALUES
                        ({$row['root_unique_id']}, '{$row['subject']}', {$row['node_id']}, 0, {$row['post_date']}, 1)", $drupal_conn);  
                }
            
                $timestamp_result = db_query("SELECT last_comment_timestamp FROM node_comment_statistics WHERE nid = {$row['root_unique_id']}", $drupal_conn);
                if(mysql_num_rows($timestamp_result)) {
                    $timestamp_row = mysql_fetch_assoc($timestamp_result);
                    if($row['post_date'] > $timestamp_row['last_comment_timestamp']) {                    
                        db_query("UPDATE node_comment_statistics 
                        		SET cid = {$row['unique_id']}, last_comment_timestamp = {$row['post_date']}, last_comment_uid = {$row['user_id']}, comment_count = comment_count + 1 
            					WHERE nid = {$row['root_unique_id']}", $drupal_conn);   
                    }
                } else {
                    db_query("INSERT IGNORE INTO node_comment_statistics (nid, cid, last_comment_timestamp, last_comment_uid, comment_count) 
            					VALUES ({$row['root_unique_id']}, {$row['unique_id']}, {$row['post_date']}, {$row['user_id']}, 1)", $drupal_conn);       
                }
            }
            
            
            $end = microtime(true);
            echo "WRITE drupal comment {$row['unique_id']}:  " . ($end - $start) . "\n";
        
        } else {
            
            // reply
            $row['subject'] = escape(html_entity_decode($row['subject']));
            $row['body'] = escape(html_entity_decode($row['body'])); 
            $row['edit_date'] = cleanDate($row['edit_date']);
            $row['post_date'] = cleanDate($row['post_date']);
    
            // any other replies at this level?
            $thread_components = array();
            $thread_result = db_query("SELECT thread FROM comment c WHERE pid = {$row['parent_unique_id']} AND nid = {$row['root_unique_id']} ORDER BY thread DESC LIMIT 1", $drupal_conn);
            $thread_row = mysql_fetch_assoc($thread_result);
            if(mysql_num_rows($thread_result)) {
                $thread_parent = $thread_row['thread'];
                $thread_parent = substr($thread_parent, 0, strlen($thread_parent)-1);
                $thread_components = preg_split('/\./', $thread_parent);
                $sequence = vancode2int($thread_components[count($thread_components) - 1]) + 1;
                unset($thread_components[count($thread_components) - 1]);
                
            } else {
                $sequence = 1;
            }
                
            
            $status = ( $row['deleted'] == 1 || $row['n_deleted'] == 1 || $row['hidden'] == 1 || $row['hidden_ancestor'] == 1 || isDeleted($row['attributes']) || isRejected($row['attributes']) ) ? 0 : 1;
            if($status == 1) {  $count++;  }
            $changed = (is_null($row['edit_date'])) ? $row['post_date'] : $row['edit_date'];
            
            $thread_pre = '';
            foreach($thread_components as $c) {
                $thread_pre .= $c . '.';
            }
            $thread = $thread_pre . int2vancode($sequence) . '/';
            
            $row['user_id'] = fold_duplicate_users($row['user_id'], $lithium_conn);
            
            db_query("INSERT IGNORE INTO comment (cid, pid, nid, uid, subject, created, changed, status, thread, name, language) VALUES
            		({$row['unique_id']}, 0, {$row['root_unique_id']}, {$row['user_id']}, '{$row['subject']}', {$row['post_date']}, $changed, $status, '$thread', '{$row['login_canon']}', 'und')", $drupal_conn);
            // FIXME wysiwg     
            db_query("INSERT IGNORE INTO field_data_comment_body (entity_type, bundle, deleted, entity_id, revision_id, language, delta, comment_body_value, comment_body_format) VALUES
            		('comment', 'comment_node_forum', 0, {$row['unique_id']}, {$row['unique_id']}, 'und', 0, '{$row['body']}', 'wysiwyg')", $drupal_conn);
            db_query("INSERT IGNORE INTO field_revision_comment_body (entity_type, bundle, deleted, entity_id, revision_id, language, delta, comment_body_value, comment_body_format) VALUES
            		('comment', 'comment_node_forum', 0, {$row['unique_id']}, {$row['unique_id']}, 'und', 0, '{$row['body']}', 'wysiwyg')", $drupal_conn);
                      
                
            // update counts
            if($status == 1) {            
                $count_result = db_query("SELECT 1 FROM forum_index WHERE nid = {$row['root_unique_id']}", $drupal_conn);
                if(mysql_num_rows($count_result)) {            
                    db_query("UPDATE forum_index SET comment_count = comment_count + 1 WHERE nid = {$row['root_unique_id']}", $drupal_conn);
                } else {
                    db_query("INSERT IGNORE INTO forum_index 
                        (nid, title, tid, sticky, created, comment_count) VALUES
                        ({$row['root_unique_id']}, '{$row['subject']}', {$row['node_id']}, 0, {$row['post_date']}, 1)", $drupal_conn);  
                }
            
                $timestamp_result = db_query("SELECT last_comment_timestamp FROM node_comment_statistics WHERE nid = {$row['root_unique_id']}", $drupal_conn);
                if(mysql_num_rows($timestamp_result)) {
                    $timestamp_row = mysql_fetch_assoc($timestamp_result);
                    if($row['post_date'] > $timestamp_row['last_comment_timestamp']) {                    
                        db_query("UPDATE node_comment_statistics 
                        		SET cid = {$row['unique_id']}, last_comment_timestamp = {$row['post_date']}, last_comment_uid = {$row['user_id']}, comment_count = comment_count + 1 
            					WHERE nid = {$row['root_unique_id']}", $drupal_conn);   
                    }
                } else {
                    db_query("INSERT IGNORE INTO node_comment_statistics (nid, cid, last_comment_timestamp, last_comment_uid, comment_count) 
            					VALUES ({$row['root_unique_id']}, {$row['unique_id']}, {$row['post_date']}, {$row['user_id']}, 1)", $drupal_conn);       
                }
            }    
                
            
            $end = microtime(true);
            echo "WRITE drupal reply {$row['unique_id']}:  " . ($end - $start) . "\n";
            
            
        }
        
        unset($row);
        
    }

    mysql_free_result($result);
    
    // no more found, exit this loop
    if(!$found)  break;
    
}
        




echo "\n...closing db resources...";
mysql_close($lithium_conn);
mysql_close($drupal_conn);

echo "\n".date("D M j G:i:s T Y")."\nFINISHED\n\n";

?>