// Actual guided markup/script; isolated browser and mocked endpoints, no station.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
let browser;
test.before(async () => { browser = await chromium.launch({headless: true, channel: process.env.BIRDNET_TEST_BROWSER || undefined}); });
test.after(async () => { if (browser) await browser.close(); });
function item(i) {
  return {key: (i+1).toString(16).padStart(64,'0'), version:'', kind:'discovery', sci_name:'Birdus '+i, species:'Test Bird '+i,
    date:'2026-09-14', end_date:'2026-09-14', title:'Confirm this species at your station', state:'open', ready:true,
    recommended:true, sample:false, priority:'important', visits:12, detections:24, reasons:['No previous human confirmation.'],
    evidence:[0,1,2].map(j=>({file_name:`bird-${i}-${j}.wav`,file_revision:'revision-'+j,clip_path:`day/Test_Bird/bird-${i}-${j}.wav`,date:'2026-09-14',time:`12:${j}0:00`,score:.9-j*.1,audio_available:true}))};
}
async function fixture(t, n=2) {
  const context=await browser.newContext({viewport:{width:1200,height:950}}); t.after(()=>context.close());
  const errors=[];context.on('page',p=>p.on('pageerror',e=>errors.push(e.message))); t.after(()=>assert.deepEqual(errors,[]));
  const state={cases:Array.from({length:n},(_,i)=>item(i)),posts:[],saves:0,responses:new Map(),undo:new Map(),failure:null,lose:false,queueFailure:false,renames:[],wait:null};
  const styles=['homepage/style.css','homepage/static/css/tokens.css','homepage/static/css/pages.css'].map(p=>'<style>'+fs.readFileSync(path.join(root,p),'utf8')+'</style>').join('');
  const markup='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'+styles+'</head><body>'+fs.readFileSync(path.join(root,'scripts/review_guided.php'),'utf8').replace(/<\?php[\s\S]*?\?>/g,'')+'</body></html>';
  await context.route('**/*',async route=>{
    const req=route.request(),u=new URL(req.url());
    const json=(value,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(value)});
    if(req.isNavigationRequest())return route.fulfill({contentType:'text/html',body:markup});
    if(u.pathname.endsWith('/RobotoFlex-Regular.ttf'))return route.fulfill({contentType:'font/ttf',body:fs.readFileSync(path.join(root,'homepage/static/RobotoFlex-Regular.ttf'))});
    if(u.pathname.endsWith('/cases')){
      if(state.queueFailure)return json({message:'offline'},503);
      const counts={recommended:0,all:0,history:0,samples:0};
      state.cases.forEach(c=>{if(c.ready){counts.all++;if(c.recommended)counts.recommended++;if(c.sample)counts.samples++;}else counts.history++;});
      const view=u.searchParams.get('view')||'recommended';
      let selected=state.cases.filter(c=>view==='history'?!c.ready:view==='all'?c.ready:view==='samples'?c.ready&&c.sample:c.ready&&c.recommended);
      if(u.searchParams.has('key'))selected=state.cases.filter(c=>c.key===u.searchParams.get('key'));
      const offset=Number(u.searchParams.get('offset')||0);
      return json({cases:selected.slice(offset,offset+25),counts,total:selected.length,start:'2026-09-09',end:'2026-09-15'});
    }
    if(u.pathname.endsWith('/case-actions')){
      const b=req.postDataJSON();state.posts.push(b);assert.equal(req.headers()['x-requested-with'],'XMLHttpRequest');
      if(state.wait)await state.wait;
      if(state.failure)return json({status:'error',message:'Injected conflict'},state.failure);
      if(state.responses.has(b.request_id))return json(state.responses.get(b.request_id));
      const token=(state.saves+1).toString(16).padStart(64,'a');
      let result;
      if(b.action==='undo') {state.cases=structuredClone(state.undo.get(b.undo_token));result={status:'ok',affected:1,message:'Previous state restored.'};}
      else {
        state.undo.set(token,structuredClone(state.cases));
        const c=state.cases.find(c=>c.key===b.case.key);assert.equal(b.case.version,c.version);
        c.version=token;
        if(b.action==='confirm'||b.action==='hide'){c.state='resolved';c.ready=false;}
        else if(b.action==='reject'){c.evidence=c.evidence.filter(e=>!b.files.some(f=>f.file_name===e.file_name));}
        else if(b.action==='uncertain'){c.state='unresolved';c.ready=false;}
        else if(b.action==='later'){c.state='later';c.ready=false;}
        else if(b.action==='resume'){c.state='open';c.ready=true;}
        result={status:'ok',affected:b.files?.length||0,undo_token:token,message:'Saved selected recording. Other recordings remain unverified.',case_state:c.state};
      }
      state.saves++;state.responses.set(b.request_id,result);
      if(state.lose){state.lose=false;return route.abort();}
      return json(result);
    }
    if(u.pathname.endsWith('/confirmed'))return json({species:[{sci_name:'Birdus 0',species:'Test Bird 0',individually_checked:1,supporting_recordings:2,audio_available:false}]});
    if(u.pathname.endsWith('/examples'))return json({examples:[]});
    if(u.searchParams.has('getlabels'))return json(['Correctus bird_Correct Bird']);
    if(u.searchParams.has('changefile')){state.renames.push(u);return route.fulfill({body:'OK'});}
    return route.abort();
  });
  const page=await context.newPage();await page.goto('http://guided.test/');await ready(page,n);return {page,context,state};
}
async function ready(page,n) {await page.waitForFunction(n=>document.getElementById('caseCount').textContent.startsWith(n+' item')&&!document.getElementById('caseRefresh').disabled,n);}
async function action(page,name) {await page.locator(`[data-action="${name}"]`).first().click();}
test('one selected recording is confirmed, with scoped message and Undo',async t=>{
  const {page,state}=await fixture(t);await page.locator('#case-0 .case-recordings summary').click();await page.locator('input[name="clip-0"][value="1"]').check();await action(page,'confirm');await ready(page,1);
  assert.deepEqual(state.posts[0].files,[{file_name:'bird-0-1.wav',file_revision:'revision-1'}]);
  assert.equal(state.posts[0].bulk_confirmed,undefined);assert.match(await page.locator('#caseStatus').textContent(),/Other recordings remain unverified/);
  await page.locator('#caseUndo').click();await ready(page,2);assert.equal(state.posts[1].action,'undo');
});
test('reject offers other evidence instead of rejecting the species/day',async t=>{
  const {page,state}=await fixture(t,1);await action(page,'reject');await ready(page,1);assert.equal(state.cases[0].evidence.length,2);assert.equal(state.cases[0].ready,true);
  assert.match(await page.locator('.case-rejection-notice').textContent(),/Marked “Not this bird”/);
  assert.match(await page.locator('.case-rejection-notice').textContent(),/12:00:00/);
  assert.match(await page.locator('.case-rejection-notice').textContent(),/Now showing another recording/);
  assert.equal(await page.locator('.case-recording-time').textContent(),'12:10:00');
  assert.ok(await page.locator('.case-next-recording').isVisible());
  assert.ok(await page.locator('.case-card').evaluate(el=>el.classList.contains('case-recording-changed')));
  assert.equal(await page.locator('.case-rejection-notice').getAttribute('role'),'status');
});

