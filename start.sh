pkill -f php trader.php
nohup php trader.php watchdog >> /var/log/phptrader.log 2> /var/log/phptrader.err &