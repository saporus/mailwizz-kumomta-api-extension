"""Disposable synthetic deployment helper faults; never calls SSH or production paths."""
from pathlib import Path
import argparse,hashlib,importlib.util,json,shutil,tempfile,uuid
REPO=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser();parser.add_argument('--fixture-parent',default=tempfile.gettempdir());args=parser.parse_args();fixture_parent=Path(args.fixture_parent).resolve()
if not fixture_parent.is_dir():raise RuntimeError('Existing fixture parent required')
spec=importlib.util.spec_from_file_location('feedback_deploy',REPO/'patches/deploy-connect.py');deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
results=[]
for scenario in ['roundtrip','fail_after_1','fail_after_2','guard_drift','candidate_drift','missing_acceptance','acceptance_mismatch','interrupted_apply_0','interrupted_apply_1','interrupted_apply_2','interrupted_apply_6','interrupted_apply_8','interrupted_apply_9','interrupted_rollback','backup_drift','unknown_target','late_target_drift','exclusive_new_file']:
    root=(fixture_parent/('connect-deploy-fixture-'+uuid.uuid4().hex[:10])).resolve();root.mkdir(mode=0o777)
    try:
        stage=root/'stage';stage.mkdir();(stage/'files').mkdir();backup=root/'backup'
        manifest={'contract':'magic-smtp-connect-release-v1','localAcceptancePassed':True,'files':{},'guards':{}}
        for i,name in enumerate(deploy.TARGETS):
            source=Path(name).name;candidate=stage/'files'/source;candidate.write_text('synthetic candidate '+str(i))
            dest=root/name.lstrip('/');dest.parent.mkdir(parents=True,exist_ok=True)
            before=None
            if i>=6:
                dest.write_text('synthetic original');before={'sha256':sha(dest),'mode':420,'uid':1003,'gid':1003}
            manifest['files'][name]={'source':source,'before':before,'afterSha256':sha(candidate),'metadata':before or {'mode':420,'uid':1003,'gid':1003}}
        for name in deploy.GUARDS:
            dest=root/name.lstrip('/');dest.parent.mkdir(parents=True,exist_ok=True);dest.write_text('synthetic untouched guard');manifest['guards'][name]=sha(dest)
        for name in ['deploy-connect.py','test-target-connect.py']:shutil.copy2(REPO/'patches'/name,stage/name)
        manifest['artifacts']={name:sha(stage/name)for name in ['deploy-connect.py','test-target-connect.py']}
        manifest['deployHelperSha256']=sha(stage/'deploy-connect.py');manifest['testHelperSha256']=sha(stage/'test-target-connect.py')
        (stage/'release-manifest.json').write_text(json.dumps(manifest));pin=sha(stage/'release-manifest.json')
        acceptance={'ok':True,'fixture':True,'manifestSha256':pin,'phpVersion':'8.1.33','candidateHashes':{name:item['afterSha256']for name,item in manifest['files'].items()}}
        if scenario=='acceptance_mismatch':acceptance['manifestSha256']='0'*64
        if scenario!='missing_acceptance':(stage/'target-runtime-receipt.json').write_text(json.dumps(acceptance))
        original={name:sha(root/name.lstrip('/')) if (root/name.lstrip('/')).exists() else None for name in deploy.TARGETS}
        if scenario=='guard_drift':(root/deploy.GUARDS[0].lstrip('/')).write_text('changed outside target scope')
        if scenario=='candidate_drift':(stage/'files'/manifest['files'][deploy.TARGETS[0]]['source']).write_text('changed candidate')
        if scenario=='roundtrip':
            assert deploy.run('dry-run',stage,backup,pin,fixture=root)['checkedFiles']==9
            assert deploy.run('apply',stage,backup,pin,fixture=root)['changedFiles']==9
            assert deploy.run('verify',stage,backup,pin,fixture=root)['ok']
            assert deploy.run('rollback',stage,backup,pin,fixture=root)['ok']
        elif scenario.startswith('interrupted_apply_') or scenario in ['interrupted_rollback','backup_drift','unknown_target']:
            assert deploy.run('apply',stage,backup,pin,fixture=root)['ok']
            if scenario.startswith('interrupted_apply_'):
                # Recreate all published prefixes after abrupt process loss;
                # unlike caught exceptions, no compensating rollback ran.
                published=int(scenario[-1])
                for index,name in enumerate(deploy.TARGETS):
                    if index<published:continue
                    item=manifest['files'][name];dest=root/name.lstrip('/')
                    if item['before']:shutil.copy2(backup/'originals'/item['source'],dest)
                    elif dest.exists():dest.unlink()
                state=deploy.run('reconcile',stage,backup,pin,fixture=root)
                assert state['ok'] and state['backupValid'] and not state['productionWrites']
                assert list(state['states'].values()).count('candidate')==published
            elif scenario=='interrupted_rollback':
                try:deploy.run('rollback',stage,backup,pin,fixture=root,fail_after=1)
                except RuntimeError:pass
                else:raise AssertionError('Expected interrupted rollback')
                assert list(deploy.run('reconcile',stage,backup,pin,fixture=root)['states'].values())==['candidate']*8+['baseline']
            else:
                drift=(backup/'originals'/manifest['files'][deploy.TARGETS[6]]['source']) if scenario=='backup_drift' else (root/deploy.TARGETS[6].lstrip('/'))
                drift.write_text('unexpected third party content')
                snapshot={name:sha(root/name.lstrip('/')) for name in deploy.TARGETS}
                try:deploy.run('rollback',stage,backup,pin,fixture=root)
                except RuntimeError:pass
                else:raise AssertionError('Expected recovery refusal')
                assert snapshot=={name:sha(root/name.lstrip('/'))for name in deploy.TARGETS}
                results.append({'scenario':scenario,'passed':True,'unexpectedContentPreserved':True});continue
            assert deploy.run('rollback',stage,backup,pin,fixture=root)['ok']
            assert deploy.run('rollback',stage,backup,pin,fixture=root)['ok'] # Idempotent resumed recovery.
        elif scenario in ['late_target_drift','exclusive_new_file']:
            atomic=deploy.atomic_checked;exclusive=deploy.rename_new
            drift=root/deploy.TARGETS[0 if scenario=='exclusive_new_file' else 6].lstrip('/')
            if scenario=='late_target_drift':
                def racing_atomic(source,dest,meta,expected,after):
                    if dest==drift:dest.write_text('unexpected third party content')
                    return atomic(source,dest,meta,expected,after)
                deploy.atomic_checked=racing_atomic
            else:
                def racing_link(source,dest):
                    if dest==drift:dest.write_text('unexpected third party content')
                    return exclusive(source,dest)
                deploy.rename_new=racing_link
            try:
                try:deploy.run('apply',stage,backup,pin,fixture=root)
                except (RuntimeError,FileExistsError):pass
                else:raise AssertionError('Expected late CAS/exclusive-create refusal')
            finally:deploy.atomic_checked=atomic;deploy.rename_new=exclusive
            assert drift.read_text()=='unexpected third party content'
            assert not deploy.run('reconcile',stage,backup,pin,fixture=root)['ok']
            results.append({'scenario':scenario,'passed':True,'unexpectedContentPreserved':True});continue
        else:
            try:deploy.run('apply',stage,backup,pin,fixture=root,fail_after=int(scenario[-1]) if scenario.startswith('fail_after_') else 0)
            except (RuntimeError,FileNotFoundError):pass
            else:raise AssertionError('Expected guarded rejection/fault')
        actual={name:sha(root/name.lstrip('/')) if (root/name.lstrip('/')).exists() else None for name in deploy.TARGETS}
        assert actual==original
        if scenario in ['guard_drift','candidate_drift','missing_acceptance','acceptance_mismatch']:assert not backup.exists()
        results.append({'scenario':scenario,'passed':True,'originalTargetsRestored':True})
    finally:
        if root.parent!=fixture_parent or not root.name.startswith('connect-deploy-fixture-'):raise RuntimeError('Unsafe fixture cleanup')
        shutil.rmtree(root)
print(json.dumps({'ok':True,'syntheticOnly':True,'results':results},indent=2))
