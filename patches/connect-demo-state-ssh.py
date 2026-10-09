"""Strict SSH for the separately reviewed one-file synthetic-state correction."""
from pathlib import Path
import argparse,hashlib,json,re,shlex,time
REPO=Path(__file__).resolve().parent.parent;BUNDLE=REPO/'.qa/connect-demo-state-release-20261009-v1';STAGE='/var/tmp/magicsmtp-connect-demo-state-20261009-v1'
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
def execute(mode,pin):
    if sha(BUNDLE/'manifest.json')!=pin:raise RuntimeError('Frozen hotfix manifest changed')
    manifest=json.loads((BUNDLE/'manifest.json').read_text());files={'manifest.json':BUNDLE/'manifest.json'}
    for name,digest in manifest['artifacts'].items():
        path=BUNDLE/name
        if not path.resolve().is_relative_to(BUNDLE) or path.is_symlink() or sha(path)!=digest:raise RuntimeError('Frozen hotfix artifact changed')
        files[name]=path
    commands={value:shlex.join(['python3',STAGE+'/deploy-connect-demo-state.py',value,pin])for value in ['test','apply','verify','reconcile','rollback']}
    if mode=='plan':return {'ok':True,'remoteExecuted':False,'stage':STAGE,'manifestSha256':pin,'commands':commands,'artifacts':len(files)}
    import paramiko
    values=dict((k.lower(),v.strip())for k,v in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(REPO.parents[1]/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
    if values['host']!='51.77.133.158':raise RuntimeError('Established target required')
    client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
    report={'ok':False,'mode':mode,'manifestSha256':pin,'mutationAttempted':False}
    def run(command):
        stdin,stdout,stderr=client.exec_command(command,timeout=90);stdin.channel.shutdown_write();raw=stdout.read();errors=stderr.read();status=stdout.channel.recv_exit_status()
        if errors or status:raise RuntimeError('Scoped hotfix command failed; inspect before retry')
        result=json.loads(raw)
        if not result.get('ok'):raise RuntimeError('Scoped hotfix result rejected')
        return result
    try:
        client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,look_for_keys=False,allow_agent=False)
        if mode=='upload-test':
            with client.open_sftp()as sftp:
                try:sftp.lstat(STAGE)
                except FileNotFoundError:pass
                else:raise RuntimeError('Private stage exists; reconcile before another attempt')
                sftp.mkdir(STAGE,mode=0o700);sftp.chmod(STAGE,0o700);made={STAGE}
                for relative,path in files.items():
                    directory=STAGE
                    for part in relative.split('/')[:-1]:
                        directory+='/'+part
                        if directory not in made:sftp.mkdir(directory,mode=0o700);made.add(directory)
                    with sftp.open(STAGE+'/'+relative,'wx')as f:f.write(path.read_bytes())
                    sftp.chmod(STAGE+'/'+relative,0o600)
            report['result']=run(commands['test'])
        else:
            with client.open_sftp()as sftp:
                for relative,digest in [('manifest.json',pin),('deploy-connect-demo-state.py',manifest['artifacts']['deploy-connect-demo-state.py'])]:
                    with sftp.open(STAGE+'/'+relative,'rb')as f:actual=hashlib.sha256(f.read()).hexdigest()
                    if actual!=digest:raise RuntimeError('Remote hotfix executable changed')
            report['mutationAttempted']=mode in ['apply','rollback'];report['result']=run(commands[mode])
            if mode=='apply':report['verify']=run(commands['verify'])
        report['ok']=True
    except Exception as failure:
        report['error']=str(failure)if isinstance(failure,RuntimeError)else type(failure).__name__
        if report['mutationAttempted']:
            try:report['reconcile']=run(commands['reconcile'])
            except Exception as second:report['reconcileError']=type(second).__name__
    finally:
        client.close();destination=REPO/'.qa/connect-20261009'/('demo-state-'+mode+'-'+str(time.time_ns())+'.json');destination.write_text(json.dumps(report,indent=2)+'\n')
    return {'ok':report['ok'],'receipt':str(destination),'result':report}
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['plan','upload-test','apply','verify','reconcile','rollback']);parser.add_argument('manifest_sha256');args=parser.parse_args();result=execute(args.mode,args.manifest_sha256);print(json.dumps(result,indent=2));raise SystemExit(0 if result['ok'] else 1)
