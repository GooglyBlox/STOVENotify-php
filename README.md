# STOVE Notify PHP

Automated STOVE free game monitor that checks for free games on the STOVE platform and sends Discord notifications. Designed to run as a cron job for automated monitoring.

## Requirements

- PHP 7.4 or higher
- cURL extension enabled

## Installation

1. Clone the repository:
```bash
git clone https://github.com/GooglyBlox/STOVENotify-php.git
cd STOVENotify-php
```

2. Create required directories:
```bash
mkdir -p storage logs
```

## Configuration

Edit `config.json` to configure the application:

```json
{
  "discord": {
    "webhook_url": "YOUR_DISCORD_WEBHOOK_URL_HERE",
    "bot_name": "STOVE Notify Bot",
    "embed_color": "ff6e29",
    "delay_between_notifications": 1
  },
  "storage": {
    "seen_games_file": "storage/seen_games.json",
    "remove_disappeared_games": true,
    "renotify_returned_games": false
  },
  "logging": {
    "enabled": true,
    "echo": false,
    "file": "logs/stovenotify.log"
  }
}
```

**Required Configuration:**
- `discord.webhook_url`: Your Discord webhook URL for notifications

## Usage

Run the monitor:
```bash
php run.php
```

### Cron Job Setup

Add to your crontab to run automatically (example runs every 30 minutes):
```bash
*/30 * * * * /usr/bin/php /path/to/STOVENotify-php/run.php
```

## License

This project is licensed under the [MIT License](https://github.com/GooglyBlox/STOVENotify-php/blob/master/LICENSE).