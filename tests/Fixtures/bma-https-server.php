<?php

declare(strict_types=1);

// Run by BmaDirectoryTest with a disposable localhost certificate and key.
$context = stream_context_create(['ssl' => ['local_cert'=>$argv[1], 'local_pk'=>$argv[2]]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
fwrite(STDOUT, 'https://'.stream_socket_get_name($server, false)."\n");
while ($socket = stream_socket_accept($server, 15)) {
    $request = trim(fgets($socket));
    $headers = [];
    while (($line = fgets($socket)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }
    $data = [];
    if (($length = (int)($headers['content-length'] ?? 0)) > 0) {
        parse_str(stream_get_contents($socket, $length), $data);
    }
    $body = '';
    if (str_starts_with($request, 'POST /index.php HTTP/')) {
        $status = "307 Temporary Redirect\r\nLocation: /index.php?canonical=1";
    } elseif (($data['Email'] ?? '') === 'outage') {
        $status = '503 Service Unavailable';
    } elseif (!str_starts_with($request, 'POST /index.php?canonical=1 HTTP/') || $headers['content-type'] !== 'application/x-www-form-urlencoded'
        || $headers['accept'] !== 'application/json' || $data !== ['secretkey'=>'synthetic-test-key', 'Email'=>'staff']) {
        $status = '400 Bad Request';
    } else {
        $status = '200 OK';
        $body = json_encode(['LdapPersonalID'=>'synthetic-id', 'UnitLevel1'=>'Synthetic unit']);
    }
    fwrite($socket, "HTTP/1.1 $status\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
    fclose($socket);
}
