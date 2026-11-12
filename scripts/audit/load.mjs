import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { performance } from 'node:perf_hooks';
import { pathToFileURL } from 'node:url';
import { once } from 'node:events';

export function createRowWriter(output) {
  let pendingDrain;
  return async row => {
    if (!output.write(JSON.stringify(row)+'\n')) {
      pendingDrain ??= once(output, 'drain').finally(() => { pendingDrain = undefined; });
      await pendingDrain;
    }
  };
}

export function percentile(values, p) {
  if (!values.length) return null;
  return [...values].sort((a,b)=>a-b)[Math.max(0,Math.ceil(values.length*p)-1)];
}
export function stageGate({errorRate, p95EndToEndMs}) {
  const errors=errorRate>.01, latency=p95EndToEndMs>5000;
  return {gatePassed:!errors&&!latency,stopReason:errors?(latency?'error_rate_and_latency_exceeded':'error_rate_exceeded'):(latency?'latency_exceeded':null)};
}
export function csrf(html) {
  const tags=html.match(/<(?:input|meta)\b[^>]*>/gi) ?? [];
  for (const tag of tags) {
    const attrs=Object.fromEntries([...tag.matchAll(/([\w-]+)\s*=\s*["']([^"']*)["']/g)].map(m=>[m[1].toLowerCase(),m[2]]));
    if (attrs.name==='csrf_token') return attrs.value;
    if (attrs.name==='csrf-token') return attrs.content;
  }
  throw new Error('missing_csrf');
}
export class Session {
  constructor(base, timeout=15000) { this.base=new URL(base); this.cookies=new Map(); this.timeout=timeout; }
  async request(route, {method='GET',body,headers={}}={}, redirects=0) {
    const url=new URL(route,this.base);
    if (url.origin!==this.base.origin || redirects>5) throw new Error('unsafe_redirect');
    const started=performance.now();
    const response=await new Promise((resolve,reject)=>{
      const req=http.request(url,{method,headers:{...headers,Cookie:[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ')}},res=>{
        let body=''; let bytes=0;
        res.on('data',c=>{bytes+=c.length;if(bytes>16*1024*1024)req.destroy(new Error('response_too_large'));else body+=c;});
        res.on('end',()=>resolve({status:res.statusCode,headers:res.headers,body,bytes}));
        res.on('error',reject);
      });
      const timer=setTimeout(()=>req.destroy(new Error('timeout')),this.timeout);
      req.on('close',()=>clearTimeout(timer));
      req.setTimeout(this.timeout,()=>req.destroy(new Error('timeout'))); req.on('error',reject); if(body)req.write(body); req.end();
    });
    for(const item of response.headers['set-cookie']??[]) {
      const [pair]=item.split(';');const split=pair.indexOf('=');const key=pair.slice(0,split);const value=pair.slice(split+1);
      if (/max-age=0(?:;|$)/i.test(item)||!value)this.cookies.delete(key);else this.cookies.set(key,value);
    }
    if ([301,302,303,307,308].includes(response.status)&&response.headers.location) {
      const preserve=[307,308].includes(response.status);
      return this.request(new URL(response.headers.location,url).href,preserve?{method,body,headers}:{},redirects+1);
    }
    return {...response,elapsedMs:performance.now()-started};
  }
  async json(route) {
    const r=await this.request(route,{headers:{'X-CSRF-Token':this.token??'','X-Requested-With':'XMLHttpRequest'}});
    try { r.json=JSON.parse(r.body); } catch { const e=new Error('malformed_json');e.response=r;throw e; }
    if(r.status!==200||r.json.success!==true){const e=new Error('semantic_failure');e.response=r;throw e;}return r;
  }
  async login(identifier,password,expectedId) {
    const page=await this.request('auth');
    const form=new URLSearchParams({auth_action:'signin',identifier,password,csrf_token:csrf(page.body)}).toString();
    const signed=await this.request('auth',{method:'POST',body:form,headers:{'Content-Type':'application/x-www-form-urlencoded'}});
    if(signed.status!==200)throw new Error('login_http_failure');
    this.token=csrf(signed.body);
    const me=await this.json('api.php?action=me');
    if(me.json.user?.id!==expectedId)throw new Error('identity_mismatch');
  }
}
async function main(file) {
  const m=JSON.parse(fs.readFileSync(file,'utf8'));const url=new URL(m.baseUrl);
  if(!m.rootConfirmed||!m.externalServicesDisabled||url.protocol!=='http:'||url.hostname!=='127.0.0.1'||!url.pathname.endsWith('/')||!['picklers_capacity_test','picklers_wholeflow_e2e'].includes(m.db?.name)||m.db?.host!=='127.0.0.1'||m.db?.port!==3306)throw new Error('unsafe_manifest');
  const root=fs.realpathSync(m.auditRoot);const projectVar=fs.realpathSync(new URL('../../var/',import.meta.url));
  const contained=(base,p)=>{const relative=path.relative(base,p);return relative!==''&&!relative.startsWith('..')&&!path.isAbsolute(relative);};
  if(!contained(projectVar,root))throw new Error('unsafe_audit_root');
  for(const k of ['statePath','privatePath','sessionPath'])if(!contained(root,fs.realpathSync(m[k])))throw new Error('unsafe_state');
  if(!contained(root,path.resolve(m.outputPath)))throw new Error('unsafe_output');
  const outputParent=fs.realpathSync(path.dirname(m.outputPath));
  if(outputParent!==root&&!contained(root,outputParent))throw new Error('unsafe_output_parent');
  const stages=m.stages??[1,10,50,100,250,500,1000,2500,5000,10000];
  if(stages.some(n=>!Number.isInteger(n)||n<1||n>10000))throw new Error('stage_exceeds_distinct_fixture');
  const duration=m.holdSeconds??300, interval=m.intervalMs??12000;
  if(!Number.isFinite(duration)||duration<1||duration>1800||!Number.isFinite(interval)||interval<1000)throw new Error('unsafe_schedule');
  const output=fs.createWriteStream(m.outputPath,{flags:'wx'});const summary=[];let stopReason=null;
  const write=createRowWriter(output);
  try {
    if(m.profileReads===true){
      for(const n of [102,1]){
        const s=new Session(m.baseUrl,m.timeoutMs??15000);await s.login(`user${n}@capacity.invalid`,m.fixturePassword,`audit_user_${String(n).padStart(5,'0')}`);
        const reads=n===1?['owner.php']:['app.php','api.php?action=feed_posts','api.php?action=messages&partner_id=audit_user_00103','api.php?action=facilities','api.php?action=slot_availability&facility_id=1&court_id=audit_court_1&date=2026-11-01'];
        for(const route of reads){const started=performance.now();let response,outcome='success';try{response=route.startsWith('api.php')?await s.json(route):await s.request(route);if(response.status!==200)throw new Error('render_http_failure');if(!route.startsWith('api.php'))csrf(response.body);}catch(e){response=e.response;outcome=e.message;}
          await write({stage:'profile',virtualUser:n,role:n===1?'owner':'player',scenario:'initial_read_profile',route,status:response?.status??null,bytes:response?.bytes??0,elapsedMs:performance.now()-started,outcome});if(outcome!=='success')throw new Error('profile_failure');}
      }
    }
    for(const size of stages) {
      const sessions=[];
      for(let i=0;i<size;i++) { const n=i<9899?i+102:i-9898;const s=new Session(m.baseUrl,m.timeoutMs??15000);s.role=n<=100?'owner':(n===101?'admin':'player');await s.login(`user${n}@capacity.invalid`,m.fixturePassword,`audit_user_${String(n).padStart(5,'0')}`);sessions.push(s); }
      const start=performance.now(),stop=start+duration*1000;const rows=[];let pending=0,maxPending=0;
      const routes=['sync','bookings','wallet','matches'];
      // shortcut: one outstanding request per user, add open-arrival scheduling for throughput certification.
      await Promise.all(sessions.map(async(s,i)=>{
        let scheduled=start+(i%100)*interval/100;let cycle=0;
        while(scheduled<stop) {
          const delay=scheduled-performance.now();if(delay>0)await new Promise(r=>setTimeout(r,delay));
          const began=performance.now();const action=routes[cycle++%routes.length];let response,outcome='success';pending++;maxPending=Math.max(maxPending,pending);
          try { response=await s.json(`api.php?action=${action}`); } catch(e) { response=e.response;outcome=['timeout','malformed_json','semantic_failure'].includes(e.message)?e.message:'transport_failure'; }
          pending--;const ended=performance.now();const row={stage:size,virtualUser:i,role:s.role,scenario:'authenticated_reads',action,scheduledMs:scheduled-start,startMs:began-start,endMs:ended-start,queueDelayMs:Math.max(0,began-scheduled),elapsedMs:ended-began,endToEndMs:ended-scheduled,status:response?.status??null,bytes:response?.bytes??0,outcome};
          rows.push(row);await write(row);scheduled+=interval;
        }
      }));
      const failed=rows.filter(r=>r.outcome!=='success').length;const p95=percentile(rows.map(r=>r.endToEndMs),.95);const record={stage:size,activeClients:sessions.length,completed:rows.length,failures:failed,errorRate:rows.length?failed/rows.length:1,p95EndToEndMs:p95,maxPending,achievedRequestsPerSecond:rows.length/((performance.now()-start)/1000),byAction:{}};
      for(const action of routes){const r=rows.filter(r=>r.action===action);record.byAction[action]={count:r.length,failures:r.filter(x=>x.outcome!=='success').length,p50:percentile(r.map(x=>x.elapsedMs),.5),p95:percentile(r.map(x=>x.elapsedMs),.95),p99:percentile(r.map(x=>x.elapsedMs),.99)};}
      Object.assign(record,stageGate(record));summary.push(record);
      if(!record.gatePassed){stopReason=record.stopReason;process.exitCode=1;break;}
    }
  } finally { await new Promise(resolve=>output.end(resolve)); }
  console.log(JSON.stringify({workload:'closed-loop authenticated reads only',gatePassed:stopReason===null,stopReason,summary,limitations:['No writes/races/mixed-load certification','No ramp/spike/recovery automation','No server resource or SQL instrumentation','At 10000 clients role mix is 9899 players, 100 owners, 1 admin']},null,2));
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href)main(process.argv[2]).catch(()=>{console.error('Audit load failed; inspect isolated server and manifest.');process.exitCode=1;});
