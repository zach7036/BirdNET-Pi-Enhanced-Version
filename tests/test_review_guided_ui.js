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
  const markup='<!doctype html><html><head><meta charset="utf-8"></head><body>'+fs.readFileSync(path.join(root,'scripts/review_guided.php'),'utf8').replace(/<\?php[\s\S]*?\?>/g,'')+'</body></html>';
  await context.route('**/*',async route=>{
    const req=route.request(),u=new URL(req.url());
    const json=(value,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(value)});
    if(req.isNavigationRequest())return route.fulfill({contentType:'text/html',body:markup});
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
  const {page,state}=await fixture(t);await page.locator('input[name="clip-0"][value="1"]').check();await action(page,'confirm');await ready(page,1);
  assert.deepEqual(state.posts[0].files,[{file_name:'bird-0-1.wav',file_revision:'revision-1'}]);
  assert.equal(state.posts[0].bulk_confirmed,undefined);assert.match(await page.locator('#caseStatus').textContent(),/Other recordings remain unverified/);
  await page.locator('#caseUndo').click();await ready(page,2);assert.equal(state.posts[1].action,'undo');
});
test('reject offers other evidence instead of rejecting the species/day',async t=>{
  const {page,state}=await fixture(t,1);await action(page,'reject');await ready(page,1);assert.equal(state.cases[0].evidence.length,2);assert.equal(state.cases[0].ready,true);
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
  const {page,state}=await fixture(t,1);await page.locator('.case-card summary').click();await page.locator('[data-bulk="0"][value="0"]').check();await page.locator('[data-bulk="0"][value="2"]').check();
  await action(page,'bulk');assert.equal(state.posts.length,0);
  assert.match(await page.locator('.case-detail').textContent(),/bird-0-0.wav/);assert.match(await page.locator('.case-detail').textContent(),/bird-0-2.wav/);
  await page.locator('[data-bulk-confirm="confirm"]').click();await ready(page,0);assert.equal(state.posts[0].bulk_confirmed,true);assert.equal(state.posts[0].files.length,2);
});
test('playing another recording selects it for the decision',async t=>{
  const {page,state}=await fixture(t,1);await page.locator('audio[data-clip="2"]').dispatchEvent('play');
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
  for(const css of ['homepage/style.css','homepage/static/css/tokens.css','homepage/static/css/pages.css'])await page.addStyleTag({path:path.join(root,css)});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if(width===1200&&process.env.BIRDNET_GUIDED_SCREENSHOT)await page.screenshot({path:process.env.BIRDNET_GUIDED_SCREENSHOT,fullPage:true});
});
