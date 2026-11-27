#!/usr/bin/env python3
"""Attendance permissions against the disposable local MTT QA fixture only.

Run after preparing lg_e2e and /tmp/lg-e2e-fixture.json as described in the
LittleGraduatesApp QA report. Never run against school records.
"""
import datetime
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8765'
fixture = json.load(open('/tmp/lg-e2e-fixture.json'))

class LocalRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlsplit(newurl).netloc != '127.0.0.1:8765':
            raise RuntimeError('Refusing redirect outside the isolated test server')
        return super().redirect_request(req, fp, code, msg, headers, newurl)

browser = urllib.request.build_opener(
    urllib.request.ProxyHandler({}), LocalRedirect(),
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
)

def request(path, fields=None):
    body = None if fields is None else urllib.parse.urlencode(fields).encode()
    try:
        response = browser.open(BASE + path, body, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode()

def check(condition, name):
    if not condition:
        raise AssertionError(name)
    print('PASS ' + name)

status, body = request('/api/v1/login.php', {
    'user_id': fixture['users']['scoped'], 'pin': '654321',
    'device_name': 'Local attendance regression',
})
auth = json.loads(body)
check(status == 200 and auth['user']['name'] == 'E2E scoped', 'isolated teacher login')
status, html = request('/app/open.php', {
    'token': auth['token'], 'path': '/students/attendance.php',
})
check(status == 200, 'open attendance through app bridge')
csrf = re.search(r'name="_csrf" value="([^"]+)"', html)[1]
own, foreign = fixture['scoped_student'], fixture['students'][0]
date = (datetime.date.today() - datetime.timedelta(days=1)).isoformat()

status, _ = request('/students/attendance.php', {
    '_csrf': csrf, 'date': date, f'mark[{foreign}][status]': 'absent',
})
check(status == 403, 'cannot mark another class')
status, body = request('/students/attendance.php', {
    '_csrf': csrf, 'date': date, f'mark[{own}][status]': 'present',
    f'mark[{own}][notes]': 'E2E original attendance',
})
check(status == 200 and 'Attendance saved' in body, 'can mark own class')
status, _ = request('/students/attendance.php', {
    '_csrf': csrf, 'date': date, f'mark[{own}][status]': 'absent',
    f'mark[{own}][notes]': 'E2E forbidden mixed batch',
    f'mark[{foreign}][status]': 'absent',
})
check(status == 403, 'mixed-class batch rejected')
status, body = request('/students/attendance.php?date=' + date)
check(status == 200 and 'E2E original attendance' in body
      and 'E2E forbidden mixed batch' not in body, 'mixed-class batch saves nothing')
