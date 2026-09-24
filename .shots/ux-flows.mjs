import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

// Dedicated users keep the local gallery and other contributors' work intact.
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', 'wpcli', ...args], {encoding:'utf8'}).trim();
const fixtures = 'wp-content/plugins/blueprint-registry/tests/ux-fixtures.php';
const U = 'http://localhost:8080';
const output = 'outputs/ux/after';
mkdirSync(output, {recursive:true});
wp('eval-file', fixtures, 'cleanup');
const f = JSON.parse(wp('eval-file', fixtures, 'setup'));
const browser = await chromium.launch();
const errors = [];
let captures = 0;
const context = async () => {
 const c = await browser.newContext({viewport:{width:1440,height:1000}});
 c.on('page', p => p.on('pageerror', e => errors.push(e.message)));
 return c;
};
const shot = async (p, name) => {
 await p.screenshot({path:`${output}/${name}.png`,fullPage:true}); captures++;
 await p.setViewportSize({width:390,height:844});
 await p.screenshot({path:`${output}/${name}-mobile.png`,fullPage:true}); captures++;
 assert(await p.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1), `Horizontal overflow on ${name}`);
 await p.setViewportSize({width:1440,height:1000});
};
const go = async (p, path) => {const response=await p.goto(path.startsWith('http') ? path : U+path);assert(response.status()<400,`${path}: ${response.status()}`);};
const login = async (p, user) => {await go(p,'/wp-login.php');await p.fill('#user_login',user.login);await p.fill('#user_pass',user.password);await Promise.all([p.waitForNavigation(),p.click('#wp-submit')]);};
const click = async (p, label) => {await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:label,exact:true}).click()]);};
const edit = async (p, title, description='A Blueprint edited in the browser.') => {
 await p.locator('.CodeMirror').waitFor();
 await p.evaluate(({title,description})=>{
  const cm=document.querySelector('.CodeMirror').CodeMirror; const data=JSON.parse(cm.getValue());
  data.meta={...data.meta,title,description};cm.setValue(JSON.stringify(data,null,2));
 },{title,description});
};
const data = () => wp('post','list','--post_type=blueprint_change',`--author=${f.contributor.id}`,'--post_status=draft','--format=count');
let contributor, reviewer;
try {
 const visitor=await (await context()).newPage();
 await go(visitor,'/blueprints/'); assert(await visitor.getByRole('link',{name:'Propose a new Blueprint',exact:true}).count()===1);await shot(visitor,'gallery-grid');
 await go(visitor,'/blueprints/?bp_layout=table');await shot(visitor,'gallery-table');
 await go(visitor,'/blueprints/?bp_q=no-match-ux-58793');await shot(visitor,'gallery-empty');
 await go(visitor,f.url);await shot(visitor,'details');
 const forkLogin=await visitor.getByRole('link',{name:'Fork',exact:true}).getAttribute('href');assert(forkLogin.includes('redirect_to=') && decodeURIComponent(forkLogin).includes('bp_new_mode=fork'));
 await go(visitor,'/blueprints/manage/new/');await shot(visitor,'sign-in');
 contributor=await (await context()).newPage();reviewer=await (await context()).newPage();
 await login(contributor,f.contributor);await login(reviewer,f.reviewer);
 await go(reviewer,'/blueprints/manage/');await shot(reviewer,'work-empty');
 await go(contributor,'/blueprints/manage/');await shot(contributor,'my-work');
 const before=data();await go(contributor,'/blueprints/manage/new/');await shot(contributor,'new-editor');assert.equal(data(),before,'Opening the editor created a draft');
 await edit(contributor,'UX New Blueprint');
 assert.equal(await contributor.locator('[data-bp-save-state]').innerText(),'Unsaved changes');
 const leaving=contributor.waitForEvent('dialog');const back=contributor.locator('.bp-back').click();
 const warning=await leaving;assert.equal(warning.type(),'beforeunload');await warning.dismiss();await back;
 assert(new URL(contributor.url()).pathname.endsWith('/new/'),'Dismissing the warning must keep the editor open');
 await click(contributor,'Review changes');assert.match(contributor.url(),/\/review\/$/);
 const newEditor=contributor.url().replace(/review\/$/,'');
 assert((await contributor.locator('.bp-code').innerText()).includes('UX New Blueprint'));
 await shot(contributor,'new-review');
 await contributor.getByRole('link',{name:'Back to editing',exact:true}).click();await edit(contributor,'UX New Blueprint revised');
 await click(contributor,'Review changes');assert.match(contributor.url(),/\/review\/$/,'Existing editor only saved instead of opening review');
 assert((await contributor.locator('.bp-code').innerText()).includes('UX New Blueprint revised'));
 // A review opened before another tab saves must not send the other tab's edits.
 const second=await contributor.context().newPage();await go(second,newEditor);await edit(second,'UX Changed elsewhere');await click(second,'Review changes');
 await click(contributor,'Submit for review');assert((await contributor.locator('.bp-notice').innerText()).includes('another tab'));assert.match(contributor.url(),/\/review\//);await second.close();
 await shot(contributor,'stale-comparison');
 await contributor.getByRole('link',{name:'Back to editing',exact:true}).click();
 contributor.once('dialog',d=>d.accept());await click(contributor,'Remove draft');assert.match(contributor.url(),/\/manage\//);
 // Fork, arbitrary files, back/edit/review, and exact submitted copies.
 await go(contributor,f.url);await contributor.getByRole('link',{name:'Fork',exact:true}).click();await edit(contributor,'UX Forked Coffee');
 await contributor.setInputFiles('[data-bp-bundle-source]',{name:'helper.php',mimeType:'application/x-php',buffer:Buffer.from('<?php echo "Bundle helper";\n')});
 const uploadRequest=contributor.waitForRequest(r=>r.method()==='POST' && r.url().includes('/blueprints/manage/'));
 assert.equal(await contributor.locator('[data-bp-bundle-source]').getAttribute('name'),null);
 await click(contributor,'Review changes');assert.match(contributor.url(),/\/review\/$/);assert((await uploadRequest).headers()['content-type'].includes('multipart/form-data')); 
 const editorUrl=contributor.url().replace(/review\/$/,'');const id=Number(editorUrl.match(/manage\/(\d+)/)[1]);
 assert((await contributor.locator('.bp-diff').innerText()).includes('helper.php'));
 assert((await contributor.locator('.bp-diff').innerText()).includes('Bundle helper'),'Added text files must be readable in the diff');
 await shot(contributor,'fork-review');
 // The preview is a real generated ZIP, not a static thumbnail.
 const preview=await contributor.locator('form[target="bp-playground"]').evaluate(form=>Object.fromEntries(new FormData(form)));
 const previewResponse=await contributor.request.post(contributor.url(),{form:preview,maxRedirects:0});
 const playground=previewResponse.headers().location;assert(playground.startsWith('https://playground.wordpress.net/'));
 const zipUrl=new URL(playground).searchParams.get('blueprint-url');const zip=await visitor.request.get(zipUrl);assert.equal(zip.status(),200);assert.equal((await zip.body()).subarray(0,2).toString(),'PK');
 const previewFiles=JSON.parse(execFileSync('python3',['-c','import io,sys,zipfile,json; z=zipfile.ZipFile(io.BytesIO(sys.stdin.buffer.read())); print(json.dumps({n:z.read(n).decode() for n in z.namelist()}))'],{input:await zip.body(),encoding:'utf8'}));
 assert(previewFiles['helper.php'].includes('Bundle helper'));assert(previewFiles['menu.txt'].includes('Espresso'));assert(JSON.parse(previewFiles['blueprint.json']).meta.title==='UX Forked Coffee');
 await click(contributor,'Submit for review');assert((await contributor.locator('.bp-chip').first().innerText()).includes('In review'));
 await shot(contributor,'pending-editor');
 await contributor.locator('.bp-submitted-history > summary').click();await contributor.locator('.bp-submitted-version > summary').first().click();await shot(contributor,'submitted-history');
 const submission1=wp('post','meta','get',String(id),'_bp_current_submission_id');
 await edit(contributor,'UX Unsubmitted title');await click(contributor,'Review changes');assert.equal(wp('post','meta','get',String(id),'_bp_status'),'pending_review');
 await go(reviewer,'/wp-admin/admin.php?page=blueprint-review-queue');assert((await reviewer.locator('main, .wrap').allTextContents()).join(' ').includes('UX Forked Coffee'));assert(!(await reviewer.locator('.bpv').innerText()).includes('UX Unsubmitted title'));
 await shot(reviewer,'review-queue');
 await go(reviewer,`/wp-admin/edit.php?post_type=blueprint&page=blueprint-review&change_id=${id}`);
 assert((await reviewer.locator('.bp-page-head h1').innerText()).includes('UX Forked Coffee'));
 await click(contributor,'Replace submitted version');const submission2=wp('post','meta','get',String(id),'_bp_current_submission_id');assert.notEqual(submission1,submission2);
 // Reviewer cannot accept the older tab's version.
 await click(reviewer,'Accept and publish');assert((await reviewer.locator('body').innerText()).includes('newer submitted version'));
 await go(reviewer,`/wp-admin/edit.php?post_type=blueprint&page=blueprint-review&change_id=${id}`);await shot(reviewer,'reviewer-decision');
 await reviewer.getByRole('button',{name:'Request changes',exact:true}).click();assert.equal(await reviewer.locator('#bp_review_note').evaluate(e=>e.validationMessage),'Add a message so the contributor knows what to do next.');
 await reviewer.fill('#bp_review_note','Please describe what the PHP file does.');await click(reviewer,'Request changes');
 await go(contributor,editorUrl);assert((await contributor.locator('.bp-feedback').innerText()).includes('Please describe'));await shot(contributor,'feedback-editor');
 // Removing a stored file must not discard JSON typed immediately beforehand.
 await edit(contributor,'UX Coffee ready');await click(contributor,'Remove helper.php');
 assert((await contributor.locator('#bp_blueprint_json').inputValue()).includes('UX Coffee ready'));assert(!(await contributor.locator('.bp-bundle-list').first().innerText()).includes('helper.php'));
 // Invalid JSON remains saved and editable, not sent for review.
 await contributor.evaluate(()=>document.querySelector('.CodeMirror').CodeMirror.setValue('{broken'));
 await click(contributor,'Review changes');assert(!new URL(contributor.url()).pathname.endsWith('/review/'));await shot(contributor,'validation-error');
 await contributor.evaluate(()=>document.querySelector('.CodeMirror').CodeMirror.setValue(JSON.stringify({$schema:'https://playground.wordpress.net/blueprint-schema.json',meta:{title:'UX Coffee ready',description:'A reviewed coffee shop.'},login:true},null,2)));
 await click(contributor,'Review changes');await click(contributor,'Submit for review');
 await go(reviewer,`/wp-admin/edit.php?post_type=blueprint&page=blueprint-review&change_id=${id}`);await click(reviewer,'Accept and publish');
 await go(contributor,editorUrl+'review/');assert.equal(new URL(contributor.url()).pathname,new URL(editorUrl).pathname);await shot(contributor,'accepted-editor');
 const published=wp('post','meta','get',String(id),'_bp_target_blueprint_id');const publishedUrl=wp('post','url',published);
 await go(visitor,publishedUrl);assert((await visitor.locator('h1').innerText()).includes('UX Coffee ready'));
 // Publish an update through the same UI; inspect historical metadata and files.
 await go(contributor,publishedUrl);await contributor.getByRole('link',{name:'Edit',exact:true}).click();await edit(contributor,'UX Coffee revision two');await click(contributor,'Review changes');
 const updateId=Number(contributor.url().match(/manage\/(\d+)/)[1]);await shot(contributor,'update-review');
 const resume=await contributor.context().newPage();await go(resume,publishedUrl);await resume.getByRole('link',{name:'Edit',exact:true}).click();assert(new URL(resume.url()).pathname===`/blueprints/manage/${updateId}/`,'Edit must resume existing work');await resume.close();await click(contributor,'Submit for review');
 await go(reviewer,`/wp-admin/edit.php?post_type=blueprint&page=blueprint-review&change_id=${updateId}`);await click(reviewer,'Accept and publish');
 await go(visitor,publishedUrl+'?release=1');assert.equal(await visitor.locator('h1').innerText(),'UX Coffee ready');await shot(visitor,'older-revision');
 await go(visitor,publishedUrl+'?release=1&compare=1');await shot(visitor,'revision-comparison');
 await go(visitor,publishedUrl);const fileLink=visitor.locator('.bp-files a.bp-file-name').filter({hasText:'menu.txt'});await fileLink.click();await shot(visitor,'file-reader');
 assert((await visitor.locator('.bp-code').innerText()).includes('Espresso'));
 // A failed transit archive must show an actionable error, never a success review.
 await go(contributor,'/blueprints/manage/new/');await edit(contributor,'UX Upload retry');
 await contributor.setInputFiles('[data-bp-bundle-archive]',{name:'broken.zip',mimeType:'application/zip',buffer:Buffer.from('not a zip')});
 await click(contributor,'Review changes');assert(!new URL(contributor.url()).pathname.endsWith('/review/'));
 await shot(contributor,'upload-error');assert(await contributor.getByRole('button',{name:'Continue without upload',exact:true}).count()===1);
 await click(contributor,'Continue without upload');assert(new URL(contributor.url()).pathname.endsWith('/review/'));
 await click(contributor,'Submit for review');const rejectedId=Number(contributor.url().match(/manage\/(\d+)/)[1]);
 await go(reviewer,`/wp-admin/edit.php?post_type=blueprint&page=blueprint-review&change_id=${rejectedId}`);
 await reviewer.fill('#bp_review_note','This example is outside the directory scope.');await click(reviewer,'Reject proposal');
 await go(contributor,`/blueprints/manage/${rejectedId}/`);assert((await contributor.locator('.bp-feedback').innerText()).includes('outside the directory scope'));assert.equal(await contributor.getByRole('button',{name:'Review changes',exact:true}).count(),0);await shot(contributor,'rejected-editor');
 // The plain-text editor still follows the same two-step path without JavaScript.
 const plainContext=await browser.newContext({javaScriptEnabled:false});const plain=await plainContext.newPage();await login(plain,f.contributor);await go(plain,'/blueprints/manage/new/');
 await plain.fill('#bp_blueprint_json',JSON.stringify({$schema:'https://playground.wordpress.net/blueprint-schema.json',meta:{title:'UX Plain editor'}}));await click(plain,'Review changes');assert(new URL(plain.url()).pathname.endsWith('/review/'));await plain.getByRole('link',{name:'Back to editing',exact:true}).click();await click(plain,'Remove draft');await plainContext.close();
 // Real keyboard navigation remains reachable on a phone.
 await go(contributor,'/blueprints/manage/');await contributor.setViewportSize({width:390,height:844});assert(await contributor.locator('.bp-app__nav').isVisible());
 assert.equal(errors.length,0,errors.join('\n'));
 console.log(`PASS: contribution/review browser flows; ${captures} desktop/mobile captures.`);
} finally {
 await browser.close();wp('eval-file',fixtures,'cleanup');
}
