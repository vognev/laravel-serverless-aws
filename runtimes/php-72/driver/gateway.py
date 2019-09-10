#!/usr/bin/env python3
import argparse
import socketserver
import http.server
import socket
from urllib.parse import urlparse
from urllib.request import Request, urlopen
from http import HTTPStatus
import json
import sys
import base64


class HttpServer(socketserver.ThreadingTCPServer):
    def __init__(self, server_address, RequestHandlerClass):
        self.allow_reuse_address = True
        self.daemon_threads = True
        self.block_on_close = False
        super().__init__(server_address, RequestHandlerClass)


parser = argparse.ArgumentParser()
parser.add_argument('-p', '--port', required=True, type=int)
parser.add_argument('host')
args = parser.parse_args()


class Handler(http.server.BaseHTTPRequestHandler):
    def handle_one_request(self):
        try:
            self.raw_requestline = self.rfile.readline(65537)
            if len(self.raw_requestline) > 65536:
                self.requestline = ''
                self.request_version = ''
                self.command = ''
                self.send_error(HTTPStatus.REQUEST_URI_TOO_LONG)
                return
            if not self.raw_requestline:
                self.close_connection = True
                return
            if not self.parse_request():
                # An error code has been sent, just exit
                return
            self.do_request()
            self.wfile.flush()  # actually send the response if not already done.
        except socket.timeout as e:
            # a read or a write timed out.  Discard this connection
            self.log_error("Request timed out: %r", e)
            self.close_connection = True
            return

    def do_request(self):
        path = urlparse(self.path)

        data = {
            'path': path.path,
            'httpMethod': self.command,
            'multiValueHeaders': {},
            'multiValueQueryStringParameters': {},
            'body': ''
        }

        cl = self.headers.get('Content-Length')
        if cl is not None:
            data['body'] = base64.encodebytes(self.rfile.read(int(cl))).decode()
            data['isBase64Encoded'] = True

        if len(path.query):
            for query_arg in path.query.split('&'):
                arg_name, arg_value = query_arg.split('=')
                try:
                    data['multiValueQueryStringParameters'][arg_name].append(arg_value)
                except KeyError:
                    data['multiValueQueryStringParameters'][arg_name] = [arg_value]

        for header_name in self.headers.keys():
            data['multiValueHeaders'][header_name] = self.headers.get_all(header_name)

        invoke_url = f'http://%s:%d/2018-06-01/runtime/invocation' % (args.host, args.port)
        invoke_data = json.dumps(data).encode('utf8')

        req = Request(invoke_url)
        req.add_header('Content-Type', 'application/json')
        req.add_header('Content-Length', len(invoke_data))

        try:
            with urlopen(req, invoke_data) as f:
                res = f.read()
            data = json.loads(res)

            if 'statusCode' in data:
                self.send_response(data['statusCode'])
                for header_name in data['multiValueHeaders']:
                    for header in data['multiValueHeaders'][header_name]:
                        self.send_header(header_name, header)
                self.end_headers()
                self.wfile.write(data['body'].encode())
            elif 'errorType' in data:
                self.send_error(500, data['errorType'], data['errorMessage'])
            else:
                self.send_error(500)
        except:
            print(str(sys.exc_info()))
            self.send_error(500, None)


http = HttpServer(('', 80), Handler)
http.serve_forever()