test('same bird stays in place with a clear six-to-five recording acknowledgement and local Undo',async t=>{
  const {page,state}=await fixture(t,2);
  state.cases[1].evidence.push(...[3,4,5].map(j=>({...state.cases[1].evidence[0],file_name:`bird-1-${j}.wav`,file_revision:'revision-'+j,clip_path:`day/Test_Bird/bird-1-${j}.wav`,time:`13:${j}0:00`})));
  await page.locator('#caseRefresh').click();await ready(page,2);
  const species=await page.locator('#case-1 h3').textContent();
  await page.locator('#case-1 [data-action="reject"]').click();await ready(page,2);
  assert.equal(await page.locator('.case-card').count(),2);assert.equal(await page.locator('#case-1 h3').textContent(),species);
  assert.match(await page.locator('#case-1 .case-recordings summary').textContent(),/5 available/);
  assert.ok(await page.locator('#case-1 .case-rejection-notice').isVisible());assert.ok(await page.locator('#case-0 .case-rejection-notice').isHidden());
  assert.match(await page.locator('#case-1 .case-player').getAttribute('src'),/bird-1-1.wav$/);
  assert.ok(await page.locator('#case-1 .case-rejection-notice').evaluate(el=>document.activeElement===el));
  await page.locator('#case-1 [data-rejection-control="undo"]').click();await ready(page,2);
  assert.equal(state.posts[1].action,'undo');assert.match(await page.locator('#case-1 .case-recordings summary').textContent(),/6 available/);
  assert.match(await page.locator('#case-1 .case-player').getAttribute('src'),/bird-1-0.wav$/);assert.ok(await page.locator('#case-1 .case-rejection-notice').isHidden());
});

