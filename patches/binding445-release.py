"""Explicit one-field binding change. Secret-bearing bytes never leave the host."""
from pathlib import Path
import argparse,base64,copy,datetime,hashlib,importlib.util,json,os,re,shutil,subprocess
STAGE=Path('/var/tmp/magicsmtp-binding445-20261009-v2')
BACKUP=Path('/root/magicsmtp-binding445-backup-20261009-v2')
TARGET=Path('/home/admin/web/servermail2.com/private/magicsmtp-policy/bridge-tenant-4e049403a560073e.php')
BASELINE='c698475bee1e647ac71efc190997c5b67d1480c126a0e736d46929e31697b65d'
CODE_STAGE=Path('/var/tmp/magicsmtp-signed-feedback-20261009-v2')
ATOMIC_SHA='2b5c74f3f332e6c0ecbde84c542f3b9c8889a9f16b3bc4c75175976a2bf45f58'
COMMON_SHA='dde853e1fc4a705b81e4f163e5435fb74ce63d78c51e2fa6e9addda6d72c23e9'
RUNTIME={'MagicSmtpBounceIngress.php':'975d9350c55dbcdfb63da344cd822a23e1fea7dc47b868696002cfca407a9fbc','DeliveryServerMagicSmtp.php':'ba6dbb81dd7c8ef25c79a05240637f01e384ac54331abada70fc921a7fadc50f'}
MODELS=Path('/home/admin/web/servermail2.com/public_html/apps/extensions/magicsmtp/models')
OLD=[433,434,436,442,444];NEW=OLD+[445]
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()
def require(value,message):
 if not value:raise RuntimeError(message)
def transform(raw):
 encoded=list(re.finditer(rb'''base64_decode\(\s*['"]([A-Za-z0-9+/=]+)['"]\s*\)''',raw))
 if encoded:
  require(len(encoded)==1,'Exactly one encoded binding required');encoded=encoded[0]
  decoded=base64.b64decode(encoded.group(1),validate=True);value=json.loads(decoded)
  binding=value[0]if isinstance(value,list) and len(value)==1 else value
  require(isinstance(binding,dict) and binding.get('server_ids')==OLD,'Encoded binding scope drift')
  matches=list(re.finditer(rb'''"server_ids"\s*:\s*\[([0-9,\s]+)\]''',decoded));require(len(matches)==1,'Exactly one numeric JSON server list required');match=matches[0]
  candidate=decoded[:match.start(1)]+b','.join(str(item).encode()for item in NEW)+decoded[match.end(1):]
  expected=copy.deepcopy(value)
  if isinstance(expected,list):expected[0]['server_ids']=NEW
  else:expected['server_ids']=NEW
  require(json.loads(candidate)==expected,'Unrelated decoded value changed')
  # Keep every wrapper/secret-file/scheduler reference byte, and every decoded
  # JSON byte outside the server list, unchanged. Never duplicate a secret.
  return raw[:encoded.start(1)]+base64.b64encode(candidate)+raw[encoded.end(1):]
 pattern=rb'''['"]server_ids['"]\s*=>\s*\[([0-9,\s]+)\]'''
 matches=list(re.finditer(pattern,raw));require(len(matches)==1,'Exactly one literal server list required')
 match=matches[0];ids=[int(value)for value in re.findall(rb'\d+',match.group(1))];require(ids==OLD,'Server list drift')
 return raw[:match.start(1)]+b','.join(str(value).encode()for value in NEW)+raw[match.end(1):]
