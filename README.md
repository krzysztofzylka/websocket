# Websocket Library

Minimalna biblioteka WebSocket dla PHP **8.3+**, zbudowana na podstawie `krzysztofzylka/event-loop`. Zapewnia serwer WebSocket, obsługę połączeń, dekodowanie ramek zgodnie z RFC 6455 oraz prosty model zdarzeń (`onOpen`, `onMessage`, `onClose`, `onError`, `onPing`, `onPong`).

## Wymagania

- PHP 8.3 lub nowszy z rozszerzeniami `sockets` i `mbstring`
- Composer

## Instalacja

```bash
composer install
```

## Szybki start

```php
<?php

use Async\EventLoop;
use Websocket\Connection;
use Websocket\Server;

require __DIR__ . '/vendor/autoload.php';

$loop = new EventLoop();
$server = new Server($loop, '0.0.0.0', 8088, [], [
    'adaptive_text_streaming' => true,
    'max_decompression_ratio' => 25,
]);
$server->setAllowedOrigins(['http://localhost:3000', 'file://', 'null'], false);
$server->setAuthValidator(static function (Connection $connection, array $headers, array $query): bool {
    return hash_equals('secret-token', $query['token'] ?? '');
});
$server->enableLogging(true);
$server->setAdaptiveMemoryTarget(64 * 1024 * 1024, 8 * 1024 * 1024);

$server->onOpen(function (Connection $connection): void {
    $connection->setProperty('user_id', 123);
    $connection->setLabel('authenticated-user');

    $connection->sendText('Welcome! Your connection id is ' . $connection->getId());
});

$server->onMessage(function (Connection $connection, string $payload, bool $isText): void {
    if ($isText) {
        $userId = $connection->getProperty('user_id');
        $connection->sendText(sprintf('[echo][user:%s] %s', $userId ?? 'guest', $payload));
    }
});

$server->start();
$loop->run();
```

### TLS (wss://)

```php
$context = [
    'ssl' => [
        'local_cert' => __DIR__ . '/cert.pem',
        'local_pk' => __DIR__ . '/privkey.pem',
        'allow_self_signed' => true,
        'verify_peer' => false,
    ],
];

$options = [
    'tls' => true,
    'tls_auto_reload' => true,
    'tls_reload_interval' => 300,
];

$server = new Server($loop, '0.0.0.0', 8443, $context, $options);
$server->enableTlsAutoReload(true, 600); // równoważne ustawieniom w $options
$server->start();
```

Kontekst `ssl` musi zawierać co najmniej klucz i certyfikat serwera. Opcjonalnie możesz ustawić niestandardową metodę szyfrowania poprzez `Server::setTlsCryptoMethod()`. Dzięki `tls_auto_reload` biblioteka cyklicznie sprawdzi zmiany w plikach certyfikatu i zaktualizuje ustawienia bez restartu serwera.

### Kompresja permessage-deflate

Jeżeli klient proponuje `permessage-deflate` i zainstalowane jest rozszerzenie `zlib`, serwer automatycznie negocjuje kompresję. Zachowanie można kontrolować:

```php
$server->enableCompression(true); // domyślnie true
$server->setCompressionContextTakeover(clientNoContext: true, serverNoContext: true);
$server->setCompressionMinBytes(256); // minimalny rozmiar danych do kompresji
```

### Duże wiadomości binarne

Fragmentowane lub duże wiadomości binarne po przekroczeniu zadanego progu trafiają do handlera `onBinaryStream`, dzięki czemu można je przetwarzać strumieniowo (bez kopiowania całych danych do pamięci).

```php
$server->setBinaryStreamThreshold(256 * 1024); // próg przełączenia na strumień
$server->setTextStreamThreshold(128 * 1024);   // osobny próg dla tekstu
$server->enableAdaptiveTextStreaming(true);    // dostosuj próg na podstawie pamięci
$server->setMaxMessageSize(8 * 1024 * 1024);  // globalny limit rozmiaru wiadomości

$server->onBinaryStream(function (Connection $connection, $stream, int $length): void {
    echo "[binary] {$length} bytes" . PHP_EOL;
    stream_copy_to_stream($stream, fopen('php://output', 'wb'));
    fclose($stream);
});
```

Mniejsze payloady wciąż trafiają do `onMessage` z `$isText === false`.
`enableAdaptiveTextStreaming()` obserwuje zużycie pamięci i automatycznie reguluje próg streamowania tekstu, co pomaga utrzymać stabilność przy dużej liczbie równoległych, długich wiadomości.

### Zabezpieczenia

```php
$server->setAllowedOrigins(['https://example.com'], true); // domyślnie wymaga nagłówka Origin zgodnego z listą
$server->setAuthValidator(static function (Connection $connection, array $headers, array $query): bool {
    return hash_equals('super-secret', $query['token'] ?? '');
});
$server->enableLogging(true); // opcjonalnie, by śledzić handshake i wiadomości
$server->setMaxDecompressionRatio(25.0); // ochrona przed zip bomb
```

