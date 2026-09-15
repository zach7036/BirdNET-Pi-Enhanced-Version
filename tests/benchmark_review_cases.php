<?php
// Opt-in synthetic benchmark. Uses memory only, never the station database.
require_once __DIR__ . '/../scripts/common.php';
$db = new SQLite3(':memory:'); $db->enableExceptions(true);
review_exec($db, 'CREATE TABLE detections (Date TEXT,Time TEXT,Sci_Name TEXT,Com_Name TEXT,Confidence REAL,File_Name TEXT)');
review_exec($db, "WITH RECURSIVE nums(n) AS (SELECT 0 UNION ALL SELECT n+1 FROM nums WHERE n<999)
  INSERT INTO detections SELECT DATE('now','localtime','-30 days'),'10:00:00','Species '||(a.n%20),'Test Bird',.99,'old-'||(a.n*1000+b.n) FROM nums a CROSS JOIN nums b");
review_exec($db, "WITH RECURSIVE nums(n) AS (SELECT 0 UNION ALL SELECT n+1 FROM nums WHERE n<9999)
  INSERT INTO detections SELECT DATE('now','localtime','-1 day'),TIME('00:00:00','+'||(n*3)||' seconds'),'Species '||(n%20),'Test Bird',.75,'recent-'||n FROM nums");
foreach (['CREATE INDEX bench_date ON detections(Date,Time)', 'CREATE INDEX bench_sci_date ON detections(Sci_Name,Date)', 'CREATE INDEX bench_file ON detections(File_Name)'] as $sql) review_exec($db,$sql);
for ($i=0;$i<20;$i++) save_detection_review($db,['file_name'=>'old-'.($i*1000),'status'=>'confirmed']);
$checks = 0;
$options = ['limit'=>0,'clip_available'=>function()use(&$checks){$checks++;return true;}];
$start=microtime(true);$data=review_cases_data($db,$options);$seconds=microtime(true)-$start;
if ($data['counts']['all'] !== 20 || $data['counts']['recommended'] !== 0 || $checks > 60) throw new RuntimeException('Unexpected benchmark selection: '.json_encode([$data['counts'],$checks]));
echo json_encode(['detections'=>1010000,'recent'=>10000,'seconds'=>round($seconds,3),'file_checks'=>$checks,'peak_mb'=>round(memory_get_peak_usage(true)/1048576,1),'counts'=>$data['counts']],JSON_PRETTY_PRINT)."\n";
