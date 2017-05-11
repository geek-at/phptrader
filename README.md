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
| Command  | Parameters                                     | What does it do                                                                                             | Example                                                                                              |
|----------|------------------------------------------------|-------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| buy      | [amount in EUR/USD] [earnings]                 | Buys the amount in EUR/USD and sells when the earnings are reached                                          | buy 100 2 (Buy 100 EUR/USD and sell when the 100 are worth 2 more (102 EUR/USD))                     |
| sell     | [amount in EUR/USD] [crypto price]             | Adds a high sell order. Will sell amount when the crypto price is reached                                   | sell 300 3000 (Sell 300 EUR/USD when 1 coin is worth 3000 EUR/USD)                                   |
| order    | [amount in EUR/USD] [earnings] [BTC/ETH price] | Adds a low buy order. Will buy amount when BTC/ETH price is reached and will sell when earnings are reached | order 500 20 1000 (Buy 500 EUR/USD when 1 coin is worth 1000 EUR/USD and sell when 500 are worth 520 |
| watchdog | -none-                                         | Starts infinite loop where prices are checked and orders are bought/sold                                    |                                                                                                      |
| list     | -none-                                         | Lists all open transactions with IDs                                                                        |                                                                                                      |
| delete   | transaction ID                                 | Allows you to delete transactions                                                                           |                                                                                                      |
| check    | -none-                                         | Checks prices and orders. Does what "watchdog" does but only once                                           |                                                                                                      |
| report   | -none-                                         | Reports current status of all transactions to chat webhook                                                  |                                
| debug   | -none-                                         | Lists all your payment methods and wallets                                                  |                                                                                                      |

### Start the watchdog
The heart of the bot is an infinite loop that checks periodically for price changes.
You can start it yourself or use the ```start.sh``` script which will put the process in background and log to ```/var/log/phptrader.log```

```./start.sh```