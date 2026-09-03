import json, os, time, base64, urllib.request, urllib.error

with open('/Users/mini/.hermes/attachments/ofxaddons-triage.json') as f:
    data = json.load(f)
addons = data['addons']
token = open('/Users/mini/github_token.txt').read().strip()

OUT = '/Users/mini/ofxaddons_readme.jsonl'
existing = {}
if os.path.exists(OUT):
    for line in open(OUT):
        line = line.strip()
        if not line:
            continue
        try:
            r = json.loads(line)
            if 'status' in r:
                existing[r['id']] = r
        except Exception:
            pass

def fetch_readme(full_name):
    # try README.md first (fast, exact name), fall back to API /readme (handles case)
    for path in ('README.md', 'readme.md', 'Readme.md'):
        url = f'https://raw.githubusercontent.com/{full_name}/HEAD/{path}'
        req = urllib.request.Request(url, headers={'User-Agent': 'hermes-agent'})
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read().decode('utf-8', 'replace')[:65536]
        except urllib.error.HTTPError as e:
            if e.code == 404:
                continue
            if e.code in (403, 429):
                time.sleep(5)
                continue
            raise
        except Exception:
            continue
    # API fallback (handles arbitrary casing)
    url = 'https://api.github.com/repos/' + full_name + '/readme'
    for attempt in range(6):
        req = urllib.request.Request(url, headers={'Authorization': 'token ' + token, 'Accept': 'application/vnd.github.raw', 'User-Agent': 'hermes-agent'})
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read().decode('utf-8', 'replace')[:65536]
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return None
            if e.code in (403, 429):
                wait = 30
                reset = e.headers.get('X-RateLimit-Reset')
                if reset:
                    wait = max(int(reset) - time.time(), 5)
                time.sleep(min(wait, 900))
                continue
            return None
        except Exception:
            time.sleep(3 + attempt * 2)
    return 'ERROR'

n_ok = n_none = n_err = 0
for i, a in enumerate(addons):
    if a['id'] in existing:
        continue
    try:
        text = fetch_readme(a['full_name'])
        rec = {'id': a['id'], 'readme': text if text is not None else 'NONE'}
        if text is None:
            n_none += 1
        elif text == 'ERROR':
            n_err += 1
        else:
            n_ok += 1
    except Exception as e:
        rec = {'id': a['id'], 'readme': 'NONE'}
        n_none += 1
    with open(OUT, 'a') as out:
        out.write(json.dumps(rec) + '\n')
    if (i + 1) % 100 == 0:
        print(f'{i+1}/2925 done (readme {n_ok}, none {n_none}, err {n_err})', flush=True)

have = set(json.loads(l)['id'] for l in open(OUT) if l.strip())
missing = [a['id'] for a in addons if a['id'] not in have]
print(f'DONE readme {n_ok} none {n_none} err {n_err} records {len(have)} missing {len(missing)}')
if missing:
    print('missing ids:', missing[:50])
