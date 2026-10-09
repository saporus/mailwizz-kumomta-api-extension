"""Explicit upload-test; default plan is local-only. Never invokes apply or rollback."""
from pathlib import Path
import argparse, errno, hashlib, json, re, shlex, stat, sys

BASE=Path(__file__).resolve().parent
REPO=BASE.parent
BUNDLE=REPO/'.qa/short-retry-release-20261009-v3'
STAGE='/var/tmp/magicsmtp-short-retry-20261009-v3'
BACKUP='/root/magicsmtp-short-retry-backup-20261009-v3'
RELEASE_SHA='5bcd3841b47db00e4f81f5795c18bd9b1ea5f7d37aaefb78e8eddef9ef5ae8db'
DEPLOY_SHA='dde853e1fc4a705b81e4f163e5435fb74ce63d78c51e2fa6e9addda6d72c23e9'
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()

def inventory():
    if sha(BUNDLE/'release-manifest.json')!=RELEASE_SHA or sha(BUNDLE/'deploy-short-retry.py')!=DEPLOY_SHA:raise RuntimeError('Reviewed v3 bundle pins changed')
    release=json.loads((BUNDLE/'release-manifest.json').read_text())
    for item in release['files'].values():
        if sha(BUNDLE/'files'/item['source'])!=item['afterSha256']:raise RuntimeError('Reviewed v3 runtime source changed')
    files={}
    for path in sorted(BUNDLE.rglob('*')):
        if path.is_symlink():raise RuntimeError('Bundle symlink rejected')
        if path.is_file():files[path.relative_to(BUNDLE).as_posix()]=path
    files['ops/test-target-short-retry.py']=BASE/'test-target-short-retry.py'
    files['ops/read-short-retry-campaign-status.php']=BASE/'read-short-retry-campaign-status.php'
    for name in ['mailwizz-2.7.3-transient-retry.patch','mailwizz-2.8.1-transient-retry.patch']:files['qa-code/patches/'+name]=BASE/name
    return files

def plan():
    files=inventory();document={'contract':'magic-smtp-short-retry-upload-v1','stage':STAGE,'files':{r:sha(p) for r,p in files.items()}}
    raw=(json.dumps(document,indent=2)+'\n').encode();pin=hashlib.sha256(raw).hexdigest()
    deploy=['python3',STAGE+'/deploy-short-retry.py']
    result={'ok':True,'mode':'plan','remoteExecuted':False,'stage':STAGE,'backup':BACKUP,'files':len(files),'uploadManifestSha256':pin,'releaseManifestSha256':RELEASE_SHA,'deployHelperSha256':DEPLOY_SHA,
      'targetTestCommand':shlex.join(['python3',STAGE+'/ops/test-target-short-retry.py',pin]),
      'deploymentCommands':{mode:shlex.join(deploy+[mode,STAGE,BACKUP,RELEASE_SHA]) for mode in ['dry-run','apply','rollback']},
      'readbackCommand':shlex.join(['php8.1',STAGE+'/ops/read-short-retry-campaign-status.php'])}
    return files,raw,result

def execute(mode):
    files,raw,result=plan()
    if mode=='plan':return result
    import paramiko
    root=BASE.parents[2]
    values=dict((k.lower(),v.strip()) for k,v in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(root/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
    if values['host'] not in ['51.77.133.158','servermail2.com']:raise RuntimeError('Credentials do not identify the established servermail2 MailWizz SSH host')
    client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
    phase='connect'
    try:
        client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
        if mode=='upload-test':
            phase='private_upload'
            sftp=client.open_sftp()
            try:
                try:sftp.lstat(STAGE)
                except IOError as error:
                    if error.errno!=errno.ENOENT:raise
                else:raise RuntimeError('Remote stage already exists; reconcile its files and receipt before any retry')
                sftp.mkdir(STAGE,mode=0o700);sftp.chmod(STAGE,0o700)
                made={STAGE}
                for relative,path in files.items():
                    parents=relative.split('/')[:-1];directory=STAGE
                    for name in parents:
                        directory+='/'+name
                        if directory not in made:sftp.mkdir(directory,mode=0o700);made.add(directory)
                    target=STAGE+'/'+relative
                    with sftp.open(target,'wx') as output:output.write(path.read_bytes())
                    sftp.chmod(target,0o600)
                with sftp.open(STAGE+'/upload-manifest.json','wx') as output:output.write(raw)
                sftp.chmod(STAGE+'/upload-manifest.json',0o600)
            finally:sftp.close()
            phase='target_tests';command=result['targetTestCommand'];stdin_data=None
        elif mode=='readback':
            phase='read_only_campaign';command='php8.1';stdin_data=(BASE/'read-short-retry-campaign-status.php').read_text()
        else:raise RuntimeError('Unsupported explicit action')
        stdin,stdout,stderr=client.exec_command(command,timeout=210)
        if stdin_data is not None:stdin.write(stdin_data)
        stdin.channel.shutdown_write();output=stdout.read();errors=stderr.read();status=stdout.channel.recv_exit_status()
        if errors:raise RuntimeError('Remote command stderr withheld; reconcile stage receipt')
        data=json.loads(output)
        destination=REPO/'.qa/short-retry-20261009'/('target-runtime-receipt.json' if mode=='upload-test' else 'campaign-status-prepost.json')
        destination.write_text(json.dumps(data,indent=2)+'\n')
        if status or not data.get('ok'):raise RuntimeError('Remote check failed; inspect saved sanitized receipt: '+str(destination))
        return {'ok':True,'mode':mode,'stage':STAGE,'productionDeployed':False,'receipt':str(destination),'result':data}
    except Exception as error:
        if isinstance(error,RuntimeError):raise
        raise RuntimeError('Operation failed during '+phase+' ('+type(error).__name__+'); do not blindly retry; reconcile '+STAGE) from None
    finally:client.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',nargs='?',default='plan',choices=['plan','upload-test','readback']);args=parser.parse_args()
    print(json.dumps(execute(args.mode),indent=2))
