<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Requête HTTP immuable construite à partir des superglobales.
 */
final class Request
{
    /** @var array<string, mixed> Paramètres de route et données ajoutées par les middlewares */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers   noms en minuscules
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = [],
    ) {
    }

    /**
     * @param list<string> $trustedProxies adresses IP ou plages CIDR des proxys de confiance (TRUSTED_PROXIES)
     */
    public static function fromGlobals(array $trustedProxies = []): self
    {
        $server = self::applyTrustedProxies($_SERVER, $trustedProxies);
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        // Les formulaires HTML ne connaissent que GET/POST : champ caché _method pour PUT/PATCH/DELETE.
        if ($method === 'POST' && isset($body['_method'])) {
            $override = strtoupper((string) $body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');

        return new self($method, self::utf8($path), self::utf8($_GET), self::utf8($body), $headers, $_COOKIE, $_FILES, $server);
    }

    /**
     * Remplace les séquences UTF-8 invalides (client mal configuré, robot) : sans cela,
     * PostgreSQL rejetterait la requête et provoquerait une erreur 500.
     *
     * @template T of string|array
     * @param T $value
     * @return T
     */
    public static function utf8(string|array $value): string|array
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $clean[is_string($key) ? self::utf8($key) : $key] = is_string($item) || is_array($item) ? self::utf8($item) : $item;
        }

        return $clean;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * Fichiers envoyés sous un nom de champ (name="fichier" ou name="fichiers[]").
     *
     * @return list<UploadedFile>
     */
    public function files(string $key): array
    {
        $entry = $this->files[$key] ?? null;

        if (is_array($entry) && array_is_list($entry) && ($entry === [] || $entry[0] instanceof UploadedFile)) {
            return $entry; // déjà normalisé (tests)
        }

        return UploadedFile::fromFilesEntry(is_array($entry) ? $entry : null);
    }

    /**
     * Requête POST dont le corps a été rejeté par PHP car il dépasse post_max_size
     * ($_POST et $_FILES sont alors vides).
     */
    public function exceedsPostMaxSize(): bool
    {
        $length = (int) ($this->headers['content-length'] ?? 0);

        return $this->method === 'POST' && $this->body === [] && $this->files === [] && $length > 0;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->headers['user-agent'] ?? ''), 0, 500);
    }

    /**
     * Derrière un proxy de confiance (répartiteur de charge, Cloudflare…), l'adresse de la visiteuse et le
     * protocole viennent des en-têtes X-Forwarded-For / X-Forwarded-Proto. Ces en-têtes sont ignorés si la
     * connexion ne vient pas d'un proxy déclaré : sinon n'importe qui pourrait usurper une adresse.
     *
     * @param array<string, mixed> $server
     * @param list<string>         $trustedProxies
     * @return array<string, mixed>
     */
    public static function applyTrustedProxies(array $server, array $trustedProxies): array
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($trustedProxies === [] || !self::ipMatches($remote, $trustedProxies)) {
            return $server;
        }

        // Adresse réelle : la plus à droite qui n'est pas elle-même un proxy de confiance.
        $chain = array_reverse(array_filter(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '')))));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $server['REMOTE_ADDR'] = $ip;
            if (!self::ipMatches($ip, $trustedProxies)) {
                break;
            }
        }

        $proto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($proto === 'https') {
            $server['HTTPS'] = 'on';
        } elseif ($proto === 'http') {
            unset($server['HTTPS']);
        }

        return $server;
    }

    /** @param list<string> $ranges adresses exactes ou plages CIDR (IPv4 et IPv6) */
    public static function ipMatches(string $ip, array $ranges): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $subnetBinary = @inet_pton(trim($subnet));
            if ($subnetBinary === false || strlen($subnetBinary) !== strlen($binary)) {
                continue;
            }
            $bits = $bits === null ? strlen($binary) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($binary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($binary[$bytes]) ^ ord($subnetBinary[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return ($https !== '' && $https !== 'off') || ($this->server['SERVER_PORT'] ?? null) == 443;
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains($this->header('accept', ''), 'application/json')
            || $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }
}
