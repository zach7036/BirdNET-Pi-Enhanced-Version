<?php
// Synthetic databases only. The existing compatibility suite runs first.
require __DIR__ . '/test_review_workflow.php';
$case_checks_start = $checks;
function case_options($db, $extra = []) {
  return $extra + ['now' => strtotime(day($db) . ' 23:59:59'), 'gap_seconds' => 300, 'clip_available' => function () { return true; }];
}
function cases_data($db, $extra = []) { return review_cases_data($db, case_options($db, $extra)); }
function first_case($db, $extra = []) { return cases_data($db, $extra)['cases'][0]; }
function case_body($case, $action = 'confirm', $index = 0) {
  $body = ['request_id' => bin2hex(random_bytes(16)), 'action' => $action,
    'case' => array_intersect_key($case, array_flip(['key','version','date','end_date','sci_name']))];
  if (isset($case['evidence'][$index])) $body['files'] = [array_intersect_key($case['evidence'][$index], array_flip(['file_name','file_revision']))];
  return $body;
}
function case_save($db, $body, $options = []) { return save_review_case($db, $body, case_options($db, $options)); }
function case_undo($db, $save) { return case_save($db, ['request_id' => bin2hex(random_bytes(16)), 'action' => 'undo', 'undo_token' => $save['undo_token']]); }
function case_fixture() {
  $db = fixture();
  for ($i = 0; $i < 12; $i++) detection($db, 'case-' . $i . '.wav', gmdate('H:i:s', 36000 + $i * 600), .70 + $i * .02);
  return $db;
}

$db = case_fixture(); $original = rows($db);
$c = first_case($db);
same(cases_data($db)['total'], 1, 'Twelve visits form one discovery question');
same($c['visits'], 12, 'Case describes underlying visits');
same(count($c['evidence']), 3, 'Three supporting recordings by default');
same(count(array_unique(array_column($c['evidence'], 'visit_key'))), 3, 'Evidence uses different visits');
same(cases_data($db, ['limit' => 0])['total'], 1, 'Count-only matches recommended');
same(review_table_exists($db, 'review_cases'), false, 'GET does not create metadata');
$body = case_body($c); $save = case_save($db, $body);
same(count_reviews($db), 1, 'Only selected recording confirmed');
same(cases_data($db)['counts']['recommended'], 0, 'Presence confirmation resolves discovery');
same(review_confirmed_presence($db)[0]['individually_checked'], 1, 'Presence records individually checked support');
same(case_save($db, $body), $save, 'Lost-response retry returns the identical result');
same(count_reviews($db), 1, 'Retry does not duplicate a verdict');
$collision = $body; $collision['action'] = 'reject'; fails(function () use ($db,$collision) { case_save($db,$collision); }, ReviewConflict::class);
case_undo($db, $save);
same(count_reviews($db), 0, 'Undo removes only the new confirmation');
same(count(review_confirmed_presence($db)), 0, 'Undo removes unsupported presence');
same(cases_data($db)['total'], 1, 'Undo restores discovery');
same(rows($db), $original, 'All raw detections unchanged');
same(case_undo($db, $save)['already_undone'], true, 'Repeated Undo is safe');

$db = case_fixture(); $c = first_case($db); $reject = case_save($db, case_body($c,'reject'));
same(cases_data($db)['total'], 1, 'Rejecting strongest evidence does not establish absence');
same(first_case($db)['evidence'][0]['file_name'] !== $c['evidence'][0]['file_name'], true, 'Next supporting clip is offered');
$confirm = case_save($db, case_body(first_case($db)));
same(count(review_confirmed_presence($db)), 1, 'Another clip establishes presence despite rejected candidate');
case_undo($db,$confirm); case_undo($db,$reject);
same(count_reviews($db), 0, 'Chained undo restores original metadata');

$db = case_fixture(); $c = first_case($db); $unknown = case_save($db, case_body($c,'uncertain'));
same(count_reviews($db), 0, 'Cannot tell writes no identification verdict');
same(cases_data($db)['total'], 0, 'Unresolved is not recommended homework');
same(first_case($db,['view'=>'history'])['state'], 'unresolved', 'Unresolved retained in history');
$later = cases_data($db, ['now'=>strtotime(day($db).' 23:59:59')+2*86400]);
same($later['total'], 0, 'Two days alone do not reopen uncertainty');
case_undo($db,$unknown);
same(cases_data($db)['total'], 1, 'Uncertainty Undo restores question');
$postpone = case_save($db, case_body(first_case($db),'later'));
same(cases_data($db)['total'], 0, 'Later postpones without verdict');
same(cases_data($db,['now'=>strtotime(day($db).' 23:59:59')+86401])['total'], 1, 'Later expires after 24 hours');
$resume = case_save($db,case_body(first_case($db,['view'=>'history']),'resume'));
same(cases_data($db)['total'], 1, 'Resume opens early');
case_undo($db,$resume); case_undo($db,$postpone);

