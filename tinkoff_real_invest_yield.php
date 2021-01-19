#!/usr/bin/php
<?php
if (!isset($argv[1])) {
    exit("USAGE: $argv[0] <session id>\n\n    Session ID is in 'psid' cookie, looks like KzEJ4C63fNuM0O8S4WXDEXOlgFETl3Xg.m1-prod-api27\n    To get it login to Tinkoff, then open Developer Tools -> Application -> Cookies -> www.tinkoff.ru\n\n/!\ Never show your Session ID anywhere, it allows stealing money. This script is OK :)\n\n");
}

$sessionId = $argv[1];
$verbose = false;


/* 1. Get all invest accounts, normally "Tinkoff" and "TinkoffIis" */
echo "[.] Getting invest accounts... ";
$brokerAccounts = tinkoff_trading_all_accounts($sessionId);
echo implode(", ", array_map('json_encode', $brokerAccounts));
echo "\r[+]\n\n";


$currentHoldings = [];
$referenceExchangeRate = [];  // changed by tinkoff_trading_securities_balance_cash( )

/* 2. Enumerate cash and securities on each account */
foreach ($brokerAccounts as $acc) {
    echo "[.] Getting '$acc' cash holdings... ";
    $currBalance = tinkoff_trading_currency_balance_cash($sessionId, $acc);
    foreach ($currBalance as $curr => $amt) {
        @$currentHoldings[$curr] += $amt;
        echo format_money($amt) . " $curr, ";
    }
    echo "\x08\x08  ";
    echo "\r[+]\n";
    
    echo "[.] Getting '$acc' securities holdings... ";
    $secBalance = tinkoff_trading_securities_balance_cash($sessionId, $acc);
    foreach ($secBalance as $curr => $amt) {
        @$currentHoldings[$curr] += $amt;
        echo format_money($amt) . " $curr, ";
    }
    echo "\x08\x08  ";
    echo "\r[+]\n";
}
echo "\n";

// Calculate reference currency exchange rates by looking at portfolio value in different currencies
$rubAmt = $referenceExchangeRate['RUB'];
foreach ($referenceExchangeRate as $curr => $amt) {
    $referenceExchangeRate[$curr] = $rubAmt / $amt;
}


$cashFlowHistory = [];
$totalDeposited = $totalWithdrawn = [];

/* 3. Enumerate bank operations related to invest accounts deposit and withdrawal */
echo "[.] Getting bank operations... ";
$operations = tinkoff_bank_operations($sessionId);
echo count($operations['payload']) . " operations";
echo "\r[+]\n";

$relevantDescriptions = [
    "Пополнение торгового счета" => true,  // БКС пополнение
    "Пополнение. Перевод средств с торгового счета" => true,  // БКС снятие

    "Пополнение счета Тинькофф Брокер" => true,  // Тинькофф пополнение
    "Вывод средств с брокерского счета" => true,  // Тинькофф/Инвесткопилка снятие
    "Вывод со счета Тинькофф Брокер" => true,  // Тинькофф снятие
    
    "Инвесткопилка" => true,  // Инвесткопилка пополнение
    "Регулярный перевод в Инвесткопилку" => true,  // Инвесткопилка пополнение
];

foreach ($operations['payload'] as $op) {
    if (!isset($relevantDescriptions[$op['description']])) {
        continue;
    }
    
    $time = $op['operationTime']['milliseconds'] / 1000;
    $curr = strtoupper($op['accountAmount']['currency']['name']);
    $amount = $op['accountAmount']['value'];
    if ($op['type'] == "Credit") {  // withdraw
        @$totalWithdrawn[$curr] += $amount;
        @$cashFlowHistory[$curr][] = ['time' => $time, 'amount' => - $amount, 'desc' => $op['description'] . ", карта " . substr($op['cardNumber'], -5)];
    } else {  // deposit
        @$totalDeposited[$curr] += $amount;
        @$cashFlowHistory[$curr][] = ['time' => $time, 'amount' => $amount, 'desc' => $op['description'] . ", карта " . substr($op['cardNumber'], -5)];
    }
}


/* 4. Enumerate trading operations related to currency exchange */
echo "[.] Getting trading operations... ";
$operations = tinkoff_trading_operations($sessionId);
echo count($operations['payload']['items']) . " operations";
echo "\r[+]\n";

foreach ($operations['payload']['items'] as $op) {
    if (@$op['instrumentType'] != "FX") {
        continue;
    }
    
    $time = strtotime($op['date']);
    $curr = str_replace("RUB", "", strtoupper($op['ticker']));
    if (strlen($curr) != 3) {
        echo "[!!!] Bad fx ticker: " . $op['ticker'] . "\n";
        continue;
    }
    $amountRub = $op['payment'];
    @$amountFx = $op['quantity'];
    if ($amountFx == 0 || $op['status'] == "decline") {
        continue;
    }
    if ($op['operationType'] == "Buy" || $op['operationType'] == "BuyWithCard") {  // conv RUB->fx
        $amountRub = - $amountRub;

        @$totalWithdrawn['RUB'] += $amountRub;
        @$cashFlowHistory['RUB'][] = ['time' => $time, 'amount' => - $amountRub, 'desc' => $op['description']];

        @$totalDeposited[$curr] += $amountFx;
        @$cashFlowHistory[$curr][] = ['time' => $time, 'amount' => $amountFx, 'desc' => $op['description']];
    } elseif ($op['operationType'] == "Sell") {  // conv fx->RUB
        @$totalWithdrawn[$curr] += $amountFx;
        @$cashFlowHistory[$curr][] = ['time' => $time, 'amount' => - $amountFx, 'desc' => $op['description']];

        @$totalDeposited['RUB'] += $amountRub;
        @$cashFlowHistory['RUB'][] = ['time' => $time, 'amount' => $amountRub, 'desc' => $op['description']];
    }
}
echo "\n";

