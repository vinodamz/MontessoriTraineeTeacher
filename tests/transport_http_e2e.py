#!/usr/bin/env python3
"""Local-only Transport HTTP + SQL readback. Never calls delivery endpoints."""
import argparse, datetime, hashlib, json, pathlib, subprocess, sys, urllib.request, urllib.error, uuid
BASE = 'http://127.0.0.1:8765'
SOCKET = '/tmp/lg-mtt-db.sock'
SCHEMA = 'lg_e2e'
ROOT = pathlib.Path('/tmp/lg-mtt-e2e')
PHP = '/opt/homebrew/bin/php'
RESULTS = []
CREATED = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise RuntimeError('Redirect refused: this test must stay on localhost')
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

def check(name, good, detail=''):
    RESULTS.append({'name': name, 'passed': bool(good), 'detail': detail})
    print(('PASS ' if good else 'FAIL ') + name + (' ' + str(detail)[:220] if detail else ''), flush=True)

def req(endpoint, data=None, role='teacher', expected=200):
    path = '/api/v1/' + endpoint
    assert endpoint in ['login.php', 'logout.php'] or endpoint.startswith('transport/')
    assert endpoint.split('?')[0] not in ['transport/alert.php', 'transport/sos.php']
    headers = {'Accept': 'application/json'}
    if role in tokens: headers['Authorization'] = 'Bearer ' + tokens[role]
    if data is not None: headers['Content-Type'] = 'application/json'
    request = urllib.request.Request(BASE + path, None if data is None else json.dumps(data).encode(), headers)
    try: response = opener.open(request, timeout=10)
    except urllib.error.HTTPError as e: response = e
    raw = response.read().decode()
    try: body = json.loads(raw)
    except ValueError: raise AssertionError('Non-JSON local response: ' + raw[:200])
    if expected is not None:
        check(f'{role} {request.get_method()} {endpoint} => {expected}', response.status == expected,
              '' if response.status == expected else {'status': response.status, 'body': body})
    return response.status, body

def sql(query, values=()):
    code = '''$p=new PDO('mysql:unix_socket=/tmp/lg-mtt-db.sock;dbname=lg_e2e;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$a=json_decode(stream_get_contents(STDIN),true);$s=$p->prepare($a[0]);$s->execute($a[1]);echo json_encode($s->fetchAll());'''
    return json.loads(subprocess.check_output([PHP, '-r', code], input=json.dumps([query,list(values)]).encode()))

def readback(trip, label):
    _, d = req('transport/trip.php?id=' + str(trip['id']))
    check(label + ' independent HTTP reload', d.get('trip') == trip)
    rows = sql('SELECT id,student_id,stop_order,status FROM transport_trip_stops WHERE trip_id=? ORDER BY stop_order,id', [trip['id']])
    shape = lambda s: (int(s['id']),int(s['student_id']),int(s.get('order',s.get('stop_order'))),s['status'])
    check(label + ' SQL stop persistence', list(map(shape, rows)) == list(map(shape, trip['stops'])))
    state = sql('SELECT status FROM transport_trips WHERE id=?', [trip['id']])[0]['status']
    check(label + ' SQL trip state', state == trip['status'])

def create(ids, label, direction='pickup'):
    _, d = req('transport/create.php', {'name': 'HTTP isolated ' + label + ' ' + tag,
        'direction': direction, 'student_ids': ids})
    trip = d['trip']; CREATED.append(trip['id'])
    check(label + ' created scheduled', trip['status'] == 'scheduled')
    return trip

def act(trip, op, expected=200, **extra):
    _, d = req('transport/action.php', {'trip_id': trip['id'], 'op': op, **extra}, expected=expected)
    if expected == 200:
        trip = d['trip']; readback(trip, op)
    return trip

def ids(trip): return [s['student_id'] for s in trip['stops']]
def stop(trip, sid): return next(s for s in trip['stops'] if s['student_id'] == sid)