$db=fixture(); detection($db,'faint.wav','10:00:00',.60);
$unknown=case_save($db,case_body(first_case($db),'uncertain'));
detection($db,'similar.wav','11:00:00',.61);
same(cases_data($db)['total'],0,'Tiny score changes do not reopen uncertainty');
detection($db,'new-evidence.wav','12:00:00',.80);
same(cases_data($db)['total'],1,'A materially stronger candidate from another visit reopens');
check(!empty(first_case($db)['reopened_reason']),'Reopened case explains why');

$db=case_fixture();$c=first_case($db);
$save=case_save($db,case_body($c,'later'));
fails(function()use($db,$c){case_save($db,case_body($c));},ReviewConflict::class);
case_undo($db,$save);
$c=first_case($db);$save=case_save($db,case_body($c));
save_detection_review($db,['file_name'=>$c['evidence'][0]['file_name'],'status'=>'confirmed']);
fails(function()use($db,$save){case_undo($db,$save);},ReviewConflict::class);
same(count_reviews($db),1,'Conflicting newer legacy decision is protected');

$db=case_fixture();$c=first_case($db);$bulk=case_body($c);
$bulk['files'][]=array_intersect_key($c['evidence'][1],array_flip(['file_name','file_revision']));
fails(function()use($db,$bulk){case_save($db,$bulk);},InvalidArgumentException::class);
$bulk['bulk_confirmed']=true;
detection($db,'arrived-later.wav','15:00:00',.99);
$save=case_save($db,$bulk);
same(count_reviews($db),2,'Explicit bulk applies only selected files, not new detections');
same((int)$db->querySingle("SELECT COUNT(*) FROM detection_reviews WHERE file_name='arrived-later.wav'"),0,'New arrival remains unverified');
case_undo($db,$save);
same(count_reviews($db),0,'Bulk undo restores each selected member');

$db=case_fixture();$c=first_case($db);
$noaudio=['clip_available'=>function(){return false;}];
same(cases_data($db,$noaudio)['total'],0,'No available audio means no recommended task');
same(cases_data($db,$noaudio)['counts']['unavailable'],1,'Missing audio is represented separately');
fails(function()use($db,$c,$noaudio){case_save($db,case_body($c),$noaudio);},ReviewConflict::class);
same(review_table_exists($db,'detection_reviews'),false,'Missing audio cannot be confirmed; failed first write leaves no schema');

$db=case_fixture();$c=first_case($db);review_case_schema($db);
$second=$c['evidence'][1]['file_name'];
$db->exec("CREATE TRIGGER fail_guided BEFORE INSERT ON detection_reviews WHEN NEW.file_name='".SQLite3::escapeString($second)."' BEGIN SELECT RAISE(ABORT,'failure'); END");
$b=case_body($c);$b['files'][]=array_intersect_key($c['evidence'][1],array_flip(['file_name','file_revision']));$b['bulk_confirmed']=true;
fails(function()use($db,$b){case_save($db,$b);});
same(count_reviews($db),0,'Failed bulk rolls back earlier members');
same((int)$db->querySingle('SELECT COUNT(*) FROM review_cases'),0,'Failed bulk creates no case state');
$db->exec('DROP TRIGGER fail_guided');
$db->exec("CREATE TRIGGER fail_case_journal BEFORE INSERT ON review_case_actions BEGIN SELECT RAISE(ABORT,'failure'); END");
fails(function()use($db,$b){case_save($db,$b);});
same(count_reviews($db),0,'Journal failure rolls back verdicts');

$db=case_fixture();$c=first_case($db);$save=case_save($db,case_body($c));
$file=$c['evidence'][0]['file_name'];
review_query($db,'UPDATE detections SET Sci_Name=:sci,Com_Name=:com,File_Name=:new WHERE File_Name=:old',[':sci'=>'Correctus birdus',':com'=>'Correct Bird',':new'=>'renamed.wav',':old'=>$file])->finalize();
review_archive_renamed_metadata($db,$file,'renamed.wav');
same(count(review_confirmed_presence($db)),0,'Reassignment does not transfer confirmation to another species');
same((int)$db->querySingle('SELECT COUNT(*) FROM review_rename_archive'),1,'Old review retained in rename archive');
fails(function()use($db,$save){case_undo($db,$save);},ReviewConflict::class);

