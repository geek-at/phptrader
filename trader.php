<?php 

if(!file_exists('config.inc.php'))
    exit('Error! set up config.inc.php first');
include_once('config.inc.php');
include_once('vendor/autoload.php');
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
            echo "php $myname buy <amount in eur> <sell when price increases by eur>\n";
            echo "php $myname sell <amount in eur>\n";
            echo "php $myname order <amount in eur> <sell when price increases by eur> <buy at btc price>\n";
            echo "\nExamples:\n---------------\n";
            echo "Buy 10 EUR in BTC and sell when it will be worth 12 EUR:\n  php $myname buy 10 2\n";
            echo "Sell 5 EUR of your BTC:\n  php $myname sell 5\n";
            echo "Add buy order for 15 EUR when 1 BTC is worth 1000 EUR and sell when the 15 EUR are worth 17 EUR:\n  php $myname order 15 2 1000\n";
        break;
    }
    
/*
$t->buyBTC(1,1);
$t->buyBTC(1,2);
$t->buyBTC(1,3);
$t->buyBTC(1,2.2);
$t->buyBTC(10,15);
*/
//$t->sellBTCID($id);


//$t->buyBTC(1,true);
//$t->sellBTC(1,true);

//$t->buyBTC($t->buyPrice);
//$t->sellBTC($t->sellPrice);

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


    function __construct()
    {
        $configuration = Configuration::apiKey(COINBASE_KEY, COINBASE_SECRET);
        $this->client = Client::create($configuration);
        $this->account = $this->client->getPrimaryAccount();
        $this->transactions = array();
        $this->traderID = substr(md5(time().microtime()."hello".rand(1,19999)),-3);

        if(file_exists('transactions.json'))
            $this->transactions = json_decode(file_get_contents('transactions.json'), true);

        $paymentMethods = $this->client->getPaymentMethods();

        //find EUR wallet ID
        foreach($paymentMethods as $pm)
        {
            if($pm->getName() == 'EUR Wallet')
            {
                $this->walletID = $pm->getId();
                echo "[i] Found EUR Wallet ID: $this->walletID\n";
                break;
            }
        }

        $this->updatePrices();
    }

    function updatePrices()
    {
        $this->lastSellPrice = $this->sellPrice;
        $this->buyPrice =  floatval($this->client->getBuyPrice('BTC-EUR')->getAmount());
        $this->sellPrice = floatval($this->client->getSellPrice('BTC-EUR')->getAmount());
        $this->spotPrice = floatval($this->client->getSpotPrice('BTC-EUR')->getAmount());

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
        echo "[i] Buying $eur € when price is <= $buyat EUR\n";
        $id = @max(array_keys($this->transactions))+1;
        $this->transactions[$id] = array('eur'=>$eur,'buyprice'=>$buyat,'sellat'=>$sellat);
        $this->saveTransactions();
        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Will buy *$eur EUR* when BTC price hits *$buyat EUR*. Currently it's at: *$this->sellPrice EUR*. Only *".($this->sellPrice-$buyat).' EUR* to go',':raised_hands:');
    }

    function buyBTC($amount,$sellat,$btc=false)
    {
        $eur = ($btc===true?($this->buyPrice*$amount):$amount);
        $btc = ($btc===true?$amount:($amount/$this->buyPrice));

        if(SIMULATE===false)
        {
            $buy = new Buy([
                'bitcoinAmount' => $btc,
                'paymentMethodId' => $this->walletID
            ]);
            
            $this->client->createAccountBuy($this->account, $buy);
        }
        $id = @max(array_keys($this->transactions))+1;
        $this->transactions[$id] = array('btc'=>$btc,'eur'=>$eur,'buyprice'=>$this->buyPrice,'sellat'=>$sellat);

        if(DEV===true)
            echo "[B #$id] Buying $eur €\t=\t$btc BTC\n";

        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Buying *$btc BTC* for *$eur EUR*",':moneybag:','Bot #'.$this->traderID);

        $this->saveTransactions();

        return $id;
    }

    function sellBTCID($id)
    {
        $data = $this->transactions[$id];
        unset($this->transactions[$id]);
        if(DEV===true)
             echo "[S #$id] Removed transaction #$id from list\n";
        $this->sellBTC($data['btc'],true);

        $profit = round(($data['btc']*$this->sellPrice)-($data['btc']*$data['buyprice']),2);

        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Selling *".$data['btc']." BTC* for *".$data['eur']." EUR*. Profit: *$profit EUR*",':money_with_wings:','Bot #'.$this->traderID);

        $this->saveTransactions();
    }

    function sellBTC($amount,$btc=false)
    {
        $eur = ($btc===true?($this->sellPrice*$amount):$amount);
        $btc = ($btc===true?$amount:($amount/$this->sellPrice));

        $sell = new Sell([
            'bitcoinAmount' => $btc 
        ]);
        if(DEV===true)
            echo "[S] Selling $eur € =\t$btc BTC\n";
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
                echo "[BTC] Price went ".($this->sellPrice>$this->lastSellPrice?'up':'down')." by ".round($this->sellPrice-$this->lastSellPrice,2)." EUR\n";
                //if(ROCKETCHAT_REPORTING===true)
                //    sendToRocketchat("Sell price changed by *".round(($this->sellPrice-$this->lastSellPrice),2)." EUR* Was: $this->lastSellPrice, is now: $this->sellPrice",':information_source:');
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
                        unset($this->transactions[$id]);
                        $this->buyBTC($eur, ($sellat-$eur) );
                    }
                        
                }
                else
                {
                    $untilsell = round(($this->sellPrice-$sellat)*$btc,2);
                    $message = " [#$id] Holding \t$eur EUR at buy. Now worth:\t ".round($newprice,2)." EUR. Change: ".($diff)." EUR. Will sell at \t$sellat EUR (+$untilsell) EUR\n";
                    echo $message;

                    if( ($this->sellPrice*$btc) >= $sellat )
                    {
                        echo "  [#$id] AWWYEAH time to sell $btc BTC since it hit ".($this->sellPrice*$btc)." EUR. Bought at $eur EUR\n";
                        $this->sellBTCID($id);
                    }
                        
                }

                

                
            }
            

            sleep(10);
            echo "------\n";
        }
    }

    function saveTransactions()
    {
        file_put_contents("transactions.json",json_encode($this->transactions));
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