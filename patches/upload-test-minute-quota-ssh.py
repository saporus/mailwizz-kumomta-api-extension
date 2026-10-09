"""Explicit private upload/isolated tests or read-only probe. Never applies code."""
from pathlib import Path
import argparse,errno,hashlib,json,re,shlex

REPO=Path(__file__).resolve().parent.parent
BUNDLE=REPO/'.qa/minute-quota-release-20261009-v4'
STAGE='/var/tmp/magicsmtp-minute-quota-20261009-v1'
BACKUP='/root/magicsmtp-minute-quota-backup-20261009-v1'
PIN='8aabf57e3fedc3c96e321df396b811bbaa2411881f38123513e02a81deb78357'
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()

def plan():
    if sha(BUNDLE/'release-manifest.json')!=PIN:raise RuntimeError('Frozen release manifest changed')
    manifest=json.loads((BUNDLE/'release-manifest.json').read_text())
    files={'release-manifest.json':BUNDLE/'release-manifest.json'}
    for relative,digest in manifest['artifacts'].items():
        path=BUNDLE/relative
        if not path.resolve().is_relative_to(BUNDLE.resolve()) or any(part.is_symlink() for part in [path,*path.parents]) or sha(path)!=digest:raise RuntimeError('Frozen private artifact changed')
        files[relative]=path
    commands={mode:shlex.join(['python3',STAGE+'/deploy-minute-quota.py',mode,STAGE,BACKUP,PIN]) for mode in ['dry-run','apply','verify','reconcile','rollback']}
    return files,{'ok':True,'mode':'plan','remoteExecuted':False,'stage':STAGE,'backup':BACKUP,'manifestSha256':PIN,'fileCount':len(files),'targetTestCommand':shlex.join(['python3',STAGE+'/test-target-minute-quota.py',PIN]),'deploymentCommands':commands}

def execute(mode):
    files,result=plan()
    if mode=='plan':return result
    import paramiko
    credentials=REPO.parents[1]/'Temp-Credentials/Mailwizz.txt'
    values=dict((key.lower(),value.strip())for key,value in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',credentials.read_text(),re.M|re.I))
    if values['host'] not in ['51.77.133.158','servermail2.com']:raise RuntimeError('Exact established MailWizz host required')
    client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
    phase='connect'
    try:
        client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
        stdin_data=None
        if mode=='upload-test':
            phase='private_upload';sftp=client.open_sftp()
            try:
                try:sftp.lstat(STAGE)
                except IOError as failure:
                    if failure.errno!=errno.ENOENT:raise
                else:raise RuntimeError('Private stage exists; reconcile instead of retrying upload')
                sftp.mkdir(STAGE,mode=0o700);sftp.chmod(STAGE,0o700);made={STAGE}
                for relative,path in files.items():
                    directory=STAGE
                    for part in relative.split('/')[:-1]:
                        directory+='/'+part
                        if directory not in made:sftp.mkdir(directory,mode=0o700);made.add(directory)
                    target=STAGE+'/'+relative
                    with sftp.open(target,'wx')as output:output.write(path.read_bytes())
                    sftp.chmod(target,0o600)
            finally:sftp.close()
            phase='isolated_target_tests';command=result['targetTestCommand']
        elif mode in ['readiness','readback']:
            phase='read_only_'+mode;command='php8.1 -d opcache.enable_cli=0'
            stdin_data=(BUNDLE/('read-minute-quota-readiness.php' if mode=='readiness' else 'read-short-retry-campaign-status.php')).read_text()
        else:raise RuntimeError('Explicit supported mode required')
        stdin,stdout,stderr=client.exec_command(command,timeout=210)
        if stdin_data is not None:stdin.write(stdin_data)
        stdin.channel.shutdown_write();raw=stdout.read();errors=stderr.read();status=stdout.channel.recv_exit_status()
        if errors:raise RuntimeError('Target stderr withheld; reconcile private stage before retry')
        data=json.loads(raw)
        destination=REPO/'.qa/quota-minute-20261009'/('target-'+mode+'-receipt.json')
        destination.write_text(json.dumps(data,indent=2)+'\n')
        if status or not data.get('ok'):raise RuntimeError('Target check failed; inspect sanitized receipt '+str(destination))
        return {'ok':True,'mode':mode,'productionApplied':False,'stage':STAGE,'receipt':str(destination),'result':data}
    except Exception as failure:
        if isinstance(failure,RuntimeError):raise
        raise RuntimeError('Operation failed during '+phase+' ('+type(failure).__name__+'); reconcile before any retry')from None
    finally:client.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',nargs='?',default='plan',choices=['plan','readiness','upload-test','readback']);args=parser.parse_args()
    print(json.dumps(execute(args.mode),indent=2))
