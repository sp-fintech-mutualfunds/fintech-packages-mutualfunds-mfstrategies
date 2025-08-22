<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;
use Apps\Fintech\Packages\Mf\Transactions\MfTransactions;

class Trajectory extends MfStrategies
{
    public $strategyDisplayName = 'Trajectory';

    public $strategyDescription = 'Perform trajectory strategy on a portfolio';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    protected $totalTransactionsAmounts = [];

    protected $transactionPackage;

    protected $startEndDates;

    protected $week = 0;

    protected $weekTransactionDone = false;

    protected $carryForwardAmount = 0;

    protected $monitoringDays = 0;

    protected $scheme;

    protected $schemeNavs = [];

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

        $this->checkData($data);

        if (isset($this->transactions[$date])) {
            $dt = \Carbon\Carbon::parse($date);
            $week = $dt->weekOfYear;

            if ($this->week === 0) {//First entry, we dont process as we do not have trajectory comparison data
                $this->week = $week;

                return true;
            }

            if ($week > $this->week) {//Change of Week.
                if (isset($data['carry_forward_to_next_week']) &&
                    $data['carry_forward_to_next_week'] == 'true' &&
                    !$this->weekTransactionDone
                ) {
                    $this->carryForwardAmount = $this->carryForwardAmount + (float) $data['amount'];
                }

                $this->weekTransactionDone = false;
                $this->monitoringDays = 0;

                $this->week = $week;

                return $this->processStrategyTransactionsByDate($data, $date);
            } else {//Same week
                if ($this->weekTransactionDone) {//Transaction for the week is done.
                    return true;
                } else {//No transaction took place in the week.
                    if (isset($this->schemeNavs[$date]['trajectory']) &&
                        $this->schemeNavs[$date]['trajectory'] === $data['trajectory']
                    ) {
                        if ($data['percent'] !== 0) {
                            if (isset($this->schemeNavs[$date]['diff_percent'])) {
                                if (abs($this->schemeNavs[$date]['diff_percent']) < $data['percent']) {
                                    return true;
                                }
                            }
                        }

                        if ((int) $data['days'] > 0) {
                            if ($this->monitoringDays === (int) $data['days']) {//Create Transaction
                                return $this->generateTransaction($data, $date);
                            } else if ($this->monitoringDays < (int) $data['days']) {
                                $this->monitoringDays++;

                                return true;
                            }
                        } else if ((int) $data['days'] === 0) {//Create Transaction
                            return $this->generateTransaction($data, $date);
                        }
                    } else {
                        $this->monitoringDays = 0;//Reset monitoring days.
                    }

                    return true;
                }
            }
        }

        $this->addResponse('Transaction with ' . $date . ' not found!', 1);

        return false;
    }

    protected function generateTransaction($data, $date)
    {
        $this->transactions[$date]['portfolio_id'] = (int) $data['portfolio_id'];
        $this->transactions[$date]['amc_id'] = (int) $data['amc_id'];
        if (isset($data['scheme_id'])) {
            $this->transactions[$date]['scheme_id'] = (int) $data['scheme_id'];
        } else if (isset($data['amfi_code'])) {
            $this->transactions[$date]['amfi_code'] = (int) $data['amfi_code'];
        }
        $this->transactions[$date]['amc_transaction_id'] = '';
        $this->transactions[$date]['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
        $this->transactions[$date]['type'] = $date['type'];
        $this->transactions[$date]['via_strategies'] = true;
        $this->transactions[$date]['date'] = $date;
        $this->transactions[$date]['amount'] = (float) $data['amount'] + $this->carryForwardAmount;
        $this->transactions[$date]['strategy_id'] = (int) $data['strategy_id'];

        if (!$this->transactionPackage->addMfTransaction($this->transactions[$date])) {
            $this->addResponse(
                $this->transactionPackage->packagesData->responseMessage,
                $this->transactionPackage->packagesData->responseCode,
                $this->transactionPackage->packagesData->responseData ?? []
            );

            return false;
        }

        $this->weekTransactionDone = true;

        $this->monitoringDays = 0;

        $this->carryForwardAmount = 0;

        return true;
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

        if (!$this->scheme) {
            $this->scheme = $this->getSchemeFromAmfiCodeOrSchemeId($data, true);

            if (!isset($this->scheme['navs']['navs'])) {
                $this->addResponse('Navs of the selected scheme not present, Please import navs.', 1);

                return false;
            }
        }

        foreach ($this->startEndDates as $dateIndex => $date) {
            $dateString = $date->toDateString();

            if (!isset($this->scheme['navs']['navs'][$dateString])) {
                $this->addResponse('Nav for date:' . $dateString . ' of the selected scheme not present, Please import navs.', 1);

                return false;
            }

            $this->schemeNavs[$dateString] = $this->scheme['navs']['navs'][$dateString];

            if ($date->isWeekend()) {
                continue;
            }

            $this->transactions[$dateString] = [];
        }

        if (count($this->transactions) > 0) {
            $this->addResponse(
                'Calculated Transactions',
                0,
                [
                    'total_transactions_count'      => $this->transactionsCount,
                    'total_transactions_amount'     =>
                        'Buy: ' . $currencySymbol .
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
                    'first_date'                    => $data['startDate'],
                    'last_date'                     => $data['endDate'],
                    'transactions'                  => []
                ]
            );

            return $this->transactions;
        }

        $this->addResponse('Error calculating transactions, check dates!', 1);
    }

    protected function checkData(&$data)
    {
        try {
            $this->startEndDates = (\Carbon\CarbonPeriod::between($data['startDate'], $data['endDate']))->toArray();
        } catch (\throwable $e) {
            $this->addResponse('Dates provided are incorrect', 1);

            return false;
        }

        if (!isset($data['amfi_code']) && !isset($data['scheme_id'])) {
            $this->addResponse('Investment scheme not provided', 1);

            return false;
        }

        $data['scheme'] = $this->getSchemeFromAmfiCodeOrSchemeId($data);

        if (!$data['scheme']) {
            $this->addResponse('Please provide correct scheme amfi code or scheme id', 1);

            return false;
        }

        if (!isset($data['amount'])) {
            $this->addResponse('Please provide amount', 1);

            return false;
        }

        $data['amount'] = (float) $data['amount'];

        // $intervals = false;

        // if ($data['interval_first'] !== '' || $data['interval_second'] !== '' || $data['interval_third'] !== '') {
        //     $intervals = true;
        // }

        // if ($intervals) {
        //     if ($data['interval_first'] === '' || $data['interval_second'] === '' || $data['interval_third'] === '') {
        //         $this->addResponse('Please provide all intervals amounts', 1);

        //         return false;
        //     }

        //     $data['interval_first'] = (float) $data['interval_first'];
        //     $data['interval_second'] = (float) $data['interval_second'];
        //     $data['interval_third'] = (float) $data['interval_third'];
        //     $intervalTotal = $data['interval_first'] + $data['interval_second'] + $data['interval_third'];

        //     if ($intervalTotal !== $data['amount']) {
        //         $this->addResponse('Interval total should be equal to Total Monthly Investment.', 1);

        //         return false;
        //     }
        // }

        $data['days'] = (int) $data['days'];
        if ($data['days'] > 4) {
            $data['days'] = 4;
        }
        $data['percent'] = (float) $data['percent'];
        // if ($data['trajectory'] === 'down') {
        //     $data['percent'] = -$data['percent'];
        // }

        return true;
    }
}