Niewalidowany klient otrzyma odpowiedź HTTP 401/403, a połączenie zostanie zakończone kodem 1008 (policy violation).
Przy włączonej kompresji `setMaxDecompressionRatio()` pilnuje relacji pomiędzy rozmiarem skompresowanych danych a wynikiem dekompresji – nadmierne rozprężenie kończy połączenie kodem 1008 zanim zużyje pamięć serwera.

### Limit połączeń i kolejka

```php
$server->setMaxConnections(200);          // null = brak limitu
$server->setConnectionQueueLimit(100);    // 0 = brak limitu kolejki

echo 'Pending: ' . $server->getPendingConnectionCount();
```

Po przekroczeniu limitu nowe gniazda trafiają do kolejki FIFO. Gdy zwolni się miejsce, serwer automatycznie zacznie obsługiwać oczekujące połączenia. Jeśli kolejka osiągnie limit, klient otrzyma odpowiedź 503 i gniazdo zostanie zamknięte.

### Identyfikacja i metadane połączeń

Każde połączenie otrzymuje unikalny identyfikator (`Connection::getId()`), który możesz wykorzystać w logach lub jako klucz w mapach. Dodatkowo połączenia udostępniają etykietę i dowolne właściwości, dzięki czemu łatwo przypiszesz np. identyfikator użytkownika:

```php
$server->onOpen(function (Connection $connection) use ($userService): void {
    $token = $connection->getQueryParameter('token');
    if ($token !== null && ($userId = $userService->resolve($token))) {
        $connection->setProperty('user_id', $userId);
        $connection->setLabel('user:' . $userId);
    }
});

$server->onMessage(function (Connection $connection, string $payload, bool $isText) {
    $userId = $connection->getProperty('user_id', 'guest');
    printf("[%s] %s\n", $connection->getId(), $payload);
});

foreach ($server->getConnections() as $connection) {
    echo $connection->getLabel() ?? $connection->getId();
}

// znajdź połączenia po etykiecie lub właściwości
$admins = $server->findConnectionsByLabel('user:admin');
$user123 = $server->findConnectionsByProperty('user_id', 123);

foreach ($user123 as $conn) {
    $conn->sendText('Personalized notification');
}
```

Metadane są przechowywane tylko w pamięci i usuwane automatycznie przy zamknięciu połączenia.

### Statystyki runtime

```php
$stats = $server->getStats();
print_r($stats);

// W razie potrzeby:
$server->resetStats();
```

`getStats()` zwraca m.in. liczbę aktywnych i oczekujących połączeń, liczbę odebranych/wysłanych wiadomości, sumę bajtów, średni rozmiar wiadomości oraz aktualne zużycie pamięci (`memory_get_usage`).

## Przykłady

1. Uruchom serwer:

   ```bash
   php example/server.php
   ```

2. Otwórz klienta w przeglądarce:

   ```
   example/client.html
   ```

   Domyślnie łączy się z `ws://127.0.0.1:8088`. Wiadomości wysłane z jednej karty są nadawane do wszystkich połączonych klientów.

   Serwer testowy loguje statystyki (`getStats()`) co 10 sekund, dzięki czemu możesz podejrzeć aktualne obciążenie.

## API w skrócie

- `Server::start()` / `Server::stop()` – uruchamia/zatrzymuje nasłuchiwanie.
- `Server::broadcast(string $payload, bool $binary = false)` – wysyła wiadomość do wszystkich otwartych połączeń.
- `Connection::sendText(string $payload)` / `Connection::sendBinary(string $payload)` – wysyłanie do pojedynczego klienta.
- Obsługa zdarzeń: `onOpen`, `onMessage`, `onClose`, `onError`, `onPing`, `onPong`.
- Dostęp do informacji o kliencie: `Connection::getPath()`, `Connection::getHeaders()`, `Connection::getQueryParameters()`, `Connection::getSubprotocol()`.
- Identyfikacja i metadane: `Connection::getId()`, `Connection::setLabel()/getLabel()`, `Connection::setProperty()/getProperty()/hasProperty()/removeProperty()`, `Connection::getProperties()`.
- Konfiguracja TLS i kompresji: `enableTls`, `setTlsCryptoMethod`, `enableCompression`, `setCompressionContextTakeover`, `setCompressionMinBytes`.
- Limity i strumieniowanie: `setMaxMessageSize`, `setBinaryStreamThreshold`, `setTextStreamThreshold`, `onBinaryStream`.
- Bezpieczeństwo: `setAllowedOrigins`, `requireOriginHeader`, `setAuthValidator`.
- Logowanie: `enableLogging`, `isLoggingEnabled`.
- Adaptacja/TLS: `enableAdaptiveTextStreaming`, `setAdaptiveMemoryTarget`, `setMaxDecompressionRatio`, `enableTls`, `enableTlsAutoReload`.
- Zarządzanie połączeniami: `setMaxConnections`, `setConnectionQueueLimit`, `getPendingConnectionCount()`.
- Statystyki: `getStats`, `resetStats`.

## Testowanie

Lint:

```bash
php -l src/Frame.php
php -l src/Connection.php
php -l src/Server.php
```

Możesz dodać własne testy integracyjne, np. z użyciem klienta WebSocket w PHP lub JavaScript.

## Licencja

MIT