parser=argparse.ArgumentParser();parser.add_argument('--report', default='/tmp/lg-transport-http-results.json'); args=parser.parse_args()
fixture=json.loads(pathlib.Path('/tmp/lg-e2e-fixture.json').read_text()); tokens={}; tag=uuid.uuid4().hex[:8]
config=(ROOT/'includes/config.php').read_text()
assert SOCKET in config and "'name' => 'lg_e2e'" in config
assert pathlib.Path(SOCKET).is_socket()
check('database isolation', sql('SELECT DATABASE() AS db')[0]['db']==SCHEMA)
source=pathlib.Path('/Users/vinodamz/LGApps/MontessoriTraineeTeacher')
files=list((ROOT/'api/v1/transport').glob('*.php'))+[ROOT/'includes/transport.php',ROOT/'includes/transport_ops.php',ROOT/'includes/api_transport.php']
check('runtime Transport matches source', all(p.read_bytes()==(source/p.relative_to(ROOT)).read_bytes() for p in files))
a,b=fixture['students']; original_settings=None
try:
    for role, uid in fixture['users'].items():
        _,d=req('login.php', {'user_id':uid,'pin':'654321','device_name':'Transport isolated '+tag},role=role)
        tokens[role]=d['token']
    for endpoint in ['today','routes','calendar','children','roster','overview','reports','messages','settings']:
        req('transport/'+endpoint+'.php')
        req('transport/'+endpoint+'.php',role='anonymous',expected=401)
        for role in ['basic','duty']:
            req('transport/'+endpoint+'.php',role=role,expected=403)
    t=create([a,b], 'lifecycle'); before={s['id'] for s in t['stops']}
    act(t,'done',expected=409,stop_id=t['stops'][0]['id'])
    t=act(t,'start'); after={s['id'] for s in t['stops']}
    print('INFO start stop ids', sorted(before), '=>', sorted(after),flush=True)
    check('start preserves draft stop identifiers', before == after)
    t=act(t,'down',stop_id=stop(t,a)['id']); check('move down reordered children',ids(t)==[b,a])
    t=act(t,'up',stop_id=stop(t,a)['id']); check('move up reordered children',ids(t)==[a,b])
    t=act(t,'reached',stop_id=stop(t,a)['id']); check('reached persisted timestamp',stop(t,a)['reached_at'] is not None)
    t=act(t,'done',stop_id=stop(t,a)['id']); check('done marks child while trip continues',stop(t,a)['status']=='done' and t['status']=='running')
    act(t,'remove',expected=409,stop_id=stop(t,a)['id'])
    t=act(t,'undo',stop_id=stop(t,a)['id']); check('undo clears reached and done',stop(t,a)['status']=='pending' and stop(t,a)['done_at'] is None and stop(t,a)['reached_at'] is None)
    t=act(t,'absent',stop_id=stop(t,a)['id']); check('absent persisted',stop(t,a)['status']=='absent')
    t=act(t,'undo',stop_id=stop(t,a)['id'])
    t=act(t,'remove',stop_id=stop(t,b)['id']); check('remove retains waiting child',ids(t)==[a] and t['status']=='running')
    t=act(t,'add',student_id=b); check('add appends requested child',ids(t)==[a,b])
    act(t,'add',expected=409,student_id=b)
    _,d=req('transport/location.php',{'trip_id':t['id'],'points':[{'lat':12.1,'lng':77.1,'accuracy':10,'speed':0}]})
    check('GPS accepted locally',d.get('accepted')==1)
    check('GPS persisted in isolated DB',len(sql('SELECT id FROM transport_locations WHERE trip_id=?',[t['id']]))==1)
    t=act(t,'finish'); check('explicit finish completed',t['status']=='completed')
    act(t,'undo',expected=409,stop_id=t['stops'][0]['id'])
    c=create([a,b],'cancel','drop'); c=act(c,'cancel'); check('cancel scheduled persisted',c['status']=='cancelled')
    act(c,'start',expected=409)
    c=create([a,b],'cancel-running'); c=act(c,'start'); c=act(c,'cancel'); check('cancel running persisted',c['status']=='cancelled')
    t=create([a,b],'auto-finish'); t=act(t,'start'); t=act(t,'done',stop_id=stop(t,a)['id']); t=act(t,'absent',stop_id=stop(t,b)['id']); check('last mark auto-finishes trip',t['status']=='completed')
    _,r=req('transport/roster.php'); byid={c['id']:c for c in r['children']}; check('roster reports arrival and absence',byid[a]['status']=='arrived' and byid[b]['status']=='absent', {x:byid[x]['status'] for x in [a,b]})
    check('roster counts match rows',sum(r['counts'].values())==len(r['children']))
    _,r=req('transport/roster.php?q='+urllib.parse.quote('auto-finish')); check('roster route search',all('auto-finish' in c['route_name'] for c in r['children']) and len(r['children'])==2)
    # Deliberate edits on a scheduled trip must survive reopen and start.
    t=create([a,b],'draft-order'); t=act(t,'down',stop_id=stop(t,a)['id']); wanted=ids(t)
    t=act(t,'start'); check('scheduled order survives start',ids(t)==wanted,{'want':wanted,'got':ids(t)}); act(t,'cancel')
    t=create([a,b],'draft-remove'); t=act(t,'remove',stop_id=stop(t,b)['id'])
    _,d=req('transport/open.php',{'route_id':t['route_id'],'direction':'pickup'}); t=d['trip']
    check('scheduled removal survives reopen',ids(t)==[a],ids(t)); act(t,'cancel')
    t=create([a],'draft-add'); t=act(t,'add',student_id=b)
    _,d=req('transport/create.php',{'route_id':t['route_id'],'direction':'pickup'}); t=d['trip']
    check('scheduled addition survives create existing',ids(t)==[a,b],ids(t))
    t=act(t,'start'); check('scheduled addition survives start',ids(t)==[a,b],ids(t)); act(t,'cancel')
    t=create([a],'empty-draft'); t=act(t,'remove',stop_id=stop(t,a)['id'])
    act(t,'start',expected=409); readback(t,'empty draft rejected')
    act(t,'cancel')
    for role in ['teacher','admin']:
        kind='message' if role=='teacher' else 'broadcast'; body='Fictional local '+kind+' '+tag
        _,d=req('transport/messages.php',{'body':body,'kind':kind},role); mid=d['message']['id']
        _,d=req('transport/messages.php'); check(kind+' HTTP readback',any(m['id']==mid and m['body']==body for m in d['messages']))
        check(kind+' DB persistence',sql('SELECT body FROM transport_messages WHERE id=?',[mid])[0]['body']==body)
    req('transport/messages.php',{'body':'Forbidden local broadcast','kind':'broadcast'},expected=403)
    req('transport/messages.php',{'body':' '},expected=400)
    req('transport/messages.php',{'body':'No delivery','kind':'sos'},expected=400)
    _,d=req('transport/settings.php',role='admin'); original_settings=d['settings']
    changed={**original_settings,'notify_pickup':not original_settings['notify_pickup'],'speed_alerts':not original_settings['speed_alerts']}
    req('transport/settings.php',changed,expected=403)
    _,d=req('transport/settings.php',changed,role='admin'); check('admin settings acknowledged',all(d['settings'][k]==v for k,v in changed.items()))
    _,d=req('transport/settings.php'); check('settings independently reloaded',d['settings']==changed)
    for role in ['basic','duty']:
        for endpoint,data in [('create',{'name':'Forbidden','direction':'pickup','student_ids':[a]}),('action',{'trip_id':t['id'],'op':'start'}),('messages',{'body':'Forbidden'}),('settings',changed),('location',{'trip_id':t['id'],'points':[]})]:
            req('transport/'+endpoint+'.php',data,role,expected=403)
finally:
    if original_settings is not None: req('transport/settings.php',original_settings,role='admin')
    for tid in CREATED:
        try:
            _,d=req('transport/trip.php?id='+str(tid),expected=None)
            if d.get('trip',{}).get('status') in ['scheduled','running']:
                req('transport/action.php',{'trip_id':tid,'op':'cancel'},expected=None)
        except Exception as e: print('Cleanup:',e)
    for role in tokens: req('logout.php',{},role,expected=None)
    pathlib.Path(args.report).write_text(json.dumps({'base':BASE,'schema':SCHEMA,'tag':tag,'trips':CREATED,'results':RESULTS},indent=2))
    print(f"{sum(r['passed'] for r in RESULTS)}/{len(RESULTS)} passed; {args.report}")
sys.exit(0 if all(r['passed'] for r in RESULTS) else 1)