test('consecutive rejections update the previous timestamp and keep acknowledgement until the next action',async t=>{
  const {page}=await fixture(t,1);await action(page,'reject');await ready(page,1);
  await page.locator('.case-player').dispatchEvent('play');
  assert.ok(await page.locator('.case-next-recording').isHidden());assert.ok(await page.locator('.case-rejection-notice').isVisible());
  await action(page,'reject');await ready(page,1);
  assert.match(await page.locator('.case-rejection-notice').textContent(),/12:10:00/);assert.equal(await page.locator('.case-recording-time').textContent(),'12:20:00');
  assert.ok(await page.locator('.case-next-recording').isVisible());
});

test('saving and failure do not claim a recording was rejected or advanced',async t=>{
  const {page,state}=await fixture(t,1);let release;state.wait=new Promise(r=>release=r);
  await action(page,'reject');assert.match(await page.locator('.case-rejection-notice').textContent(),/Saving/);
  assert.ok(await page.locator('.case-next-recording').isHidden());assert.equal(await page.locator('.case-recording-time').textContent(),'12:00:00');
  state.failure=409;release();await page.waitForFunction(()=>document.getElementById('caseError').textContent.includes('conflict'));
  assert.match(await page.locator('.case-rejection-notice').textContent(),/Save not confirmed/);
  assert.ok(await page.locator('.case-next-recording').isHidden());assert.equal(state.saves,0);
});

test('saved rejection with failed refresh blocks stale evidence until an in-card refresh succeeds',async t=>{
  const {page,state}=await fixture(t,1);state.queueFailure=true;await action(page,'reject');
  await page.waitForFunction(()=>document.getElementById('caseError').textContent.includes('Could not load'));
  assert.match(await page.locator('.case-rejection-notice').textContent(),/Marked “Not this bird”/);
  assert.match(await page.locator('.case-rejection-notice').textContent(),/Refresh to load/);
  assert.ok(await page.locator('.case-next-recording').isHidden());assert.ok(await page.locator('[data-action="reject"]').isDisabled());
  await page.locator('.case-card').focus();await page.keyboard.press('n');assert.equal(state.posts.length,1);
  state.queueFailure=false;await page.locator('[data-rejection-control="refresh"]').click();await ready(page,1);
  assert.ok(await page.locator('.case-next-recording').isVisible());assert.equal(await page.locator('.case-recording-time').textContent(),'12:10:00');
});

test('unacknowledged rejection can be retried from the same card without a duplicate save',async t=>{
  const {page,state}=await fixture(t,1);state.lose=true;await action(page,'reject');await page.locator('[data-rejection-control="retry"]').waitFor();
  assert.match(await page.locator('.case-rejection-notice').textContent(),/Save not confirmed/);assert.ok(await page.locator('.case-next-recording').isHidden());
  await page.locator('[data-rejection-control="retry"]').click();await ready(page,1);
  assert.equal(state.saves,1);assert.equal(state.posts[0].request_id,state.posts[1].request_id);assert.ok(await page.locator('.case-next-recording').isVisible());
});

