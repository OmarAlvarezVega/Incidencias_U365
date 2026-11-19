<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Cliente IMAP4rev1 mínimo sobre sockets TLS, sin ext-imap (no disponible en
 * PHP 8.4 y no garantizada en IONOS). Solo lo que la aplicación necesita:
 * LOGIN o AUTHENTICATE XOAUTH2, SELECT, UID SEARCH, UID FETCH del mensaje
 * completo, marcar como leído y mover a otra carpeta.
 *
 * Nunca escribe la contraseña ni el token en registros: los errores del
 * servidor durante la autenticación se devuelven sin repetir lo enviado.
 */
final class ImapClient
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    /** @var list<string> */
    private array $capabilities = [];
    /** Últimas líneas intercambiadas (sin credenciales) para diagnóstico. */
    public array $transcript = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'ssl',
        private readonly int $timeout = 25,
    ) {
    }

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            throw new MailException("No se pudo conectar con {$this->host}:{$this->port} ($errstr).");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new MailException('Saludo IMAP inesperado.');
        }
        if ($this->encryption === 'starttls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailException('No se pudo activar TLS (STARTTLS).');
            }
        }
        $this->loadCapabilities();
    }

    private function loadCapabilities(): void
    {
        [$untagged] = $this->command('CAPABILITY');
        foreach ($untagged as $line) {
            if (str_starts_with($line, '* CAPABILITY ')) {
                $this->capabilities = explode(' ', strtoupper(substr($line, 13)));
            }
        }
    }

    public function hasCapability(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->capabilities, true);
    }

    public function login(string $user, string $password): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password), true);
        $this->loadCapabilities();
    }

    public function authenticateXoauth2(string $oauth64): void
    {
        $tag = $this->nextTag();
        $this->write("$tag AUTHENTICATE XOAUTH2 $oauth64\r\n", true);
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) {
                // El servidor devuelve un error en base64: se responde vacío para cerrar el intercambio
                $this->write("\r\n", true);
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with($line, "$tag OK")) {
                    throw new MailException('El servidor rechazó el token OAuth2.');
                }
                break;
            }
        }
        $this->loadCapabilities();
    }

    /** @return array{exists:int,uidvalidity:int,uidnext:int} */
    public function select(string $folder): array
    {
        [$untagged] = $this->command('SELECT ' . self::quote(self::utf7Encode($folder)));
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($untagged as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                $info['exists'] = (int)$m[1];
            } elseif (preg_match('/UIDVALIDITY (\d+)/', $line, $m)) {
                $info['uidvalidity'] = (int)$m[1];
            } elseif (preg_match('/UIDNEXT (\d+)/', $line, $m)) {
                $info['uidnext'] = (int)$m[1];
            }
        }
        return $info;
    }

    /** @return list<int> UID mayores que $afterUid, ascendentes */
    public function uidsAfter(int $afterUid): array
    {
        [$untagged] = $this->command('UID SEARCH UID ' . ($afterUid + 1) . ':*');
        $uids = [];
        foreach ($untagged as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $u) {
                    if ($u !== '' && ctype_digit($u) && (int)$u > $afterUid) {
                        $uids[] = (int)$u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** Tamaño del mensaje en bytes. */
    public function size(int $uid): int
    {
        [$untagged] = $this->command("UID FETCH $uid (RFC822.SIZE)");
        foreach ($untagged as $line) {
            if (preg_match('/RFC822\.SIZE (\d+)/', $line, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    /** Mensaje completo (RFC 5322) sin marcarlo como leído. */
    public function fetchRaw(int $uid): ?string
    {
        [$untagged, , $literals] = $this->command("UID FETCH $uid (BODY.PEEK[])");
        foreach ($literals as $lit) {
            return $lit;
        }
        return null;
    }

    public function markSeen(int $uid): void
    {
        $this->command("UID STORE $uid +FLAGS.SILENT (\\Seen)");
    }

    /** Mueve el mensaje (MOVE si existe; si no, COPY + borrado). */
    public function move(int $uid, string $folder): void
    {
        $f = self::quote(self::utf7Encode($folder));
        if ($this->hasCapability('MOVE')) {
            $this->command("UID MOVE $uid $f");
            return;
        }
        $this->command("UID COPY $uid $f");
        $this->command("UID STORE $uid +FLAGS.SILENT (\\Deleted)");
        if ($this->hasCapability('UIDPLUS')) {
            $this->command("UID EXPUNGE $uid");
        }
    }

    public function ensureFolder(string $folder): void
    {
        try {
            $this->command('CREATE ' . self::quote(self::utf7Encode($folder)));
        } catch (MailException) {
            // Ya existe (o no se permite crear): el MOVE posterior lo dirá
        }
    }

    public function logout(): void
    {
        if ($this->sock === null) {
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
        }
        @fclose($this->sock);
        $this->sock = null;
    }

    public function __destruct()
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
        }
    }

    /**
     * @return array{0:list<string>,1:string,2:list<string>} [líneas sin etiqueta, línea final, literales]
     */
    private function command(string $cmd, bool $sensitive = false): array
    {
        $tag = $this->nextTag();
        $this->write("$tag $cmd\r\n", $sensitive);
        $untagged = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            // Literales {n}: se leen exactamente n bytes
            while (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->readBytes((int)$m[1]);
                $line = substr($line, 0, -strlen($m[0])) . '[literal]' . $this->readLine();
            }
            if (str_starts_with($line, $tag . ' ')) {
                $status = substr($line, strlen($tag) + 1, 2);
                if ($status !== 'OK') {
                    $detail = $sensitive ? 'credenciales rechazadas' : mb_substr(substr($line, strlen($tag) + 1), 0, 200);
                    throw new MailException('IMAP: ' . $detail);
                }
                return [$untagged, $line, $literals];
            }
            $untagged[] = $line;
        }
    }

    private function nextTag(): string
    {
        return 'A' . str_pad((string)++$this->tag, 4, '0', STR_PAD_LEFT);
    }

    private function write(string $data, bool $sensitive): void
    {
        if ($this->sock === null) {
            throw new MailException('Conexión IMAP cerrada.');
        }
        $this->log('C: ' . ($sensitive ? '[credenciales ocultas]' : rtrim($data)));
        $len = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = fwrite($this->sock, substr($data, $sent));
            if ($n === false || $n === 0) {
                throw new MailException('Error escribiendo en el servidor IMAP.');
            }
            $sent += $n;
        }
    }

    private function readLine(): string
    {
        if ($this->sock === null) {
            throw new MailException('Conexión IMAP cerrada.');
        }
        $line = fgets($this->sock, 65536);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new MailException($meta['timed_out'] ? 'Tiempo de espera agotado con el servidor IMAP.' : 'El servidor IMAP cerró la conexión.');
        }
        $line = rtrim($line, "\r\n");
        $this->log('S: ' . mb_substr($line, 0, 200));
        return $line;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out'] || feof($this->sock)) {
                    throw new MailException('Conexión interrumpida al descargar el mensaje.');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function log(string $line): void
    {
        $this->transcript[] = $line;
        if (count($this->transcript) > 40) {
            array_shift($this->transcript);
        }
    }

    public static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** Codificación UTF-7 modificada de IMAP para nombres de carpeta. */
    public static function utf7Encode(string $s): string
    {
        if (preg_match('/^[\x20-\x25\x27-\x7e]*$/', $s)) {
            return $s;
        }
        return (string)preg_replace_callback('/&|[^\x20-\x7e]+/u', static function ($m) {
            if ($m[0] === '&') {
                return '&-';
            }
            $utf16 = mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8');
            return '&' . str_replace('/', ',', rtrim(base64_encode($utf16), '=')) . '-';
        }, $s);
    }
}
