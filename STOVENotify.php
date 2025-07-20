<?php
class STOVENotify
{
    private $config;
    private $seenGamesFile;
    private $seenGames;

    public function __construct($configPath = 'config.json')
    {
        $this->loadConfig($configPath);
        $this->seenGamesFile = $this->config['storage']['seen_games_file'] ?? 'storage/seen_games.json';
        $this->loadSeenGames();
    }

    private function loadConfig($configPath)
    {
        if (!file_exists($configPath)) {
            throw new Exception("Config file not found: $configPath");
        }

        $configData = file_get_contents($configPath);
        $this->config = json_decode($configData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in config file: " . json_last_error_msg());
        }

        $this->validateConfig();
    }

    private function validateConfig()
    {
        $required = ['api', 'discord', 'storage'];
        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new Exception("Missing required config section: $key");
            }
        }

        if (!isset($this->config['discord']['webhook_url']) || empty($this->config['discord']['webhook_url'])) {
            throw new Exception("Discord webhook URL is required");
        }
    }

    private function loadSeenGames()
    {
        if (file_exists($this->seenGamesFile)) {
            $data = file_get_contents($this->seenGamesFile);
            $this->seenGames = json_decode($data, true) ?? [];
        } else {
            $this->seenGames = [];
            $this->ensureStorageDirectory();
        }
    }

    private function ensureStorageDirectory()
    {
        $dir = dirname($this->seenGamesFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function saveSeenGames()
    {
        $this->ensureStorageDirectory();

        $jsonString = $this->customJsonEncode($this->seenGames);
        file_put_contents($this->seenGamesFile, $jsonString);
    }

    private function customJsonEncode($data)
    {
        $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $jsonString = json_encode($data, $jsonOptions);

        $jsonString = preg_replace('/(\d+\.\d{2})\d+/', '$1', $jsonString);

        return $jsonString;
    }

    public function fetchFreeGames()
    {
        $url = $this->buildApiUrl();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $this->getRequestHeaders(),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.6943.143 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => 'gzip, deflate, br'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("cURL error: $error");
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP error: $httpCode - Response: $response");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }

        if (!isset($data['value']['contents'])) {
            throw new Exception("Unexpected API response structure");
        }

        return $data['value']['contents'];
    }

    private function buildApiUrl()
    {
        $baseUrl = $this->config['api']['base_url'];
        $params = array_merge($this->config['api']['default_params'], [
            'timestemp' => time() * 1000
        ]);

        return $baseUrl . '?' . http_build_query($params);
    }

    private function getRequestHeaders()
    {
        return [
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Accept-Language: en-US,en;q=0.9',
            'Caller-Detail: b793a69d-5fb7-466f-a428-5380940-f6a5',
            'Caller-id: indie-web-store',
            'Origin: https://store.onstove.com',
            'Priority: u=1, i',
            'Referer: https://store.onstove.com/',
            'Sec-Ch-Ua: "Google Chrome";v="133"; "Chromium";v="133"; "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
            'X-Client-Lang: en',
            'X-Lang: EN',
            'X-Nation: US',
            'X-Timezone: America/Los_Angeles',
            'X-Utc-Offset: -420'
        ];
    }

    public function processGames($games)
    {
        $newGames = [];
        $currentGameIds = [];

        foreach ($games as $game) {
            $productNo = $game['product_no'];
            $currentGameIds[] = $productNo;

            if (!$this->isGameSeen($productNo)) {
                $newGames[] = $this->formatGameData($game);
                $this->markGameAsSeen($productNo, $game);
            } else {
                $this->updateSeenGame($productNo, $game);
            }
        }

        if ($this->config['storage']['remove_disappeared_games']) {
            $this->removeDisappearedGames($currentGameIds);
        }

        $this->saveSeenGames();

        return $newGames;
    }

    private function isGameSeen($productNo)
    {
        return isset($this->seenGames[$productNo]);
    }

    private function markGameAsSeen($productNo, $gameData)
    {
        $this->seenGames[$productNo] = [
            'first_seen' => time(),
            'last_updated' => time(),
            'product_name' => $gameData['product_name'],
            'original_price' => (float)sprintf('%.2f', $gameData['amount']['original_price']),
            'ever_removed' => false
        ];
    }

    private function updateSeenGame($productNo, $gameData)
    {
        if (isset($this->seenGames[$productNo])) {
            $this->seenGames[$productNo]['last_updated'] = time();
            $this->seenGames[$productNo]['product_name'] = $gameData['product_name'];
            $this->seenGames[$productNo]['original_price'] = (float)sprintf('%.2f', $gameData['amount']['original_price']);
        }
    }

    private function removeDisappearedGames($currentGameIds)
    {
        foreach ($this->seenGames as $productNo => $data) {
            if (!in_array($productNo, $currentGameIds)) {
                if ($this->config['storage']['renotify_returned_games']) {
                    $this->seenGames[$productNo]['ever_removed'] = true;
                } else {
                    unset($this->seenGames[$productNo]);
                }
            }
        }
    }

    private function formatGameData($game)
    {
        return [
            'product_no' => $game['product_no'],
            'product_name' => $game['product_name'],
            'short_piece' => $game['short_piece'],
            'original_price' => (float)sprintf('%.2f', $game['amount']['original_price']),
            'sales_price' => (float)sprintf('%.2f', $game['amount']['sales_price']),
            'discount_rate' => $game['amount']['discount_rate'],
            'store_url' => $this->buildStoreUrl($game['product_no']),
            'title_image' => $game['title_image_rectangle'] ?? $game['title_image_square'],
            'genres' => array_map(function ($genre) {
                return $genre['tag_name'];
            }, $game['genres']),
            'tags' => array_map(function ($tag) {
                return $tag['tag_name'];
            }, $game['tags'])
        ];
    }

    private function buildStoreUrl($productNo)
    {
        return "https://store.onstove.com/en/games/{$productNo}";
    }

    public function sendDiscordNotifications($newGames)
    {
        if (empty($newGames)) {
            $this->log("No new games to notify about");
            return true;
        }

        foreach ($newGames as $game) {
            $success = $this->sendDiscordWebhook($game);
            if (!$success) {
                $this->log("Failed to send Discord notification for game: " . $game['product_name']);
                return false;
            }

            if ($this->config['discord']['delay_between_notifications'] > 0) {
                sleep($this->config['discord']['delay_between_notifications']);
            }
        }

        return true;
    }

    private function sendDiscordWebhook($game)
    {
        $embed = $this->createDiscordEmbed($game);

        $payload = [
            'username' => $this->config['discord']['bot_name'],
            'embeds' => [$embed]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->config['discord']['webhook_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && ($httpCode === 200 || $httpCode === 204);
    }

    private function createDiscordEmbed($game)
    {
        $description = !empty($game['short_piece']) ?
            (strlen($game['short_piece']) > 200 ?
                substr($game['short_piece'], 0, 200) . '...' :
                $game['short_piece']
            ) : 'No description available';

        $embed = [
            'title' => $game['product_name'],
            'description' => $description,
            'url' => $game['store_url'],
            'color' => hexdec($this->config['discord']['embed_color']),
            'timestamp' => date('c'),
            'fields' => [
                [
                    'name' => 'Original Price',
                    'value' => '$' . number_format($game['original_price'], 2),
                    'inline' => true
                ],
                [
                    'name' => 'Current Price',
                    'value' => 'FREE',
                    'inline' => true
                ],
                [
                    'name' => 'Discount',
                    'value' => $game['discount_rate'] . '%',
                    'inline' => true
                ]
            ],
            'footer' => [
                'text' => 'STOVENotify v1.0.0'
            ]
        ];

        if (!empty($game['title_image'])) {
            $embed['thumbnail'] = ['url' => $game['title_image']];
        }

        if (!empty($game['genres'])) {
            $embed['fields'][] = [
                'name' => 'Genres',
                'value' => implode(', ', array_slice($game['genres'], 0, 3)),
                'inline' => false
            ];
        }

        if (!empty($game['tags'])) {
            $embed['fields'][] = [
                'name' => 'Tags',
                'value' => implode(', ', array_slice($game['tags'], 0, 5)),
                'inline' => false
            ];
        }

        return $embed;
    }

    public function run()
    {
        try {
            $this->log("Starting STOVENotify scan...");

            $games = $this->fetchFreeGames();
            $this->log("Fetched " . count($games) . " free games from API");

            $newGames = $this->processGames($games);
            $this->log("Found " . count($newGames) . " new free games");

            if (!empty($newGames)) {
                $success = $this->sendDiscordNotifications($newGames);
                if ($success) {
                    $this->log("Successfully sent Discord notifications for " . count($newGames) . " games");
                } else {
                    $this->log("Failed to send some Discord notifications");
                }
            }

            $this->log("STOVENotify scan completed successfully");
        } catch (Exception $e) {
            $this->log("Error during STOVENotify scan: " . $e->getMessage());
            throw $e;
        }
    }

    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;

        if ($this->config['logging']['enabled']) {
            $logFile = $this->config['logging']['file'] ?? 'logs/stovenotify.log';
            $logDir = dirname($logFile);

            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }

        if ($this->config['logging']['echo'] ?? false) {
            echo $logMessage;
        }
    }

    public function getSeenGamesCount()
    {
        return count($this->seenGames);
    }

    public function getSeenGames()
    {
        return $this->seenGames;
    }
}
