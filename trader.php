<?php 
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', dirname(__FILE__));

if(!file_exists(ROOT.DS.'config.inc.php'))
    exit('Error! set up config.inc.php first');
include_once(ROOT.DS.'config.inc.php');
//catch legacy configurations
if(!defined('CRYPTO'))
    define('CRYPTO','BTC');
include_once(ROOT.DS.'vendor/autoload.php');
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;
use Coinbase\Wallet\Resource\Sell;
use Coinbase\Wallet\Resource\Buy;
use Coinbase\Wallet\Value\Money;
$t = new trader();

    $myname = $argv[0];
    switch($argv[1])
    {
        case 'buy':
            $amount = $argv[2];
            $sellat = $argv[3];
            $t->buyBTC($amount,$sellat);
        break;

        
        case 'sell':
            $amount = $argv[2];
            $t->sellBTC($amount,true);
        break;
        
        
        case 'order':
            $amount = $argv[2];
            $sellat = $argv[3];
            $buyat = $argv[4];

            $t->addBuyTransaction($amount,$buyat,$sellat);
        break;

        case 'watchdog':
            $t->watchdog();
        break;

        default:
            echo "Usage info\n---------------\n";
            echo "php $myname buy <amount in ".CURRENCY."> <sell when price increases by ".CURRENCY.">\n";
            //echo "php $myname sell <amount in ".CRYPTO.">\n";
            echo "php $myname order <amount in ".CURRENCY."> <sell when price increases by ".CURRENCY."> <buy at ".CRYPTO." price>\n";
            echo "\nExamples:\n---------------\n";
            echo "Buy 10 ".CURRENCY." in ".CRYPTO." and sell when it will be worth 12 ".CURRENCY.":\n  php $myname buy 10 2\n";
            //echo "Sell 5 ".CURRENCY." of your ".CRYPTO.":\n  php $myname sell 5\n";
            echo "Add buy order for 15 ".CURRENCY." when 1 ".CRYPTO." is worth 1000 ".CURRENCY." and sell when the 15 ".CURRENCY." are worth 17 ".CURRENCY.":\n  php $myname order 15 2 1000\n";
        break;
    }

class trader
{
    public $buyPrice;
    public $sellPrice;
    public $spotPrice;
    public $lastSellPrice;
    
    private $client;
    private $account;
    private $walletID;
    private $transactions;
    private $traderID;

    private $redis;


    function __construct()
    {
        $configuration = Configuration::apiKey(COINBASE_KEY, COINBASE_SECRET);
        $this->client = Client::create($configuration);

        $accounts = $this->client->getAccounts();
        foreach($accounts as $account)
        {
            if($account->getName()==CRYPTO.' Wallet')
            {
                $this->account = $account;
                echo "[i] Found '".CRYPTO." Wallet' :)\n";
            }
        }
        if(!$this->account)
        {
            $this->account = $this->client->getPrimaryAccount();
            echo "[i] Didn't find your '".CRYPTO." Wallet' Account.. falling back to default\n";
        }

        $this->transactions = array();
        $this->traderID = substr(md5(time().microtime()."hello".rand(1,19999)),-3);

        //setup Redis connection if user has configured it
        if(defined('REDIS_SERVER') && REDIS_SERVER != '')
        {
            $this->redis = new Predis\Client(array(
                'scheme'   => 'tcp',
                'host'     => REDIS_SERVER,
                'port'     => REDIS_PORT,
                'database' => REDIS_DB,
                'password' => REDIS_PASS
            ));

            //var_dump($this->redis->get('1234:quota'));
        }

        //load previous data
        $this->loadTransactions();

        $paymentMethods = $this->client->getPaymentMethods();

        //find wallet ID
        foreach($paymentMethods as $pm)
        {
            if($pm->getName() == CURRENCY.' Wallet')
            {
                $this->walletID = $pm->getId();
                echo "[i] Found ".CURRENCY." Wallet ID: $this->walletID\n";
                break;
            }
        }
        if(!$this->walletID)
            exit("[ERR] Could not find your ".CURRENCY." Wallet. Do you have one on Coinbase?\n");

        $this->updatePrices();
    }

