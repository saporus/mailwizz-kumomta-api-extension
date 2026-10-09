"""Pinned explicit private-test/apply modes for the two-file signed feedback candidate."""
from pathlib import Path
import argparse,errno,hashlib,json,re,shlex
REPO=Path(__file__).resolve().parent.parent;BUNDLE=REPO/'.qa/connect-release-20261009-v1'
STAGE='/var/tmp/magicsmtp-connect-20261009-v1';BACKUP='/root/magicsmtp-connect-backup-20261009-v1'
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()
def execute(mode,PIN,settings_sha=None):
    if sha(BUNDLE/'release-manifest.json')!=PIN:raise RuntimeError('Frozen manifest changed')
    manifest=json.loads((BUNDLE/'release-manifest.json').read_text());files={'release-manifest.json':BUNDLE/'release-manifest.json'}
    for name,digest in manifest['artifacts'].items():
        path=BUNDLE/name
        if not path.resolve().is_relative_to(BUNDLE.resolve()) or path.is_symlink() or sha(path)!=digest:raise RuntimeError('Frozen artifact changed')
        files[name]=path
    commands={value:shlex.join(['python3',STAGE+'/deploy-connect.py',value,STAGE,BACKUP,PIN])for value in ['dry-run','apply','verify','reconcile','rollback']}
    for label,action in [('prepare','prepare'),('inspect','inspect'),('activate','activate'),('disable-enrollment','disable-enrollment')]:
        args=['python3',STAGE+'/setup-connect.py',action,PIN]
        if label=='activate':
            if settings_sha and not re.fullmatch('[a-f0-9]{64}',settings_sha):raise RuntimeError('Invalid settings pin')
            args+=['--settings-sha256',settings_sha or 'REQUIRED_AFTER_PREPARE']
        commands[label]=shlex.join(args)
    test_command=shlex.join(['python3',STAGE+'/test-target-connect.py',PIN])
    if mode=='plan':return {'ok':True,'remoteExecuted':False,'stage':STAGE,'backup':BACKUP,'manifestSha256':PIN,'candidateHashes':{name:item['afterSha256']for name,item in manifest['files'].items()},'files':len(files),'testCommand':test_command,'commands':commands}
    import paramiko
    values=dict((key.lower(),value.strip())for key,value in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(REPO.parents[1]/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
    if values['host'] not in ['51.77.133.158','servermail2.com']:raise RuntimeError('Established MailWizz host required')
    client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
    report={'ok':False,'mode':mode,'manifestSha256':PIN,'phase':'connect','applyAttempted':False}
    def run(command):
        stdin,stdout,stderr=client.exec_command(command,timeout=120);stdin.channel.shutdown_write();raw=stdout.read();errors=stderr.read();status=stdout.channel.recv_exit_status()
        if errors or status:raise RuntimeError('Remote command failed; details withheld, reconcile before retry')
        result=json.loads(raw)
        if not result.get('ok'):raise RuntimeError('Remote result failed')
        return result
    try:
        client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
        if mode=='test-receipt':
            report['phase']='read_private_receipt'
            with client.open_sftp()as sftp:
                with sftp.open(STAGE+'/target-runtime-receipt.json','rb')as stream:report['result']=json.loads(stream.read())
        elif mode=='upload-test':
            report['phase']='private_upload'
            with client.open_sftp()as sftp:
                for name,item in manifest['files'].items():
                    if item['before']:
                        target=sftp.stat(name)
                        if (target.st_mode&0o777,target.st_uid,target.st_gid)!=(420,1003,1003):raise RuntimeError('Target metadata differs from approved baseline')
                    else:
                        try:sftp.lstat(name)
                        except IOError as failure:
                            if failure.errno!=errno.ENOENT:raise
                        else:raise RuntimeError('Expected absent helper exists')
                try:sftp.lstat(STAGE)
                except IOError as failure:
                    if failure.errno!=errno.ENOENT:raise
                else:raise RuntimeError('Private stage exists; reconcile instead of repeating upload')
                sftp.mkdir(STAGE,mode=0o700);sftp.chmod(STAGE,0o700);made={STAGE}
                for relative,path in files.items():
                    directory=STAGE
                    for part in relative.split('/')[:-1]:
                        directory+='/'+part
                        if directory not in made:sftp.mkdir(directory,mode=0o700);made.add(directory)
                    with sftp.open(STAGE+'/'+relative,'wx')as output:output.write(path.read_bytes())
                    sftp.chmod(STAGE+'/'+relative,0o600)
            report['phase']='isolated_tests';report['result']=run(test_command)
        else:
            with client.open_sftp()as sftp:
                for relative in ['release-manifest.json','deploy-connect.py','deploy-short-retry.py','setup-connect.py','connect-installation.php']:
                    expected=PIN if relative=='release-manifest.json' else manifest['artifacts'][relative]
                    with sftp.open(STAGE+'/'+relative,'rb')as stream:actual=hashlib.sha256(stream.read()).hexdigest()
                    if actual!=expected:raise RuntimeError('Remote executable pin changed')
            report['phase']=mode;report['applyAttempted']=mode in ['apply','prepare','activate','disable-enrollment'];report['result']=run(commands[mode])
            if mode=='apply':report['verify']=run(commands['verify'])
        report['ok']=True;report['phase']='complete'
    except Exception as failure:
        report['error']=str(failure)if isinstance(failure,RuntimeError)else type(failure).__name__
        if report['applyAttempted']:
            try:report['reconcile']=run(commands['reconcile'])
            except Exception as second:report['reconcileError']=type(second).__name__
    finally:
        client.close();directory=REPO/'.qa/connect-20261009';directory.mkdir(exist_ok=True);import time;destination=directory/(Path(STAGE).name+'-'+mode+'-'+str(time.time_ns())+'-receipt.json');destination.write_text(json.dumps(report,indent=2)+'\n')
    return {'ok':report['ok'],'receipt':str(destination),'result':report}
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',nargs='?',default='plan',choices=['plan','upload-test','test-receipt','dry-run','prepare','inspect','activate','disable-enrollment','apply','verify','reconcile','rollback']);parser.add_argument('manifest_sha256');parser.add_argument('--settings-sha256');args=parser.parse_args()
    result=execute(args.mode,args.manifest_sha256,args.settings_sha256);print(json.dumps(result,indent=2));raise SystemExit(0 if result['ok'] else 1)
