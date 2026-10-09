"""Isolated PHP8.1/SQLite acceptance; native FBL source, no application bootstrap."""
from pathlib import Path
import hashlib,importlib.util,json,os,subprocess,sys,tempfile,time
STAGE=Path('/var/tmp/magicsmtp-signed-feedback-20261009-v2');BACKUP='/root/magicsmtp-signed-feedback-backup-20261009-v2'
SQLITE=Path('/var/tmp/magicsmtp-policy-candidate-20261002-6c7305d-v2/private-php-deps/unpacked/usr/lib/php/20210902/pdo_sqlite.so')
SQLITE_SHA='53a0fc6b05702930c2fc29a12f87713ae66349a4e53bc81f8c343ef2e0333d9b'
FBL=Path('/home/admin/web/servermail2.com/public_html/apps/common/models/option/OptionCronProcessFeedbackLoopServers.php')
FBL_SHA='305bdf8c422c1bcac02d05df7c633f1a36dbbd0a53e6a99796e729db2d0414cc'
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()
def main(pin):
 receipt={'ok':False,'manifestSha256':pin,'productionWrites':False,'networkSends':False,'normalDemoAcceptance':'not-verified','tests':[]};started=time.monotonic()
 def require(value,message):
  if not value:raise RuntimeError(message)
 def run(args,env=None):
  result=subprocess.run(args,capture_output=True,text=True,timeout=60,env=env);require(result.returncode==0 and not result.stderr,'Isolated check failed; details withheld');return result.stdout
 try:
  require(STAGE.is_dir() and not STAGE.is_symlink() and STAGE.stat().st_mode&0o077==0,'Private stage required')
  require(sha(STAGE/'release-manifest.json')==pin,'Manifest changed');manifest=json.loads((STAGE/'release-manifest.json').read_text())
  for name,digest in manifest['artifacts'].items():
   path=STAGE/name;require(path.resolve().is_relative_to(STAGE) and not path.is_symlink() and sha(path)==digest,'Artifact changed')
  require(sha(SQLITE)==SQLITE_SHA and sha(FBL)==FBL_SHA,'Native fixture dependency changed')
  deploy=['python3',str(STAGE/'deploy-signed-feedback.py'),'dry-run',str(STAGE),BACKUP,pin];receipt['dryRunBefore']=json.loads(run(deploy))
  spec=importlib.util.spec_from_file_location('publisher',STAGE/'deploy-signed-feedback.py');publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
  a=STAGE/'rename-probe-source';b=STAGE/'rename-probe-destination';a.write_bytes(b'first');publisher.rename_new(a,b);require(b.read_bytes()==b'first' and not a.exists(),'Exclusive rename probe failed');a.write_bytes(b'second')
  try:publisher.rename_new(a,b)
  except FileExistsError:pass
  else:raise RuntimeError('Exclusive rename overwrote destination')
  require(a.read_bytes()==b'second' and b.read_bytes()==b'first','Exclusive rename collision changed files');a.unlink();b.unlink();receipt['exclusiveRenameVerified']=True
  php=['php8.1','-d','opcache.enable_cli=0','-d','extension='+str(SQLITE)];receipt['phpVersion']=run(php+['-r','echo PHP_VERSION;']).strip();require(receipt['phpVersion'].startswith('8.1.'),'PHP8.1 required')
  env=dict(os.environ,MAGIC_SMTP_NATIVE_FBL=str(FBL),MAGIC_SMTP_SQLITE_EXTENSION=str(SQLITE))
  for name in ['signed_bounce_runtime_test.php','bounce_callback_runtime_test.php','policy_callback_runtime_test.php','hook_runtime_test.php']:
   run(php+[str(STAGE/'qa/tests'/name)]+(['suite-only']if name=='signed_bounce_runtime_test.php'else[]),env);receipt['tests'].append({'name':name,'passed':True})
  fixture=str(STAGE/'qa/tests/signed_bounce_runtime_test.php')
  with tempfile.TemporaryDirectory(prefix='isolated-concurrency-',dir=STAGE)as directory:
   require(run(php+[fixture,'seed',directory],env)=='PASS seed','Concurrent seed failed')
   workers=[subprocess.Popen(php+[fixture,'worker',directory],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)for _ in range(8)]
   try:
    for worker in workers:
     output,errors=worker.communicate(timeout=45);require(worker.returncode==0 and not errors and output=='PASS worker','Concurrent worker failed')
   finally:
    for worker in workers:
     if worker.poll()is None:worker.kill();worker.communicate()
   require(run(php+[fixture,'verify',directory],env)=='PASS concurrent database proof','Concurrent proof failed')
   receipt['tests'].append({'name':'8 PHP processes / 160 callbacks / one protected bounce','passed':True})
  for item in manifest['files'].values():run(php+['-l',str(STAGE/'files'/item['source'])])
  receipt['dryRunAfter']=json.loads(run(deploy));receipt['candidateHashes']={name:item['afterSha256']for name,item in manifest['files'].items()};receipt['nativeSourceGuards']=manifest['guards'];receipt['ok']=True
 except Exception as failure:receipt['error']=str(failure)if isinstance(failure,RuntimeError)else type(failure).__name__
 receipt['seconds']=round(time.monotonic()-started,3)
 with (STAGE/'target-runtime-receipt.json').open('x')as output:json.dump(receipt,output,indent=2);output.write('\n')
 print(json.dumps(receipt,indent=2));return 0 if receipt['ok']else 1
if __name__=='__main__':raise SystemExit(main(sys.argv[1]))