// Summarize deposits and withdrawals totals for each currency
foreach (array_keys($totalDeposited + $totalWithdrawn) as $curr) {
    echo "[i] $curr: deposited " . format_money_flow(@(float)$totalDeposited[$curr]) . " $curr, ";
    echo "withdrawn " . format_money_flow(-@(float)$totalWithdrawn[$curr]) . " $curr\n";
}
echo "\n";


$cumulCurrentRub = 0;
$cumulProfitRub = 0;
foreach ($currentHoldings as $curr => $amt) {
    $cumulCurrentRub += $amt * $referenceExchangeRate[$curr];
}

/* 5. Use deposits/withdrawals dates to derive equivalent APY rate for each currency */
foreach ($cashFlowHistory as $curr => $ops) {
    usort($cashFlowHistory[$curr], function ($a, $b) { return $a['time'] - $b['time']; });
}

foreach ($cashFlowHistory as $curr => $ops) {
    echo "[*] Currency $curr\n";
    
    if ($verbose) {
        echo "    ==================== Operations with $curr ====================\n\n";
        foreach ($ops as $op) {
            printf("    %s   %12.2f   %s\n", date("Y-m-d H:i:s", $op['time']), $op['amount'], $op['desc']);
        }
        echo "\n\n";
    }
    
    // $equation = build_equation_daily_interest($ops);  // as if APY was paid daily
    $equation = build_equation_monthly_interest($ops);  // reallife Tinkoff deposits, APY paid monthly on weighted-avg balance
    
    if ($verbose) {
        echo "    $equation\n\n";
    }
    
    $func = create_function('$p', $equation);  // $p is daily multiplier
    $dailyMultiplier = binary_search($func, 1, 10000, (float)@$currentHoldings[$curr]);
    $apy = ($dailyMultiplier - 1) * 365 * 100;

    $totalProfits = @$currentHoldings[$curr] + @$totalWithdrawn[$curr] - @$totalDeposited[$curr];
    echo "    Total profits: " . format_money_flow($totalProfits) . " $curr\n";
    echo "    Derived $curr APY rate: " . format_percent($apy) . " %\n\n";
    
    $cumulProfitRub += $totalProfits * $referenceExchangeRate[$curr];
}


echo "[***] Overall converted to RUB\n";
$cumulDepositRub = $cumulCurrentRub - $cumulProfitRub;
echo "      Investments:   " . format_money($cumulDepositRub) . " RUB\n";
echo "      Current value: " . format_money($cumulCurrentRub) . " RUB\n";
echo "      Profits:       " . format_money_flow($cumulProfitRub) . " RUB (" . format_percent_flow($cumulProfitRub / $cumulDepositRub * 100) . " %)\n";


/* * * * * * * * * * * * * * * * * * * * * * * * * * */


function tinkoff_api_call($query, $slug) {
    static $useLocalCache = null;
    
    if ($useLocalCache === null && file_exists("$slug.json")) {
        echo "\n[?] Found local cache at '$slug.json'. Use cache instead of online api calls? [n] ";
        $choice = strtolower(fgets(STDIN)[0]);
        $useLocalCache = $choice == 'y';
        echo "    ... ";
    }
    
    for ($retries = 0; $retries < 3; $retries++) {
        if ($useLocalCache && file_exists("$slug.json")) {
            echo "(from cache)... ";
            $res = file_get_contents("$slug.json");
        } else {
            $res = file_get_contents("https://www.tinkoff.ru" . $query);
            file_put_contents("$slug.json", $res);
        }
        
        @$res = json_decode($res, true);
        if (!is_array($res)) {
            if ($retries < 2) {
                echo "json_decode failed, retrying... ";
                @unlink("$slug.json");
            }
            continue;
        }
        
        if (isset($res['errorMessage'])) {
            echo $res['errorMessage'], "\n";
            echo "    (check session id?)\n";
            exit;
        }
        
        if (isset($res['status']) && $res['status'] == 'Error' && isset($res['payload']['message'])) {
            echo $res['payload']['message'], "\n";
            echo "    (check session id?)\n";
            exit;
        }
        
        if (!isset($res['payload'])) {
            if ($retries < 2) {
                echo "no 'payload', retrying... ";
                @unlink("$slug.json");
            }
            continue;
        }
        
        return $res;
    }
    
    echo "FAILED\n";
    exit;
}

