"""Exercise the scoped deploy helper only against disposable filesystem fixtures."""
from pathlib import Path
import hashlib, importlib.util, json, shutil, tempfile, uuid, os, sys
repo=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('deploy',repo/'patches/deploy-short-retry.py');deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)
bundle=Path(sys.argv[1]) if len(sys.argv)>1 else repo/'.qa/short-retry-release-20261009-v3'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()

def setup(base):
    app=base/'fixture';app.mkdir();stage=base/'stage';shutil.copytree(bundle,stage)
    manifest=json.loads((stage/'release-manifest.json').read_text())
    for target,item in list(manifest['files'].items())+list(manifest['guards'].items()):
        if target in manifest['files'] and item['before'] is None:continue
        p=app/target.lstrip('/');p.parent.mkdir(parents=True,exist_ok=True);p.write_text('Synthetic original: '+target)
        meta={'sha256':sha(p),'bytes':p.stat().st_size,'mode':420,'uid':0,'gid':0}
        if target in manifest['files']:item['before']=meta
        else:manifest['guards'][target]=meta
    scheduler=json.loads((stage/'files/scheduler.json').read_text())
    scheduler['sha256']['apps/common/components/db/behaviors/CampaignQueueTableBehavior.php']=manifest['guards'][deploy.GUARDS[1]]['sha256']
    (stage/'files/scheduler.json').write_text(json.dumps(scheduler))
    manifest['files'][deploy.TARGETS[-1]]['afterSha256']=sha(stage/'files/scheduler.json')
    (stage/'release-manifest.json').write_text(json.dumps(manifest))
    return app,stage,base/'backup',sha(stage/'release-manifest.json')

def snapshot(app):return {str(p.relative_to(app)):p.read_bytes() for p in app.rglob('*') if p.is_file()}

fixture_base=(Path.home()/'Documents/Codex') if os.name=='nt' else Path(tempfile.gettempdir())
tempfile.tempdir=str(fixture_base)
root=fixture_base/('short-retry-test-'+uuid.uuid4().hex[:8])
root.mkdir()
try:
    for n in range(8):
        base=root/str(n);base.mkdir();app,stage,backup,pin=setup(base);before=snapshot(app)
        if n==0:
            assert deploy.run('dry-run',stage,backup,pin,fixture=app)['ok']
            assert not backup.exists() and snapshot(app)==before
            deploy.run('apply',stage,backup,pin,fixture=app)
            deploy.run('rollback',stage,backup,pin,fixture=app)
            assert snapshot(app)==before
            print('PASS dry-run, apply and exact rollback including new helper removal')
        elif n<=5:
            try:deploy.run('apply',stage,backup,pin,fixture=app,fail_after=n)
            except RuntimeError as error:assert str(error)=='Injected replacement failure'
            else:raise AssertionError('Expected injected failure')
            assert snapshot(app)==before
            print('PASS scoped automatic restoration after replacement '+str(n))
        else:
            if n==6:(app/deploy.GUARDS[-1].lstrip('/')).write_text('Changed binding fixture')
            else:(stage/'files/MagicSmtpShortRetry.php').write_text('Changed candidate fixture')
            changed=snapshot(app)
            try:deploy.run('apply',stage,backup,pin,fixture=app)
            except RuntimeError:pass
            else:raise AssertionError('Expected drift rejection')
            assert not backup.exists() and snapshot(app)==changed
            print('PASS drift rejected before backup or target writes')
finally:
    assert root.resolve().is_relative_to(fixture_base.resolve()) and root.name.startswith('short-retry-test-')
    shutil.rmtree(root)
print('PASS eight disposable local scenarios; no remote execution')
