<?php
// Offline structural smoke test. Does not require a running database.
$fail=[];$pass=[];
$required=['index.php','config.php','db.php','setup.php','health.php','.htaccess','database/schema.sql','assets/app.css','assets/app.js','setup.bat','run.bat','fix-mysql.bat','tools/fix-mysql.ps1','database/hostel_management.sql','HANDOVER.md','README.md'];
foreach($required as $file){if(is_file(__DIR__.'/'.$file))$pass[]="File exists: $file";else $fail[]="Missing file: $file";}
$schema=file_get_contents(__DIR__.'/database/schema.sql');
foreach(['users','rooms','allocations','complaints','payments','notices','visitors','activity_logs'] as $table){if(str_contains($schema,'CREATE TABLE IF NOT EXISTS '.$table))$pass[]="Schema table: $table";else $fail[]="Missing schema table: $table";}
$app=file_get_contents(__DIR__.'/index.php');
foreach(['password_verify','password_hash','verify_csrf','require_admin','room_request','complaint_status','payment_status','visitor_status'] as $term){if(str_contains($app,$term)||str_contains(file_get_contents(__DIR__.'/config.php'),$term))$pass[]="Security/workflow marker: $term";else $fail[]="Missing marker: $term";}
foreach($pass as $p)echo "PASS - $p\n";foreach($fail as $f)echo "FAIL - $f\n";echo "\nSTRUCTURAL SMOKE TEST: ".($fail?'FAIL':'PASS')." (".count($pass)." passed, ".count($fail)." failed)\n";exit($fail?1:0);
