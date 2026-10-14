import http from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { randomBytes,randomUUID,scrypt as scryptCb,timingSafeEqual,createHash } from 'node:crypto';
import { promisify } from 'node:util';
import { mkdirSync,readFileSync,existsSync,statSync } from 'node:fs';
import { resolve,extname,sep } from 'node:path';
import { emptyBoard,applyAction,InputError,dateInZone } from './engine.mjs';

const scrypt=promisify(scryptCb),host=process.env.HOST||'127.0.0.1',port=Number(process.env.PORT||3000),origin=process.env.APP_ORIGIN||'http://localhost:3000',secure=origin.startsWith('https://'),dataDir=process.env.DATA_DIR||'./data';
mkdirSync(dataDir,{recursive:true});const db=new DatabaseSync(resolve(dataDir,'retailflow.sqlite'));
db.exec(`PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,salt TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'member',must_change INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS boards (id TEXT PRIMARY KEY,revision INTEGER NOT NULL DEFAULT 0,document TEXT NOT NULL,updated_at TEXT NOT NULL);`);
const hashToken=t=>createHash('sha256').update(t).digest('hex');
async function passwordHash(password,salt){return Buffer.from(await scrypt(password,salt,64)).toString('hex');}
async function passwordMatches(password,u){const expected=Buffer.from(u.password_hash,'hex'),actual=Buffer.from(await scrypt(password,u.salt,64));return timingSafeEqual(actual,expected);}
async function createUser(name,email,password,role='member'){const salt=randomBytes(24).toString('hex'),id=randomUUID();const hash=await passwordHash(password,salt);db.prepare('INSERT INTO users(id,name,email,password_hash,salt,role,must_change,created_at) VALUES(?,?,?,?,?,?,1,?)').run(id,name,email.toLowerCase(),hash,salt,role,new Date().toISOString());return id;}
if(!db.prepare('SELECT id FROM users LIMIT 1').get()){
 const password=process.env.ADMIN_PASSWORD;if(!password||password.length<16)throw new Error('A strong ADMIN_PASSWORD is required for first startup.');
 await createUser(process.env.ADMIN_NAME||'Administrator',process.env.ADMIN_EMAIL||'admin@retailflow.local',password,'admin');
 console.log('Initial administrator created. Password change required on first login.');
}
db.prepare('INSERT OR IGNORE INTO boards(id,revision,document,updated_at) VALUES(?,?,?,?)').run('retailflow',0,JSON.stringify(existsSync(resolve(dataDir,'initial-board.json'))?JSON.parse(readFileSync(resolve(dataDir,'initial-board.json'),'utf8')):emptyBoard()),new Date().toISOString());
const STATIC=resolve('dist'),sessionsTtl=24*3600*1000,rememberedSessionTtl=30*24*3600*1000,attempts=new Map();
const mime={'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.svg':'image/svg+xml','.png':'image/png','.ico':'image/x-icon','.woff2':'font/woff2'};
function send(res,status,obj,headers={}){res.writeHead(status,{'Content-Type':'application/json','Cache-Control':'no-store',...headers});res.end(JSON.stringify(obj));}
function cleanText(v,max=200){return typeof v==='string'?v.trim().slice(0,max):'';}
function getSession(req){const token=(req.headers.cookie||'').split(';').map(p=>p.trim()).find(p=>p.startsWith('rf_session='))?.slice(11);if(!token||!/^[a-f0-9]{64}$/.test(token))return null;const tokenHash=hashToken(token);const u=db.prepare('SELECT u.*,s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>?').get(tokenHash,Date.now());return u?{...u,tokenHash}:null;}
const cookie=(token,{clear=false,remember=false}={})=>`rf_session=${token}; Path=/; HttpOnly; SameSite=Lax${clear?'; Max-Age=0':remember?'; Max-Age='+rememberedSessionTtl/1000:''}${secure?'; Secure':''}`;
async function body(req){let size=0,chunks=[];for await(const chunk of req){size+=chunk.length;if(size>50000)throw new InputError('Request too large.');chunks.push(chunk);}try{const v=JSON.parse(Buffer.concat(chunks).toString());if(!v||typeof v!=='object'||Array.isArray(v))throw 0;return v;}catch{throw new InputError('Invalid request.');}}
function boardFor(u){for(let tries=0;tries<5;tries++){const row=db.prepare('SELECT revision,document FROM boards WHERE id=?').get('retailflow');const board=JSON.parse(row.document);if(board.members.some(m=>m.userId===u.id))return {board,revision:row.revision,user:{userId:u.id,displayName:u.name,email:u.email,role:u.role},today:dateInZone()};const existing=board.members.find(m=>m.email?.toLowerCase()===u.email.toLowerCase());if(existing)existing.userId=u.id;else board.members.push({id:randomUUID(),name:u.name,email:u.email,userId:u.id});if(writeBoard(board,row.revision))return {board,revision:row.revision+1,user:{userId:u.id,displayName:u.name,email:u.email,role:u.role},today:dateInZone()};}throw new Error('Workspace busy.');}
function writeBoard(board,revision){return db.prepare('UPDATE boards SET document=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?').run(JSON.stringify(board),new Date().toISOString(),'retailflow',revision).changes===1;}
function throttleKey(req,email){return `${req.headers['x-forwarded-for']||req.socket.remoteAddress}|${email}`;}
function limited(key){const a=attempts.get(key);return a&&a.until>Date.now()&&a.count>=8;}
function failed(key){const old=attempts.get(key);attempts.set(key,old&&old.until>Date.now()?{...old,count:old.count+1}:{count:1,until:Date.now()+15*60000});}
setInterval(()=>{const now=Date.now();for(const [key,a] of attempts)if(a.until<now)attempts.delete(key);db.prepare('DELETE FROM sessions WHERE expires_at<?').run(now);},60000).unref();

