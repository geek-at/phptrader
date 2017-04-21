# Automated Bitcoin and Ethereum trading bot

## Tutorial on https://blog.haschek.at/post/feb62

## Features
- Uses the Coinbase API
- Works with USD or EUR/USD Wallets
- Works with Bitcoin or Ethereum
- Automatically buys when your desired BTC/ETH price is reached
- Automatically sells when your desired earnings are reached
- Stores buy/sell data in a local JSON file or on a Redis server

## Requirements
- A [Coinbase](https://www.coinbase.com) account
- Some money on your EUR/USD/USD Wallet on Coinbase
- Raspberry Pi or some Linux box
- php5 or up
- [composer](https://getcomposer.org/)
- (Optional) A Rocket.Chat or Slack webhook which will inform you whenever BTC/ETH are sold or bought

## Install
1. Download the repo by using ```git clone https://github.com/chrisiaut/phptrader.git``` or download as [ZIP file](https://github.com/chrisiaut/phptrader/archive/master.zip)
2. Inside the Traderbot directory let composer install the dependencies: ```composer install```
3. Rename example.config.inc.php to config.inc.php and fill in your data and wether you want to trade BTC or ETH

## Upgrading
1. Re-download or pull repo
2. check example.config.inc.php for new settings and add them to your config.inc.php
3. re-run ```composer install``` in the root directory to install new libraries

## Usage

This consists of two parts

### Part 1: Setup the bot
Using the following commands, the bot will create a ```transacitons.json``` file where the amount in EUR/USD, BTC/ETH, start price and sell price will be logged.

- php trader.php buy [amount in EUR/USD] [sell when price increases by EUR/USD]
- php trader.php sell [amount in EUR/USD] [sell when BTC/ETH price is reached]
- php trader.php order [amount in EUR/USD] [sell when price increases by EUR/USD] [buy at BTC/ETH price]

***Examples:***
- Buy 10 EUR/USD in BTC/ETH and sell when it will be worth 12 EUR/USD:
```php trader.php buy 10 2```

- Add sell order. Sell 100 EUR/USD when BTC/ETH price reaches 2000:
```php trader.php sell 100 2000```

- Add buy order for 15 EUR/USD when 1 BTC/ETH is worth 1000 EUR/USD and sell when the 15 EUR/USD are worth 17 EUR/USD:
```php trader.php order 15 2 1000```

### Part 2: Start the watchdog
The heart of the bot is an infinite loop that checks periodically for price changes.
You can start it yourself or use the ```start.sh``` script which will put the process in background and log to ```/var/log/phptrader.log```

```./start.sh```