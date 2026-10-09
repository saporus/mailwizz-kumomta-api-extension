"""Parallel isolated workers execute exact private core reservation methods. No network."""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing,contextmanager
import argparse,json,shutil,sqlite3,subprocess,uuid

parser=argparse.ArgumentParser()
parser.add_argument('native_source');parser.add_argument('worker_source')
parser.add_argument('--php',default='php');parser.add_argument('--pdo-sqlite',default='php_pdo_sqlite.dll')
args=parser.parse_args();fixture=Path(__file__).with_name('minute_quota_native_runtime_test.php')
php=[args.php]+(['-d','extension='+args.pdo_sqlite] if args.pdo_sqlite else [])
@contextmanager
def private_fixture():
    parent=(fixture.parent.parent/'.qa').resolve();parent.mkdir(exist_ok=True)
    directory=parent/('minute-quota-concurrency-'+uuid.uuid4().hex);directory.mkdir(mode=0o777)
    try:yield str(directory)
    finally:
        resolved=directory.resolve()
        if resolved.parent!=parent or not resolved.name.startswith('minute-quota-concurrency-'):raise RuntimeError('Unsafe fixture cleanup target')
        shutil.rmtree(resolved)
receipts=[]
for scenario,kinds in [('all_new',['new']*8),('mixed_old_new',['old','new']*4)]:
    with private_fixture() as directory:
        with closing(sqlite3.connect(Path(directory)/'ledger.sqlite')) as db:
            db.execute('CREATE TABLE usage(id INTEGER PRIMARY KEY AUTOINCREMENT,server_id INTEGER NOT NULL,at REAL NOT NULL)')
            db.execute('CREATE INDEX usage_server_time ON usage(server_id,at)')
            db.commit()
        def work(kind):
            result=subprocess.run(php+[str(fixture),str(Path(args.native_source).resolve()),'--worker',directory,kind,str(Path(args.worker_source).resolve())],capture_output=True,text=True,timeout=60)
            if result.returncode or result.stderr:raise RuntimeError('Fixture process failed: '+result.stderr+result.stdout)
            return json.loads(result.stdout)
        with ThreadPoolExecutor(max_workers=8) as pool:workers=list(pool.map(work,kinds))
        with closing(sqlite3.connect(Path(directory)/'ledger.sqlite')) as db:count=db.execute('SELECT COUNT(*) FROM usage').fetchone()[0]
        accepted=sum(w['accepted'] for w in workers)
        assert accepted==450 and count==450 and len({w['pid'] for w in workers})==8
        receipts.append({'scenario':scenario,'processes':8,'attempts':640,'accepted':accepted,'nativeLedgerRows':count,'denied':sum(w['denied'] for w in workers),'networkCalls':0})
print(json.dumps({'ok':True,'isolatedSynthetic':True,'results':receipts},indent=2))
