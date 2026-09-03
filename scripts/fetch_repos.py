import json, os, time, urllib.request, urllib.error

with open('/Users/mini/.hermes/attachments/ofxaddons-triage.json') as f:
    data = json.load(f)
addons = data['addons']
token = open('/Users/mini/github_token.txt').read().strip()

OUT = '/Users/mini/ofxaddons_repo_info.jsonl'
existing = {}
if os.path.exists(OUT):
    for line in open(OUT):
        line = line.strip()
        if not line:
            continue
        try:
            r = json.loads(line)
            if r.get('status') in ('ok', 'error'):
                existing[r['id']] = r
        except Exception:
            pass

n_ok = n_err = n_404 = 0
for i, a in enumerate(addons):
    if a['id'] in existing:
        continue
    url = "https://api.github.com/repos/" + a['full_name']
    rec = None
    for attempt in range(6):
        req = urllib.request.Request(url, headers={'Authorization': 'token ' + token, 'Accept': 'application/vnd.github+json', 'User-Agent': 'hermes-agent'})
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                d = json.loads(r.read())
                rec = {'id': a['id'], 'status': 'ok', 'name': d.get('name'), 'desc': d.get('description'), 'archived': d.get('archived'), 'topics': d.get('topics'), 'pushed': d.get('pushed_at'), 'stars': d.get('stargazers_count'), 'forks': d.get('forks_count'), 'lang': d.get('language')}
                break
        except urllib.error.HTTPError as e:
            if e.code == 404:
                rec = {'id': a['id'], 'status': '404'}
                break
            if e.code in (403, 429):
                reset = e.headers.get('X-RateLimit-Reset')
                retry_after = e.headers.get('Retry-After')
                if retry_after:
                    wait = min(int(retry_after) + 5, 900)
                elif reset:
                    wait = max(int(reset) - time.time(), 5)
                else:
                    wait = 60
                time.sleep(min(wait, 900))
                continue
            rec = {'id': a['id'], 'status': str(e.code)}
            break
        except Exception:
            time.sleep(2 + attempt * 2)
    if rec is None:
        rec = {'id': a['id'], 'status': 'error'}
    if rec['status'] == 'ok':
        n_ok += 1
    elif rec['status'] == '404':
        n_404 += 1
    else:
        n_err += 1
    with open(OUT, 'a') as out:
        out.write(json.dumps(rec) + '\n')
    if (i + 1) % 100 == 0:
        print(f'{i+1}/2925 done (ok {n_ok}, 404 {n_404}, err {n_err})', flush=True)

# final: verify every addon has a record
missing = [a['id'] for a in addons if a['id'] not in existing and not any(True for _ in [0])]
# recount
have = set(json.loads(l)['id'] for l in open(OUT) if l.strip())
missing = [a['id'] for a in addons if a['id'] not in have]
print(f'DONE ok {n_ok} 404 {n_404} err {n_err} total_records {len(have)} missing {len(missing)}')
if missing:
    print('missing ids:', missing[:50])