function tinkoff_trading_all_accounts($sessionId) {
    $allAccounts = tinkoff_api_call("/api/trading/portfolio/all_accounts?sessionId=$sessionId", "all_accounts");
    $brokerAccounts = [];
    foreach ($allAccounts['payload']['accounts'] as $acc) {
        $brokerAccounts[] = $acc['brokerAccountType'];
    }
    return $brokerAccounts;
}

function tinkoff_trading_currency_balance_cash($sessionId, $acc) {
    $balance = [];
    
    $currLimits = tinkoff_api_call("/api/trading/portfolio/currency_limits?sessionId=$sessionId&brokerAccountType=$acc", "currency_limits-$acc");
    foreach ($currLimits['payload']['data'] as $curr) {
        if ($curr['currentBalance'] == 0) {
            continue;
        }
        $balance[strtoupper($curr['currency'])] = $curr['currentBalance'];
    }
    
    return $balance;
}

function tinkoff_trading_securities_balance_cash($sessionId, $acc) {
    global $referenceExchangeRate;
    
    $balance = [];
    
    $purchSec = tinkoff_api_call("/api/trading/portfolio/purchased_securities?sessionId=$sessionId&brokerAccountType=$acc", "purchased_securities-$acc");
    foreach ($purchSec['payload']['data'] as $sec) {
        if ($sec['currentAmount']['value'] == 0) {
            continue;
        }
        @$balance[strtoupper($sec['currentAmount']['currency'])] += $sec['currentAmount']['value'];
    }
    
    foreach ($purchSec['payload']['portfolioAmountByCurrency'] as $curr => $amt) {
        @$referenceExchangeRate[strtoupper($curr)] += $amt;
    }
    
    return $balance;
}

function tinkoff_bank_operations($sessionId) {
    $operations = tinkoff_api_call("/api/common/v1/operations?sessionid=$sessionId&start=0", "operations");
    return $operations;
}

function tinkoff_trading_operations($sessionId) {
    $operations = tinkoff_api_call("/api/trading/user/operations?sessionId=$sessionId&from=2001-01-01T00:00:00Z&to=2099-01-01T00:00:00Z", "trading-operations");
    return $operations;
}

function build_equation_daily_interest($ops) {  // as if APY was paid daily
    $startDay = strtotime(date("Y-m-d", $ops[0]['time']) . " 23:59:59");
    $endDay = strtotime(date("Y-m-d", time() + 86400));

    $eqn = '(0)*($p**0)';
    for ($day = $startDay; $day <= $endDay; $day += 86400) {
        $delta = 0;
        while (!empty($ops) && $day >= $ops[0]['time']) {
            $delta += $ops[0]['amount'];
            array_shift($ops);
        }
        
        // increment exponent
        $eqn = substr($eqn, 0, strrpos($eqn, '**')) . '**' . ((int)substr($eqn, strrpos($eqn, '**') + 2) + 1) . ')';
        
        if ($delta != 0) {
            $eqn = '(' . $eqn . ' + (' . $delta . '))*($p**0)';
        }
    }
    $eqn = "return $eqn;";
    
    return $eqn;
}

function build_equation_monthly_interest($ops) {  // reallife Tinkoff deposits, APY paid monthly on weighted-avg balance
    $startDay = strtotime(date("Y-m-d", $ops[0]['time']) . " 23:59:59");
    $endDay = strtotime(date("Y-m-d", time() + 86400));

    $eqn = '$b=0; $t=0; ';
    for ($day = $startDay; $day <= $endDay; $day += 86400) {
        $delta = 0;
        while (!empty($ops) && $day >= $ops[0]['time']) {
            $delta += $ops[0]['amount'];
            array_shift($ops);
        }
        
        $eqn .= '$t+=$b*($p-1); ';
        
        if ($delta != 0) {
            $eqn .= '$b+=' . $delta . '; ';
        }
        
        if (date('j', $day) == 1) {  // pay interest on 1st of every month
            $eqn .= '$b+=$t; $t=0; ';
        }
    }
    $eqn .= '$b+=$t; $t=0; return $b;';
    
    return $eqn;
}

function binary_search($func, $minP, $maxP, $target) {
    $midP = 1;
    $val = $func($midP);
    if (abs($val - $target) >= 0.01) {
        while (abs($maxP - $minP) > 0.0000001) {
            $midP = ($minP + $maxP) / 2;
            $val = $func($midP);
            // echo "    minP: $minP, maxP: $maxP, midP: $midP, val: $val\n";
            if (abs($val - $target) < 0.01) {
                break;
            }
            if ($target < $val) {
                $maxP = $midP;
            } elseif ($target > $val) {
                $minP = $midP;
            }
        }
    }
    
    return $midP;
}

function format_money($amount) {
    return number_format($amount, 2, '.', ' ');
}

function format_money_flow($amount) {
    return ($amount > 0 ? "+" : "") . format_money($amount);
}

function format_percent($percent) {
    return round($percent, 2);
}

function format_percent_flow($percent) {
    return ($percent > 0 ? "+" : "") . format_percent($percent);
}