for(const width of [1200,320])test('rejection feedback fits and respects reduced motion at '+width+'px',async t=>{
  const {page}=await fixture(t,1);await page.setViewportSize({width,height:950});await page.emulateMedia({reducedMotion:'reduce'});
  await action(page,'reject');await ready(page,1);
  assert.equal(await page.locator('.case-recording-meta').evaluate(el=>getComputedStyle(el).animationName),'none');
  assert.ok(await page.locator('.case-next-recording').isVisible());assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if(process.env.BIRDNET_GUIDED_PREVIEW_DIR)await page.screenshot({path:path.join(process.env.BIRDNET_GUIDED_PREVIEW_DIR,`review-rejection-${width}.png`),fullPage:true});
});
test('cannot tell and Later are different, and History permits reopening',async t=>{
  const {page,state}=await fixture(t,1);await action(page,'uncertain');await ready(page,0);
  assert.equal(state.cases[0].state,'unresolved');await page.locator('[data-view="history"]').click();await ready(page,1);
  await action(page,'resume');await ready(page,1);await page.locator('[data-view="recommended"]').click();await ready(page,1);
  await action(page,'later');await ready(page,0);assert.equal(state.cases[0].state,'later');
});
test('lost acknowledgement retries same request after reload without duplicate decisions',async t=>{
  const {page,state}=await fixture(t,1);state.lose=true;await action(page,'confirm');await page.locator('#caseRetry').waitFor();
  assert.equal(state.saves,1);const request=state.posts[0].request_id;await page.reload();await page.locator('#caseRetry').click();await ready(page,0);
  assert.equal(state.posts[1].request_id,request);assert.equal(state.saves,1);assert.ok(await page.locator('#caseUndo').isVisible());
});
test('Undo survives reload and conflicts preserve its token',async t=>{
  const {page,state}=await fixture(t,1);await action(page,'confirm');await ready(page,0);await page.reload();await ready(page,0);
  state.failure=409;await page.locator('#caseUndo').click();await page.waitForFunction(()=>document.getElementById('caseError').textContent.includes('conflict'));
  assert.ok(await page.locator('#caseUndo').isVisible());state.failure=null;await page.locator('#caseUndo').click();await ready(page,1);
});
test('five-question sessions do not claim remaining questions are verified',async t=>{
  const {page}=await fixture(t,7);await page.locator('#caseSession').click();await page.waitForFunction(()=>document.getElementById('caseProgress').textContent.includes('up to 5'));
  assert.equal(await page.locator('.case-card').count(),5);
  for(let i=0;i<5;i++){await action(page,'confirm');await ready(page,6-i);}
  assert.match(await page.locator('#caseQueue').textContent(),/Session complete/);assert.match(await page.locator('#caseProgress').textContent(),/2 items remain/);
  await page.locator('#caseStop').click();await ready(page,2);assert.equal(await page.locator('.case-card').count(),2);
});
test('optional samples are distinct from recommended and record their source',async t=>{
  const {page,state}=await fixture(t,2);state.cases[1].sample=true;state.cases[1].recommended=false;state.cases[1].priority='routine';
  await page.locator('#caseRefresh').click();await ready(page,1);assert.equal(await page.locator('[data-view="samples"] span').textContent(),'1');
  await page.locator('[data-view="samples"]').click();await ready(page,1);await action(page,'confirm');await ready(page,0);assert.equal(state.posts[0].source,'sample');
});
test('bulk preview names exactly selected recordings and requires a second action',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-tools > summary').click();await page.locator('.case-bulk > summary').click();await page.locator('[data-bulk="0"][value="0"]').check();await page.locator('[data-bulk="0"][value="2"]').check();
  await action(page,'bulk');assert.equal(state.posts.length,0);
  assert.match(await page.locator('.case-detail').textContent(),/bird-0-0.wav/);assert.match(await page.locator('.case-detail').textContent(),/bird-0-2.wav/);
  await page.locator('[data-bulk-confirm="confirm"]').click();await ready(page,0);assert.equal(state.posts[0].bulk_confirmed,true);assert.equal(state.posts[0].files.length,2);
});
test('playing another recording selects it for the decision',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-recordings summary').click();await page.locator('input[name="clip-0"][value="2"]').check();await page.locator('audio[data-clip="2"]').dispatchEvent('play');
  assert.ok(await page.locator('input[name="clip-0"][value="2"]').isChecked());await action(page,'confirm');await ready(page,0);
  assert.equal(state.posts[0].files[0].file_name,'bird-0-2.wav');
});
test('audio failure blocks both buttons and keyboard verdicts',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('audio').first().dispatchEvent('error');assert.ok(await page.locator('[data-action="confirm"]').isDisabled());
  await page.locator('.case-card').focus();await page.keyboard.press('y');assert.equal(state.posts.length,0);assert.ok(await page.locator('[data-action="uncertain"]').isEnabled());
});
test('duplicate inputs cannot save during a request',async t=>{
  const {page,state}=await fixture(t,2);let release;state.wait=new Promise(r=>release=r);await action(page,'confirm');await page.keyboard.press('y');
  assert.equal(state.posts.length,1);release();await ready(page,1);
});
test('more than 25 questions remain accessible and writes reset no skipping offset',async t=>{
  const {page}=await fixture(t,30);assert.equal(await page.locator('.case-card').count(),25);await page.locator('#caseNext').click();await ready(page,30);assert.equal(await page.locator('.case-card').count(),5);
});
test('confirmed species describe legacy support and unavailable audio',async t=>{
  const {page}=await fixture(t);await page.locator('#confirmedPanel summary').click();await page.locator('#confirmedLoad').click();await page.waitForFunction(()=>document.getElementById('confirmedList').textContent.includes('history retained'));
  assert.match(await page.locator('#confirmedList').textContent(),/1 individually checked; 1 supporting records from bulk\/prior reviews/);
});
for(const width of [1200,390,320])test('guided review fits at '+width+'px',async t=>{
  const {page}=await fixture(t,1);await page.setViewportSize({width,height:1000});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if(width===1200&&process.env.BIRDNET_GUIDED_SCREENSHOT)await page.screenshot({path:process.env.BIRDNET_GUIDED_SCREENSHOT,fullPage:true});
});

