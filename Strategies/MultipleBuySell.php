<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;
use Apps\Fintech\Packages\Mf\Transactions\MfTransactions;

class MultipleBuySell extends MfStrategies
{
    public $strategyDisplayName = 'Multiple Buy/Sell';

    public $strategyDescription = 'Perform multiple buy/sell of any scheme or schemes';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    public $totalTransactionsCount = 0;

    protected $totalTransactionsAmounts = [];

    protected $transactionPackage;

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function processStrategyTransactionsByDate($data, $date)
    {
        if (!$this->transactionPackage) {
            $this->transactionPackage = $this->usePackage(MfTransactions::class);
        }

        if (isset($this->transactions[$date]) && count($this->transactions[$date]) > 0) {
            foreach ($this->transactions[$date] as $transactionType => $transactions) {
                if ($transactionType === 'buy') {
                    if (count($transactions) > 0) {
                        foreach ($transactions as $transaction) {
                            $transaction['amc_transaction_id'] = '';
                            $transaction['portfolio_id'] = $data['portfolio_id'];
                            $transaction['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
                            $transaction['via_strategies'] = true;

                            if (!$this->transactionPackage->addMfTransaction($transaction)) {
                                $this->addResponse(
                                    $this->transactionPackage->packagesData->responseMessage,
                                    $this->transactionPackage->packagesData->responseCode,
                                    $this->transactionPackage->packagesData->responseData ?? []
                                );

                                return false;
                            }
                        }
                    }
                }
            }

            foreach ($this->transactions[$date] as $transactionType => $transactions) {
                if ($transactionType === 'sell') {
                    if (count($transactions) > 0) {
                        foreach ($transactions as $transaction) {
                            $transaction['amc_transaction_id'] = '';
                            $transaction['portfolio_id'] = $data['portfolio_id'];
                            $transaction['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
                            $transaction['via_strategies'] = true;

                            if (!$this->transactionPackage->addMfTransaction($transaction)) {
                                if (str_contains($this->transactionPackage->packagesData->responseMessage, 'exceeds')) {
                                    $transactions['sell_all'] = 'true';

                                    if (!$this->transactionPackage->addMfTransaction($transactions)) {
                                        $this->addResponse(
                                            $this->transactionPackage->packagesData->responseMessage,
                                            $this->transactionPackage->packagesData->responseCode,
                                            $this->transactionPackage->packagesData->responseData ?? []
                                        );

                                        return false;
                                    }
                                }

                                $this->addResponse(
                                    $this->transactionPackage->packagesData->responseMessage,
                                    $this->transactionPackage->packagesData->responseCode,
                                    $this->transactionPackage->packagesData->responseData ?? []
                                );

                                return false;
                            }
                        }
                    }
                }
            }

            return true;
        }

        $this->addResponse('Transaction with ' . $date . ' not found!', 1);

        return false;
    }

    protected function getStategyArgs()
    {
        return [
        ];
    }

    public function getStrategiesTransactions($data)
    {
        if (!$this->checkData($data)) {
            return false;
        }

        $currencySymbol = '$';
        if (isset($this->access->auth->account()['profile']['locale_country_id'])) {
            $country = $this->basepackages->geoCountries->getById((int) $this->access->auth->account()['profile']['locale_country_id']);

            if ($country && isset($country['currency_symbol'])) {
                $currencySymbol = $country['currency_symbol'];
            }
        }

        $this->transactionsCount = ['buy' => 0, 'sell' => 0];
        $this->totalTransactionsAmounts = ['buy' => 0, 'sell' => 0];

        foreach ($data['data'] as $order) {
            if ($order['type'] === 'buy') {
                $this->transactionsCount['buy']++;

                if (!isset($this->transactions[$order['date']]['buy'])) {
                    $this->transactions[$order['date']]['buy'] = [];
                }
                $transaction = [];
                $transaction['type'] = 'buy';
                $transaction['amfi_code'] = $order['amfi_code'];
                $transaction['scheme'] = $order['scheme'];
                $transaction['date'] = $order['date'];
                $transaction['amount'] = (float) $order['amount'];
                $transaction['portfolio_id'] = $data['portfolio_id'];
                array_push($this->transactions[$order['date']]['buy'], $transaction);
                $this->totalTransactionsAmounts['buy'] += $transaction['amount'];
            } else if ($order['type'] === 'sell') {
                $this->transactionsCount['sell']++;

                if (!isset($this->transactions[$order['date']]['sell'])) {
                    $this->transactions[$order['date']]['sell'] = [];
                }
                $transaction = [];
                $transaction['type'] = 'sell';
                $transaction['amfi_code'] = $order['amfi_code'];
                $transaction['scheme'] = $order['scheme'];
                $transaction['date'] = $order['date'];
                $transaction['amount'] = (float) $order['amount'];
                $transaction['portfolio_id'] = $data['portfolio_id'];
                array_push($this->transactions[$order['date']]['sell'], $transaction);
                $this->totalTransactionsAmounts['sell'] += $transaction['amount'];
            }

            $this->totalTransactionsCount++;
        }

        if (count($this->transactions) > 0) {
            $this->addResponse(
                'Calculated Transactions',
                0,
                [
                    'total_transactions_count'      => $this->transactionsCount,
                    'total_transactions_amount'     => 'Buy: ' . $currencySymbol .
                        str_replace('EN_ ',
                            '',
                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                ->formatCurrency($this->totalTransactionsAmounts['buy'], 'en_IN')) .
                        ' Sell: ' . $currencySymbol .
                        str_replace('EN_ ',
                            '',
                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                ->formatCurrency($this->totalTransactionsAmounts['sell'], 'en_IN'))
                    ,
                    'first_date'                    => $this->helper->firstKey($this->transactions),
                    'last_date'                     => $this->helper->lastKey($this->transactions),
                    'transactions'                  => $this->transactions
                ]
            );

            return $this->transactions;
        }

        $this->addResponse('Error calculating transactions, check data!', 1);
    }

    protected function checkData(&$data)
    {
        if (!isset($data['data']) ||
            isset($data['data']) && count($data['data']) === 0
        ) {
            $this->addResponse('Please provide multiple buy/sell data.', 1);

            return false;
        }

        $portfolioPackage = $this->usePackage(MfPortfolios::class);

        $portfolio = $portfolioPackage->getPortfolioById($data['portfolio_id']);

        $checkPass = true;
        $sellAmounts = [];
        $thisPackage = $this;
        array_walk($data['data'], function($row, $index) use(&$data, $portfolio, &$checkPass, &$sellAmounts, &$thisPackage) {
            unset($data['data'][$index]['action']);

            if (!isset($row['type']) &&
                !isset($row['amfi_code']) &&
                !isset($row['date']) &&
                !isset($row['amount'])
            ) {
                $checkPass = false;

                $this->addResponse('Incomplete order data provided, please provide amfi_code, date, type and amount.', 1);

                return;
            }

            $row['type'] = strtolower($row['type']);
            $data['data'][$index]['type'] = strtolower($row['type']);

            if ($row['type'] === 'sell') {
                if (!isset($portfolio['investments']) ||
                    isset($portfolio['investments']) && count($portfolio['investments']) === 0
                ) {
                    $checkPass = false;

                    $this->addResponse('You have sell order in queue, but there are no investments in the portfolio. Please buy scheme first.', 1);

                    return;
                }

                if (!isset($portfolio['investments'][$row['amfi_code']])) {
                    $checkPass = false;

                    $this->addResponse('You have sell order in queue, but there are no investments in the portfolio. Please buy scheme first.', 1);

                    return;
                }

                if (!isset($sellAmounts[$row['amfi_code']])) {
                    $sellAmounts[$row['amfi_code']] = 0;
                }

                $sellAmounts[$row['amfi_code']] = $sellAmounts[$row['amfi_code']] + (float) $row['amount'];
            }
        });

        if (!$checkPass) {
            return false;
        }

        if (count($sellAmounts) > 0) {
            foreach ($sellAmounts as $sellAmountAmfiCode => $sellAmount) {
                if ($sellAmount > $portfolio['investments'][$sellAmountAmfiCode]['latest_value']) {
                    $this->addResponse('Sell order total is greater than available amount.', 1);

                    return false;
                }
            }
        }

        return true;
    }
}