<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;
use Apps\Fintech\Packages\Mf\Transactions\MfTransactions;

class Swp extends MfStrategies
{
    public $strategyDisplayName = 'SWP';

    public $strategyDescription = 'Perform SWP strategy on a portfolio';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    public $totalTransactionsCount = 0;

    protected $incrementWeek;

    protected $incrementMonth;

    protected $incrementYear;

    protected $incrementAmount = 0;

    protected $totalTransactionsAmounts = [];

    protected $nextTransactionIndex = 1;

    protected $incrementSchedule = 'increment-none';

    protected $transactionPackage;

    protected $startEndDates;

    protected $previousPercentValue = 0;

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

        if (isset($this->transactions[$date])) {
            $this->transactions[$date]['portfolio_id'] = (int) $data['portfolio_id'];
            $this->transactions[$date]['amc_id'] = (int) $data['amc_id'];
            if (isset($data['scheme_id'])) {
                $this->transactions[$date]['scheme_id'] = (int) $data['scheme_id'];
            } else if (isset($data['amfi_code'])) {
                $this->transactions[$date]['amfi_code'] = (int) $data['amfi_code'];
            }
            $this->transactions[$date]['amc_transaction_id'] = '';
            $this->transactions[$date]['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
            $this->transactions[$date]['via_strategies'] = true;

                if (!$this->transactionPackage->addMfTransaction($this->transactions[$date])) {
                    if (str_contains($this->transactionPackage->packagesData->responseMessage, 'exceeds')) {
                        $this->transactions[$date]['sell_all'] = 'true';

                        if (!$this->transactionPackage->addMfTransaction($this->transactions[$date])) {
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

        if (isset($data['increment_schedule']) && $data['increment_schedule'] !== 'increment-none') {
            $this->incrementSchedule = $data['increment_schedule'];
        }

        if (isset($data['scheme_id'])) {
            $this->totalTransactionsCount++;
            $this->transactionsCount['buy']++;
            $this->transactions[$data['investmentDate']]['type'] = 'buy';
            $this->transactions[$data['investmentDate']]['scheme'] = $data['scheme']['name'];
            $this->transactions[$data['investmentDate']]['date'] = $data['investmentDate'];
            $this->transactions[$data['investmentDate']]['amount'] = (float) $data['investmentAmount'];
            $this->totalTransactionsAmounts['buy'] += $this->transactions[$data['investmentDate']]['amount'];
        }

        foreach ($this->startEndDates as $index => $date) {
            $dateString = $date->toDateString();

            if ($data['schedule'] === 'weekly') {
                if (in_array($date->dayOfWeek(), $data['weekly_days'])) {
                    if (!isset($this->transactions[$dateString])) {
                        $this->transactions[$dateString] = [];
                    } else {
                        //If you are buying on the day which is also the start day of sell, this will overwrite the buy order,
                        //to avoid that the workaround is to buy via transact mode and then create strategy of sell
                        //or the date of sell will be calculated from the next date.
                        if ($this->transactions[$dateString]['type'] === 'buy') {
                            continue;
                        }
                    }

                    $this->totalTransactionsCount++;
                    $this->transactionsCount['sell']++;
                    $this->transactions[$dateString]['type'] = 'sell';
                    $this->transactions[$dateString]['scheme'] = $data['scheme']['name'];
                    $this->transactions[$dateString]['date'] = $dateString;

                    if ((isset($data['scheme_id']) && count($this->transactions) === 2) ||
                        (!isset($data['scheme_id']) && count($this->transactions) === 1)
                    ) {
                        $this->incrementWeek = $date->weekOfYear;
                        $this->incrementMonth = $date->month;
                        $this->incrementYear = $date->year;

                        $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                    } else {
                        if ($this->incrementSchedule !== 'increment-none') {
                            $this->transactions[$dateString]['amount'] = $this->getIncrementedAmount($date, $data);
                        } else {
                            $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                        }
                    }

                    $this->totalTransactionsAmounts['sell'] += $this->transactions[$dateString]['amount'];
                }
            } else if ($data['schedule'] === 'monthly') {
                if (in_array($date->month, $data['monthly_months'])) {
                    if ($date->day == $data['monthly_day']) {
                        //If transaction is happening on Sunday, move it to Monday.
                        if ($date->englishDayOfWeek === 'Sunday') {
                            $date = $date->addDay();
                            $dateString = $date->toDateString();
                        }

                        if (!isset($this->transactions[$dateString])) {
                            $this->transactions[$dateString] = [];
                        }

                        $this->totalTransactionsCount++;
                        $this->transactionsCount['sell']++;
                        $this->transactions[$dateString]['type'] = 'sell';
                        $this->transactions[$dateString]['scheme'] = $data['scheme']['name'];
                        $this->transactions[$dateString]['date'] = $dateString;

                        if ((isset($data['scheme_id']) && count($this->transactions) === 2) ||
                            (!isset($data['scheme_id']) && count($this->transactions) === 1)
                        ) {
                            $this->incrementWeek = $date->weekOfYear;
                            $this->incrementMonth = $date->month;
                            $this->incrementYear = $date->year;

                            $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                        } else {
                            if ($this->incrementSchedule !== 'increment-none') {
                                $this->transactions[$dateString]['amount'] = $this->getIncrementedAmount($date, $data);
                            } else {
                                $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                            }
                        }

                        $this->totalTransactionsAmounts['sell'] += $this->transactions[$dateString]['amount'];
                    }
                }
            }
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
                    'first_date'                    => $this->helper->first($this->transactions)['date'],
                    'last_date'                     => $this->helper->last($this->transactions)['date'],
                    'transactions'                  => $this->transactions
                ]
            );

            return $this->transactions;
        }

        $this->addResponse('Error calculating transactions, check dates!', 1);
    }

    protected function getIncrementedAmount($date, $data)
    {
        if ($this->incrementAmount === 0) {
            $this->incrementAmount = (float) $data['amount'];
        }

        if ($this->previousPercentValue === 0) {
            $this->previousPercentValue = (float) $data['amount'];
        }

        if ($this->incrementSchedule === 'increment-next') {
            if ($data['increment_type'] === 'percent') {
                $this->previousPercentValue =
                    $this->incrementAmount =
                        round(((100 + $data['increment_value']) / 100) * $this->previousPercentValue);
            } else if ($data['increment_type'] === 'amount') {
                $this->incrementAmount = $data['amount'] + ($this->nextTransactionIndex * $data['increment_value']);
            }

            $this->nextTransactionIndex++;

            return (float) $this->incrementAmount;
        } else if ($this->incrementSchedule === 'increment-weekly') {
            if ($this->incrementWeek !== $date->weekOfYear) {
                if ($data['increment_type'] === 'percent') {
                    $this->previousPercentValue =
                        $this->incrementAmount =
                            round(((100 + $data['increment_value']) / 100) * $this->previousPercentValue);
                } else if ($data['increment_type'] === 'amount') {
                    $this->incrementAmount = $data['amount'] + ($this->nextTransactionIndex * $data['increment_value']);
                }

                $this->incrementWeek = $date->weekOfYear;

                $this->nextTransactionIndex++;
            }

            return (float) $this->incrementAmount;
        } else if ($this->incrementSchedule === 'increment-monthly') {
            if ($this->incrementMonth !== $date->month) {
                if ($data['increment_type'] === 'percent') {
                    $this->previousPercentValue =
                        $this->incrementAmount =
                            round(((100 + $data['increment_value']) / 100) * $this->previousPercentValue);
                } else if ($data['increment_type'] === 'amount') {
                    $this->incrementAmount = $data['amount'] + ($this->nextTransactionIndex * $data['increment_value']);
                }

                $this->incrementMonth = $date->month;

                $this->nextTransactionIndex++;
            }

            return (float) $this->incrementAmount;
        } else if ($this->incrementSchedule === 'increment-yearly') {
            if ($this->incrementYear !== $date->year) {
                if ($data['increment_type'] === 'percent') {
                    $this->previousPercentValue =
                        $this->incrementAmount =
                            round(((100 + $data['increment_value']) / 100) * $this->previousPercentValue);
                } else if ($data['increment_type'] === 'amount') {
                    $this->incrementAmount = $data['amount'] + ($this->nextTransactionIndex * $data['increment_value']);
                }

                $this->incrementYear = $date->year;

                $this->nextTransactionIndex++;
            }

            return (float) $this->incrementAmount;
        }

        return (float) $data['amount'];
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

        if (isset($data['scheme_id'])) {
            if (!isset($data['investmentDate']) || !isset($data['investmentAmount'])) {
                $this->addResponse('Investment date/amount not provided', 1);

                return false;
            }

            if ((\Carbon\Carbon::parse($data['startDate']))->lt((\Carbon\Carbon::parse($data['investmentDate'])))) {
                $this->addResponse('Start date cannot be before investment date', 1);

                return false;
            }
        }

        $data['scheme'] = $this->getSchemeFromAmfiCodeOrSchemeId($data);

        if (!isset($data['amount'])) {
            $this->addResponse('Please provide amount', 1);

            return false;
        }

        if (!isset($data['schedule'])) {
            $this->addResponse('Please provide schedule', 1);

            return false;
        }

        if ($data['schedule'] === 'monthly' &&
            (!isset($data['monthly_months']) || !isset($data['monthly_day']))
        ) {
            $this->addResponse('Please provide schedule months data', 1);

            return false;
        }

        if ($data['schedule'] === 'weekly' && !isset($data['weekly_days'])) {
            $this->addResponse('Please provide schedule weeks', 1);

            return false;
        }

        if (isset($data['increment_schedule']) &&
            $data['increment_schedule'] !== 'increment-none'
        ) {
            if (!isset($data['increment_type'])) {
                $data['increment_type'] = 'amount';
            }

            if (!isset($data['increment_value'])) {
                $this->addResponse('Please provide increment schedule value', 1);

                return false;
            }
        }

        return true;
    }
}