test('one player per card, concise context, and collapsed secondary controls',async t=>{
  const {page}=await fixture(t,2);
  assert.equal(await page.locator('.case-card audio:visible').count(),2);
  assert.ok(await page.locator('#case-0 [data-action="confirm"]').isVisible());
  assert.ok(await page.locator('#case-0 [data-action="reassign"]').isHidden());
  assert.ok(await page.locator('#case-0 [data-bulk]').first().isHidden());
  assert.ok(await page.locator('#case-0 .review-explanations').isHidden());
  assert.ok(await page.locator('#caseSamples').isHidden());
  const box=await page.locator('#case-0').boundingBox();assert.ok(box.height<500,'A desktop question should fit without scrolling past stacked players');
});

test('recording choice updates the only player, score, selection, and verdict together',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-recordings summary').click();await page.locator('input[value="2"][type="radio"]').check();
  assert.equal(await page.locator('.case-player').count(),1);
  assert.match(await page.locator('.case-player').getAttribute('src'),/bird-0-2.wav/);
  assert.equal(await page.locator('.case-score-value').textContent(),'70%');
  assert.equal(await page.locator('.case-recording-time').textContent(),'12:20:00');
  assert.equal(await page.locator('.case-recording-choice.is-selected input').inputValue(),'2');
  await action(page,'reject');await ready(page,1);assert.equal(state.posts[0].files[0].file_name,'bird-0-2.wav');
});

test('switching away from unavailable audio re-enables only the chosen playable recording',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-player').dispatchEvent('error');assert.ok(await page.locator('[data-action="confirm"]').isDisabled());
  await page.locator('.case-recordings summary').click();await page.locator('input[value="1"][type="radio"]').check();
  assert.ok(await page.locator('.case-player').isVisible());assert.ok(await page.locator('.case-audio-warning').isHidden());assert.ok(await page.locator('[data-action="confirm"]').isEnabled());
  await action(page,'confirm');await ready(page,0);assert.equal(state.posts[0].files[0].file_name,'bird-0-1.wav');
});

test('loading more evidence preserves the selected file and leaves its picker open',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-recordings summary').click();await page.locator('input[value="2"][type="radio"]').check();
  state.cases[0].evidence.reverse();await action(page,'evidence');await ready(page,1);
  assert.ok(await page.locator('.case-recordings').evaluate(el=>el.open));assert.match(await page.locator('.case-player').getAttribute('src'),/bird-0-2.wav/);
  await action(page,'confirm');await ready(page,0);assert.equal(state.posts[0].files[0].file_name,'bird-0-2.wav');
});