    function loadTransactions()
    {
        if(defined('REDIS_SERVER') && REDIS_SERVER != '')
        {
            if(file_exists(ROOT.DS.'transactions'.(CRYPTO!='BTC'?'-'.CRYPTO:'').'.json')) //migrate to redis
            {
                if(DEV===true) echo "[C] Found transactions".(CRYPTO!='BTC'?'-'.CRYPTO:'').".json and Redis is configured. Converting json to Redis.. ";
                $transactions = json_decode(file_get_contents(ROOT.DS.'transactions'.(CRYPTO!='BTC'?'-'.CRYPTO:'').'.json'), true);
                foreach($transactions as $key=>$transaction)
                {
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':btc',$transaction['btc']);
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':eur',$transaction['eur']);
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':buyprice',$transaction['buyprice']);
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':sellat',$transaction['sellat']);
                }
                unlink(ROOT.DS.'transactions'.(CRYPTO!='BTC'?'-'.CRYPTO:'').'.json');
                if(DEV===true) echo "done\n";
            }

            if(DEV===true) echo "[i] Loading data from Redis.. ";
            $data = $this->redis->keys('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':*');
            if(is_array($data))
                foreach($data as $d)
                {
                    $a = explode(':',$d);
                    $key = $a[1];
                    $var = $a[2];
                    $val = $this->redis->get("phptrader".(CRYPTO!='BTC'?'-'.CRYPTO:'').":$key:$var");
                    $this->transactions[$key][$var] = $val;
                }
            
            if(DEV===true) echo "done! Found ".count($this->transactions)." data points.\n";
        }
        else if(file_exists(ROOT.DS.'transactions'.(CRYPTO!='BTC'?'-'.CRYPTO:'').'.json'))
            $this->transactions = json_decode(file_get_contents(ROOT.DS.'transactions'.(CRYPTO!='BTC'?'-'.CRYPTO:'').'.json'), true);

    }

    function deleteTransaction($id)
    {
        if(DEV===true) echo "[i] Deleting transaction ID $id\n";

        if(defined('REDIS_SERVER') && REDIS_SERVER != '')
        {
            if(!$this->redis->exists("phptrader".(CRYPTO!='BTC'?'-'.CRYPTO:'').":$id:buyprice") && DEV===true)
                echo " [!ERR!] Key $id does not exist in Redis!\n";
            else
            {
                $keys = $this->redis->keys("phptrader".(CRYPTO!='BTC'?'-'.CRYPTO:'').":$id:*");
                foreach ($keys as $key) {
                    $this->redis->del($key);
                }
            }
        }
        else
            unset($this->transactions[$id]);

        $this->saveTransactions();
    }

    function saveTransactions()
    {
        if(defined('REDIS_SERVER') && REDIS_SERVER != '')
        {
            foreach($this->transactions as $key=>$transaction)
                {
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':btc',$transaction['btc']);
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':eur',$transaction['eur']);
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':buyprice',$transaction['buyprice']);
                    $this->redis->set('phptrader'.(CRYPTO!='BTC'?'-'.CRYPTO:'').':'.$key.':sellat',$transaction['sellat']);
                }
        }
        else
            file_put_contents(ROOT.DS."transactions".(CRYPTO!='BTC'?'-'.CRYPTO:'').".json",json_encode($this->transactions));
    }

    function updatePrices()
    {
        $this->lastSellPrice = $this->sellPrice;
        $this->buyPrice =  floatval($this->client->getBuyPrice(CRYPTO.'-'.CURRENCY)->getAmount());
        $this->sellPrice = floatval($this->client->getSellPrice(CRYPTO.'-'.CURRENCY)->getAmount());
        $this->spotPrice = floatval($this->client->getSpotPrice(CRYPTO.'-'.CURRENCY)->getAmount());

        if(!$this->lastSellPrice) $this->lastSellPrice = $this->sellPrice;

        if(DEV===true)
        {
            echo "[i] Buy price: $this->buyPrice\n";
            echo "[i] Sell price: $this->sellPrice\n";
            echo "[i] Spot price: $this->spotPrice\n";
            echo "[i] Difference buy/sell: ".($this->buyPrice-$this->sellPrice)."\n\n";
        }
        
    }

    function addBuyTransaction($eur,$buyat,$sellat)
    {
        $this->loadTransactions();
        echo "[i] Buying $eur € when price is <= $buyat ".CURRENCY."\n";
        $id = @max(array_keys($this->transactions))+1;
        $this->transactions[$id] = array('eur'=>$eur,'buyprice'=>$buyat,'sellat'=>$sellat);
        $this->saveTransactions();
        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Will buy *$eur ".CURRENCY."* when ".CRYPTO." price hits *$buyat ".CURRENCY."*. Currently it's at: *$this->sellPrice ".CURRENCY."*. Only *".($this->sellPrice-$buyat).' ".CURRENCY."* to go',':raised_hands:');
    }

    function buyBTC($amount,$sellat,$btc=false)
    {
        $eur = ($btc===true?($this->buyPrice*$amount):$amount);
        $btc = ($btc===true?$amount:($amount/$this->buyPrice));

        if(SIMULATE===false)
        {
            $buy = new Buy([
                'bitcoinAmount' => $btc,
                //'amount' => new Money($btc, CRYPTO),
                'paymentMethodId' => $this->walletID
            ]);

            $this->client->createAccountBuy($this->account, $buy);
        }
        $this->loadTransactions();
        $id = @max(array_keys($this->transactions))+1;
        $this->transactions[$id] = array('btc'=>$btc,'eur'=>$eur,'buyprice'=>$this->buyPrice,'sellat'=>$sellat);

        if(DEV===true)
            echo "[B #$id] Buying $eur €\t=\t$btc ".CRYPTO."\n";

        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Buying *$btc ".CRYPTO."* for *$eur ".CURRENCY."*",':moneybag:','Bot #'.$this->traderID);

        $this->saveTransactions();

        return $id;
    }

    function sellBTCID($id)
    {
        $data = $this->transactions[$id];
        $this->deleteTransaction($id);
        if(DEV===true)
             echo "[S #$id] Removed transaction #$id from list\n";
        $this->sellBTC($data['btc'],true);

        $profit = round(($data['btc']*$this->sellPrice)-($data['btc']*$data['buyprice']),2);

        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Selling *".$data['btc']." ".CRYPTO."* for *".$data['eur']." ".CURRENCY."*. Profit: *$profit ".CURRENCY."*",':money_with_wings:','Bot #'.$this->traderID);
    }

    function sellBTC($amount,$btc=false)
    {
        $eur = ($btc===true?($this->sellPrice*$amount):$amount);
        $btc = ($btc===true?$amount:($amount/$this->sellPrice));

        $sell = new Sell([
            'bitcoinAmount' => $btc 
            //'amount' => new Money($btc, CRYPTO)
        ]);
        if(DEV===true)
            echo "[S] Selling $eur € =\t$btc ".CRYPTO."\n";
        if(SIMULATE===false)
            $this->client->createAccountSell($this->account, $sell);            
        
    }

    function watchdog()
    {
        if(count($this->transactions)<=0)
        {
            echo "[ERR] No transactions to watch\n";
            return;
        }
            
        while(1)
        {
            $this->updatePrices();

            if($this->lastSellPrice!=$this->sellPrice && round(abs($this->sellPrice-$this->lastSellPrice),2) > 0)
            {
                echo "[".CRYPTO."] Price went ".($this->sellPrice>$this->lastSellPrice?'up':'down')." by ".round($this->sellPrice-$this->lastSellPrice,2)." ".CURRENCY."\n";
                //if(ROCKETCHAT_REPORTING===true)
                //    sendToRocketchat("Sell price changed by *".round(($this->sellPrice-$this->lastSellPrice),2)." ".CURRENCY."* Was: $this->lastSellPrice, is now: $this->sellPrice",':information_source:');
            }
                

            foreach($this->transactions as $id=>$td)
            {
                $btc = $td['btc'];
                $eur = $td['eur'];
                $buyprice = $td['buyprice'];
                $sellat = $td['sellat']+$eur;
                $newprice = $btc*$this->sellPrice;
                
                $diff = round(($this->sellPrice-$buyprice)*$btc,2);

                //if this is a future transaction
                if(!$btc)
                {
                    if($this->buyPrice <= $buyprice) //time to buy?
                    {
                        deleteTransaction($id);
                        $this->buyBTC($eur, ($sellat-$eur) );
                    }
                        
                }
                else
                {
                    $untilsell = round(($this->sellPrice-$sellat)*$btc,2);
                    $message = " [#$id] Holding \t$eur ".CURRENCY." at buy. Now worth:\t ".round($newprice,2)." ".CURRENCY.". Change: ".($diff)." ".CURRENCY.". Will sell at \t$sellat ".CURRENCY." (+$untilsell) ".CURRENCY."\n";
                    echo $message;

                    if( ($this->sellPrice*$btc) >= $sellat )
                    {
                        echo "  [#$id] AWWYEAH time to sell $btc ".CRYPTO." since it hit ".($this->sellPrice*$btc)." ".CURRENCY.". Bought at $eur ".CURRENCY."\n";
                        $this->sellBTCID($id);
                    }
                }

            }
            

            sleep((defined('SLEEPTIME')?SLEEPTIME:10));
            echo "------\n";
        }
    }

}


//rocketchat
function sendToRocketchat($message,$icon=':ghost:',$username='Traderbot')
{
  $data = array("icon_emoji"=>$icon,
                "username"=>$username,
		        "text"=>$message);
  makeRequest(ROCKETCHAT_WEBHOOK,array('payload'=>json_encode($data)));
}

function makeRequest($url,$data,$headers=false,$post=true)
{
    $headers[] = 'Content-type: application/x-www-form-urlencoded';
    $options = array(
        'http' => array(
            'header'  => $headers,
            'method'  => $post?'POST':'GET',
            'content' => http_build_query($data)
        )
    );
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) { /* Handle error */ }
    return json_decode($result,true);
}