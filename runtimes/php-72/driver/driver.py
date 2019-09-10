#!/usr/bin/env python3
# todo: from naive implementation to bulletproof
# todo: runtime pool (instead of only one currently running)
# todo: async/await/future instead of low-level stuff
import re, os, sys, uuid, fcntl, select, argparse, http.server, socketserver
from subprocess import Popen, PIPE
from threading import Event
from queue import Queue


def start_runtime(port, bootstrap, handler):
    env = os.environ.copy()
    env.update({
        "AWS_LAMBDA_RUNTIME_API": f"127.0.0.1:%d" % port,
        "_HANDLER": handler,
    })

    λ = Popen([bootstrap], stdout=PIPE, stderr=PIPE, shell=False, env=env)

    # make stdout/stderr non-blocking
    fcntl.fcntl(λ.stdout, fcntl.F_SETFL, os.O_NONBLOCK | fcntl.fcntl(λ.stdout, fcntl.F_GETFL))
    fcntl.fcntl(λ.stderr, fcntl.F_SETFL, os.O_NONBLOCK | fcntl.fcntl(λ.stderr, fcntl.F_GETFL))

    return λ


class Dispatcher(dict):
    def notify(self, request_id, success, failure):
        try:
            events = self.pop(request_id)
        except KeyError:
            events = []

        for event in events:
            event.success = success
            event.failure = failure
            event.set()

    def subscribe(self, request_id, event):
        try:
            self[request_id].push(event)
        except KeyError:
            self[request_id] = [event]


class HttpServer(socketserver.ThreadingTCPServer):
    def __init__(self, server_address, RequestHandlerClass):
        self.allow_reuse_address = True
        self.daemon_threads = True
        self.block_on_close = False
        super().__init__(server_address, RequestHandlerClass)


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/2018-06-01/runtime/invocation/next':
            request_id, body = queue.get()
            self.send_response(200)
            self.send_header('Lambda-Runtime-AWS-Request-Id', request_id)
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_error(404)

    def do_POST(self):
        if '/2018-06-01/runtime/invocation' == self.path:
            return self.invoke()

        match = re.search(r'^/2018-06-01/runtime/invocation/([^/]+)/(error|response)$', self.path)
        if match is None:
            self.send_error(400)

        action = match.group(2)
        request_id = match.group(1)

        {'error': self.error, 'response': self.success}[action](request_id)

    def invoke(self):
        request_id = str(uuid.uuid4())
        queue.put((request_id, self.rfile.read(int(self.headers.get('Content-Length')))))

        event = Event()
        dispatcher.subscribe(request_id, event)
        event.wait()

        self.send_response(200)
        self.send_header('Lambda-Runtime-AWS-Request-Id', request_id)
        self.end_headers()
        self.wfile.write(event.success or event.failure)

    def error(self, request_id):
        global dispatcher
        dispatcher.notify(request_id, None, self.rfile.read(int(self.headers.get('Content-Length'))))
        self.send_response(200)
        self.end_headers()

    def success(self, request_id):
        global dispatcher
        dispatcher.notify(request_id, self.rfile.read(int(self.headers.get('Content-Length'))), None)
        self.send_response(200)
        self.end_headers()


parser = argparse.ArgumentParser()
parser.add_argument('-p', '--port', required=True, type=int)
parser.add_argument('bootstrap')
parser.add_argument('handler')
args = parser.parse_args()

dispatcher = Dispatcher()
queue = Queue()

http = HttpServer(('', args.port), Handler)
while True:
    try:
        λ = start_runtime(args.port, args.bootstrap, args.handler)
        print("Runtime started with pid %d" % λ.pid, file=sys.stderr)

        while λ.poll() is None:
            r = select.select([λ.stdout, λ.stderr, http], [], [])[0]
            for readable in r:
                if readable is λ.stdout:
                    sys.stdout.buffer.write(readable.read())
                if readable is λ.stderr:
                    sys.stderr.buffer.write(readable.read())
                if readable is http:
                    http.handle_request()

        print("Runtime exited with exit code %d" % λ.returncode, file=sys.stderr)
    except KeyboardInterrupt:
        http.server_close()
        break