test('keyboard opens a disclosure without accidentally playing or submitting',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-recordings summary').focus();await page.keyboard.press('Space');
  assert.ok(await page.locator('.case-recordings').evaluate(el=>el.open));await page.keyboard.press('y');assert.equal(state.posts.length,0);
});

for(const theme of ['light','dark'])for(const width of [1440,768,390,320])test(theme+' review layout and expanded tools at '+width+'px',async t=>{
  const {page,state}=await fixture(t,2);await page.setViewportSize({width,height:1000});
  if(theme==='dark'){
    // Base CSS is already loaded; skip its import in this isolated fixture.
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'homepage/static/dark-style.css'),'utf8').replace(/@import[^;]+;/g,'')});
    await page.evaluate(()=>document.documentElement.dataset.theme='dark');
  }
  state.cases[0].species='Red-headed Woodpecker';state.cases[0].evidence[0].score=.5696;
  state.cases[1].species='Blue Jay';state.cases[1].visits=84;state.cases[1].detections=1562;
  await page.locator('#caseRefresh').click();await ready(page,2);await page.evaluate(()=>document.fonts.ready);
  const dimensions=await page.locator('#case-0').evaluate(card=>{const listen=card.querySelector('.case-listen').getBoundingClientRect(),decision=card.querySelector('.case-decision').getBoundingClientRect();return {lx:listen.x,ly:listen.y,dx:decision.x,dy:decision.y};});
  if(width>760)assert.equal(dimensions.ly,dimensions.dy,'Desktop places listening alongside the decision');else assert.ok(dimensions.dy>dimensions.ly,'Phones stack the decision below listening');
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if(process.env.BIRDNET_GUIDED_PREVIEW_DIR)await page.screenshot({path:path.join(process.env.BIRDNET_GUIDED_PREVIEW_DIR,`guided-review-${theme}-${width}.png`),fullPage:true});
  await page.locator('#case-0 .case-recordings > summary').click();await page.locator('#case-0 .case-tools > summary').click();await page.locator('#case-0 .case-bulk > summary').click();await page.locator('.case-options > summary').click();
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Expanded controls fit too');
  await page.locator('#case-0 [data-bulk][value="1"]').check();await action(page,'bulk');assert.ok(await page.locator('[data-bulk-confirm="confirm"]').isVisible());
});

test('desktop sidebar-sized content stacks without overflow',async t=>{
  const {page}=await fixture(t,1);await page.setViewportSize({width:1100,height:900});await page.locator('.guided-review').evaluate(el=>el.style.width='600px');
  const fits=await page.locator('.guided-review').evaluate(el=>{const a=el.querySelector('.case-listen').getBoundingClientRect(),b=el.querySelector('.case-decision').getBoundingClientRect();return b.y>a.y&&el.scrollWidth<=el.clientWidth+1;});
  assert.ok(fits,'Layout responds to the actual content width, not only the screen');
});

test('completed History card does not claim the bird is still unconfirmed',async t=>{
  const {page}=await fixture(t,1);await action(page,'confirm');await ready(page,0);await page.locator('[data-view="history"]').click();await ready(page,1);
  assert.match(await page.locator('.case-why').textContent(),/complete/);assert.doesNotMatch(await page.locator('.case-why').textContent(),/Not yet confirmed/);
});

test('secondary reassignment still targets exactly the selected recording',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('.case-recordings summary').click();await page.locator('input[type="radio"][value="2"]').check();
  await page.locator('.case-tools > summary').click();await action(page,'reassign');await page.locator('.case-labels').selectOption('Correctus bird_Correct Bird');await page.locator('.case-rename').click();
  await page.waitForFunction(()=>document.getElementById('caseStatus').textContent.includes('Recording reassigned'));
  assert.equal(state.renames.length,1);assert.match(state.renames[0].searchParams.get('changefile'),/bird-0-2.wav$/);assert.equal(state.posts.length,0);
});