$db=case_fixture();$legacy=verdict($db,'confirmed','10:00:00','11:50:00');
same(review_confirmed_presence($db)[0]['individually_checked'],0,'Old whole-visit reviews are not called individually heard');
same(review_confirmed_presence($db)[0]['supporting_recordings'],12,'Legacy confirmations retained');
undo_detection_review($db,$legacy['undo_token']);
same(count(review_confirmed_presence($db)),0,'Legacy Undo remains functional');

$db=fixture();
foreach(['Alpha bird','Beta bird','Gamma bird'] as $i=>$sci){
  detection($db,'known'.$i,'10:00:00',.99,5,$sci);
  save_detection_review($db,['file_name'=>'known'.$i,'status'=>'confirmed']);
  detection($db,'sample'.$i,'12:00:00',.99,1,$sci);
}
$sampledata=cases_data($db,['view'=>'samples']);
same($sampledata['total'],2,'Daily optional sample is bounded to two species');
$samplekeys=array_column($sampledata['cases'],'key');
$b=case_body($sampledata['cases'][0]);$b['source']='sample';$save=case_save($db,$b);
same(cases_data($db,['view'=>'samples'])['total'],1,'Finishing a sample does not add a replacement');
same(cases_data($db)['total'],0,'Optional checks do not inflate dashboard count');
$h=review_evidence_history($db,null,case_options($db)['now']);
same($h[$sampledata['cases'][0]['sci_name']]['sample']['confirmed'],1,'Sampled evidence is recorded separately');
case_undo($db,$save);
same(array_column(cases_data($db,['view'=>'samples'])['cases'],'key'),$samplekeys,'Undo restores stable sample selection');

// An external/legacy clear must not leave a stale cached presence flag.
$db=case_fixture();$c=first_case($db);$save=case_save($db,case_body($c));
save_detection_review($db,['file_name'=>$c['evidence'][0]['file_name'],'status'=>'clear']);
same(cases_data($db)['counts']['recommended'],1,'Clearing last supporting verdict reopens discovery');

// Older unresolved questions remain visible outside the current date window.
$db=fixture();detection($db,'old-question.wav','12:00:00',.80,20);
$c=first_case($db,['start'=>day($db,20),'end'=>day($db,20)]);
case_save($db,case_body($c,'uncertain'));
same(first_case($db,['view'=>'history'])['state'],'unresolved','Acted-on older question retained in history');
same(cases_data($db)['total'],0,'Older unresolved questions do not become daily homework');

// An active visit alone is held back; earlier completed evidence is usable.
$db=fixture();detection($db,'active.wav','23:59:00',.90);
same(cases_data($db)['counts']['active'],1,'Active-only question is held back');
detection($db,'finished.wav','12:00:00',.80);
same(cases_data($db)['total'],1,'Completed visit supports a question despite another active visit');
same(first_case($db)['evidence'][0]['file_name'],'finished.wav','Active recording is never offered as completed evidence');

// New independent confirmations remain if an earlier confirmation is undone.
$db=case_fixture();$c=first_case($db);$save=case_save($db,case_body($c));
save_detection_review($db,['file_name'=>'case-0.wav','status'=>'confirmed']);
case_undo($db,$save);
same(count(review_confirmed_presence($db)),1,'Undo does not erase another supporting confirmation');

// A single long visit needs only three file checks when all files survive.
$db=fixture();for($i=0;$i<1000;$i++)detection($db,'continuous'.$i,gmdate('H:i:s',36000+$i),.70);
$file_checks=0;cases_data($db,['limit'=>0,'clip_available'=>function()use(&$file_checks){$file_checks++;return true;}]);
same($file_checks,3,'A chatty single visit does not stat every recording');

