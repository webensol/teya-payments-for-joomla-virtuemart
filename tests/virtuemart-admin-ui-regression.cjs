const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const listeners = {}, timers = [], labels = {};
let reloads = 0, popupBlocked = false, ready = false;
function element() { const classes = new Set(); return {dataset:{}, hidden:false, textContent:'', handlers:{}, classList:{toggle(name,on){on ? classes.add(name):classes.delete(name);},contains(name){return classes.has(name);}}, addEventListener(name,fn){this.handlers[name]=fn;},appendChild(child){this.child=child;}}; }
const options = ['live','test'].map(env => {
 const option = element(); option.dataset = {teyaMode:env,connectedLabel:'Connected',disconnectedLabel:'Not connected',connectLabel:'Connect '+env,reconnectLabel:'Reconnect '+env};
 const connect = element(), disconnect = element(), status = element(), output = element(), title = element();
 connect.dataset.teyaStatusUrl = '/status/'+env; connect.href='/connect/'+env; disconnect.href='/disconnect/'+env; disconnect.hidden=true;
 option.querySelector = selector => ({'.teya-connect-button':connect,'.teya-disconnect-button':disconnect,'.teya-environment-status':status,'.teya-poll-status':output,'.teya-connect-text':title})[selector];
 option.querySelectorAll = () => [connect,disconnect];
 labels[env]={option,connect,disconnect,status,output,title}; return option;
});
const strings = Object.fromEntries([...fs.readFileSync(path.join(__dirname,'../plugins/vmpayment/teya/language/en-GB/en-GB.plg_vmpayment_teya.ini'),'utf8').matchAll(/^VMPAYMENT_TEYA_([A-Z_]+)="(.*)"$/gm)].map(match=>[match[1],match[2]]));
const container = {dataset:{activeMode:'live',messages:JSON.stringify(strings)},querySelectorAll:()=>options};
const fields = [{name:'params[sandbox]',type:'radio',tagName:'INPUT',value:'0',checked:false},{name:'params[sandbox]',type:'radio',tagName:'INPUT',value:'1',checked:true}];
const connected={test:false,live:false};
const document = {addEventListener:(name,fn)=>listeners[name]=fn,createElement:()=>element(),querySelectorAll:selector=>selector==='input,select' ? fields : selector==='.teya-onboarding-container' ? [container] : options};
const fetch = async (url,settings) => {
 const [action,env] = url.slice(1).split('/'); let data;
 if(action==='status') data={environment:env,active_environment:'live',connected:connected[env],csrf_token:'token',completed_attempt:ready && env==='test' ? 'test-attempt' : null};
 else {
  assert.equal(settings.method,'POST'); assert.equal(settings.body.get('token'),'1');
  if(action==='connect') data={url:'https://teya.example/onboard',attempt:'test-attempt'};
  else { connected[env]=false; data={environment:env,connected:false}; }
 }
 return {ok:true,json:async()=>data};
};
const window={setTimeout:fn=>timers.push(fn),open:()=>popupBlocked ? null : {location:{}},location:{href:'/settings',reload(){reloads++;}}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../plugins/vmpayment/teya/assets/teya-admin.js'),'utf8'),{document,window,fetch,URLSearchParams,Date,Error});
const flush=async()=>{for(let i=0;i<10;i++)await Promise.resolve();};
(async()=>{
 listeners.DOMContentLoaded(); await flush();
 assert.equal(container.dataset.activeMode,'test'); assert.equal(labels.test.option.hidden,false); assert.equal(labels.live.option.hidden,true);
 await labels.test.connect.handlers.click({preventDefault(){}});
 connected.test=true; ready=true; await timers.shift()(); await flush();
 assert.equal(labels.test.status.textContent,'Connected'); assert.equal(labels.test.title.textContent,'Reconnect test'); assert.equal(labels.test.disconnect.hidden,false);
 assert.equal(labels.live.status.textContent,'Not connected'); assert.equal(reloads,0); assert.equal(container.dataset.activeMode,'test');
 assert.match(labels.test.output.textContent,/Connection saved for TEST/);
 fields[0].checked=true; fields[1].checked=false; listeners.change();
 assert.equal(container.dataset.activeMode,'live'); assert.equal(labels.test.option.hidden,true);
 await labels.test.disconnect.handlers.click({preventDefault(){}});
 assert.equal(labels.test.status.textContent,'Not connected'); assert.equal(labels.test.disconnect.hidden,true); assert.equal(reloads,0);
 popupBlocked=true; ready=false;
 await labels.test.connect.handlers.click({preventDefault(){}});
 assert.equal(window.location.href,'/settings'); assert.equal(labels.test.output.child.href,'https://teya.example/onboard');
 connected.test=true; ready=true; await timers.shift()(); await flush();
 assert.equal(labels.test.status.textContent,'Connected'); assert.equal(container.dataset.activeMode,'live');
 console.log('PASS: UI environment selection, CSS state, saved connection display, disconnect and popup-blocked flow');
})().catch(error=>{console.error(error);process.exitCode=1;});