const server=http.createServer(async(req,res)=>{
 res.setHeader('X-Content-Type-Options','nosniff');res.setHeader('X-Frame-Options','DENY');res.setHeader('Referrer-Policy','same-origin');res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
 try{
  const url=new URL(req.url,'http://localhost'),path=url.pathname;
  if(path==='/healthz')return send(res,200,{ok:true});
  if(path.startsWith('/api/')){
   if(!['GET','POST'].includes(req.method))return send(res,405,{error:'Method not allowed.'});
   if(req.method==='POST'){
    if(!req.headers['content-type']?.startsWith('application/json'))return send(res,415,{error:'JSON required.'});
    if(req.headers.origin!==origin)return send(res,403,{error:'Invalid request origin.'});
   }
   if(path==='/api/login'&&req.method==='POST'){
    const input=await body(req),email=cleanText(input.email,200).toLowerCase(),password=typeof input.password==='string'?input.password:'';
    const key=throttleKey(req,email),ipKey=throttleKey(req,'*');if(limited(key)||limited(ipKey))return send(res,429,{error:'Too many attempts. Try again in 15 minutes.'});
    if(password.length>256)return send(res,400,{error:'Invalid email or password.'});
    const u=db.prepare('SELECT * FROM users WHERE email=?').get(email);if(!u||!await passwordMatches(password,u)){failed(key);failed(ipKey);return send(res,401,{error:'Invalid email or password.'});}
    attempts.delete(key);attempts.delete(ipKey);const remember=input.remember===true,ttl=remember?rememberedSessionTtl:sessionsTtl,token=randomBytes(32).toString('hex');db.prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES(?,?,?)').run(hashToken(token),u.id,Date.now()+ttl);return send(res,200,{ok:true,mustChangePassword:!!u.must_change},{'Set-Cookie':cookie(token,{remember})});
   }
   const user=getSession(req);if(!user)return send(res,401,{error:'Please sign in.'});
   if(path==='/api/me'&&req.method==='GET')return send(res,200,{name:user.name,email:user.email,role:user.role,mustChangePassword:!!user.must_change});
   if(path==='/api/logout'&&req.method==='POST'){db.prepare('DELETE FROM sessions WHERE token_hash=?').run(user.tokenHash);return send(res,200,{ok:true},{'Set-Cookie':cookie('',{clear:true})});}
   if(path==='/api/password'&&req.method==='POST'){
    const p=await body(req);if(typeof p.currentPassword!=='string'||typeof p.password!=='string'||p.password.length<12||p.password.length>128)return send(res,400,{error:'Use a new password of 12–128 characters.'});
    const key='password:'+user.id;if(limited(key))return send(res,429,{error:'Too many attempts. Try again in 15 minutes.'});
    if(!await passwordMatches(p.currentPassword,user)){failed(key);return send(res,400,{error:'Current password is incorrect.'});}if(p.currentPassword===p.password)return send(res,400,{error:'Choose a different password.'});
    const salt=randomBytes(24).toString('hex'),hash=await passwordHash(p.password,salt);db.prepare('UPDATE users SET password_hash=?,salt=?,must_change=0 WHERE id=?').run(hash,salt,user.id);db.prepare('DELETE FROM sessions WHERE user_id=? AND token_hash<>?').run(user.id,user.tokenHash);attempts.delete(key);return send(res,200,{ok:true});
   }
   if(user.must_change)return send(res,403,{error:'Change your temporary password before continuing.'});
   if(path==='/api/accounts'&&req.method==='POST'){
    if(user.role!=='admin')return send(res,403,{error:'Only the administrator can create logins.'});
    const p=await body(req),state=boardFor(user),member=state.board.members.find(m=>m.id===p.memberId);
    if(!member||!member.email)return send(res,400,{error:'Add a member with a valid email first.'});
    if(db.prepare('SELECT id FROM users WHERE email=?').get(member.email.toLowerCase()))return send(res,409,{error:'This person already has a login.'});
    const password=randomBytes(15).toString('base64url');const id=await createUser(member.name,member.email,password);boardFor(db.prepare('SELECT * FROM users WHERE id=?').get(id));return send(res,201,{email:member.email,password});
   }
   if(path==='/api/board'){
    const state=boardFor(user);if(req.method==='GET')return send(res,200,state);
    const input=await body(req);if(typeof input.action!=='string'||!input.payload||typeof input.payload!=='object'||Array.isArray(input.payload))throw new InputError('Invalid request.');
    if(input.revision!==state.revision)return send(res,409,{error:'Someone updated the calendar. Latest changes have been loaded; review and save again.'});
    const actor=state.board.members.find(m=>m.userId===user.id),board=applyAction(state.board,input.action,input.payload,actor);
    if(!writeBoard(board,state.revision))return send(res,409,{error:'Someone updated the calendar. Latest changes have been loaded; review and save again.'});
    return send(res,200,{...state,board,revision:state.revision+1});
   }
   return send(res,404,{error:'Not found.'});
  }
  if(req.method!=='GET'&&req.method!=='HEAD'){res.writeHead(405);return res.end();}
  let file=resolve(STATIC,'.'+decodeURIComponent(path));if(!file.startsWith(STATIC+sep)&&file!==STATIC){res.writeHead(404);return res.end();}
  if(path==='/'||!extname(path))file=resolve(STATIC,'index.html');
  if(!existsSync(file)||!statSync(file).isFile()){res.writeHead(404);return res.end('Not found');}
  const data=readFileSync(file);res.writeHead(200,{'Content-Type':mime[extname(file)]||'application/octet-stream','Cache-Control':path.startsWith('/assets/')?'public, max-age=31536000, immutable':'no-store','Content-Length':data.length});res.end(req.method==='HEAD'?undefined:data);
 }catch(e){if(e instanceof InputError)return send(res,400,{error:e.message});console.error('Request failed',e instanceof Error?e.message:'Unknown');if(!res.headersSent)send(res,500,{error:'The server could not complete this request. Please retry.'});else res.end();}
});
server.requestTimeout=15000;server.headersTimeout=10000;server.listen(port,host,()=>console.log(`Retailflow listening on ${host}:${port}`));
function shutdown(){server.close(()=>{db.close();process.exit(0);});setTimeout(()=>process.exit(1),10000).unref();}process.on('SIGTERM',shutdown);process.on('SIGINT',shutdown);