// Same-score-band sampling reduces ordering, not visibility or truth status.
$db=fixture();review_case_schema($db);
for($i=0;$i<10;$i++){
  $file='sample-history-'.$i;detection($db,$file,gmdate('H:i:s',36000+$i*600),.95,10+($i%3));
  save_detection_review($db,['file_name'=>$file,'status'=>'confirmed']);
  $target=review_rows($db,'SELECT File_Name,Sci_Name,Com_Name,Date,Time FROM detections WHERE File_Name=:f',[':f'=>$file])[0];
  $version=review_metadata_state($db,$file)['version']['token'];
  review_case_restore_row($db,'review_evidence','file_name',$file,['file_name'=>$file,'target_json'=>json_encode($target),'review_token'=>$version,'case_key'=>'fixture','source'=>'sample','visit_key'=>'independent-'.$i,'score'=>.95,'checked_at'=>time()]);
}
detection($db,'routine-today','12:00:00',.75);
same(first_case($db,['view'=>'all'])['lower_priority'],false,'Strong samples do not establish trust for weaker scores');
review_exec($db,'UPDATE review_evidence SET score=.75');
same(first_case($db,['view'=>'all'])['lower_priority'],true,'Ten comparable positive samples across dates lower routine priority');
same(cases_data($db,['view'=>'all'])['total'],1,'Lower-priority evidence remains available');
save_detection_review($db,['file_name'=>'sample-history-0','status'=>'false_positive']);
same(first_case($db,['view'=>'all'])['lower_priority'],false,'A changed decision invalidates stale sampled support');

// Read requests finish their SQLite statements before filesystem work, so a
// recorder on another connection can commit while the response is assembled.
$temp_db=tempnam(sys_get_temp_dir(),'birdnet-guided-lock-');
$db=fixture(new SQLite3($temp_db));detection($db,'before.wav','12:00:00',.75);
$writer=new SQLite3($temp_db);$writer->enableExceptions(true);$writer->busyTimeout(100);
$wrote=false;
try {
  $data=cases_data($db,['clip_available'=>function()use($writer,&$wrote){
    if(!$wrote){$writer->exec('BEGIN IMMEDIATE');detection($writer,'during.wav','14:00:00',.99);$writer->exec('COMMIT');$wrote=true;}return true;
  }]);
  check($wrote,'Concurrent recorder can commit during read-only evidence checks');
  same($data['cases'][0]['detections'],1,'In-flight read does not mix a later recording into its selection');
  same(cases_data($db)['cases'][0]['detections'],2,'Next refresh discovers the newly committed recording');
} finally {$writer->close();$db->close();unlink($temp_db);}

// A failing Undo is atomic and its original token remains retryable.
$db=case_fixture();$c=first_case($db);$save=case_save($db,case_body($c));
$before=rows($db,'detection_reviews');
$db->exec("CREATE TRIGGER fail_case_undo BEFORE DELETE ON review_cases BEGIN SELECT RAISE(ABORT,'failure'); END");
fails(function()use($db,$save){case_undo($db,$save);});
same(rows($db,'detection_reviews'),$before,'Failed Undo rolls back its restored recording metadata');
$db->exec('DROP TRIGGER fail_case_undo');case_undo($db,$save);
same(count_reviews($db),0,'Undo can be retried after a transient failure');

// Reassignment must not archive a review changed after its pre-rename snapshot.
$db=case_fixture();$c=first_case($db);case_save($db,case_body($c));
$file=$c['evidence'][0]['file_name'];$expected=review_rename_state($db,$file);
save_detection_review($db,['file_name'=>$file,'status'=>'false_positive']);
fails(function()use($db,$file,$expected){review_archive_renamed_metadata($db,$file,'new.wav',$expected);},ReviewConflict::class);
same(review_metadata_state($db,$file)['review']['status'],'false_positive','Newer review survives reassignment metadata conflict');

// Story copy distinguishes model-only discoveries from human confirmation.
$db=case_fixture();$story=build_todays_story($db);
check(strpos(json_encode($story),'Possible new species')!==false,'Unverified discovery is labeled possible');
case_save($db,case_body(first_case($db)));$story=build_todays_story($db);
check(strpos(json_encode($story),'human-confirmed presence')!==false,'Confirmed presence gets an explicit story label');

// A scoped save performs file checks only for its explicit selection, keeping
// SQLite writer time independent of unrelated questions' missing audio.
$db=case_fixture();detection($db,'another-species','12:00:00',.8,0,'Another bird');
$c=first_case($db);$io=0;
case_save($db,case_body($c),['clip_available'=>function()use(&$io){$io++;return true;}]);
same($io,1,'Single-clip save checks only that recording while holding writer lock');

// The date limit counts inclusive calendar dates, including daylight changes.
$db=fixture();
same(cases_data($db,['start'=>'2026-03-01','end'=>'2026-03-31'])['end'],'2026-03-31','A full 31-date interval is allowed');
fails(function()use($db){cases_data($db,['start'=>'2026-03-01','end'=>'2026-04-01']);},InvalidArgumentException::class);
fails(function()use($db){cases_data($db,['start'=>'2026-11-01','end'=>'2026-12-02']);},InvalidArgumentException::class);

echo 'PASS: '.($checks-$case_checks_start)." guided case checks\n";
