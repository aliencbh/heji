"""Exercise the three real HTTPS virtual hosts using the candidate's own CA."""
import datetime
import http.client
import os
from pathlib import Path
import re
import secrets
import socket
import ssl
import sys
from urllib.parse import urlencode

port=int(sys.argv[1])
logdir=Path(sys.argv[2])
logdir.mkdir(exist_ok=True)
tls=ssl.create_default_context(cafile=str(Path(os.environ["HEJI_VAR"])/"tls/fullchain.pem"))

class LocalHTTPS(http.client.HTTPSConnection):
    def connect(self):
        raw=socket.create_connection(("127.0.0.1",port),timeout=15)
        self.sock=tls.wrap_socket(raw,server_hostname=self.host)

def request(site,method,path,fields=None,cookies=""):
    host=site+".laravel.demo"
    client=LocalHTTPS(host,port,context=tls)
    body=urlencode(fields or {}) if fields is not None else None
    client.request(method,path,body,{"Host":host,"Cookie":cookies,"Content-Type":"application/x-www-form-urlencoded"})
    response=client.getresponse()
    result=(response.status,response.getheaders(),response.read())
    client.close()
    with (logdir/(host+".log")).open("a") as output:
        print(datetime.datetime.now(datetime.timezone.utc).isoformat(),method,path,result[0],file=output)
    return result

def expect(value,expected):
    if value!=expected:
        raise RuntimeError(f"expected {expected!r}, received {value!r}")

for site in ("shop","stock","recipe"):
    status,headers,body=request(site,"GET","/health")
    expect((status,body),(200,b"ok"))
    expect(request(site,"GET","/assets/site.txt")[2],(site+"\n").encode())
    expect(request(site,"GET","/assets/app.js")[0],200)
    expect(request(site,"GET","/assets/app.css")[0],200)
    expect(request(site,"GET","/assets/calendar.css")[0],200 if site=="recipe" else 404)
    status,headers,body=request(site,"GET","/admin")
    expect(status,302)
    expect(dict((k.lower(),v) for k,v in headers)["location"].endswith("/login"),True)
    expect(request(site,"GET","/")[0],200 if site=="shop" else 302)
    expect(request(site,"POST","/login",{"email":"guest@example.test","password":"x"})[0],419)
    with (logdir/(site+".laravel.demo.log")).open("a") as out:
        print("PASS TLS, 公共文件夹, 登录重定向, CSRF",file=out)

password=secrets.token_urlsafe(24)+"1a"
status,headers,body=request("shop","GET","/register")
expect(status,200)
token=re.search(rb'name="_token" value="([^"]+)"',body).group(1).decode()
cookies="; ".join(v.split(";",1)[0] for k,v in headers if k.lower()=="set-cookie")
status,_,_=request("shop","POST","/register",{"_token":token,"name":"容器验收","email":"browser@example.test","password":password,"password_confirmation":password},cookies)
expect(status,302)
secret_path=logdir.parent/"browser-password"
with secret_path.open("x") as f: f.write(password)
secret_path.chmod(0o600)