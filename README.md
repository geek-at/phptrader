# Automated Bitcoin trading bot

Tutorial on https://blog.haschek.at/post/feb62

## Requirements
- A [Coinbase](https://www.coinbase.com) account
- Some money on your EUR/USD Wallet on Coinbase
- Raspberry Pi or some Linux box
- php5 or up
- [composer](https://getcomposer.org/)
- (Optional) A Rocket.Chat webhook which will inform you whenever BTCs are sold or bought

## Install
1. Download the repo by using ```git clone https://github.com/chrisiaut/phptrader.git``` or download as [ZIP file](https://github.com/chrisiaut/phptrader/archive/master.zip)
2. Inside the Traderbot directory let composer install the dependencies: ```composer install```
3. Rename example.config.inc.php to config.inc.php and fill in your data

## Usage

This consists of two parts

### Part 1: Setup the bot
Using the following commands, the bot will create a ```transacitons.json``` file where the amount in EUR, BTC, start price and sell price will be logged.

- php trader.php buy [amount in EUR] [sell when price increases by EUR]
- php trader.php sell [amount in EUR]
- php trader.php order [amount in EUR] [sell when price increases by EUR] [buy at btc price]

***Examples:***
- Buy 10 EUR in BTC and sell when it will be worth 12 EUR:
```php trader.php buy 10 2```

- Sell 5 EUR of your BTC:
```php trader.php sell 5```

- Add buy order for 15 EUR when 1 BTC is worth 1000 EUR and sell when the 15 EUR are worth 17 EUR:
```php trader.php order 15 2 1000```

### Part 2: Start the watchdog
The heart of the bot is an infinite loop that checks periodically for price changes.
You can start it yourself or use the ```start.sh``` script which will put the process in background and log to ```/var/log/phptrader.log```

```./start.sh```