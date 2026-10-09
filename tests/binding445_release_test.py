"""Synthetic transform/CAS/rollback fixtures, no SSH or live configuration."""
from pathlib import Path
import base64,contextlib,hashlib,importlib.util,json,shutil,uuid
REPO=Path(__file__).resolve().parent.parent
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
atomic=load('binding_fixture_atomic',REPO/'patches/deploy-signed-feedback.py')
deploy=load('binding_fixture_deploy',REPO/'patches/binding445-release.py')
raw=b"<?php return [['secret'=>'synthetic-secret-unchanged', 'server_ids' => [433, 434,436,442,444], 'enabled'=>true]];"
changed=deploy.transform(raw)
assert changed==raw.replace(b'[433, 434,436,442,444]',b'[433,434,436,442,444,445]')
for bad in [raw.replace(b'444',b'443'),raw+b"// 'server_ids'=>[433,434,436,442,444]",raw.replace(b'server_ids',b'other')]:
 try:deploy.transform(bad)
 except RuntimeError:pass
 else:raise AssertionError('Unsafe transform accepted')
encoded_value=[{'bridge_id':'synthetic-bridge','tenant_id':'synthetic-tenant','customer_id':1,'server_ids':[433,434,436,442,444],'enabled':True}]
encoded_json=json.dumps(encoded_value,separators=(',',':')).encode()
wrapper=b"<?php $b=json_decode(base64_decode('"+base64.b64encode(encoded_json)+b"'),true); $b[0]['secret']=trim(file_get_contents('/private/synthetic-secret')); $b[0]['scheduler_manifest']=json_decode(file_get_contents('/private/synthetic-scheduler'),true); return $b;"
expected_json=encoded_json.replace(b'[433,434,436,442,444]',b'[433,434,436,442,444,445]')
assert deploy.transform(wrapper)==wrapper.replace(base64.b64encode(encoded_json),base64.b64encode(expected_json))
object_json=json.dumps(encoded_value[0],separators=(',',':')).encode();object_wrapper=wrapper.replace(base64.b64encode(encoded_json),base64.b64encode(object_json))
assert deploy.transform(object_wrapper)==object_wrapper.replace(base64.b64encode(object_json),base64.b64encode(object_json.replace(b'[433,434,436,442,444]',b'[433,434,436,442,444,445]')))
for bad in [wrapper+wrapper,wrapper.replace(base64.b64encode(encoded_json),base64.b64encode(encoded_json.replace(b'444',b'443')))]:
 try:deploy.transform(bad)
 except RuntimeError:pass
 else:raise AssertionError('Encoded scope drift accepted')
results=[]
@contextlib.contextmanager
def fixture_directory():
 parent=(REPO/'.qa').resolve();root=parent/('binding445-fixture-'+uuid.uuid4().hex[:10]);root.mkdir(mode=0o777)
 try:yield str(root)
 finally:
  if root.resolve().parent!=parent or not root.name.startswith('binding445-fixture-'):raise RuntimeError('Unsafe fixture cleanup')
  shutil.rmtree(root)
for scenario in ['roundtrip','target_drift','candidate_drift','runtime_drift','backup_drift','interrupted_apply','post_write_failure']:
 with fixture_directory()as directory:
  root=Path(directory).resolve();deploy.STAGE=root/'stage';deploy.STAGE.mkdir(mode=0o777);deploy.BACKUP=root/'backup';deploy.TARGET=root/'binding.php';deploy.TARGET.write_bytes(raw);deploy.BASELINE=deploy.sha(deploy.TARGET);deploy.MODELS=root/'models';deploy.MODELS.mkdir(mode=0o777)
  deploy.RUNTIME={name:hashlib.sha256(name.encode()).hexdigest()for name in deploy.RUNTIME}
  for name in deploy.RUNTIME:(deploy.MODELS/name).write_text(name)
  deploy.atomic_module=lambda:atomic
  helper=deploy.STAGE/'binding445-release.py';shutil.copy2(REPO/'patches/binding445-release.py',helper);(deploy.STAGE/'candidate.php').write_bytes(changed)
  manifest={'contract':'magic-smtp-binding445-v1','target':str(deploy.TARGET),'beforeSha256':deploy.BASELINE,'afterSha256':deploy.sha(deploy.STAGE/'candidate.php'),'helperSha256':deploy.sha(helper),'oldServerIds':deploy.OLD,'newServerIds':deploy.NEW,'metadata':{'mode':384,'uid':1003,'gid':1003},'beforeScope':{'sharedSecretSha256':'synthetic-shared-hash'}}
  (deploy.STAGE/'release-manifest.json').write_text(json.dumps(manifest));pin=deploy.sha(deploy.STAGE/'release-manifest.json')
  def scope(path):
   if scenario=='post_write_failure' and path.read_bytes()==changed:raise RuntimeError('Synthetic post-write failure')
   return {'serverIds':deploy.NEW if path.read_bytes()==changed else deploy.OLD,'sharedSecretSha256':'synthetic-shared-hash'}
  deploy.scope=scope
  if scenario=='roundtrip':
   assert deploy.run('dry-run',pin,root)['ok'];assert deploy.run('apply',pin,root)['ok'];assert deploy.run('verify',pin,root)['ok'];assert deploy.run('rollback',pin,root)['ok'];assert deploy.run('rollback',pin,root)['ok'];assert deploy.TARGET.read_bytes()==raw
  elif scenario in ['backup_drift','interrupted_apply']:
   assert deploy.run('apply',pin,root)['ok'];assert deploy.run('reconcile',pin,root)['backupValid']
   if scenario=='backup_drift':
    (deploy.BACKUP/'original.php').write_bytes(b'unknown')
    try:deploy.run('rollback',pin,root)
    except RuntimeError:pass
    else:raise AssertionError('Backup drift overwritten')
    assert deploy.TARGET.read_bytes()==changed
   else:assert deploy.run('rollback',pin,root)['ok'] and deploy.TARGET.read_bytes()==raw
  else:
   if scenario=='target_drift':deploy.TARGET.write_bytes(b'unknown')
   if scenario=='candidate_drift':(deploy.STAGE/'candidate.php').write_bytes(b'unknown')
   if scenario=='runtime_drift':(deploy.MODELS/next(iter(deploy.RUNTIME))).write_bytes(b'unknown')
   try:deploy.run('apply',pin,root)
   except RuntimeError:pass
   else:raise AssertionError('Expected failure')
   assert deploy.TARGET.read_bytes()==(b'unknown'if scenario=='target_drift'else raw)
  results.append({'scenario':scenario,'passed':True})
print(json.dumps({'ok':True,'syntheticOnly':True,'transformCases':8,'results':results},indent=2))