def scope(path):
 script=r'''$b=require $argv[1]; if(!is_array($b)||count($b)!==1)exit(2);$b=array_values($b)[0];if(!is_array($b)||($b['enabled']??null)!==true||($b['bridge_id']??'')!=='rpb-58702af2-dde8-44e7-ac86-07bccd0ae772'||($b['tenant_id']??'')!=='tenant-4e049403a560073e'||($b['customer_id']??null)!==1||!is_string($b['secret']??null)||strlen($b['secret'])<32)exit(3);echo json_encode(['bridgeId'=>$b['bridge_id'],'tenantId'=>$b['tenant_id'],'customerId'=>$b['customer_id'],'enabled'=>$b['enabled'],'serverIds'=>$b['server_ids'],'sharedSecretSha256'=>hash('sha256',$b['secret'])]);'''
 result=subprocess.run(['php8.1','-d','opcache.enable_cli=0','-r',script,str(path)],capture_output=True,text=True,timeout=15)
 require(result.returncode==0 and not result.stderr,'Binding structure rejected');return json.loads(result.stdout)
def atomic_module():
 require(sha(CODE_STAGE/'deploy-signed-feedback.py')==ATOMIC_SHA and sha(CODE_STAGE/'deploy-short-retry.py')==COMMON_SHA,'Reviewed atomic helper changed')
 spec=importlib.util.spec_from_file_location('binding_atomic',CODE_STAGE/'deploy-signed-feedback.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
def run(mode,pin=None,fixture=None):
 if mode=='plan':return {'ok':True,'remoteExecuted':False,'target':str(TARGET),'stage':str(STAGE),'backup':str(BACKUP),'beforeSha256':BASELINE,'oldServerIds':OLD,'newServerIds':NEW,'configurationField':'server_ids only','codeMustAlreadyMatch':RUNTIME,'engineCoordinationRequired':True}
 atomic=atomic_module();atomic.common.safe(STAGE);atomic.common.safe(TARGET);atomic.common.safe(BACKUP)
 if fixture is not None:require(STAGE.parent==Path(fixture).resolve() and STAGE.parent.name.startswith('binding445-fixture-'),'Disposable fixture required')
 require(STAGE.is_dir() and (fixture is not None or STAGE.stat().st_mode&0o077==0),'Private stage required')
 helper=STAGE/'binding445-release.py';require(fixture is not None or helper.resolve()==Path(__file__).resolve(),'Execute staged helper only')
 if mode=='prepare':
  require(sha(TARGET)==BASELINE,'Baseline binding changed');before=scope(TARGET);require(before['serverIds']==OLD,'Current scope changed')
  raw=TARGET.read_bytes();candidate=transform(raw);path=STAGE/'candidate.php'
  with path.open('xb')as output:output.write(candidate);output.flush();os.fchmod(output.fileno(),0o600);os.fsync(output.fileno())
  lint=subprocess.run(['php8.1','-l',str(path)],capture_output=True,text=True,timeout=15);require(lint.returncode==0 and not lint.stderr,'Candidate syntax rejected')
  metadata=TARGET.stat();require((metadata.st_mode&0o777,metadata.st_uid,metadata.st_gid)==(0o600,1003,1003),'Binding metadata drift')
  manifest={'contract':'magic-smtp-binding445-v1','target':str(TARGET),'beforeSha256':BASELINE,'afterSha256':sha(path),'helperSha256':sha(helper),'oldServerIds':OLD,'newServerIds':NEW,'metadata':{'mode':0o600,'uid':1003,'gid':1003},'beforeScope':before,'outsideServerListUnchanged':True}
  with (STAGE/'release-manifest.json').open('x')as output:json.dump(manifest,output,indent=2);output.write('\n');output.flush();os.fsync(output.fileno())
  os.chmod(STAGE/'release-manifest.json',0o600);atomic.common.sync_dir(STAGE)
  return {'ok':True,'mode':mode,'productionWrites':False,'manifestSha256':sha(STAGE/'release-manifest.json'),'beforeSha256':BASELINE,'afterSha256':manifest['afterSha256'],'scope':before,'newServerIds':NEW}
 require(pin and sha(STAGE/'release-manifest.json')==pin,'Exact prepared manifest pin required');manifest=json.loads((STAGE/'release-manifest.json').read_text())
 require(manifest['contract']=='magic-smtp-binding445-v1' and manifest['target']==str(TARGET) and manifest['beforeSha256']==BASELINE and manifest['oldServerIds']==OLD and manifest['newServerIds']==NEW,'Unexpected configuration scope')
 require(sha(helper)==manifest['helperSha256'] and sha(STAGE/'candidate.php')==manifest['afterSha256'],'Prepared artifact changed')
 actual=atomic.current(TARGET);known=actual in [BASELINE,manifest['afterSha256']]
 if mode=='reconcile':return {'ok':known,'mode':mode,'productionWrites':False,'state':'baseline'if actual==BASELINE else('candidate'if known else'unknown'),'backupValid':atomic.current(BACKUP/'original.php')==BASELINE and atomic.current(BACKUP/'release-manifest.json')==pin}
 require(known if mode=='rollback' else actual==(manifest['afterSha256']if mode=='verify'else BASELINE),'Binding target drift')
 if mode in ['dry-run','verify']:
  current=scope(TARGET);require(current['serverIds']==(NEW if mode=='verify'else OLD) and current['sharedSecretSha256']==manifest['beforeScope']['sharedSecretSha256'],'Binding semantic drift')
  return {'ok':True,'mode':mode,'productionWrites':False,'targetSha256':actual,'scope':current}
 if mode=='apply':
  for name,digest in RUNTIME.items():require(sha(MODELS/name)==digest,'Signed runtime is not installed')
  require(transform(TARGET.read_bytes())==(STAGE/'candidate.php').read_bytes(),'Only serverIds may change')
  BACKUP.mkdir(mode=0o700,exist_ok=False);shutil.copy2(TARGET,BACKUP/'original.php');require(sha(BACKUP/'original.php')==BASELINE,'Backup changed');shutil.copy2(STAGE/'release-manifest.json',BACKUP/'release-manifest.json')
  for name in ['original.php','release-manifest.json']:
   with (BACKUP/name).open('rb+')as output:os.fsync(output.fileno())
  atomic.common.sync_dir(BACKUP)
 else:require(sha(BACKUP/'original.php')==BASELINE and sha(BACKUP/'release-manifest.json')==pin,'Backup changed')
 expected=manifest['afterSha256']if mode=='apply'else BASELINE
 try:
  if mode=='apply':atomic.atomic_checked(STAGE/'candidate.php',TARGET,manifest['metadata'],BASELINE,expected)
  elif actual!=BASELINE:atomic.atomic_checked(BACKUP/'original.php',TARGET,manifest['metadata'],manifest['afterSha256'],BASELINE)
  require(sha(TARGET)==expected,'Post-write binding changed');current=scope(TARGET)
  require(current['serverIds']==(NEW if mode=='apply'else OLD) and current['sharedSecretSha256']==manifest['beforeScope']['sharedSecretSha256'],'Post-write scope changed')
 except BaseException:
  if mode=='apply' and atomic.current(TARGET)==manifest['afterSha256']:atomic.atomic_checked(BACKUP/'original.php',TARGET,manifest['metadata'],manifest['afterSha256'],BASELINE)
  raise
 result={'ok':True,'mode':mode,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'manifestSha256':pin,'targetSha256':sha(TARGET),'scope':current,'changedFiles':1,'changedField':'server_ids','engineChanges':False,'databasesChanged':False,'servicesChanged':False,'replayed':False,'engineReadinessStillRequiresVerification':True}
 (BACKUP/(mode+'-receipt.json')).write_text(json.dumps(result,indent=2)+'\n');atomic.common.sync_dir(BACKUP);return result
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['plan','prepare','dry-run','apply','verify','reconcile','rollback']);parser.add_argument('pin',nargs='?');args=parser.parse_args();result=run(args.mode,args.pin);print(json.dumps(result,indent=2));raise SystemExit(0 if result['ok']else 1)
