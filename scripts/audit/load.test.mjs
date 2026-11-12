import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { Writable } from 'node:stream';
import {Session,csrf,percentile,createRowWriter,stageGate} from './load.mjs';
test('load gate preserves exact boundaries and reports the actual failed stage',()=>{
  assert.deepEqual(stageGate({errorRate:.01,p95EndToEndMs:5000}),{gatePassed:true,stopReason:null});
  assert.deepEqual(stageGate({errorRate:151/15000,p95EndToEndMs:5000}),{gatePassed:false,stopReason:'error_rate_exceeded'});
  assert.deepEqual(stageGate({errorRate:.01,p95EndToEndMs:5001}),{gatePassed:false,stopReason:'latency_exceeded'});
  assert.deepEqual(stageGate({errorRate:749/15000,p95EndToEndMs:12559}),{gatePassed:false,stopReason:'error_rate_and_latency_exceeded'});
});
test('concurrent rows share one drain waiter and preserve every row',async()=>{
  const received=[];let release;
  const output=new Writable({highWaterMark:1,write(chunk,encoding,done){received.push(JSON.parse(chunk.toString()));if(!release)release=done;else done();}});
  const write=createRowWriter(output);
  const pending=Array.from({length:100},(_,i)=>write({i}));
  assert.equal(output.listenerCount('drain'),1);
  release();await Promise.all(pending);
  assert.equal(output.listenerCount('drain'),0);
  assert.deepEqual(received,Array.from({length:100},(_,i)=>({i})));
  await write({i:100});
  await new Promise((resolve,reject)=>output.end(error=>error?reject(error):resolve()));
  assert.equal(received.length,101);assert.equal(output.writableFinished,true);
});
test('one drain waiter rejects every waiting write on stream failure',async()=>{
  let release;
  const output=new Writable({highWaterMark:1,write(chunk,encoding,done){release=done;}});
  const write=createRowWriter(output);
  const pending=Array.from({length:100},(_,i)=>write({i}));
  const settled=Promise.allSettled(pending);
  assert.equal(output.listenerCount('drain'),1);
  release(new Error('dummy disk failure'));
  const results=await settled;
  assert.equal(results.filter(r=>r.status==='rejected'&&r.reason.message==='dummy disk failure').length,100);
  assert.equal(output.listenerCount('drain'),0);
});
test('CSRF attribute order and percentile boundaries',()=>{
  assert.equal(csrf('<input value="abc" type="hidden" name="csrf_token">'),'abc');
  assert.equal(csrf('<meta content="def" name="csrf-token">'),'def');
  assert.throws(()=>csrf('no token'));assert.equal(percentile([4,1,3,2],.95),4);assert.equal(percentile([], .5),null);
});
test('independent sessions, redirects, rotation, JSON and bounded timeout',async()=>{
  const server=http.createServer((req,res)=>{
    if(req.url==='/start'){res.writeHead(302,{'set-cookie':'SID=first; Path=/','location':'/rotate'});res.end();}
    else if(req.url==='/rotate'){assert.equal(req.headers.cookie,'SID=first');res.writeHead(200,{'set-cookie':'SID=second; Path=/'});res.end('rotated');}
    else if(req.url==='/json'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({success:true,cookie:req.headers.cookie??''}));}
    else if(req.url==='/bad'){res.end('<html>login</html>');}
    else if(req.url==='/hang'){}else{res.end();}
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  try{
    const base=`http://127.0.0.1:${server.address().port}/`;const a=new Session(base,50),b=new Session(base,50);
    await a.request('start');assert.equal((await a.json('json')).json.cookie,'SID=second');assert.equal((await b.json('json')).json.cookie,'');
    await assert.rejects(a.json('bad'),/malformed_json/);await assert.rejects(a.request('hang'),/timeout/);
    await assert.rejects(a.request('http://example.com/'),/unsafe_redirect/);
  }finally{server.closeAllConnections();await new Promise(r=>server.close(r));}
});
test('login submits real CSRF and verifies identity',async()=>{
  const server=http.createServer((req,res)=>{
    if(req.url==='/auth'&&req.method==='GET'){res.setHeader('Set-Cookie','SID=anonymous; Path=/');res.end('<input name="csrf_token" value="login-token">');}
    else if(req.url==='/auth'){
      let body='';req.on('data',c=>body+=c);req.on('end',()=>{
        const form=new URLSearchParams(body);assert.equal(form.get('csrf_token'),'login-token');assert.equal(form.get('identifier'),'user102@capacity.invalid');assert.equal(req.headers.cookie,'SID=anonymous');
        res.writeHead(303,{'Set-Cookie':'SID=authenticated; Path=/','Location':'/app.php'});res.end();
      });
    }else if(req.url==='/app.php'){res.end('<meta name="csrf-token" content="current-token">');}
    else{assert.equal(req.headers.cookie,'SID=authenticated');assert.equal(req.headers['x-csrf-token'],'current-token');res.end(JSON.stringify({success:true,user:{id:'audit_user_00102'}}));}
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  try{const s=new Session(`http://127.0.0.1:${server.address().port}/`);await s.login('user102@capacity.invalid','synthetic-password','audit_user_00102');await assert.rejects(s.login('user102@capacity.invalid','synthetic-password','wrong'),/identity_mismatch/);}
  finally{server.closeAllConnections();await new Promise(r=>server.close(r));}
});
